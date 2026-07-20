<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Outbound\NewSaleController as BaseNewSaleController;
use App\Http\Resources\ResponseResource;
use App\Models\Buyer;
use App\Models\Sale;
use App\Models\SaleDocument;
use App\Models\VoucherApproval;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class NewSaleController extends BaseNewSaleController
{
    private function recalculateSaleDocumentTotals(SaleDocument $saleDocument): array
    {
        $totalProductPriceSale = (float) Sale::where('code_document_sale', $saleDocument->code_document_sale)
            ->sum('product_price_sale');

        $voucherValue = (float) ($saleDocument->voucher ?? 0);
        $voucherRankValue = (float) ($saleDocument->voucher_rank_value ?? 0);
        $cardboxTotalPrice = (float) ($saleDocument->cardbox_total_price ?? 0);
        $taxRate = (float) ($saleDocument->tax ?? 0);
        $isTax = (int) ($saleDocument->is_tax ?? 0);

        $totalPriceDocumentSale = max(0, $totalProductPriceSale - $voucherValue - $voucherRankValue);
        $grandTotal = $totalPriceDocumentSale + $cardboxTotalPrice;

        $priceAfterTax = $grandTotal;
        if ($isTax === 1 && $taxRate > 0) {
            $priceAfterTax = $grandTotal + ($grandTotal * ($taxRate / 100));
        }

        return [
            'total_product_price_sale' => $totalProductPriceSale,
            'voucher_value' => $voucherValue,
            'voucher_rank_value' => $voucherRankValue,
            'cardbox_total_price' => $cardboxTotalPrice,
            'total_price_document_sale' => $totalPriceDocumentSale,
            'grand_total' => $grandTotal,
            'price_after_tax' => ceil($priceAfterTax),
        ];
    }

     public function pakaiVoucher(Request $request)
    {
        DB::beginTransaction();

        try {

            $userId = auth()->id();

            $validator = Validator::make($request->all(), [
                'voucher_id' => 'required|exists:vouchers,id',
            ]);

            if ($validator->fails()) {
                return new ResponseResource(
                    false,
                    'Input tidak valid!',
                    $validator->errors()
                );
            }

            $saleDocument = SaleDocument::where(
                'status_document_sale',
                'proses'
            )
                ->where('user_id', $userId)
                ->first();

            if (!$saleDocument) {
                DB::rollBack();

                return new ResponseResource(
                    false,
                    'Tidak ada transaksi yang sedang diproses',
                    null
                );
            }

            if (!$saleDocument->buyer_id_document_sale) {
                DB::rollBack();

                return new ResponseResource(
                    false,
                    'Buyer belum dipilih pada transaksi ini',
                    null
                );
            }

            $buyer = Buyer::findOrFail(
                $saleDocument->buyer_id_document_sale
            );

            $voucher = $buyer->vouchers()
                ->wherePivot('status', true)
                ->where('vouchers.id', $request->voucher_id)
                ->select(
                    'vouchers.id',
                    'vouchers.amount',
                    'vouchers.max_usage',
                    'vouchers.max_week',
                    'vouchers.start_date',
                    'vouchers.min_transaction'
                )
                ->first();

            if (!$voucher) {
                DB::rollBack();

                return new ResponseResource(
                    false,
                    'Voucher tidak ditemukan atau tidak aktif',
                    null
                );
            }

            $startDate = Carbon::parse($voucher->start_date);

            $expiredDate = $startDate->copy()->addWeeks($voucher->max_week);

            if (now()->gt($expiredDate)) {
                DB::rollBack();

                return new ResponseResource(
                    false,
                    'Voucher sudah expired',
                    null
                );
            }

            $maxUsedCount = $voucher->max_usage > 0
                ? (int) floor($voucher->amount / $voucher->max_usage)
                : 0;

            if (($voucher->pivot->used ?? 0) >= $maxUsedCount) {
                DB::rollBack();

                return new ResponseResource(
                    false,
                    'Kuota penggunaan voucher sudah habis',
                    null
                );
            }

            $calculation = $this->recalculateSaleDocumentTotals($saleDocument);
            $transactionTotal = (float) ($calculation['total_price_document_sale'] ?? 0);
            $minimumTransaction = (float) ($voucher->min_transaction ?? 0);

            if ($minimumTransaction > 0 && $transactionTotal < $minimumTransaction) {
                DB::rollBack();

                return (new ResponseResource(
                    false,
                    'Voucher gagal digunakan. Total transaksi harus minimal Rp ' . number_format($minimumTransaction, 0, ',', '.'),
                    [
                        'total_price_document_sale' => $transactionTotal,
                        'minimum_transaction' => $minimumTransaction,
                    ]
                ))->response()->setStatusCode(422);
            }

            // Cek apakah sudah ada request pending
            $pending = VoucherApproval::where('sale_document_id', $saleDocument->id)
                ->where('status', 'pending')
                ->exists();

            if ($pending) {
                DB::rollBack();

                return new ResponseResource(
                    false,
                    'Masih ada pengajuan voucher yang menunggu approval',
                    null
                );
            }

            VoucherApproval::create([
                'requested_by'     => $userId,
                'voucher_id'       => $voucher->id,
                'buyer_id'         => $buyer->id,
                'nominal'          => $voucher->max_usage,
                'usage'            => $voucher->pivot->used ?? 0,
                'status'           => 'pending',
                'date_request'     => now(),
                'sale_document_id' => $saleDocument->id,
            ]);

            DB::commit();

            return new ResponseResource(
                true,
                'Pengajuan voucher berhasil dibuat dan menunggu approval',
                null
            );
        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Error mengajukan voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return new ResponseResource(
                false,
                'Gagal mengajukan voucher',
                $e->getMessage()
            );
        }
    }

    public function lepasVoucher()
    {
        DB::beginTransaction();

        try {
            $userId = auth()->id();

            $saleDocument = SaleDocument::where('status_document_sale', 'proses')
                ->where('user_id', $userId)
                ->first();

            if (!$saleDocument) {
                DB::rollBack();

                return new ResponseResource(false, 'Tidak ada transaksi yang sedang diproses', null);
            }

            if (!$saleDocument->voucher_id) {
                DB::rollBack();

                return new ResponseResource(false, 'Tidak ada voucher yang digunakan', null);
            }

            $currentVoucherId = $saleDocument->voucher_id;
            $buyerId = $saleDocument->buyer_id_document_sale;

            if ($buyerId) {
                $buyer = Buyer::find($buyerId);
                if ($buyer) {
                    $voucher = $buyer->vouchers()
                        ->where('vouchers.id', $currentVoucherId)
                        ->first();

                    if ($voucher) {
                        $currentUsed = $voucher->pivot->used ?? 0;

                        $buyer->vouchers()->updateExistingPivot(
                            $currentVoucherId,
                            [
                                'used' => max(0, $currentUsed - 1),
                            ]
                        );
                    }
                }
            }

            $saleDocument->update([
                'voucher_id' => null,
                'voucher_rank_value' => null,
            ]);

            $calculation = $this->recalculateSaleDocumentTotals($saleDocument);

            $saleDocument->update([
                'total_price_document_sale' => $calculation['total_price_document_sale'],
                'price_after_tax' => $calculation['price_after_tax'],
            ]);

            DB::commit();

            return new ResponseResource(
                true,
                'Voucher berhasil dihapus',
                [
                    'sale_document' => [
                        'id' => $saleDocument->id,
                        'code_document_sale' => $saleDocument->code_document_sale,
                        'voucher' => $saleDocument->voucher,
                        'voucher_id' => $saleDocument->voucher_id,
                        'voucher_rank_value' => $saleDocument->voucher_rank_value,
                        'total_price_document_sale' => $saleDocument->total_price_document_sale,
                        'price_after_tax' => $saleDocument->price_after_tax,
                        'grand_total' => $saleDocument->grand_total,
                    ],
                    'calculation' => $calculation,
                ]
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error menghapus voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return new ResponseResource(false, 'Gagal menghapus voucher', $e->getMessage());
        }
    }

    public function approveVoucher($id)
    {
        DB::beginTransaction();

        try {
            $approval = VoucherApproval::findOrFail($id);

            if ($approval->status !== 'pending') {
                DB::rollBack();

                return new ResponseResource(false, 'Request voucher sudah diproses', null);
            }

            $saleDocument = SaleDocument::findOrFail($approval->sale_document_id);

            $isManualVoucher = empty($approval->voucher_id);

            if ($isManualVoucher) {
                $approval->update([
                    'approved_by' => auth()->id(),
                    'status' => 'approve',
                    'date_approved' => now(),
                ]);

                $saleDocument->update([
                    'voucher' => (float) $approval->nominal,
                    'voucher_id' => null,
                ]);

                $calculation = $this->recalculateSaleDocumentTotals($saleDocument);

                $saleDocument->update([
                    'total_price_document_sale' => $calculation['total_price_document_sale'],
                    'price_after_tax' => $calculation['price_after_tax'],
                ]);

                DB::commit();

                return new ResponseResource(
                    true,
                    'Voucher berhasil diapprove',
                    [
                        'sale_document' => [
                            'id' => $saleDocument->id,
                            'code_document_sale' => $saleDocument->code_document_sale,
                            'voucher' => $saleDocument->voucher,
                            'voucher_id' => $saleDocument->voucher_id,
                            'voucher_rank_value' => $saleDocument->voucher_rank_value,
                            'total_price_document_sale' => $saleDocument->total_price_document_sale,
                            'price_after_tax' => $saleDocument->price_after_tax,
                            'grand_total' => $saleDocument->grand_total,
                        ],
                        'calculation' => $calculation,
                    ]
                );
            }

            $buyer = Buyer::findOrFail($approval->buyer_id);

            $voucher = $buyer->vouchers()
                ->wherePivot('status', true)
                ->where('vouchers.id', $approval->voucher_id)
                ->select(
                    'vouchers.id',
                    'vouchers.amount',
                    'vouchers.max_usage',
                    'vouchers.max_week',
                    'vouchers.start_date'
                )
                ->first();

            if (!$voucher) {
                DB::rollBack();

                return new ResponseResource(false, 'Voucher tidak ditemukan atau tidak aktif', null);
            }

            $expiredDate = Carbon::parse($voucher->start_date)->addWeeks($voucher->max_week);

            if (now()->gt($expiredDate)) {
                DB::rollBack();

                return new ResponseResource(false, 'Voucher sudah expired', null);
            }

            $maxUsedCount = $voucher->max_usage > 0
                ? (int) floor($voucher->amount / $voucher->max_usage)
                : 0;

            if (($voucher->pivot->used ?? 0) >= $maxUsedCount) {
                DB::rollBack();

                return new ResponseResource(false, 'Kuota penggunaan voucher sudah habis', null);
            }

            $approval->update([
                'approved_by' => auth()->id(),
                'status' => 'approve',
                'date_approved' => now(),
            ]);

            $saleDocument->update([
                'voucher_id' => $voucher->id,
                'voucher_rank_value' => $voucher->max_usage,
            ]);

            $calculation = $this->recalculateSaleDocumentTotals($saleDocument);

            $saleDocument->update([
                'total_price_document_sale' => $calculation['total_price_document_sale'],
                'price_after_tax' => $calculation['price_after_tax'],
            ]);

            $buyer->vouchers()->updateExistingPivot(
                $voucher->id,
                [
                    'used' => ($voucher->pivot->used ?? 0) + 1,
                ]
            );

            DB::commit();

            return new ResponseResource(
                true,
                'Voucher berhasil diapprove',
                [
                    'sale_document' => [
                        'id' => $saleDocument->id,
                        'code_document_sale' => $saleDocument->code_document_sale,
                        'voucher' => $saleDocument->voucher,
                        'voucher_id' => $saleDocument->voucher_id,
                        'voucher_rank_value' => $saleDocument->voucher_rank_value,
                        'total_price_document_sale' => $saleDocument->total_price_document_sale,
                        'price_after_tax' => $saleDocument->price_after_tax,
                        'grand_total' => $saleDocument->grand_total,
                    ],
                    'calculation' => $calculation,
                ]
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error approve voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return new ResponseResource(false, 'Gagal approve voucher', $e->getMessage());
        }
    }

    public function rejectVoucher($id)
    {
        DB::beginTransaction();

        try {
            $approval = VoucherApproval::findOrFail($id);

            if ($approval->status !== 'pending') {
                DB::rollBack();

                return new ResponseResource(false, 'Request voucher sudah diproses', null);
            }

            $approval->update([
                'approved_by' => auth()->id(),
                'status' => 'reject',
                'date_approved' => now(),
            ]);

            DB::commit();

            return new ResponseResource(true, 'Pengajuan voucher berhasil ditolak', null);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error reject voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return new ResponseResource(false, 'Gagal menolak pengajuan voucher', $e->getMessage());
        }
    }

    public function checkPendingApproval()
    {
        try {
            $approval = VoucherApproval::with([
                'voucher:id,name',
                'saleDocument:id,code_document_sale',
            ])
                ->where('requested_by', auth()->id())
                ->where('status', 'pending')
                ->latest('date_request')
                ->first();

            return new ResponseResource(
                true,
                'Berhasil mendapatkan status approval',
                [
                    'is_approval_pending' => (bool) $approval,
                    'voucher_name' => $approval?->voucher?->name ?? 'Voucher Manual',
                    'code_document' => $approval?->saleDocument?->code_document_sale,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Error check pending approval', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return new ResponseResource(
                false,
                'Gagal mendapatkan status approval',
                $e->getMessage()
            );
        }
    }

    public function listApprovalVoucher(Request $request)
    {
        try {
            $approvals = VoucherApproval::with([
                'voucher:id,name,max_usage',
                'requester:id,name',
                'approver:id,name',
                'buyer',
            ])
                ->when($request->filled('q'), function ($query) use ($request) {
                    $query->whereHas('requester', function ($q) use ($request) {
                        $q->where('name', 'like', '%' . $request->q . '%');
                    });
                })
                ->when($request->filled('status'), function ($query) use ($request) {
                    if ($request->status !== 'all') {
                        $query->where('status', $request->status);
                    }
                })
                ->latest('date_request')
                ->paginate(10);

            $approvals->getCollection()->transform(function ($approval) {
                $pivot = $approval->buyer
                    ?->vouchers()
                    ->where('voucher_id', $approval->voucher_id)
                    ->first()?->pivot;

                return [
                    'id' => $approval->id,
                    'voucher_name' => $approval->voucher?->name ?? 'Voucher Manual',
                    'requested_by' => $approval->requester->name,
                    'approved_by' => $approval->approver?->name,
                    'nominal' => $approval->voucher?->max_usage ?? $approval->nominal,
                    'buyer_name' => $approval->buyer?->name_buyer,
                    'usage' => $approval->voucher_id ? ($pivot?->used ?? 0) : ($approval->usage ?? 0),
                    'status' => $approval->status,
                    'date_request' => $approval->date_request,
                    'date_approved' => $approval->date_approved,
                ];
            });

            return new ResponseResource(
                true,
                'Berhasil mendapatkan data approval',
                $approvals
            );
        } catch (\Throwable $e) {
            Log::error('Error list approval voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return new ResponseResource(
                false,
                'Gagal mendapatkan data approval',
                $e->getMessage()
            );
        }
    }
}

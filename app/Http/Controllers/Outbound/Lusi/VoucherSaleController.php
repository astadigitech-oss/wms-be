<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Sale;
use App\Models\SaleDocument;
use App\Models\VoucherApproval;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VoucherSaleController extends Controller
{
    // public function store(Request $request, $saleDocument = null)
    // {
    //     $saleDocument = $this->resolveSaleDocument($saleDocument, $request);
    //     if (!$saleDocument) {
    //         return (new ResponseResource(false, 'Sale document tidak ditemukan', null))
    //             ->response()
    //             ->setStatusCode(404);
    //     }

    //     $validator = Validator::make($request->all(), [
    //         'voucher' => 'required|numeric|min:0',
    //     ]);

    //     if ($validator->fails()) {
    //         return (new ResponseResource(false, 'Input tidak valid!', $validator->errors()))
    //             ->response()
    //             ->setStatusCode(422);
    //     }

    //     DB::beginTransaction();

    //     try {
    //         if ($saleDocument->status_document_sale !== 'proses') {
    //             DB::rollBack();

    //             return (new ResponseResource(false, 'Voucher hanya bisa diajukan untuk sale document yang masih proses', [
    //                 'status_document_sale' => $saleDocument->status_document_sale,
    //             ]))->response()->setStatusCode(422);
    //         }

    //         $pending = VoucherApproval::where('sale_document_id', $saleDocument->id)
    //             ->where('status', 'pending')
    //             ->exists();

    //         if ($pending) {
    //             DB::rollBack();

    //             return (new ResponseResource(false, 'Masih ada pengajuan voucher yang menunggu approval', null))
    //                 ->response()
    //                 ->setStatusCode(422);
    //         }

    //         // if (!$saleDocument->buyer_id_document_sale) {
    //         //     DB::rollBack();

    //         //     return (new ResponseResource(false, 'Buyer belum dipilih pada transaksi ini', null))
    //         //         ->response()
    //         //         ->setStatusCode(422);
    //         // }

    //         // $voucherValue = (float) $request->input('voucher');

    //         // VoucherApproval::create([
    //         //     'requested_by' => auth()->id(),
    //         //     'voucher_id' => null,
    //         //     'buyer_id' => $saleDocument->buyer_id_document_sale,
    //         //     'nominal' => $voucherValue,
    //         //     'usage' => null,
    //         //     'status' => 'pending',
    //         //     'date_request' => now(),
    //         //     'sale_document_id' => $saleDocument->id,
    //         // ]);
    //         if (!$saleDocument->buyer_id_document_sale) {
    //             DB::rollBack();

    //             return (new ResponseResource(false, 'Buyer belum dipilih pada transaksi ini', null))
    //                 ->response()
    //                 ->setStatusCode(422);
    //         }

    //         // ============================================
    //         // Validasi minimal transaksi Rp 5.000.000
    //         // ============================================
    //         $minimumTransaction = 5000000;

    //         if ((float) $saleDocument->total_price_document_sale <= $minimumTransaction) {
    //             DB::rollBack();

    //             return (new ResponseResource(
    //                 false,
    //                 'Voucher hanya dapat diajukan apabila total transaksi lebih dari Rp ' . number_format($minimumTransaction, 0, ',', '.'),
    //                 [
    //                     'total_price_document_sale' => (float) $saleDocument->total_price_document_sale,
    //                     'minimum_transaction' => $minimumTransaction,
    //                 ]
    //             ))->response()->setStatusCode(422);
    //         }

    //         $voucherValue = (float) $request->input('voucher');

    //         VoucherApproval::create([
    //             'requested_by' => auth()->id(),
    //             'voucher_id' => null,
    //             'buyer_id' => $saleDocument->buyer_id_document_sale,
    //             'nominal' => $voucherValue,
    //             'usage' => null,
    //             'status' => 'pending',
    //             'date_request' => now(),
    //             'sale_document_id' => $saleDocument->id,
    //         ]);

    //         DB::commit();

    //         return new ResponseResource(true, 'Pengajuan voucher berhasil dibuat dan menunggu approval', [
    //             'sale_document' => [
    //                 'id' => $saleDocument->id,
    //                 'code_document_sale' => $saleDocument->code_document_sale,
    //                 'buyer_id_document_sale' => $saleDocument->buyer_id_document_sale,
    //                 'status_document_sale' => $saleDocument->status_document_sale,
    //             ],
    //             'voucher' => [
    //                 'nominal' => $voucherValue,
    //             ],
    //         ]);
    //     } catch (\Exception $e) {
    //         DB::rollBack();

    //         Log::error('Gagal mengajukan voucher sale document', [
    //             'message' => $e->getMessage(),
    //             'file' => $e->getFile(),
    //             'line' => $e->getLine(),
    //             'request' => $request->all(),
    //         ]);

    //         return (new ResponseResource(false, 'Gagal menyimpan voucher', [
    //             'message' => $e->getMessage(),
    //         ]))->response()->setStatusCode(500);
    //     }
    // }
    public function store(Request $request, $saleDocument = null)
    {
        $saleDocument = $this->resolveSaleDocument($saleDocument, $request);
        if (!$saleDocument) {
            return (new ResponseResource(false, 'Sale document tidak ditemukan', null))
                ->response()
                ->setStatusCode(404);
        }

        $validator = Validator::make($request->all(), [
            'voucher' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(false, 'Input tidak valid!', $validator->errors()))
                ->response()
                ->setStatusCode(422);
        }

        DB::beginTransaction();

        try {
            if ($saleDocument->status_document_sale !== 'proses') {
                DB::rollBack();

                return (new ResponseResource(false, 'Voucher hanya bisa diajukan untuk sale document yang masih proses', [
                    'status_document_sale' => $saleDocument->status_document_sale,
                ]))->response()->setStatusCode(422);
            }

            $pending = VoucherApproval::where('sale_document_id', $saleDocument->id)
                ->where('status', 'pending')
                ->exists();

            if ($pending) {
                DB::rollBack();

                return (new ResponseResource(false, 'Masih ada pengajuan voucher yang menunggu approval', null))
                    ->response()
                    ->setStatusCode(422);
            }

            if (!$saleDocument->buyer_id_document_sale) {
                DB::rollBack();

                return (new ResponseResource(false, 'Buyer belum dipilih pada transaksi ini', null))
                    ->response()
                    ->setStatusCode(422);
            }

            // ============================================
            // Validasi minimal transaksi Rp 3.000.000
            // Mengacu ke total_price_document_sale hasil kalkulasi sales GET
            // ============================================
            $minimumTransaction = 3000000;
            $calculation = $this->calculateSaleDocumentTotals($saleDocument);
            $totalPriceDocumentSale = (float) ($calculation['total_price_document_sale'] ?? 0);

            if ($totalPriceDocumentSale < $minimumTransaction) {
                DB::rollBack();

                return (new ResponseResource(
                    false,
                    'Voucher hanya dapat diajukan apabila total transaksi minimal Rp ' . number_format($minimumTransaction, 0, ',', '.'),
                    [
                        'total_price_document_sale' => $totalPriceDocumentSale,
                        'minimum_transaction' => $minimumTransaction,
                    ]
                ))->response()->setStatusCode(422);
            }

            $voucherValue = (float) $request->input('voucher');

            VoucherApproval::create([
                'requested_by' => auth()->id(),
                'voucher_id' => null,
                'buyer_id' => $saleDocument->buyer_id_document_sale,
                'nominal' => $voucherValue,
                'usage' => null,
                'status' => 'pending',
                'date_request' => now(),
                'sale_document_id' => $saleDocument->id,
            ]);

            DB::commit();

            return new ResponseResource(true, 'Pengajuan voucher berhasil dibuat dan menunggu approval', [
                'sale_document' => [
                    'id' => $saleDocument->id,
                    'code_document_sale' => $saleDocument->code_document_sale,
                    'buyer_id_document_sale' => $saleDocument->buyer_id_document_sale,
                    'status_document_sale' => $saleDocument->status_document_sale,
                ],
                'voucher' => [
                    'nominal' => $voucherValue,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Gagal mengajukan voucher sale document', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return (new ResponseResource(false, 'Gagal menyimpan voucher', [
                'message' => $e->getMessage(),
            ]))->response()->setStatusCode(500);
        }
    }

    public function destroy(Request $request, $saleDocument = null)
    {
        $saleDocument = $this->resolveSaleDocument($saleDocument, $request);

        if (!$saleDocument) {
            return (new ResponseResource(false, 'Sale document tidak ditemukan', null))
                ->response()
                ->setStatusCode(404);
        }

        DB::beginTransaction();

        try {
            $approval = VoucherApproval::where('sale_document_id', $saleDocument->id)
                ->where('requested_by', auth()->id())
                ->whereIn('status', ['pending', 'approve'])
                ->latest('date_request')
                ->first();

            if (!$approval) {
                DB::rollBack();

                return (new ResponseResource(false, 'Tidak ada pengajuan voucher yang menunggu approval', null))
                    ->response()
                    ->setStatusCode(404);
            }

            // Jika voucher berasal dari sistem dan sudah diapprove, tidak boleh dihapus
            if ($approval->status === 'approve' && !empty($approval->voucher_id)) {
                DB::rollBack();

                return (new ResponseResource(false, 'Voucher yang sudah diapprove hanya bisa dihapus untuk voucher manual', null))
                    ->response()
                    ->setStatusCode(422);
            }

            // Lepaskan voucher dari transaksi
            $saleDocument->update([
                'voucher' => 0,
                'voucher_id' => null,
                'voucher_rank_value' => 0,
            ]);

            // Hitung ulang total transaksi
            $calculation = $this->calculateSaleDocumentTotals($saleDocument);

            $saleDocument->update([
                'total_price_document_sale' => $calculation['total_price_document_sale'],
                'price_after_tax' => $calculation['price_after_tax'],
            ]);

            // Tidak menghapus approval dan tidak mengubah status approval
            DB::commit();

            $saleDocument->refresh();

            return new ResponseResource(true, 'Voucher berhasil dilepas dari transaksi', [
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
                'approval' => [
                    'id' => $approval->id,
                    'status' => $approval->status,
                    'voucher_id' => $approval->voucher_id,
                    'requested_by' => $approval->requested_by,
                    'approved_by' => $approval->approved_by,
                ],
                'calculation' => $calculation,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Gagal menghapus voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return (new ResponseResource(false, 'Gagal menghapus voucher', [
                'message' => $e->getMessage(),
            ]))
                ->response()
                ->setStatusCode(500);
        }
    }
    private function calculateSaleDocumentTotals(SaleDocument $saleDocument): array
    {
        $totalProductPriceSale = (float) Sale::where('code_document_sale', $saleDocument->code_document_sale)
            ->sum('product_price_sale');

        $voucherValue = (float) ($saleDocument->voucher ?? 0);
        $voucherRankValue = (float) ($saleDocument->voucher_rank_value ?? 0);
        $cardboxTotalPrice = (float) ($saleDocument->cardbox_total_price ?? 0);
        $taxRate = (float) ($saleDocument->tax ?? 0);
        $isTax = (int) ($saleDocument->is_tax ?? 0);

        $totalBeforeTax = max(0, $totalProductPriceSale - $voucherValue - $voucherRankValue);
        $grandTotal = $totalBeforeTax + $cardboxTotalPrice;

        $priceAfterTax = $grandTotal;
        if ($isTax === 1 && $taxRate > 0) {
            $priceAfterTax = $grandTotal + ($grandTotal * ($taxRate / 100));
        }

        return [
            'total_product_price_sale' => $totalProductPriceSale,
            'voucher_value' => $voucherValue,
            'voucher_rank_value' => $voucherRankValue,
            'cardbox_total_price' => $cardboxTotalPrice,
            'total_price_document_sale' => $totalBeforeTax,
            'grand_total' => $grandTotal,
            'price_after_tax' => ceil($priceAfterTax),
        ];
    }

    private function resolveSaleDocument($saleDocumentKey, Request $request): ?SaleDocument
    {
        if ($saleDocumentKey instanceof SaleDocument) {
            return $saleDocumentKey;
        }

        $candidate = SaleDocument::query()
            ->whereKey($saleDocumentKey)
            ->orWhere('code_document_sale', $saleDocumentKey)
            ->first();

        if ($candidate) {
            return $candidate;
        }

        $bodyId = $request->input('sale_document_id');
        $bodyCode = $request->input('code_document_sale');

        if ($bodyId || $bodyCode) {
            return SaleDocument::query()
                ->when($bodyId, fn($query) => $query->whereKey($bodyId))
                ->when(!$bodyId && $bodyCode, fn($query) => $query->where('code_document_sale', $bodyCode))
                ->first();
        }

        return null;
    }
}

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
}

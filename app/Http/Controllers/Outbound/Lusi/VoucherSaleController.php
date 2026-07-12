<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Sale;
use App\Models\SaleDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VoucherSaleController extends Controller
{
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

                return (new ResponseResource(false, 'Voucher hanya bisa diubah untuk sale document yang masih proses', [
                    'status_document_sale' => $saleDocument->status_document_sale,
                ]))->response()->setStatusCode(422);
            }

            $voucherValue = (float) $request->input('voucher');

            $calculation = $this->calculateSaleDocumentTotals($saleDocument, $voucherValue);

            $saleDocument->update([
                'voucher' => $voucherValue,
                'total_price_document_sale' => $calculation['total_price_document_sale'],
                'price_after_tax' => $calculation['price_after_tax'],
            ]);

            $saleDocument->refresh();

            DB::commit();

            return new ResponseResource(true, 'Voucher berhasil disimpan', $this->buildResponsePayload($saleDocument, $calculation));
        } catch (\Exception $e) {
            DB::rollBack();

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
            if ($saleDocument->status_document_sale !== 'proses') {
                DB::rollBack();

                return (new ResponseResource(false, 'Voucher hanya bisa dihapus untuk sale document yang masih proses', [
                    'status_document_sale' => $saleDocument->status_document_sale,
                ]))->response()->setStatusCode(422);
            }

            $calculation = $this->calculateSaleDocumentTotals($saleDocument, 0);

            $saleDocument->update([
                'voucher' => 0,
                'total_price_document_sale' => $calculation['total_price_document_sale'],
                'price_after_tax' => $calculation['price_after_tax'],
            ]);

            $saleDocument->refresh();

            DB::commit();

            return new ResponseResource(true, 'Voucher berhasil dihapus', $this->buildResponsePayload($saleDocument, $calculation));
        } catch (\Exception $e) {
            DB::rollBack();

            return (new ResponseResource(false, 'Gagal menghapus voucher', [
                'message' => $e->getMessage(),
            ]))->response()->setStatusCode(500);
        }
    }

    private function calculateSaleDocumentTotals(SaleDocument $saleDocument, float $voucherValue): array
    {
        $totalProductPriceSale = (float) Sale::where('code_document_sale', $saleDocument->code_document_sale)
            ->sum('product_price_sale');

        $voucherRankValue = (float) ($saleDocument->voucher_rank_value ?? 0);
        $cardboxTotalPrice = (float) ($saleDocument->cardbox_total_price ?? 0);
        $taxRate = (float) ($saleDocument->tax ?? 0);
        $isTax = (int) ($saleDocument->is_tax ?? 0);

        $totalBeforeTax = max(0, $totalProductPriceSale - $voucherValue - $voucherRankValue + $cardboxTotalPrice);

        $priceAfterTax = $totalBeforeTax;
        if ($isTax === 1 && $taxRate > 0) {
            $priceAfterTax = $totalBeforeTax + ($totalBeforeTax * ($taxRate / 100));
        }

        return [
            'total_product_price_sale' => $totalProductPriceSale,
            'voucher_value' => $voucherValue,
            'voucher_rank_value' => $voucherRankValue,
            'cardbox_total_price' => $cardboxTotalPrice,
            'total_price_document_sale' => max(0, $totalProductPriceSale - $voucherValue - $voucherRankValue),
            'total_before_tax' => $totalBeforeTax,
            'price_after_tax' => ceil($priceAfterTax),
        ];
    }

    private function buildResponsePayload(SaleDocument $saleDocument, array $calculation): array
    {
        return [
            'sale_document' => [
                'id' => $saleDocument->id,
                'code_document_sale' => $saleDocument->code_document_sale,
                'voucher' => $saleDocument->voucher,
                'voucher_id' => $saleDocument->voucher_id,
                'voucher_rank_value' => $saleDocument->voucher_rank_value ?? 0,
                'total_price_document_sale' => $saleDocument->total_price_document_sale,
                'cardbox_total_price' => $saleDocument->cardbox_total_price ?? 0,
                'price_after_tax' => $saleDocument->price_after_tax,
                'grand_total' => $saleDocument->grand_total,
                'tax' => $saleDocument->tax,
                'is_tax' => $saleDocument->is_tax,
            ],
            'calculation' => $calculation,
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
                ->when($bodyId, fn ($query) => $query->whereKey($bodyId))
                ->when(!$bodyId && $bodyCode, fn ($query) => $query->where('code_document_sale', $bodyCode))
                ->first();
        }

        return null;
    }
}

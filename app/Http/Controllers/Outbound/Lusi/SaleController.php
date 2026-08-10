<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\SaleController as BaseSaleController;
use App\Http\Resources\ResponseResource;
use App\Models\Buyer;
use App\Models\LoyaltyRank;
use App\Models\Sale;
use App\Models\SaleDocument;
use App\Models\VoucherApproval;
use Carbon\Carbon;
class SaleController extends BaseSaleController
{
    public function index()
    {
        $userId = auth()->id();

        $allSales = Sale::where('status_sale', 'proses')
            ->where('user_id', $userId)
            ->get();
        $grossTotalSale = $allSales->sum('product_price_sale');

        $sale = Sale::where('status_sale', 'proses')
            ->where('user_id', $userId)
            ->latest()
            ->paginate(50);

        $saleDocument = SaleDocument::where('status_document_sale', 'proses')
            ->where('user_id', $userId)
            ->first();

        $pendingApproval = null;
        if ($saleDocument) {
            $pendingApproval = VoucherApproval::with([
                'voucher:id,name',
            ])
                ->where('sale_document_id', $saleDocument->id)
                ->where('requested_by', $userId)
                ->where('status', 'pending')
                ->latest('date_request')
                ->first();
        }

        $getBuyer = null;
        $currentTransaction = 0;
        $nextRank = null;
        $monthlyPoint = 0;
        $monthlyRank = 0;

        if ($saleDocument && $saleDocument->buyer_id_document_sale) {
            $getBuyer = Buyer::with(['buyerLoyalty.rank'])
                ->where('id', $saleDocument->buyer_id_document_sale)
                ->first();

            if ($getBuyer && $getBuyer->buyerLoyalty) {
                $currentTransaction = $getBuyer->buyerLoyalty->transaction_count ?? 0;

                if ($currentTransaction <= 1) {
                    $nextRank = LoyaltyRank::where('min_transactions', $currentTransaction)
                        ->first();
                } else {
                    $nextRank = LoyaltyRank::where('min_transactions', '>', $currentTransaction)
                        ->orderBy('min_transactions', 'asc')
                        ->first();
                }

                $monthlyPoint = SaleDocument::where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
                    ->where('status_document_sale', 'selesai')
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->sum('buyer_point_document_sale');

                $higherRankCount = SaleDocument::selectRaw('SUM(buyer_point_document_sale) as total_point')
                    ->where('status_document_sale', 'selesai')
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->groupBy('buyer_id_document_sale')
                    ->havingRaw('SUM(buyer_point_document_sale) > ?', [$monthlyPoint])
                    ->count();

                $monthlyRank = $higherRankCount + 1;
            }
        }

        $buyerAvail = $saleDocument?->buyer_id_document_sale
            ? Buyer::find($saleDocument->buyer_id_document_sale)
            : null;

        // ================================================================
        // KODE LAMA (di-disable, diganti versi baru di bawah)
        // Sebelumnya hanya mengambil min_transaction terendah dari seluruh
        // voucher buyer tanpa membedakan tipe voucher & tanpa status
        // enable/disable per voucher
        // ================================================================
        /*
        $minTransaction = $buyerAvail?->vouchers()->min('min_transaction');
        */

        // ================================================================
        // VERSI BARU - Ambil daftar voucher aktif milik buyer + status
        // enable/disable
        // - Voucher NOMINAL : enable jika gross total >= min_transaction
        // - Voucher BARANG  : enable jika gross total dalam range
        //                     nominal voucher ± min_transaction, selain itu disabled
        // ================================================================
        $voucherAvailable = [];
        $minTransaction = null;

        if ($buyerAvail) {
            $buyerVouchers = $buyerAvail->vouchers()
                ->wherePivot('status', true)
                ->select(
                    'vouchers.id',
                    'vouchers.code',
                    'vouchers.name',
                    'vouchers.voucher_type',
                    'vouchers.amount',
                    'vouchers.max_usage',
                    'vouchers.max_week',
                    'vouchers.start_date',
                    'vouchers.min_transaction'
                )
                ->get();

            foreach ($buyerVouchers as $bv) {
                $startDate = Carbon::parse($bv->start_date);
                $expiredDate = $startDate->copy()->addWeeks($bv->max_week);

                if (now()->gt($expiredDate)) {
                    continue;
                }

                $maxUsedCount = $bv->max_usage > 0
                    ? (int) floor($bv->amount / $bv->max_usage)
                    : 0;

                if (($bv->pivot->used ?? 0) >= $maxUsedCount) {
                    continue;
                }

                $voucherType = $bv->voucher_type ?? 'nominal';
                $isEnabled = false;
                $rangeMin = null;
                $rangeMax = null;

                if ($voucherType === 'barang') {
                    $rangeTolerance = 20000;
                    $rangeCenter = (float) ($bv->min_transaction ?? 0);

                    $rangeMin = $rangeCenter - $rangeTolerance;
                    $rangeMax = $rangeCenter + $rangeTolerance;
                    $isEnabled = $grossTotalSale >= $rangeMin && $grossTotalSale <= $rangeMax;
                } else {
                    $minTransVoucher = (float) ($bv->min_transaction ?? 0);
                    $isEnabled = $minTransVoucher <= 0 || $grossTotalSale >= $minTransVoucher;

                    if ($minTransaction === null || ($minTransVoucher > 0 && $minTransVoucher < $minTransaction)) {
                        $minTransaction = $minTransVoucher > 0 ? $minTransVoucher : $minTransaction;
                    }
                }

                $voucherAvailable[] = [
                    'id' => $bv->id,
                    'code' => $bv->code,
                    'name' => $bv->name,
                    'voucher_type' => $voucherType,
                    'amount' => $bv->amount,
                    'max_usage' => $bv->max_usage,
                    'min_transaction' => $bv->min_transaction,
                    'range_min' => $rangeMin,
                    'range_max' => $rangeMax,
                    'status' => $isEnabled ? 'active' : 'disabled',
                    'message' => $isEnabled
                        ? 'Voucher dapat digunakan'
                        : ($voucherType === 'barang'
                            ? 'Voucher barang hanya dapat digunakan saat nominal transaksi berada di antara Rp ' .
                                number_format($rangeMin, 0, ',', '.') . ' s/d Rp ' .
                                number_format($rangeMax, 0, ',', '.')
                            : 'Voucher belum memenuhi minimal transaksi'),
                ];
            }

            // Untuk keperluan kompatibilitas: min_transaction terendah dari voucher nominal aktif
            if ($minTransaction === null) {
                $minTransaction = $buyerAvail->vouchers()->min('min_transaction');
            }
        }

        $calculation = $this->calculateSaleDocumentTotals($saleDocument, $grossTotalSale);

        $data = [
            'buyer_id_document_sale' => $saleDocument?->buyer_id_document_sale ?? null,
            'code_document_sale' => $saleDocument?->code_document_sale ?? codeDocumentSale($userId),
            'buyer_address' => $saleDocument?->buyer_address_document_sale ?? '',
            'buyer_phone' => $saleDocument?->buyer_phone_document_sale ?? '',
            'sale_buyer_name' => $saleDocument?->buyer_name_document_sale ?? '',
            'sale_buyer_id' => $saleDocument?->buyer_id_document_sale ?? '',
            'total_sale' => $grossTotalSale,
            'gross_total_sale' => $grossTotalSale,
            'rank' => optional(optional($getBuyer?->buyerLoyalty)->rank)->rank ?? null,
            'next_rank' => $nextRank?->rank ?? null,
            'transaction_next' => $nextRank ? max(1, $nextRank->min_transactions - $currentTransaction) : 0,
            'percentage_discount' => optional(optional($getBuyer?->buyerLoyalty)->rank)->percentage_discount ?? 0,
            'current_transaction' => $currentTransaction,
            'monthly_point' => (int) $monthlyPoint,
            'monthly_rank_position' => $monthlyRank > 0 ? $monthlyRank : '-',
            'voucher' => $calculation['voucher_value'],
            'voucher_id' => $saleDocument?->voucher_id ?? null,
            'voucher_rank_available' => !empty($voucherAvailable) && $minTransaction !== null ? $grossTotalSale >= $minTransaction : false,
            'voucher_rank_value' => $calculation['voucher_rank_value'],
            'voucher_type' => $saleDocument?->voucher ? $this->getVoucherType($saleDocument) : null,
            'voucher_available' => $voucherAvailable,
            'total_price_document_sale' => $calculation['total_price_document_sale'],
            'cardbox_total_price' => $calculation['cardbox_total_price'],
            'price_after_tax' => $calculation['price_after_tax'],
            'grand_total' => $calculation['grand_total'],
            'need_voucher_approval' => (bool) $pendingApproval,
            'approval_voucher_name' => $pendingApproval?->voucher?->name ?? 'Voucher Manual',
            'min_transaction' => $minTransaction,
            'calculation' => $calculation,
        ];

        $data += $sale->toArray();

        return (new ResponseResource(true, 'list data sale', $data))->response();
    }

    public function show(Sale $sale)
    {
        return (new ResponseResource(true, 'data sale', $sale))->response();
    }

    private function getVoucherType(SaleDocument $saleDocument): ?string
    {
        $voucher = \App\Models\Voucher::find($saleDocument->voucher_id);

        return $voucher?->voucher_type ?? 'nominal';
    }

    private function calculateSaleDocumentTotals(?SaleDocument $saleDocument, float $grossTotalSale = 0): array
    {
        if (!$saleDocument) {
            return [
                'total_product_price_sale' => 0,
                'voucher_value' => 0,
                'voucher_rank_value' => 0,
                'cardbox_total_price' => 0,
                'total_price_document_sale' => 0,
                'grand_total' => 0,
                'price_after_tax' => 0,
            ];
        }

        $voucherValue = (float) ($saleDocument->voucher ?? 0);
        $voucherRankValue = (float) ($saleDocument->voucher_rank_value ?? 0);
        $cardboxTotalPrice = (float) ($saleDocument->cardbox_total_price ?? 0);
        $taxRate = (float) ($saleDocument->tax ?? 0);
        $isTax = (int) ($saleDocument->is_tax ?? 0);

        $totalPriceDocumentSale = max(0, $grossTotalSale - $voucherValue - $voucherRankValue);
        $grandTotal = $totalPriceDocumentSale + $cardboxTotalPrice;

        $priceAfterTax = $grandTotal;
        if ($isTax === 1 && $taxRate > 0) {
            $priceAfterTax = $grandTotal + ($grandTotal * ($taxRate / 100));
        }

        return [
            'total_product_price_sale' => $grossTotalSale,
            'voucher_value' => $voucherValue,
            'voucher_rank_value' => $voucherRankValue,
            'cardbox_total_price' => $cardboxTotalPrice,
            'total_price_document_sale' => $totalPriceDocumentSale,
            'grand_total' => $grandTotal,
            'price_after_tax' => ceil($priceAfterTax),
        ];
    }
}

<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\SaleController as BaseSaleController;
use App\Http\Resources\ResponseResource;
use App\Models\Buyer;
use App\Models\LoyaltyRank;
use App\Models\Sale;
use App\Models\SaleDocument;
use App\Models\VoucherApproval;

class SaleController extends BaseSaleController
{
    public function index()
    {
        $userId = auth()->id();

        $allSales = Sale::where('status_sale', 'proses')->where('user_id', $userId)->get();
        $totalSale = $allSales->sum('product_price_sale');
        $sale = Sale::where('status_sale', 'proses')->where('user_id', $userId)->latest()->paginate(50);

        $saleDocument = SaleDocument::where('status_document_sale', 'proses')->where('user_id', $userId)->first();

        $pendingApproval = null;

        if ($saleDocument) {
            $pendingApproval = VoucherApproval::with([
                'voucher:id,name'
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

        if ($saleDocument == null) {
            $codeDocumentSale = codeDocumentSale($userId);
            $saleBuyerName = '';
            $saleBuyerId = '';
            $addressBuyer = '';
            $buyerPhone = '';
            $buyerIdDocumentSale = null;
        } else {
            $codeDocumentSale = $saleDocument->code_document_sale;
            $saleBuyerName = $saleDocument->buyer_name_document_sale ?? '';
            $saleBuyerId = $saleDocument->buyer_id_document_sale ?? '';
            $addressBuyer = $saleDocument->buyer_address_document_sale ?? '';
            $buyerPhone = $saleDocument->buyer_phone_document_sale ?? '';
            $buyerIdDocumentSale = $saleDocument->buyer_id_document_sale;

            if ($saleDocument->buyer_id_document_sale) {
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
                        ->get()
                        ->count();

                    $monthlyRank = $higherRankCount + 1;
                }
            }
        }

        $buyerAvail = Buyer::find($buyerIdDocumentSale);

        $minTransaction = null;

        if ($buyerAvail) {
            $minTransaction = $buyerAvail->vouchers()->min('min_transaction');
        }

        $data = [
            'buyer_id_document_sale' => $buyerIdDocumentSale,
            'code_document_sale' => $codeDocumentSale,
            'buyer_address' => $addressBuyer,
            'buyer_phone' => $buyerPhone,
            'sale_buyer_name' => $saleBuyerName,
            'sale_buyer_id' => $saleBuyerId,
            'total_sale' => $totalSale,
            'rank' => optional(optional($getBuyer?->buyerLoyalty)->rank)->rank ?? null,
            'next_rank' => $nextRank?->rank ?? null,
            'transaction_next' => $nextRank ? max(1, $nextRank->min_transactions - $currentTransaction) : 0,
            'percentage_discount' => optional(optional($getBuyer?->buyerLoyalty)->rank)->percentage_discount ?? 0,
            'current_transaction' => $currentTransaction,
            'monthly_point' => (int) $monthlyPoint,
            'monthly_rank_position' => $monthlyRank > 0 ? $monthlyRank : '-',
            'voucher' => $saleDocument?->voucher ?? 0,
            'voucher_id' => $saleDocument?->voucher_id ?? null,
            'voucher_rank_available' => $totalSale >= $minTransaction ? true : false,
            'voucher_rank_value' => $saleDocument?->voucher_rank_value ?? 0,
            'total_price_document_sale' => $saleDocument?->total_price_document_sale ?? 0,
            'cardbox_total_price' => $saleDocument?->cardbox_total_price ?? 0,
            'price_after_tax' => $saleDocument?->price_after_tax ?? 0,
            'grand_total' => $saleDocument?->grand_total ?? 0,
            'need_voucher_approval' => (bool) $pendingApproval,
            'approval_voucher_name' => $pendingApproval?->voucher?->name,
            'min_transaction' => $minTransaction,
        ];

        $data += $sale->toArray();

        $resource = new ResponseResource(true, "list data sale", $data);
        return $resource->response();
    }
}

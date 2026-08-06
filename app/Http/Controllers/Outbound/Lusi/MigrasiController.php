<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Buyer;
use App\Models\CogsSupplier;
use App\Models\LoyaltyRank;
use App\Models\StagingProduct;
use Illuminate\Http\Request;

class MigrasiController extends Controller
{
    /**
     * List rank (class) tanpa auth, hanya data penting.
     */
    public function ranks(Request $request)
    {
        $q = $request->query('q');

        $ranks = LoyaltyRank::query()
            ->when($q, function ($query) use ($q) {
                $query->where('rank', 'LIKE', '%' . $q . '%');
            })
            ->select('id', 'rank', 'min_transactions', 'min_amount_transaction', 'percentage_discount', 'expired_weeks')
            ->get();

        return new ResponseResource(true, "List Rank (Class)", $ranks);
    }

    /**
     * List buyer tanpa auth, hanya data penting.
     */
    public function buyers(Request $request)
    {
        $q = $request->query('q');

        $buyers = Buyer::query()
            ->when($q, function ($query) use ($q) {
                $query->where(function ($subQuery) use ($q) {
                    $subQuery->where('name_buyer', 'LIKE', '%' . $q . '%')
                        ->orWhere('phone_buyer', 'LIKE', '%' . $q . '%')
                        ->orWhere('email', 'LIKE', '%' . $q . '%')
                        ->orWhere('type_buyer', 'LIKE', '%' . $q . '%');
                });
            })
            ->select('id', 'name_buyer', 'phone_buyer', 'address_buyer', 'type_buyer', 'point_buyer', 'email')
            ->with('buyerLoyalty.rank:id,rank')
            ->get();

        return new ResponseResource(true, "List Buyer", $buyers);
    }

    /**
     * List supplier tanpa auth, hanya data penting.
     */
    public function suppliers(Request $request)
    {
        $q = $request->query('q');

        $suppliers = CogsSupplier::query()
            ->when($q, function ($query) use ($q) {
                $query->where('name', 'LIKE', '%' . $q . '%');
            })
            ->select('id', 'name')
            ->get();

        return new ResponseResource(true, "List Supplier", $suppliers);
    }

    /**
     * List buyer voucher (voucher milik buyer) tanpa auth, hanya data penting.
     * Bisa difilter per buyer via parameter ?buyer_id=
     */
    public function buyerVouchers(Request $request)
    {
        $buyerId = $request->query('buyer_id');

        $buyers = Buyer::query()
            ->when($buyerId, function ($query) use ($buyerId) {
                $query->where('id', $buyerId);
            })
            ->select('id', 'name_buyer', 'phone_buyer', 'email')
            ->with(['vouchers' => function ($query) {
                $query->select('vouchers.id', 'vouchers.code', 'vouchers.name', 'vouchers.amount', 'vouchers.max_usage', 'vouchers.is_active', 'vouchers.start_date', 'vouchers.min_transaction')
                    ->withPivot(['used', 'status', 'start_date']);
            }])
            ->get();

        return new ResponseResource(true, "List Buyer Voucher", $buyers);
    }

    /**
     * List product staging tanpa auth, hanya data penting.
     * Bisa difilter via ?q= (barcode/name) dan ?status= (new_status_product).
     */
    public function stagingProducts(Request $request)
    {
        $q = $request->query('q');
        $status = $request->query('status');

        $products = StagingProduct::query()
            ->when($q, function ($query) use ($q) {
                $query->where(function ($subQuery) use ($q) {
                    $subQuery->where('new_barcode_product', 'LIKE', '%' . $q . '%')
                        ->orWhere('new_name_product', 'LIKE', '%' . $q . '%');
                });
            })
            ->when($status, function ($query) use ($status) {
                $query->where('new_status_product', $status);
            })
            ->with('rack:id,barcode,name')
            ->orderBy('created_at', 'desc')
            ->get();

        return new ResponseResource(true, "List Product Staging", $products);
    }
}

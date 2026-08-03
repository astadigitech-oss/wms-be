<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Voucher;
use Illuminate\Http\Request;

class PublicVoucherController extends Controller
{
    /**
     * List voucher tanpa auth, hanya data penting.
     */
    public function index(Request $request)
    {
        $q = $request->query('q');

        $vouchers = Voucher::query()
            ->when($q, function ($query) use ($q) {
                $query->where(function ($subQuery) use ($q) {
                    $subQuery->where('name', 'LIKE', '%' . $q . '%')
                        ->orWhere('code', 'LIKE', '%' . $q . '%');
                });
            })
            ->select('id', 'code', 'name', 'amount', 'max_usage', 'is_active', 'max_week', 'start_date', 'min_transaction')
            ->get();

        return new ResponseResource(true, "Data Voucher", $vouchers);
    }
}
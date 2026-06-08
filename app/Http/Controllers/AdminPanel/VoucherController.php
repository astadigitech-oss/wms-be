<?php

namespace App\Http\Controllers\AdminPanel;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Buyer;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class VoucherController extends Controller
{
    public function listVoucher(Request $request)
    {
        try {
            $q = $request->query('q');

            $data = Voucher::query()
                ->when($q, function ($query) use ($q) {
                    $query->where(function ($subQuery) use ($q) {
                        $subQuery->where('name', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%");
                    });
                })
                ->paginate(10);

            return new ResponseResource(
                true,
                'List Voucher',
                $data
            );
        } catch (\Throwable $e) {
            Log::error('Error list voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return new ResponseResource(
                false,
                'Gagal mengambil data voucher',
                null
            );
        }
    }

    public function buatVoucher(Request $request)
    {
        $validator = Validator($request->all(), [
            'name' => 'required|string',
            'amount' => 'required|numeric',
            'max_usage' => 'required|integer',
            'max_week' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return new ResponseResource(
                false,
                'Gagal membuat voucher',
                $validator->errors()
            );
        }

        DB::beginTransaction();

        try {
            $voucher = Voucher::create($validator->validated());

            DB::commit();

            return new ResponseResource(
                true,
                'Voucher berhasil dibuat',
                $voucher
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error membuat voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return new ResponseResource(
                false,
                'Gagal membuat voucher',
                null
            );
        }
    }

    public function updateVoucher(Request $request, $id)
    {
        $validator = Validator($request->all(), [
            'name' => 'required|string',
            'amount' => 'required|numeric',
            'max_usage' => 'required|integer',
            'is_active' => 'required|boolean',
            'max_week' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return new ResponseResource(
                false,
                'Gagal mengupdate voucher',
                $validator->errors()
            );
        }

        $voucher = Voucher::findOrFail($id);

        if (!$voucher) {
            return new ResponseResource(
                false,
                'Voucher tidak ditemukan',
                null
            );
        }

        // dd($voucher);

        DB::beginTransaction();

        try {
            $voucher->update($validator->validated());

            DB::commit();

            return new ResponseResource(
                true,
                'Voucher berhasil diupdate',
                $voucher
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error mengupdate voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return new ResponseResource(
                false,
                'Gagal membuat voucher',
                null
            );
        }
    }

    public function tambahBuyerKeVoucher(Request $request, $id)
    {
        // dd($request->all());
        $validator = Validator($request->all(), [
            'buyer_id' => 'required|integer|exists:buyers,id',
        ]);
        // $buyer = Buyer::find($request->buyer_id);
        // dd($buyer);
        if ($validator->fails()) {
            return new ResponseResource(
                false,
                'Gagal menambahkan buyer ke voucher',
                $validator->errors()
            );
        }

        try {
            $voucher = Voucher::findOrFail($id);

            if (!$voucher->is_active) {
                return new ResponseResource(
                    false,
                    'Voucher tidak aktif',
                    null
                );
            }

            $voucher->buyers()->attach(
                $validator->validated()['buyer_id']
            );

            return new ResponseResource(
                true,
                'Buyer berhasil ditambahkan ke voucher',
                null
            );
        } catch (\Throwable $e) {
            Log::error('Error menambahkan buyer ke voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return new ResponseResource(
                false,
                'Gagal menambahkan buyer ke voucher',
                null
            );
        }
    }

    public function detailVoucer($id)
    {
        try {
            $voucher = Voucher::findOrFail($id);

            return new ResponseResource(
                true,
                'Detail Voucher',
                $voucher
            );
        } catch (\Throwable $e) {
            Log::error('Error detail voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return new ResponseResource(
                false,
                'Gagal mengambil detail voucher',
                null
            );
        }
    }
}

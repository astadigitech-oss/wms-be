<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\AdminPanel\VoucherController as BaseVoucherController;
use App\Http\Resources\ResponseResource;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VoucherController extends BaseVoucherController
{
    // ================================================================
    // VERSI LUSI - buatVoucher mendukung voucher_type (nominal/barang)
    // ================================================================
    public function buatVoucher(Request $request)
    {
        $validator = \Validator($request->all(), [
            'name' => 'required|string',
            'voucher_type' => 'nullable|in:nominal,barang',
            'amount' => 'required|numeric',
            'max_usage' => 'required|integer',
            'max_week' => 'required|integer',
            'start_date' => 'nullable|date',
            'min_transaction' => 'nullable|numeric',
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
            $data = $validator->validated();
            $data['voucher_type'] = $data['voucher_type'] ?? 'nominal';

            $voucher = Voucher::create($data);

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

    // ================================================================
    // VERSI LUSI - updateVoucher mendukung voucher_type (nominal/barang)
    // ================================================================
    public function updateVoucher(Request $request, $id)
    {
        $validator = \Validator($request->all(), [
            'name' => 'required|string',
            'voucher_type' => 'nullable|in:nominal,barang',
            'amount' => 'required|numeric',
            'max_usage' => 'required|integer',
            'is_active' => 'required|boolean',
            'max_week' => 'required|integer',
            'min_transaction' => 'nullable|numeric',
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

        DB::beginTransaction();

        try {
            $data = $validator->validated();
            $data['voucher_type'] = $data['voucher_type'] ?? 'nominal';

            $voucher->update($data);

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
                'Gagal mengupdate voucher',
                null
            );
        }
    }
}

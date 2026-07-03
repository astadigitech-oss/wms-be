<?php

namespace App\Http\Controllers\Outbound;

use App\Exports\Outbound\NewBulkySaleExport;
use App\Exports\Outbound\NewSaleExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Buyer;
use App\Models\SaleDocument;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class NewSaleController extends Controller
{
    public function exportSales(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        try {
            // validasi input
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
            ]);

            $start = $request->start_date ?? 'all';
            $end = $request->end_date ?? 'all';

            $fileName = "sales-{$start}_to_{$end}-" . now()->format('YmdHis') . ".xlsx";
            $publicPath = 'exports';

            Excel::store(
                new NewSaleExport($request),
                $publicPath . '/' . $fileName,
                'public'
            );

            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(
                true,
                'File berhasil diunduh',
                $downloadUrl
            );
        } catch (\Exception $e) {
            return new ResponseResource(
                false,
                'Gagal export: ' . $e->getMessage(),
                []
            );
        }
    }

    public function exportBulkySale(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        try {
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
            ]);

            $start = $request->start_date ?? 'all';
            $end = $request->end_date ?? 'all';

            $fileName = "b2b-sales-{$start}_to_{$end}-" . now()->format('YmdHis') . ".xlsx";
            $publicPath = 'exports';

            Excel::store(
                new NewBulkySaleExport($request),
                $publicPath . '/' . $fileName,
                'public'
            );

            return new ResponseResource(
                true,
                'File berhasil diunduh',
                asset('storage/' . $publicPath . '/' . $fileName)
            );
        } catch (\Exception $e) {
            return new ResponseResource(
                false,
                'Gagal export: ' . $e->getMessage(),
                []
            );
        }
    }

    public function listVoucherBuyer(Request $request, $id)
    {
        try {
            $buyer = Buyer::findOrFail($id);

            $data = $buyer->vouchers()
                ->wherePivot('status', true)
                ->select(
                    'vouchers.id',
                    'vouchers.code',
                    'vouchers.name',
                    'vouchers.amount',
                    'vouchers.max_usage',
                    'vouchers.max_week',
                    'vouchers.start_date'
                )
                ->get()
                ->filter(function ($voucher) {

                    $startDate = Carbon::parse($voucher->start_date);

                    $expiredDate = $startDate->copy()->addWeeks($voucher->max_week);

                    $maxUsedCount = (int) floor(
                        $voucher->amount / $voucher->max_usage
                    );

                    return now()->lte($expiredDate)
                        && ($voucher->pivot->used ?? 0) < $maxUsedCount;
                })
                ->values()
                ->map(function ($voucher) {

                    $startDate = Carbon::parse($voucher->start_date);

                    $expiredDate = $startDate->copy()->addWeeks($voucher->max_week);

                    $sisaHari = now()->diffInDays($expiredDate, false);

                    $used = $voucher->pivot->used ?? 0;

                    $maxUsedCount = (int) floor(
                        $voucher->amount / $voucher->max_usage
                    );

                    return [
                        'id' => $voucher->id,
                        'code' => $voucher->code,
                        'name' => $voucher->name,
                        'amount' => $voucher->amount,
                        'max_usage' => $voucher->max_usage,

                        // jumlah pemakaian saat ini
                        'used' => $used,

                        // maksimal boleh dipakai
                        'max_used_count' => $maxUsedCount,

                        // sisa penggunaan
                        'remaining_usage' => max(
                            0,
                            $maxUsedCount - $used
                        ),

                        'tanggal_dapat_voucher' => $startDate->translatedFormat('d M Y'),
                        'tanggal_expired' => $expiredDate->translatedFormat('d M Y'),
                        'sisa_hari' => max(0, $sisaHari),
                        'status' => 'active',
                    ];
                });

            return new ResponseResource(
                true,
                'List Voucher',
                $data
            );
        } catch (\Exception $e) {
            return new ResponseResource(
                false,
                $e->getMessage(),
                null
            );
        }
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
                    'vouchers.start_date'
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

            $expiredDate = $startDate
                ->copy()
                ->addWeeks($voucher->max_week);

            if (now()->gt($expiredDate)) {
                DB::rollBack();

                return new ResponseResource(
                    false,
                    'Voucher sudah expired',
                    null
                );
            }

            $maxUsedCount = $voucher->max_usage > 0
                ? (int) floor(
                    $voucher->amount / $voucher->max_usage
                )
                : 0;

            if (($voucher->pivot->used ?? 0) >= $maxUsedCount) {
                DB::rollBack();

                return new ResponseResource(
                    false,
                    'Kuota penggunaan voucher sudah habis',
                    null
                );
            }

            $saleDocument->update([
                'voucher_id' => $voucher->id,
                'voucher_rank_value' => $voucher->max_usage,
            ]);

            $currentUsed = $voucher->pivot->used ?? 0;

            $buyer->vouchers()->updateExistingPivot(
                $voucher->id,
                [
                    'used' => $currentUsed + 1,
                ]
            );

            DB::commit();

            return new ResponseResource(
                true,
                'Voucher berhasil digunakan',
                null
            );
        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Error menggunakan voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return new ResponseResource(
                false,
                'Gagal menggunakan voucher',
                $e->getMessage()
            );
        }
    }

    public function lepasVoucher()
    {
        DB::beginTransaction();

        try {

            $userId = auth()->id();

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

            if (!$saleDocument->voucher_id) {
                DB::rollBack();

                return new ResponseResource(
                    false,
                    'Tidak ada voucher yang digunakan',
                    null
                );
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

            DB::commit();

            return new ResponseResource(
                true,
                'Voucher berhasil dihapus',
                null
            );
        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Error menghapus voucher', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return new ResponseResource(
                false,
                'Gagal menghapus voucher',
                $e->getMessage()
            );
        }
    }
}

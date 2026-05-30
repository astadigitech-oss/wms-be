<?php

namespace App\Http\Controllers;

use App\Exports\MovementLastStateExport;
use App\Models\DailySaldoSnapshot;
use App\Services\MovementService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class MovementProductController extends Controller
{
    /**
     * GET /api/movement/last-state
     *
     * Query params:
     *   - lokasi  (required) — filter berdasarkan posisi akhir produk (kolom `to`)
     *   - tanggal (optional) — format Y-m-d, default hari ini
     *
     * Mengembalikan file Excel berisi semua produk yang last state-nya
     * berada di lokasi tersebut pada tanggal yang diminta.
     */
    public function lastStateAll(Request $request)
    {
        if (!$request->filled('lokasi')) {
            return response()->json([
                'code'    => 422,
                'success' => false,
                'message' => 'Parameter lokasi wajib diisi.',
                'errors'  => ['lokasi' => ['Parameter lokasi wajib diisi.']],
            ], 422);
        }

        $request->validate([
            'lokasi'  => 'required|string',
            'tanggal' => 'nullable|date_format:Y-m-d',
        ]);

        $lokasi   = $request->query('lokasi');
        $tanggal  = $request->query('tanggal') ?? now()->toDateString();
        $filename = 'movement_' . $lokasi . '_' . $tanggal . '.xlsx';

        return Excel::download(new MovementLastStateExport($lokasi, $tanggal), $filename);
    }

    /**
     * GET /api/movement/{productId}/last-state
     *
     * Query param: as_of (optional) — datetime Y-m-d H:i:s atau Y-m-d
     * Mengembalikan posisi terakhir barang pada waktu tertentu.
     */
    public function lastState(Request $request, string $productId)
    {
        $request->validate([
            'as_of' => 'nullable|date',
        ]);

        $asOf = $request->query('as_of');

        $state = MovementService::getLastState($productId, $asOf);

        if (!$state) {
            return response()->json([
                'code'    => 404,
                'success' => false,
                'message' => 'Tidak ada data movement untuk produk ini.',
                'errors'  => [],
            ], 404);
        }

        return response()->json([
            'code'    => 200,
            'success' => true,
            'message' => 'Data berhasil diambil.',
            'data'    => $state,
            'meta'    => [
                'product_id' => $productId,
                'as_of'      => $asOf ?? now()->toDateTimeString(),
            ],
        ]);
    }

    /**
     * GET /api/movement/saldo
     * GET /api/movement/saldo?date=Y-m-d
     *
     * Tanpa date  → real-time dari source tables (saldo akhir hari ini)
     * Dengan date → dari daily_inventory_snapshots (saldo awal / historical)
     */
    public function saldo(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $date = $request->query('date');

        if ($date) {
            $snapshot = DailySaldoSnapshot::where('snapshot_date', $date)->first();

            if (!$snapshot) {
                return response()->json([
                    'code'    => 404,
                    'success' => false,
                    'message' => 'Tidak ada snapshot untuk tanggal tersebut.',
                    'errors'  => [],
                ], 404);
            }

            $breakdown = $snapshot->breakdown ?? [];
            $totalPriceBefore = collect($breakdown)->whereNotNull('total_price_before')->sum('total_price_before');

            return response()->json([
                'code'    => 200,
                'success' => true,
                'message' => 'Data saldo berhasil diambil.',
                'data'    => [
                    'total_qty'          => (int) $snapshot->total_qty,
                    'total_price'        => (int) $snapshot->total_price,
                    'total_price_before' => (int) $totalPriceBefore,
                    'breakdown'          => $breakdown,
                ],
                'meta'    => [
                    'as_of'  => $snapshot->snapshot_date,
                    'source' => 'snapshot',
                ],
            ]);
        }

        $result = MovementService::getSaldo();

        return response()->json([
            'code'    => 200,
            'success' => true,
            'message' => 'Data saldo berhasil dihitung.',
            'data'    => $result['summary'],
            'meta'    => [
                'as_of'  => $result['as_of'],
                'source' => 'realtime',
            ],
        ]);
    }

    /**
     * GET /api/movement/display-color/saldo
     *
     * Mengembalikan saldo_awal (snapshot kemarin) dan saldo_realtime untuk display_color (tag/sticker).
     */
    public function displayColorSaldo()
    {
        $result = MovementService::getDisplayColorSaldo();

        return response()->json([
            'code'    => 200,
            'success' => true,
            'message' => 'Data saldo display color berhasil diambil.',
            'data'    => [
                'saldo_awal'     => $result['saldo_awal'],
                'saldo_realtime' => $result['saldo_realtime'],
            ],
            'meta'    => [
                'as_of' => $result['as_of'],
            ],
        ]);
    }

    /**
     * GET /api/movement/display/saldo
     *
     * Mengembalikan saldo_awal (snapshot kemarin) dan saldo_realtime untuk display (reguler + color).
     */
    public function displaySaldo()
    {
        $result = MovementService::getDisplaySaldo();

        return response()->json([
            'code'    => 200,
            'success' => true,
            'message' => 'Data saldo display berhasil diambil.',
            'data'    => [
                'saldo_awal'     => $result['saldo_awal'],
                'saldo_realtime' => $result['saldo_realtime'],
            ],
            'meta'    => [
                'as_of' => $result['as_of'],
            ],
        ]);
    }

    /**
     * GET /api/movement/staging/saldo
     *
     * Mengembalikan saldo_awal (snapshot kemarin) dan saldo_realtime untuk staging_reguler.
     */
    public function stagingSaldo()
    {
        $result = MovementService::getStagingSaldo();

        return response()->json([
            'code'    => 200,
            'success' => true,
            'message' => 'Data saldo staging berhasil diambil.',
            'data'    => [
                'saldo_awal'     => $result['saldo_awal'],
                'saldo_realtime' => $result['saldo_realtime'],
            ],
            'meta'    => [
                'as_of' => $result['as_of'],
            ],
        ]);
    }
}

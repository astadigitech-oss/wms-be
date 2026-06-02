<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdjustMovementService
{
    /**
     * Lokasi yang dianggap "aktif" (masih di dalam inventory / masuk saldo).
     *
     * Exclude:
     * - pending       → belum masuk inventory
     * - cargo         → setara palet, dikemas = keluar inventory
     * - repair        → sementara keluar, tidak dihitung saldo
     * - reguler_sales → sudah terjual
     * - scrap_qcd     → sudah di-scrap
     */
    const ACTIVE_LOCATIONS = [
        'staging_reguler',
        'display_reguler',
        'display_color',
        'staging_sku',
        'bundle',
    ];

    /**
     * Log a single product movement.
     */
    public static function log(
        string $productId,
        bool $isSku,
        string $type,
        ?string $typeOut,
        string $from,
        string $to,
        ?int $qty
    ): void {
        $now = now()->toDateTimeString();

        DB::table('movement_products')->insert([
            'id'         => Str::uuid()->toString(),
            'product_id' => $productId,
            'is_sku'     => $isSku ? 1 : 0,
            'type'       => $type,
            'type_out'   => $typeOut,
            'from'       => $from,
            'to'         => $to,
            'qty'        => $qty,
            'dateTime'   => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Log multiple product movements in one bulk insert.
     *
     * Each row must have: product_id, is_sku, type, from, to
     * Optional: type_out, qty
     */
    public static function logBulk(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $now = now()->toDateTimeString();
        $data = array_map(fn($r) => [
            'id'         => Str::uuid()->toString(),
            'product_id' => $r['product_id'],
            'is_sku'     => ($r['is_sku'] ?? false) ? 1 : 0,
            'type'       => $r['type'],
            'type_out'   => $r['type_out'] ?? null,
            'from'       => $r['from'],
            'to'         => $r['to'],
            'qty'        => $r['qty'] ?? null,
            'dateTime'   => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        // Chunk to avoid exceeding MySQL max_allowed_packet on very large sets
        foreach (array_chunk($data, 500) as $chunk) {
            DB::table('movement_products')->insert($chunk);
        }
    }

    /**
     * Semua lokasi yang valid untuk filter movement.
     */
    const ALL_LOCATIONS = [
        'pending',
        'staging_reguler',
        'display_reguler',
        'display_color',
        'staging_sku',
        'bundle',
        'cargo',
        'repair',
        'reguler_sales',
        'scrap',
        'qcd',
        'scrap_qcd',
        'transfer',
    ];

    /**
     * Get the last known state of a product at or before a given point in time.
     *
     * @param  string       $productId  The product_id to look up.
     * @param  string|null  $asOf       Datetime string (Y-m-d H:i:s). Defaults to now.
     * @return object|null  Last movement row, or null if no movement found.
     */
    public static function getLastState(string $productId, ?string $asOf = null): ?object
    {
        $asOf = $asOf ?? now()->toDateTimeString();

        return DB::table('movement_products')
            ->where('product_id', $productId)
            ->where('dateTime', '<=', $asOf)
            ->orderByDesc('dateTime')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Get the last known state of ALL products whose final position is at a given location,
     * evaluated at the end of the given date.
     *
     * @param  string       $lokasi   Location to filter (kolom `to`).
     * @param  string|null  $tanggal  Date string Y-m-d. Defaults to today.
     * @return \Illuminate\Support\Collection  Collection of last movement rows.
     */
    public static function getLastStateAll(string $lokasi, ?string $tanggal = null): \Illuminate\Support\Collection
    {
        $tanggal = $tanggal ?? now()->toDateString();

        // Subquery: per product_id, ambil dateTime terbesar pada tanggal tersebut
        $sub = DB::table('movement_products')
            ->select('product_id', DB::raw('MAX(dateTime) as max_dt'))
            ->whereDate('dateTime', $tanggal)
            ->groupBy('product_id');

        // Join ke subquery, lalu filter yang posisi akhirnya (to) = lokasi
        return DB::table('movement_products as mp')
            ->joinSub($sub, 'latest', function ($join) {
                $join->on('mp.product_id', '=', 'latest.product_id')
                    ->on('mp.dateTime', '=', 'latest.max_dt');
            })
            ->where('mp.to', $lokasi)
            ->select('mp.*')
            ->orderByDesc('mp.dateTime')
            ->orderByDesc('mp.created_at')
            ->get();
    }

    /**
     * Same as getLastStateAll but returns query builder (tidak dieksekusi).
     * Digunakan untuk export agar bisa di-chunk oleh Laravel Excel.
     *
     * @param  string       $lokasi
     * @param  string|null  $tanggal  Date string Y-m-d. Defaults to today.
     * @return \Illuminate\Database\Query\Builder
     */
    public static function getLastStateQuery(string $lokasi, ?string $tanggal = null): \Illuminate\Database\Query\Builder
    {
        $tanggal = $tanggal ?? now()->toDateString();

        $sub = DB::table('movement_products')
            ->select('product_id', DB::raw('MAX(dateTime) as max_dt'))
            ->whereDate('dateTime', $tanggal)
            ->groupBy('product_id');

        return DB::table('movement_products as mp')
            ->joinSub($sub, 'latest', function ($join) {
                $join->on('mp.product_id', '=', 'latest.product_id')
                    ->on('mp.dateTime', '=', 'latest.max_dt');
            })
            ->leftJoin('staging_products as sp', 'mp.product_id', '=', 'sp.new_barcode_product')
            ->leftJoin('new_products as np', 'mp.product_id', '=', 'np.new_barcode_product')
            ->leftJoin('product__bundles as pb', 'mp.product_id', '=', 'pb.new_barcode_product')
            ->leftJoin('sku_products as skup', 'mp.product_id', '=', 'skup.barcode_product')
            ->where('mp.to', $lokasi)
            ->selectRaw('
                mp.*,
                COALESCE(sp.new_name_product, np.new_name_product, pb.new_name_product, skup.name_product) as nama_produk,
                COALESCE(sp.new_price_product, np.new_price_product, pb.new_price_product) as price_after,
                COALESCE(sp.old_price_product, np.old_price_product, pb.old_price_product, skup.price_product) as price_before
            ')
            ->orderByDesc('mp.dateTime')
            ->orderByDesc('mp.created_at');
    }

    /**
     * Hitung saldo inventory saat ini: total produk + total harga per lokasi.
     *
     * Query langsung ke tabel sumber (staging_products, new_products, dll)
     * dengan filter yang sama seperti summaryEndingBalance — konsisten dan proven.
     *
     * @return array{as_of: string, summary: array{total_qty: int, total_price: int, breakdown: array}}
     */
    public static function getSaldo(): array
    {
        $sources = [
            [
                'location'     => 'staging_reguler',
                'table'        => 'staging_products',
                'price'        => 'new_price_product',
                'price_before' => 'old_price_product',
                'filter'       => fn($q) => $q->whereNull('new_tag_product')
                    ->whereIn('new_status_product', ['display', 'expired', 'slow_moving'])
                    ->where('quality_lolos', 'lolos'),
            ],
            [
                'location'     => 'display_reguler',
                'table'        => 'new_products',
                'price'        => 'new_price_product',
                'price_before' => 'old_price_product',
                'filter'       => fn($q) => $q->whereNotNull('new_category_product')
                    ->whereNull('new_tag_product')
                    ->whereIn('new_status_product', ['display', 'expired', 'slow_moving'])
                    ->where('quality_lolos', 'lolos'),
            ],
            [
                'location'     => 'display_color',
                'table'        => 'new_products',
                'price'        => 'new_price_product',
                'price_before' => 'old_price_product',
                'filter'       => fn($q) => $q->whereNull('new_category_product')
                    ->whereNotNull('new_tag_product')
                    ->where('new_status_product', 'display')
                    ->where('quality_lolos', 'lolos'),
            ],
            [
                'location'     => 'staging_sku',
                'table'        => 'sku_products',
                'qty_col'      => 'quantity_product',
                'price'        => null,
                'price_before' => 'price_product',
                'filter'       => fn($q) => $q->where('quantity_product', '>', 0),
            ],
            [
                'location'     => 'bundle',
                'table'        => 'product__bundles',
                'price'        => 'new_price_product',
                'price_before' => 'old_price_product',
                'filter'       => fn($q) => $q->where('new_status_product', 'bundle'),
            ],
        ];

        $breakdown = collect($sources)->map(function ($src) {
            $query = DB::table($src['table']);

            if ($src['filter']) {
                $query = ($src['filter'])($query);
            }

            $qtyExpr = isset($src['qty_col']) ? "SUM({$src['qty_col']})" : 'COUNT(*)';
            $parts   = ["{$qtyExpr} as qty"];

            if ($src['price']) {
                $parts[] = "SUM({$src['price']}) as total_price";
            }
            if ($src['price_before']) {
                $parts[] = "SUM({$src['price_before']}) as total_price_before";
            }

            $result = $query->selectRaw(implode(', ', $parts))->first();

            return [
                'location'           => $src['location'],
                'qty'                => (int) ($result->qty ?? 0),
                'total_price'        => $src['price'] ? (int) ($result->total_price ?? 0) : null,
                'total_price_before' => $src['price_before'] ? (int) ($result->total_price_before ?? 0) : null,
            ];
        })->filter(fn($row) => $row['qty'] > 0)->values();

        return [
            'as_of'   => now()->toDateTimeString(),
            'summary' => [
                'total_qty'          => $breakdown->sum('qty'),
                'total_price'        => $breakdown->sum('total_price'),
                'total_price_before' => $breakdown->whereNotNull('total_price_before')->sum('total_price_before'),
                'breakdown'          => $breakdown,
            ],
        ];
    }

    /**
     * Hitung saldo display_color (tag/sticker): saldo_awal (snapshot kemarin) + saldo_realtime.
     *
     * @return array
     */
    public static function getDisplayColorSaldo(): array
    {
        // =========================
        // NEW PRODUCTS (DISPLAY COLOR)
        // =========================
        $displayColor = DB::table('new_products')
            ->whereNull('new_category_product')
            ->whereNotNull('new_tag_product')
            ->whereNull('is_so')
            ->where('is_pending', false)
            ->where(function ($query) {
                $query->whereRaw("
                JSON_UNQUOTE(
                    JSON_EXTRACT(new_quality, '$.lolos')
                ) = 'lolos'
            ")
                    ->orWhereRaw("
                JSON_UNQUOTE(
                    JSON_EXTRACT(
                        JSON_UNQUOTE(new_quality),
                        '$.lolos'
                    )
                ) = 'lolos'
            ");
            })
            ->whereIn('new_status_product', [
                'display',
                'expired',
                'slow_moving'
            ])
            ->where(function ($type) {
                $type->whereNull('type')
                    ->orWhere('type', 'type1');
            })
            ->selectRaw("
            COUNT(*) as qty,
            SUM(new_price_product) as total_price,
            SUM(old_price_product) as total_price_before
        ")
            ->first();

        // =========================
        // BUNDLES (DISPLAY COLOR)
        // =========================
        $bundle = DB::table('bundles')
            ->whereNotNull('name_color')
            ->whereNull('category')
            ->whereIn('product_status', ['not sale'])
            ->where(function ($type) {
                $type->whereNull('type')
                    ->orWhere('type', 'type1')
                    ->orWhere('type', 'type2');
            })
            ->selectRaw("
            COUNT(*) as qty,
            SUM(total_price_custom_bundle) as total_price,
            SUM(total_price_bundle) as total_price_before
        ")
            ->first();

        // =========================
        // TOTAL REALTIME
        // =========================
        $realtimeQty =
            (int) ($displayColor->qty ?? 0) +
            (int) ($bundle->qty ?? 0);

        $realtimeTotalPrice =
            (float) ($displayColor->total_price ?? 0) +
            (float) ($bundle->total_price ?? 0);

        $realtimeTotalPriceBefore =
            (float) ($displayColor->total_price_before ?? 0) +
            (float) ($bundle->total_price_before ?? 0);

        // =========================
        // SNAPSHOT KEMARIN
        // =========================
        $yesterday = now()->subDay()->toDateString();

        $snapshot = DB::table('daily_saldo_snapshots')
            ->where('snapshot_date', $yesterday)
            ->first();

        $awal = null;

        if ($snapshot && !empty($snapshot->breakdown)) {

            $breakdown = is_string($snapshot->breakdown)
                ? json_decode($snapshot->breakdown, true)
                : (array) $snapshot->breakdown;

            $displayColorRow = collect($breakdown)
                ->firstWhere('location', 'display_color');

            if ($displayColorRow) {

                $awal = [
                    'qty' => (int) ($displayColorRow['qty'] ?? 0),

                    'total_price' =>
                    (float) ($displayColorRow['total_price'] ?? 0),

                    'total_price_before' =>
                    isset($displayColorRow['total_price_before'])
                        ? (float) $displayColorRow['total_price_before']
                        : null,

                    'snapshot_date' => $yesterday,
                ];
            }
        }

        return [
            'as_of' => now()->toDateTimeString(),

            'saldo_awal' => $awal,

            'saldo_realtime' => [
                'qty' => $realtimeQty,

                'total_price' => $realtimeTotalPrice,

                'total_price_before' => $realtimeTotalPriceBefore,
            ],
        ];
    }


    /**
     * Hitung saldo display (display_reguler + display_color): saldo_awal (snapshot kemarin) + saldo_realtime.
     *
     * @return array
     */

    public static function getDisplaySaldo(): array
    {
        // =========================
        // NEW PRODUCTS (DISPLAY)
        // =========================
        $display = DB::table('new_products')
            ->whereNotNull('new_category_product')
            ->whereNull('new_tag_product')
            ->where('is_pending', false)
            ->where(function ($query) {
                $query->whereRaw("
                JSON_UNQUOTE(
                    JSON_EXTRACT(new_quality, '$.lolos')
                ) = 'lolos'
            ")
                    ->orWhereRaw("
                JSON_UNQUOTE(
                    JSON_EXTRACT(
                        JSON_UNQUOTE(new_quality),
                        '$.lolos'
                    )
                ) = 'lolos'
            ");
            })
            ->where(function ($status) {
                $status->where('new_status_product', 'display')
                    ->orWhere('new_status_product', 'expired')
                    ->orWhere('new_status_product', 'slow_moving');
            })
            ->where(function ($type) {
                $type->whereNull('type')
                    ->orWhere('type', 'type1')
                    ->orWhere('type', 'type2');
            })
            ->selectRaw("
            COUNT(*) as qty,
            SUM(new_price_product) as total_price,
            SUM(old_price_product) as total_price_before
        ")
            ->first();

        // =========================
        // BUNDLES (DISPLAY)
        // =========================
        $bundle = DB::table('bundles')
            ->whereNotNull('category')
            ->where('source', 'display')
            ->whereNull('name_color')
            ->where('product_status', 'not sale')
            ->where(function ($type) {
                $type->whereNull('type')
                    ->orWhere('type', 'type1')
                    ->orWhere('type', 'type2');
            })
            ->selectRaw("
            COUNT(*) as qty,
            SUM(total_price_custom_bundle) as total_price,
            SUM(total_price_bundle) as total_price_before
        ")
            ->first();

        // =========================
        // TOTAL REALTIME
        // =========================
        $realtimeQty =
            (int) ($display->qty ?? 0) +
            (int) ($bundle->qty ?? 0);

        $realtimeTotalPrice =
            (float) ($display->total_price ?? 0) +
            (float) ($bundle->total_price ?? 0);

        $realtimeTotalPriceBefore =
            (float) ($display->total_price_before ?? 0) +
            (float) ($bundle->total_price_before ?? 0);

        // =========================
        // SNAPSHOT KEMARIN
        // =========================
        $yesterday = now()->subDay()->toDateString();

        $snapshot = DB::table('daily_saldo_snapshots')
            ->where('snapshot_date', $yesterday)
            ->first();

        $awal = null;

        if ($snapshot && !empty($snapshot->breakdown)) {

            $breakdown = is_string($snapshot->breakdown)
                ? json_decode($snapshot->breakdown, true)
                : (array) $snapshot->breakdown;

            $displayRow = collect($breakdown)
                ->firstWhere('location', 'display_reguler');

            if ($displayRow) {

                $awal = [
                    'qty' => (int) ($displayRow['qty'] ?? 0),

                    'total_price' =>
                    (float) ($displayRow['total_price'] ?? 0),

                    'total_price_before' =>
                    isset($displayRow['total_price_before'])
                        ? (float) $displayRow['total_price_before']
                        : null,

                    'snapshot_date' => $yesterday,
                ];
            }
        }

        return [
            'as_of' => now()->toDateTimeString(),

            'saldo_awal' => $awal,

            'saldo_realtime' => [
                'qty' => $realtimeQty,

                'total_price' => $realtimeTotalPrice,

                'total_price_before' => $realtimeTotalPriceBefore,
            ],
        ];
    }

    /**
     * Hitung saldo staging_reguler: saldo_awal (snapshot kemarin) + saldo_realtime.
     *
     * @return array
     */
    public static function getStagingSaldo(): array
    {
        // =========================
        // STAGING PRODUCTS
        // =========================
        $staging = DB::table('staging_products')
            ->whereNotIn('new_status_product', [
                'dump',
                'sale',
                'migrate',
                'repair',
                'scrap_qcd'
            ])
            ->where(function ($query) {
                $query->whereRaw("
                JSON_UNQUOTE(
                    JSON_EXTRACT(new_quality, '$.lolos')
                ) = 'lolos'
            ")
                    ->orWhereRaw("
                JSON_UNQUOTE(
                    JSON_EXTRACT(
                        JSON_UNQUOTE(new_quality),
                        '$.lolos'
                    )
                ) = 'lolos'
            ");
            })
            ->whereNull('new_tag_product')
            ->whereNull('stage')
            ->where('is_pending', false)
            ->whereNotNull('new_category_product')
            ->where('new_category_product', '!=', '')
            ->selectRaw("
            COUNT(*) as qty,
            SUM(new_price_product) as total_price,
            SUM(old_price_product) as total_price_before
        ")
            ->first();

        // =========================
        // BUNDLES
        // =========================
        $bundle = DB::table('bundles')
            ->whereNotNull('category')
            ->where('source', 'staging')
            ->whereNull('name_color')
            ->where('product_status', 'not sale')
            ->where(function ($type) {
                $type->whereNull('type')
                    ->orWhere('type', 'type1')
                    ->orWhere('type', 'type2');
            })
            ->selectRaw("
            COUNT(*) as qty,
            SUM(total_price_custom_bundle) as total_price,
            SUM(total_price_bundle) as total_price_before
        ")
            ->first();

        // =========================
        // TOTAL REALTIME
        // =========================
        $realtimeQty =
            (int) ($staging->qty ?? 0) +
            (int) ($bundle->qty ?? 0);

        $realtimeTotalPrice =
            (float) ($staging->total_price ?? 0) +
            (float) ($bundle->total_price ?? 0);

        $realtimeTotalPriceBefore =
            (float) ($staging->total_price_before ?? 0) +
            (float) ($bundle->total_price_before ?? 0);

        // =========================
        // SNAPSHOT KEMARIN
        // =========================
        $yesterday = now()->subDay()->toDateString();

        $snapshot = DB::table('daily_saldo_snapshots')
            ->where('snapshot_date', $yesterday)
            ->first();

        $awal = null;

        if ($snapshot && !empty($snapshot->breakdown)) {

            $breakdown = is_string($snapshot->breakdown)
                ? json_decode($snapshot->breakdown, true)
                : (array) $snapshot->breakdown;

            $stagingRow = collect($breakdown)
                ->firstWhere('location', 'staging_reguler');

            if ($stagingRow) {

                $awal = [
                    'qty' => (int) ($stagingRow['qty'] ?? 0),

                    'total_price' =>
                    (float) ($stagingRow['total_price'] ?? 0),

                    'total_price_before' =>
                    isset($stagingRow['total_price_before'])
                        ? (float) $stagingRow['total_price_before']
                        : null,

                    'snapshot_date' => $yesterday,
                ];
            }
        }

        return [
            'as_of' => now()->toDateTimeString(),

            'saldo_awal' => $awal,

            'saldo_realtime' => [
                'qty' => $realtimeQty,

                'total_price' => $realtimeTotalPrice,

                'total_price_before' => $realtimeTotalPriceBefore,
            ],
        ];
    }
}

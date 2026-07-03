<?php

namespace App\Console\Commands;

use App\Models\BulkySale;
use App\Models\New_product;
use App\Models\Product_old;
use App\Models\RepairProduct;
use App\Models\SkuProduct;
use App\Models\StagingProduct;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InsertInitialMovement extends Command
{
    protected $signature = 'movement:insertInitial
                            {--date= : Override tanggal untuk semua produk (Y-m-d). Default: pakai tanggal masing-masing produk}
                            {--force : Hapus semua data movement lama dan insert ulang}';

    protected $description = 'Insert initial movement records sebagai baseline state awal semua produk';

    private const CHUNK_SIZE = 500;

    public function handle(): int
    {
        // Kalau --date diisi, semua produk pakai tanggal itu. Kalau tidak, pakai tanggal masing-masing produk.
        $override = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()->toDateTimeString()
            : null;

        // Guard: cek apakah movement_products sudah berisi data
        $existingCount = DB::table('movement_products')->count();
        if ($existingCount > 0 && !$this->option('force')) {
            $this->warn("movement_products sudah berisi {$existingCount} record.");
            $this->warn("Gunakan --force untuk menghapus semua dan insert ulang.");
            return Command::FAILURE;
        }

        if ($this->option('force') && $existingCount > 0) {
            $this->warn("--force: menghapus {$existingCount} record lama...");
            DB::table('movement_products')->truncate();
        }

        $this->info("=== Insert Initial Movement ===");
        $this->info("mode     : " . ($override ? "override ({$override})" : "per-product date"));
        $this->newLine();

        $summary = [];

        $this->line("[1/7] pending (product_olds)...");
        $summary[] = ['pending', $this->insertPending($override)];

        $this->line("[2/7] staging_reguler (staging_products)...");
        $summary[] = ['staging_reguler', $this->insertStagingReguler($override)];

        $this->line("[3/7] display_color (new_products | tag != null)...");
        $summary[] = ['display_color', $this->insertDisplayColor($override)];

        $this->line("[4/7] staging_sku (sku_products)...");
        $summary[] = ['staging_sku', $this->insertStagingSku($override)];

        $this->line("[5/7] display_reguler (new_products | category != null)...");
        $summary[] = ['display_reguler', $this->insertDisplayReguler($override)];

        $this->line("[6/7] cargo (bulky_sales | status_bulky = proses)...");
        $summary[] = ['cargo', $this->insertCargo($override)];

        $this->line("[7/7] repair (migrate_bulky_products | status = repair)...");
        $summary[] = ['repair', $this->insertRepair($override)];

        $total = array_sum(array_column($summary, 1));

        $this->newLine();
        $this->info("=== Summary ===");
        $this->table(
            ['Lokasi', 'Jumlah Record'],
            array_map(fn($row) => [$row[0], number_format($row[1])], $summary)
        );
        $this->newLine();
        $this->line("Total records inserted : " . number_format($total));
        $this->info("Initial movement berhasil diinsert.");

        return Command::SUCCESS;
    }

    // --- [1] pending ---
    private function insertPending(?string $override): int
    {
        $count = 0;
        Product_old::select('old_barcode_product', 'old_quantity_product', 'created_at')
            ->chunk(self::CHUNK_SIZE, function ($items) use ($override, &$count) {
                $rows = $items->map(fn($item) => $this->makeRow(
                    $item->old_barcode_product,
                    false,
                    'In',
                    null,
                    'inbound',
                    'pending',
                    $item->old_quantity_product,
                    $override ?? Carbon::parse($item->created_at)->toDateTimeString()
                ))->toArray();
                DB::table('movement_products')->insert($rows);
                $count += count($rows);
            });
        return $count;
    }

    // --- [2] staging_reguler ---
    private function insertStagingReguler(?string $override): int
    {
        $count = 0;
        StagingProduct::select('new_barcode_product', 'new_quantity_product', 'new_date_in_product', 'created_at')
            ->whereNull('new_tag_product')
            ->whereIn('new_status_product', ['display', 'expired', 'slow_moving'])
            ->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->chunk(self::CHUNK_SIZE, function ($items) use ($override, &$count) {
                $rows = $items->map(fn($item) => $this->makeRow(
                    $item->new_barcode_product,
                    false,
                    'In',
                    null,
                    'pending',
                    'staging_reguler',
                    $item->new_quantity_product,
                    $override ?? Carbon::parse($item->new_date_in_product ?? $item->created_at)->toDateTimeString()
                ))->toArray();
                DB::table('movement_products')->insert($rows);
                $count += count($rows);
            });
        return $count;
    }

    // --- [3] display_color ---
    private function insertDisplayColor(?string $override): int
    {
        $count = 0;
        New_product::select('new_barcode_product', 'new_quantity_product', 'new_date_in_product', 'created_at')
            ->whereNull('new_category_product')
            ->whereNotNull('new_tag_product')
            ->where('new_status_product', 'display')
            ->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->chunk(self::CHUNK_SIZE, function ($items) use ($override, &$count) {
                $rows = $items->map(fn($item) => $this->makeRow(
                    $item->new_barcode_product,
                    false,
                    'In',
                    null,
                    'pending',
                    'display_color',
                    $item->new_quantity_product,
                    $override ?? Carbon::parse($item->new_date_in_product ?? $item->created_at)->toDateTimeString()
                ))->toArray();
                DB::table('movement_products')->insert($rows);
                $count += count($rows);
            });
        return $count;
    }

    // --- [4] staging_sku ---
    private function insertStagingSku(?string $override): int
    {
        $count = 0;
        SkuProduct::select('barcode_product', 'quantity_product', 'created_at')
            ->chunk(self::CHUNK_SIZE, function ($items) use ($override, &$count) {
                $rows = $items->map(fn($item) => $this->makeRow(
                    $item->barcode_product,
                    true,
                    'In',
                    null,
                    'display_reguler',
                    'staging_sku',
                    $item->quantity_product,
                    $override ?? Carbon::parse($item->created_at)->toDateTimeString()
                ))->toArray();
                DB::table('movement_products')->insert($rows);
                $count += count($rows);
            });
        return $count;
    }

    // --- [5] display_reguler ---
    private function insertDisplayReguler(?string $override): int
    {
        $count = 0;
        New_product::select('new_barcode_product', 'new_quantity_product', 'new_date_in_product', 'created_at')
            ->whereNotNull('new_category_product')
            ->whereNull('new_tag_product')
            ->whereIn('new_status_product', ['display', 'expired', 'slow_moving'])
            ->where(function ($q) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->chunk(self::CHUNK_SIZE, function ($items) use ($override, &$count) {
                $rows = $items->map(fn($item) => $this->makeRow(
                    $item->new_barcode_product,
                    false,
                    'In',
                    null,
                    'staging_reguler',
                    'display_reguler',
                    $item->new_quantity_product,
                    $override ?? Carbon::parse($item->new_date_in_product ?? $item->created_at)->toDateTimeString()
                ))->toArray();
                DB::table('movement_products')->insert($rows);
                $count += count($rows);
            });
        return $count;
    }

    // --- [6] cargo ---
    private function insertCargo(?string $override): int
    {
        $count = 0;
        BulkySale::select('bulky_sales.barcode_bulky_sale', 'bulky_sales.qty', 'bulky_sales.created_at')
            ->join('bulky_documents', 'bulky_documents.id', '=', 'bulky_sales.bulky_document_id')
            ->whereNotNull('bulky_sales.bulky_document_id')
            ->where('bulky_documents.status_bulky', 'proses')
            ->chunk(self::CHUNK_SIZE, function ($items) use ($override, &$count) {
                $rows = $items->map(fn($item) => $this->makeRow(
                    $item->barcode_bulky_sale,
                    false,
                    'Out',
                    'cargo',
                    'display_reguler',
                    'cargo',
                    $item->qty,
                    $override ?? Carbon::parse($item->created_at)->toDateTimeString()
                ))->toArray();
                DB::table('movement_products')->insert($rows);
                $count += count($rows);
            });
        return $count;
    }

    // --- [7] repair ---
    private function insertRepair(?string $override): int
    {
        $count = 0;
        RepairProduct::select('new_barcode_product', 'new_quantity_product', 'new_date_in_product', 'created_at')
            ->where('new_status_product', 'repair')
            ->chunk(self::CHUNK_SIZE, function ($items) use ($override, &$count) {
                $rows = $items->map(fn($item) => $this->makeRow(
                    $item->new_barcode_product,
                    false,
                    'Move',
                    null,
                    'display_reguler',
                    'repair',
                    $item->new_quantity_product,
                    $override ?? Carbon::parse($item->new_date_in_product ?? $item->created_at)->toDateTimeString()
                ))->toArray();
                DB::table('movement_products')->insert($rows);
                $count += count($rows);
            });
        return $count;
    }

    private function makeRow(
        string $productId,
        bool $isSku,
        string $type,
        ?string $typeOut,
        string $from,
        string $to,
        ?int $qty,
        string $dateTime
    ): array {
        $now = now()->toDateTimeString();
        return [
            'id'         => Str::uuid()->toString(),
            'product_id' => $productId,
            'is_sku'     => $isSku ? 1 : 0,
            'type'       => $type,
            'type_out'   => $typeOut,
            'from'       => $from,
            'to'         => $to,
            'qty'        => $qty,
            'dateTime'   => $dateTime,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}

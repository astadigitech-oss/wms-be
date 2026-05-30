<?php

namespace App\Console\Commands;

use App\Models\DailySaldoSnapshot;
use App\Services\MovementService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateInitialSnapshot extends Command
{
    protected $signature = 'snapshot:generateInitial
                            {--date= : Tanggal snapshot (format: Y-m-d). Default: hari ini}
                            {--force : Timpa snapshot jika sudah ada untuk tanggal tersebut}';

    protected $description = 'Generate initial snapshot barang lama sebagai baseline saldo awal (daily_saldo_snapshots)';

    public function handle()
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : Carbon::now()->toDateString();

        $this->info("=== Generate Initial Snapshot ===");
        $this->info("Tanggal snapshot : $date");
        $this->newLine();

        $existing = DailySaldoSnapshot::where('snapshot_date', $date)->first();
        if ($existing && !$this->option('force')) {
            $this->warn("Snapshot untuk tanggal $date sudah ada (qty: {$existing->total_qty}).");
            $this->warn("Gunakan --force untuk menimpa.");
            return Command::FAILURE;
        }

        $this->info("Mengambil data barang aktif...");

        $result  = MovementService::getSaldo();
        $summary = $result['summary'];

        $this->newLine();
        $this->info("=== Breakdown per Lokasi ===");
        $this->table(
            ['Lokasi', 'Total Barang', 'Total Nilai (Rp)', 'Total Nilai Before (Rp)'],
            collect($summary['breakdown'])->map(fn($row) => [
                $row['location'],
                number_format($row['qty']),
                $row['total_price'] !== null ? number_format($row['total_price']) : '-',
                $row['total_price_before'] !== null ? number_format($row['total_price_before']) : '-',
            ])->push([
                '--- TOTAL ---',
                number_format($summary['total_qty']),
                number_format($summary['total_price']),
                number_format($summary['total_price_before']),
            ])->toArray()
        );

        $this->newLine();
        $this->info("Menyimpan snapshot ke database...");

        DailySaldoSnapshot::updateOrCreate(
            ['snapshot_date' => $date],
            [
                'total_qty'   => $summary['total_qty'],
                'total_price' => $summary['total_price'],
                'breakdown'   => $summary['breakdown'],
            ]
        );

        $this->info("Snapshot berhasil disimpan!");
        $this->newLine();
        $this->line("  Tanggal : $date");
        $this->line("  Qty     : " . number_format($summary['total_qty']));
        $this->line("  Price   : Rp " . number_format($summary['total_price']));

        return Command::SUCCESS;
    }
}

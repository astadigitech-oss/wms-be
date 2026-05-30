<?php

namespace App\Console\Commands;

use App\Models\DailySaldoSnapshot;
use App\Services\MovementService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NewSnapshotDailyInventory extends Command
{
    protected $signature = 'cron:newSnapshotDailySummary
                            {--date= : Tanggal snapshot (format: Y-m-d). Default: hari ini}
                            {--force : Timpa snapshot jika sudah ada untuk tanggal tersebut}';

    protected $description = 'Menyimpan saldo inventory harian ke daily_saldo_snapshots (total_qty, total_price, breakdown per lokasi)';

    public function handle()
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : Carbon::now()->toDateString();

        $this->info("=== Snapshot Saldo Harian ===");
        $this->info("Tanggal: $date");

        $existing = DailySaldoSnapshot::where('snapshot_date', $date)->first();
        if ($existing && !$this->option('force')) {
            $this->warn("Snapshot untuk tanggal $date sudah ada (qty: {$existing->total_qty}).");
            $this->warn("Gunakan --force untuk menimpa.");
            return Command::FAILURE;
        }

        $this->info('Menghitung saldo...');

        $result  = MovementService::getSaldo();
        $summary = $result['summary'];

        DailySaldoSnapshot::updateOrCreate(
            ['snapshot_date' => $date],
            [
                'total_qty'   => $summary['total_qty'],
                'total_price' => $summary['total_price'],
                'breakdown'   => $summary['breakdown'],
            ]
        );

        $this->info("Berhasil disimpan!");
        $this->line("  Tanggal : $date");
        $this->line("  Qty     : " . number_format($summary['total_qty']));
        $this->line("  Price   : Rp " . number_format($summary['total_price']));

        return Command::SUCCESS;
    }
}

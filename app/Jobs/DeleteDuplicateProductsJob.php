<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

class DeleteDuplicateProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $modelClass;

    public function __construct($modelClass)
    {
        $this->modelClass = $modelClass;
    }

    public function handle()
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        // Inisialisasi model
        $model = new $this->modelClass;

        // Ambil barcodes dari Redis
        $productOldInit = json_decode(Redis::get('product_old:code_document:0001'), true);

        if (!$productOldInit) {
            // Redis cache tidak tersedia, hentikan job
            return;
        }

        // Query dan hapus produk dengan menggunakan chunk
        $model::where('code_document', '0004/11/2024')
            ->chunk(100, function ($products) use (&$productOldInit) {
                foreach ($products as $product) {
                    if (in_array($product->old_barcode_product, $productOldInit)) {
                        // Memulai transaksi untuk penghapusan
                        DB::transaction(function () use ($product, &$productOldInit) {
                            // Hapus dari `code_document = '0004/11/2024'`
                            $product->delete();

                            // Hapus dari `code_document = '0001/11/2024'`
                            $product->where('code_document', '0001/11/2024')
                                ->where('old_barcode_product', $product->old_barcode_product)
                                ->delete();

                            // Hapus barcode dari array untuk menghindari pemeriksaan ulang
                            $productOldInit = array_filter($productOldInit, fn($barcode) => $barcode !== $product->old_barcode_product);

                            // Update cache di Redis
                            Redis::set('product_old:code_document:0001', json_encode($productOldInit), 'EX', 600);
                        });
                    }
                }
            });
    }
}

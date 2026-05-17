<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class ProductBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $batchSize;

    public function __construct($batchSize)
    {
        $this->batchSize = $batchSize;
    }

    public function handle()
    {
        $redisKey = 'product_batch';
        $batchData = [];

        for ($i = 0; $i < $this->batchSize; $i++) {
            $data = Redis::lpop($redisKey);
            
            if (!$data) {
                break; // Jika Redis sudah kosong, hentikan loop
            }
            
            $batchData[] = json_decode($data, true);
        }

        if (empty($batchData)) {
            return; // Tidak ada yang perlu diproses
        }

        $approveData = [];
        $newProductData = [];
        $now = now(); // Ambil waktu saat ini untuk timestamp

        // pisahkan data berdasarkan tujuan model
        foreach ($batchData as $inputData) {
            $inputData['created_at'] = $now;
            $inputData['updated_at'] = $now;

            if (isset($inputData['condition']) && $inputData['condition'] === 'lolos') {
                $approveData[] = $inputData;
            } else {
                $newProductData[] = $inputData;
            }
        }

        // 3. Bulk Insert ke Database (Hanya butuh 1-2 Query saja untuk semua data)
        if (!empty($approveData)) {
            \App\Models\ProductApprove::insert($approveData);
        }

        if (!empty($newProductData)) {
            \App\Models\New_product::insert($newProductData);
        }
    }
}
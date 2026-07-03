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

        // Ambil batch data dari Redis
        $batchData = Redis::lrange($redisKey, 0, $this->batchSize - 1);

        // Jika ada data untuk diproses
        if ($batchData) {
            foreach ($batchData as $data) {
                $inputData = json_decode($data, true);

                $model = new \App\Models\ProductApprove;
                $model->create($inputData);
            }

            // Hapus data yang telah diproses dari Redis list
            Redis::ltrim($redisKey, $this->batchSize, -1);
        }
    }
}
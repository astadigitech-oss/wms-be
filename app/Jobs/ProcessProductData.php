<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis; 
use App\Models\ProductApprove;


class ProcessProductData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $barcode;
    protected $modelClass;

    public function __construct($barcode, $modelClass)
    {
        $this->barcode = $barcode;
        $this->modelClass = $modelClass;
    }

    public function handle()
    {
        $redisKey = 'product:' . $this->barcode;
        $data = Redis::get($redisKey); 

        if ($data) {
            $inputData = json_decode($data, true);

            $model = new $this->modelClass;
            $model->create($inputData);

            Redis::del($redisKey);
        }
    }
}
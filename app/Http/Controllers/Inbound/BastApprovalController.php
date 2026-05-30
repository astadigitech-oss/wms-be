<?php

namespace App\Http\Controllers\Inbound;

use App\Http\Controllers\Controller;
use App\Http\Resources\DuplicateRequestResource;
use App\Http\Resources\ProductapproveResource;
use App\Http\Resources\ResponseResource;
use App\Jobs\ProductBatch;
use App\Models\Document;
use App\Models\New_product;
use App\Models\ProductApprove;
use App\Models\ProductDefect;
use App\Models\RiwayatCheck;
use App\Models\ScanPending;
use App\Models\StagingProduct;
use App\Models\UserScanWeb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use Throwable;

class BastApprovalController extends Controller
{
    public function mintaApproveDataAsal(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'id_asal'     => 'required|exists:product_olds,id',
                'edited_name' => 'nullable|string',
                'edited_qty'  => 'nullable|integer',
            ]
        );

        if ($validator->fails()) {
            return (new ResponseResource(
                false,
                'Validation error',
                $validator->errors()
            ))->response()->setStatusCode(422);
        }

        DB::beginTransaction();

        try {
            $user = auth()->user();

            $data = ScanPending::where('source_model', 'Product_old')
                ->where('source_id', $request->id_asal)
                ->where('status', 'pending')
                ->first();

            if ($data) {
                return (new ResponseResource(
                    false,
                    'Request approve untuk data ini sudah dibuat',
                    null
                ))->response()->setStatusCode(422);
            }

            $scanPending = ScanPending::create([
                'source_model' => 'Product_old',
                'source_id'    => $request->id_asal,
                'edited_name'  => $request->edited_name,
                'edited_qty'   => $request->edited_qty,
                'editor_id'    => $user->id,
            ]);

            DB::commit();

            return (new ResponseResource(
                true,
                'Request approve berhasil dibuat',
                $scanPending
            ))->response()->setStatusCode(200);
        } catch (Throwable $e) {

            DB::rollBack();

            Log::error('Gagal membuat approval data asal', [
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
                'file'  => $e->getFile(),
            ]);

            return (new ResponseResource(
                false,
                'Terjadi kesalahan pada server',
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function checkScanPaused()
    {
        try {

            $user = auth()->user();

            $hasPendingApproval = \App\Models\ScanPending::where('editor_id', $user->id)
                ->where('status', 'pending')
                ->exists();

            $idSourceProducts = \App\Models\ScanPending::where('editor_id', $user->id)
                ->where('status', 'pending')
                ->where('source_model', 'Product_old')
                ->pluck('source_id')
                ->toArray();

            $barcodeOldProducts = \App\Models\Product_old::whereIn('id', $idSourceProducts)->first();
            // dd($barcodeOldProducts->old_barcode_product);

            return (new ResponseResource(
                true,
                'Success',
                [
                    'barcode_old_product' => $barcodeOldProducts?->old_barcode_product,
                    'scan_paused' => $hasPendingApproval
                ]
            ))->response()->setStatusCode(200);
        } catch (\Throwable $e) {

            Log::error('Gagal check scan paused', [
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
                'file'  => $e->getFile(),
            ]);
            // dd($e->getMessage());

            return (new ResponseResource(
                false,
                'Terjadi kesalahan pada server',
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function scannerBaru(Request $request)
    {
        $codeDocument = $request->input('code_document');
        $oldBarcode = $request->input('old_barcode_product');

        if (!$codeDocument) {
            return new ResponseResource(false, "Code document tidak boleh kosong.", null);
        }

        if (!$oldBarcode) {
            return new ResponseResource(false, "Barcode tidak boleh kosong.", null);
        }

        $isProcessed = \App\Models\New_product::where('code_document', $codeDocument)
            ->where('old_barcode_product', $oldBarcode)
            ->exists();

        if ($isProcessed) {
            return new ResponseResource(false, "Produk ini sudah selesai diproses.", []);
        }

        $product = \App\Models\Product_old::where('code_document', $codeDocument)
            ->where('old_barcode_product', $oldBarcode)
            ->first();

        if (!$product) {
            return new ResponseResource(false, "Produk ini sudah selesai di scan", []);
        }

        $approvedPending = \App\Models\ScanPending::where('source_model', 'Product_old')
            ->where('source_id', $product->id)
            ->where('status', 'approved')
            ->latest()
            ->first();
        // dd($approvedPending, $product->id);

        $product->edited_name_product =
            $approvedPending?->edited_name ?? $product->old_name_product;
        // dd($product->edited_name_product);

        $product->edited_quantity_product =
            $approvedPending?->edited_qty ?? $product->old_quantity_product;

        $product->approval_status = $approvedPending?->status;

        $response = [
            'product' => $product
        ];

        if ($product->old_price_product <= 99999) {
            $response['color_tags'] = \App\Models\Color_tag::where('min_price_color', '<=', $product->old_price_product)
                ->where('max_price_color', '>=', $product->old_price_product)
                ->get();
        }

        return new ResponseResource(true, "Produk ditemukan.", $response);
    }

    public function scannerSubmitBaru(Request $request)
    {
        $userId = auth()->id();

        $validator = Validator::make($request->all(), [
            'code_document' => 'required',
            'old_barcode_product' => 'required|exists:product_olds,old_barcode_product',
            'new_name_product' => 'required',
            'new_quantity_product' => 'required|integer',
            'new_price_product' => 'required|numeric',
            'old_price_product' => 'required|numeric',
            'new_status_product' => 'required|in:display,expired,promo,bundle,palet,dump',
            'condition' => 'required|in:lolos,damaged,abnormal,non',
            'new_category_product' => 'nullable|exists:categories,name_category',
            'new_tag_product' => 'nullable|exists:color_tags,name_color',
            'deskripsi' => 'nullable|string',
        ], [
            'old_barcode_product.exists' => 'barcode tidak ada',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $oldBarcode = $request->input('old_barcode_product');

        $ttlRedis = 5;
        $throttleTtl = 7;

        $redisKey = "barcode:$oldBarcode";

        $rateLimiter = app(\Illuminate\Cache\RateLimiter::class);

        $throttleKey = "throttle:$oldBarcode";

        if ($rateLimiter->tooManyAttempts($throttleKey, 1)) {

            return new DuplicateRequestResource(
                false,
                "throttle - barcode awal di scan lebih dari 1x dalam waktu $throttleTtl detik",
                $oldBarcode,
                429
            );
        }

        $rateLimiter->hit($throttleKey, $throttleTtl);

        $luaScript = '
        if redis.call("exists", KEYS[1]) == 1 then
            return 0
        else
            redis.call("setex", KEYS[1], ARGV[1], "processing")
            return 1
        end
    ';

        $redis = app('redis');

        $lockAcquired = $redis->eval($luaScript, 1, $redisKey, $ttlRedis);

        if ($lockAcquired == 0) {

            return new DuplicateRequestResource(
                false,
                "redis - barcode awal di scan lebih dari 1x dalam waktu $ttlRedis detik",
                $oldBarcode,
                429
            );
        }

        $status = $request->input('condition');

        $description = $request->input('deskripsi', '');

        $qualityData = $this->prepareQualityData($status, $description);

        $inputData = $this->prepareInputData(
            $request,
            $status,
            $qualityData,
            $userId
        );

        DB::beginTransaction();

        try {

            if ($inputData['condition'] == 'lolos') {

                if (
                    $inputData['new_category_product'] == null &&
                    $inputData['new_tag_product'] == null
                ) {

                    DB::rollBack();

                    return (new ResponseResource(
                        false,
                        "ulangi scan lagi, ada kesalahan generate karna penggunaan tinggi",
                        $inputData
                    ))->response()->setStatusCode(429);
                }
            }

            $document = Document::where(
                'code_document',
                $request->input('code_document')
            )->first();

            if ($document->custom_barcode) {

                $generate = newBarcodeCustom(
                    $document->custom_barcode,
                    $userId
                );
            } else {

                $generate = generateNewBarcode(
                    $inputData['new_category_product']
                );
            }

            $inputData['new_barcode_product'] = $generate;

            $tables = [
                New_product::class,
                ProductApprove::class,
                StagingProduct::class,
            ];

            $oldBarcodeExists = false;
            $newBarcodeExists = false;

            foreach ($tables as $table) {

                if (
                    $table::where(
                        'old_barcode_product',
                        $oldBarcode
                    )->exists()
                ) {

                    $oldBarcodeExists = true;
                }

                if (
                    $table::where(
                        'new_barcode_product',
                        $inputData['new_barcode_product']
                    )->exists()
                ) {

                    $newBarcodeExists = true;
                }
            }

            if ($oldBarcodeExists) {

                DB::rollBack();

                return new ProductapproveResource(
                    false,
                    false,
                    "The old barcode already exists",
                    $inputData
                );
            }

            if ($newBarcodeExists) {

                DB::rollBack();

                return (new ResponseResource(
                    false,
                    "The new barcode already exists",
                    $inputData
                ))->response()->setStatusCode(429);
            }

            $riwayatCheck = RiwayatCheck::where(
                'code_document',
                $request->input('code_document')
            )->first();

            if ($qualityData['lolos'] != null) {

                $modelClass = ProductApprove::class;
            } else if ($qualityData['damaged'] != null) {

                $modelClass = New_product::class;

                if ($riwayatCheck->status_file == 1) {

                    ProductDefect::create([
                        'riwayat_check_id' => $riwayatCheck->id,
                        'code_document' => $document->code_document,
                        'old_barcode_product' => $inputData['old_barcode_product'],
                        'new_barcode_product' => $inputData['new_barcode_product'],
                        'old_price_product' => $inputData['old_price_product'],
                        'type' => 'damaged',
                        'note' => $inputData['note'] ?? null
                    ]);
                }
            } else if ($qualityData['abnormal'] != null) {

                $modelClass = New_product::class;

                if ($riwayatCheck->status_file == 1) {

                    ProductDefect::create([
                        'riwayat_check_id' => $riwayatCheck->id,
                        'code_document' => $document->code_document,
                        'old_barcode_product' => $inputData['old_barcode_product'],
                        'new_barcode_product' => $inputData['new_barcode_product'],
                        'old_price_product' => $inputData['old_price_product'],
                        'type' => 'abnormal',
                        'note' => $inputData['note'] ?? null
                    ]);
                }
            } else if (
                isset($qualityData['non']) &&
                $qualityData['non'] != null
            ) {

                $modelClass = New_product::class;

                if ($riwayatCheck->status_file == 1) {

                    ProductDefect::create([
                        'riwayat_check_id' => $riwayatCheck->id,
                        'code_document' => $document->code_document,
                        'old_barcode_product' => $inputData['old_barcode_product'],
                        'new_barcode_product' => $inputData['new_barcode_product'],
                        'old_price_product' => $inputData['old_price_product'],
                        'type' => 'non',
                        'note' => $inputData['note'] ?? null
                    ]);
                }
            }

            $redisKey = 'product_batch';

            $batchSize = 15;

            if (isset($modelClass)) {

                Redis::rpush(
                    $redisKey,
                    json_encode($inputData)
                );

                $listSize = Redis::llen($redisKey);

                if ($listSize >= $batchSize) {

                    ProductBatch::dispatch($batchSize);
                }
            }

            $this->deleteOldProduct(
                $inputData['code_document'],
                $request->input('old_barcode_product')
            );

            UserScanWeb::updateOrCreateDailyScan(
                $userId,
                $document->id
            );

            $this->updateDocumentStatus(
                $request->input('code_document')
            );

            DB::commit();

            return new ProductapproveResource(
                true,
                true,
                "New Produk Berhasil ditambah",
                $inputData
            );
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

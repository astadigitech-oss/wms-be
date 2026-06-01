<?php

namespace App\Http\Controllers\Inbound;

use App\Http\Controllers\Controller;
use App\Http\Resources\DuplicateRequestResource;
use App\Http\Resources\ProductapproveResource;
use App\Http\Resources\ResponseResource;
use App\Jobs\ProductBatch;
use App\Models\Category;
use App\Models\Document;
use App\Models\New_product;
use App\Models\Notification;
use App\Models\Product_old;
use App\Models\ProductApprove;
use App\Models\ProductDefect;
use App\Models\ProductEditHistory;
use App\Models\RiwayatCheck;
use App\Models\ScanPending;
use App\Models\StagingProduct;
use App\Models\UserScanWeb;
use Carbon\Carbon;
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
            // 'new_barcode_product' => 'unique:new_products,new_barcode_product',
            'new_name_product' => 'required',
            'new_quantity_product' => 'required|integer',
            'new_price_product' => 'required|numeric',
            'old_price_product' => 'required|numeric',
            // 'new_date_in_product' => 'required|date',
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

        // Tambahkan hit untuk throttle
        $rateLimiter->hit($throttleKey, $throttleTtl);
        // Lua Script untuk Atomic Lock
        $luaScript = '
            if redis.call("exists", KEYS[1]) == 1 then
                return 0 -- Duplikasi
            else
                redis.call("setex", KEYS[1], ARGV[1], "processing")
                return 1 -- Sukses
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

        $inputData = $this->prepareInputData($request, $status, $qualityData, $userId);

        // Ensure is_pending is always false
        $inputData['is_pending'] = false;

        $oldBarcode = $request->input('old_barcode_product');

        DB::beginTransaction();
        try {

            if ($inputData['condition'] == 'lolos') {
                if ($inputData['new_category_product'] == null && $inputData['new_tag_product'] == null) {
                    return (new ResponseResource(false, "ulangi scan lagi, ada kesalahan generate karna penggunaan tinggi", $inputData))->response()->setStatusCode(429);
                }
            }

            $document = Document::where('code_document', $request->input('code_document'))->first();

            if ($document->custom_barcode) {
                $generate = newBarcodeCustom($document->custom_barcode, $userId);
            } else {
                $generate = generateNewBarcode($inputData['new_category_product']);
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
                if ($table::where('old_barcode_product', $oldBarcode)->exists()) {
                    $oldBarcodeExists = true;
                }
                if ($table::where('new_barcode_product', $inputData['new_barcode_product'])->exists()) {
                    $newBarcodeExists = true;
                }
            }

            if ($oldBarcodeExists) {
                return new ProductapproveResource(false, false, "The old barcode already exists", $inputData);
            }

            if ($newBarcodeExists) {
                return (new ResponseResource(false, "The new barcode already exists", $inputData))->response()->setStatusCode(429);
            }

            $user = auth()->user();
            $isAdminOrSpv = false;
            if ($user && $user->role) {
                $isAdminOrSpv = in_array($user->role->role_name, ['Admin', 'Spv']);
            }

            $oldProduct = Product_old::where('old_barcode_product', $oldBarcode)->first();
            $isDifferent = false;

            if ($oldProduct) {
                $nameChanged = trim($request->input('new_name_product')) !== trim($oldProduct->old_name_product);
                $qtyChanged = (int)$request->input('new_quantity_product') !== (int)$oldProduct->old_quantity_product;
                $isDifferent = ($nameChanged || $qtyChanged);
            }

            if ($isDifferent) {
                $historyData = [
                    'code_document' => $inputData['code_document'],
                    'barcode_product' => $inputData['new_barcode_product'],
                    'old_value' => $oldProduct ? [
                        'barcode' => $oldProduct->old_barcode_product,
                        'name_product' => $oldProduct->old_name_product,
                        'qty' => $oldProduct->old_quantity_product,
                        'old_price' => $oldProduct->old_price_product,
                        'category' => $oldProduct->new_category_product ?? '-',
                        'quality' => isset($oldProduct->new_quality) ? json_decode($oldProduct->new_quality, true) : null,
                    ] : null,
                    'new_value' => [
                        'barcode' => $inputData['new_barcode_product'],
                        'name_product' => $inputData['new_name_product'],
                        'qty' => $inputData['new_quantity_product'],
                        'old_price' => $inputData['old_price_product'],
                        'new_price' => $inputData['new_price_product'],
                        'category' => $inputData['new_category_product'] ?? '-',
                        'quality' => is_string($inputData['new_quality']) ? json_decode($inputData['new_quality'], true) : $inputData['new_quality'],
                    ],
                    'request_user_id' => $userId,
                ];

                if ($isAdminOrSpv) {
                    $historyData['notification_id'] = null;
                    $historyData['status'] = 'approved';
                    $historyData['approver_id'] = $userId;

                    ProductEditHistory::create($historyData);
                } else {
                    $roleName = $user && $user->role ? $user->role->role_name : 'Crew';

                    $notification = Notification::create([
                        'notification_name' => 'Approval Perubahan Data: ' . $inputData['new_barcode_product'],
                        'status' => 'pending_approval',
                        'user_id' => $userId,
                        'role' => $roleName,
                    ]);

                    $historyData['notification_id'] = $notification->id;
                    $historyData['status'] = 'done';

                    ProductEditHistory::create($historyData);
                }
            }

            $riwayatCheck = RiwayatCheck::where('code_document', $request->input('code_document'))->first();
            // $totalDataIn = 1 + $riwayatCheck->total_data_in;
            // $checkSoCategory = SummarySoCategory::where('type', 'process')->first();
            // $checkSoColor = SummarySoColor::where('type', 'process')->first();

            if ($qualityData['lolos'] != null) {
                $modelClass = ProductApprove::class;
                // if($checkSoCategory && $inputData['new_category_product'] !== null){
                //     $checkSoCategory->increment('product_staging');
                // }
                // if($checkSoColor && $inputData['new_tag_product'] !== null){
                //     $soColor = SoColor::where('summary_so_color_id', $checkSoColor->id)
                //         ->where('color', $inputData['new_tag_product'])
                //         ->first();
                //     if ($soColor) {
                //         $soColor->increment('total_color');
                //     }
                // }
                // $riwayatCheck->total_data_lolos += 1;
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
                // if($inputData['old_price_product'] < 100000){
                //     if($checkSoColor){
                //         $soColor = SoColor::where('summary_so_color_id', $checkSoColor->id)
                //             ->where('color', $inputData['new_tag_product'])
                //             ->first();
                //         if ($soColor) {
                //             $soColor->increment('product_damaged');
                //         }
                //     }
                // }else{
                //     if($checkSoCategory){
                //         $checkSoCategory->increment('product_damaged');
                //     }
                // }
                // $riwayatCheck->total_data_damaged += 1;
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
                // // $riwayatCheck->total_data_abnormal += 1;
                // if($inputData['old_price_product'] < 100000){
                //     if($checkSoColor){
                //         $soColor = SoColor::where('summary_so_color_id', $checkSoColor->id)
                //             ->where('color', $inputData['new_tag_product'])
                //             ->first();
                //         if ($soColor) {
                //             $soColor->increment('product_abnormal');
                //         }
                //     }
                // }else{
                //     if($checkSoCategory){
                //         $checkSoCategory->increment('product_abnormal');
                //     }
                // }

            } else if (isset($qualityData['non']) && $qualityData['non'] != null) {
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
                Redis::rpush($redisKey, json_encode($inputData));

                $listSize = Redis::llen($redisKey);

                if ($listSize >= $batchSize) {
                    ProductBatch::dispatch($batchSize);
                }
            }
            $this->deleteOldProduct($inputData['code_document'], $request->input('old_barcode_product'));

            UserScanWeb::updateOrCreateDailyScan($userId, $document->id);


            // $totalDiscrepancy = Product_old::where('code_document', $request->input('code_document'))->pluck('code_document');

            // $riwayatCheck->update([
            //     'total_data_in' => $totalDataIn,
            //     'total_data_lolos' => $riwayatCheck->total_data_lolos,
            //     'total_data_damaged' => $riwayatCheck->total_data_damaged,
            //     'total_data_abnormal' => $riwayatCheck->total_data_abnormal,
            //     'total_discrepancy' => count($totalDiscrepancy),
            //     'status_approve' => 'pending',
            //     // persentase
            //     'percentage_total_data' => ($document->total_column_in_document / $document->total_column_in_document) * 100,
            //     'percentage_in' => ($totalDataIn / $document->total_column_in_document) * 100,
            //     'percentage_lolos' => ($riwayatCheck->total_data_lolos / $document->total_column_in_document) * 100,
            //     'percentage_damaged' => ($riwayatCheck->total_data_damaged / $document->total_column_in_document) * 100,
            //     'percentage_abnormal' => ($riwayatCheck->total_data_abnormal / $document->total_column_in_document) * 100,
            //     'percentage_discrepancy' => (count($totalDiscrepancy) / $document->total_column_in_document) * 100,
            // ]);
            //end data history

            $this->updateDocumentStatus($request->input('code_document'));

            DB::commit();

            return new ProductapproveResource(true, true, "New Produk Berhasil ditambah", $inputData);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function prepareQualityData($status, $description)
    {
        return [
            'lolos' => $status === 'lolos' ? 'lolos' : null,
            'damaged' => $status === 'damaged' ? $description : null,
            'abnormal' => $status === 'abnormal' ? $description : null,
            'non' => $status === 'non' ? $description : null,
        ];
    }

    private function prepareInputData($request, $status, $qualityData, $userId)
    {
        $inputData = $request->only([
            'code_document',
            'old_barcode_product',
            'new_barcode_product',
            'new_name_product',
            'new_quantity_product',
            'new_price_product',
            'old_price_product',
            'new_status_product',
            'new_category_product',
            'new_tag_product',
            'condition',
            'deskripsi',
            'type',
            'user_id',
            'discount_category',
            'is_so'
        ]);

        if ($inputData['old_price_product'] < 100000) {
            $inputData['new_barcode_product'] = $inputData['old_barcode_product'];
        }
        $category = Category::where('name_category', $inputData['new_category_product'])->first();
        $inputData['discount_category'] = $category ? $category->discount_category : null;
        $inputData['new_date_in_product'] = Carbon::now('Asia/Jakarta')->toDateString();
        $inputData['new_quality'] = json_encode($qualityData);
        $inputData['actual_new_quality'] = json_encode($qualityData);
        $inputData['actual_old_price_product'] = $inputData['old_price_product'];
        $inputData['type'] = 'type1';
        $inputData['is_so'] = null;
        // $inputData['is_so'] = "done";
        // $inputData['user_so'] = $userId;

        $inputData['new_discount'] = 0;
        $inputData['user_id'] = $userId;
        $inputData['display_price'] = $inputData['new_price_product'];
        $inputData['note'] = $inputData['deskripsi'] ?? null;

        if ($status !== 'lolos') {
            $inputData['new_category_product'] = null;
            $inputData['new_price_product'] = null;
        }

        if ($inputData['new_price_product'] == null) {
            $inputData['display_price'] = 0;
        }

        return $inputData;
    }

    private function deleteOldProduct($code_document, $old_barcode_product)
    {
        $product = \App\Models\Product_old::where('code_document', $code_document)
            ->where('old_barcode_product', $old_barcode_product)
            ->first();

        if ($product) {
            return $product->delete();
        }

        return false;
    }

    private function updateDocumentStatus($codeDocument)
    {
        $document = Document::where('code_document', $codeDocument)->firstOrFail();
        if ($document->status_document === 'pending') {
            $document->update(['status_document' => 'in progress']);
        }
    }

    public function addProductOld(Request $request)
    {
        $userId = auth()->id();
        try {

            DB::beginTransaction();
            $status = $request->input('condition');
            $description = $request->input('deskripsi', '');

            $qualityData = $this->prepareQualityData($status, $description);

            $inputData = $this->prepareInputData($request, $status, $qualityData, $userId);

            $document = Document::where('code_document', $inputData['code_document'])->first();
            $generate = null;

            $maxRetry = 5;
            for ($i = 0; $i < $maxRetry; $i++) {
                if ($document->custom_barcode) {
                    $generate = newBarcodeCustom($document->custom_barcode, $userId);
                } else {
                    $generate = generateNewBarcode($inputData['new_category_product']);
                }

                if (!ProductApprove::where('new_barcode_product', $generate)->exists()) {
                    break;
                }

                if ($i === $maxRetry - 1) {
                    throw new \Exception("Failed to generate unique barcode after multiple attempts.");
                }
            }

            $inputData['new_barcode_product'] = $generate;

            // Set display price
            $inputData['display_price'] = $inputData['new_price_product'] ?? $inputData['old_price_product'];

            $category = Category::where('name_category', $inputData['new_category_product'])->first();
            $inputData['discount_category'] = $category ? $category->discount_category : null;


            $user = auth()->user();
            $isAdminOrSpv = false;
            if ($user && $user->role) {
                $isAdminOrSpv = in_array($user->role->role_name, ['Admin', 'Spv']);
            }

            $oldProduct = Product_old::where('old_barcode_product', $inputData['old_barcode_product'])->first();
            $isDifferent = false;

            if ($oldProduct) {
                $nameChanged = trim($request->input('new_name_product')) !== trim($oldProduct->old_name_product);
                $qtyChanged = (int)$request->input('new_quantity_product') !== (int)$oldProduct->old_quantity_product;
                $isDifferent = ($nameChanged || $qtyChanged);
            }

            if ($isDifferent) {
                $historyData = [
                    'code_document' => $inputData['code_document'],
                    'barcode_product' => $inputData['new_barcode_product'],
                    'old_value' => $oldProduct ? [
                        'barcode' => $oldProduct->old_barcode_product,
                        'name_product' => $oldProduct->old_name_product,
                        'qty' => $oldProduct->old_quantity_product,
                        'old_price' => $oldProduct->old_price_product,
                        'category' => $oldProduct->new_category_product ?? '-',
                        'quality' => isset($oldProduct->new_quality) ? json_decode($oldProduct->new_quality, true) : null,
                    ] : null,
                    'new_value' => [
                        'barcode' => $inputData['new_barcode_product'],
                        'name_product' => $inputData['new_name_product'],
                        'qty' => $inputData['new_quantity_product'],
                        'old_price' => $inputData['old_price_product'],
                        'new_price' => $inputData['new_price_product'],
                        'category' => $inputData['new_category_product'] ?? '-',
                        'quality' => is_string($inputData['new_quality']) ? json_decode($inputData['new_quality'], true) : $inputData['new_quality'],
                    ],
                    'request_user_id' => $userId,
                ];

                if ($isAdminOrSpv) {
                    ProductEditHistory::create($historyData);
                } else {
                    // legacy bypass
                    $inputData['is_pending'] = false;

                    $roleName = $user && $user->role ? $user->role->role_name : 'Crew';

                    $notification = Notification::create([
                        'notification_name' => 'Approval Perubahan Data: ' . $inputData['new_barcode_product'],
                        'status' => 'approved',
                        'user_id' => $userId,
                        'role' => $roleName,
                    ]);

                    $historyData['notification_id'] = $notification->id;
                    $historyData['status'] = 'approved';

                    ProductEditHistory::create($historyData);
                }
            }

            $this->deleteOldProduct($inputData['code_document'], $inputData['old_barcode_product']);

            $riwayatCheck = RiwayatCheck::where('code_document', $request->input('code_document'))->first();
            $totalDataIn = 1 + $riwayatCheck->total_data_in;

            if ($qualityData['lolos'] != null) {
                $riwayatCheck->total_data_lolos += 1;
            } else if ($qualityData['damaged'] != null) {
                $riwayatCheck->total_data_damaged += 1;
            } else if ($qualityData['abnormal'] != null) {
                $riwayatCheck->total_data_abnormal += 1;
            }

            UserScanWeb::updateOrCreateDailyScan($userId, $document->id);


            $totalDiscrepancy = Product_old::where('code_document', $request->input('code_document'))->pluck('code_document');

            $riwayatCheck->update([
                'total_data_in' => $totalDataIn,
                'total_data_lolos' => $riwayatCheck->total_data_lolos,
                'total_data_damaged' => $riwayatCheck->total_data_damaged,
                'total_data_abnormal' => $riwayatCheck->total_data_abnormal,
                'total_discrepancy' => count($totalDiscrepancy),
                'status_approve' => 'pending',
                // persentase
                'percentage_total_data' => ($document->total_column_in_document / $document->total_column_in_document) * 100,
                'percentage_in' => ($totalDataIn / $document->total_column_in_document) * 100,
                'percentage_lolos' => ($riwayatCheck->total_data_lolos / $document->total_column_in_document) * 100,
                'percentage_damaged' => ($riwayatCheck->total_data_damaged / $document->total_column_in_document) * 100,
                'percentage_abnormal' => ($riwayatCheck->total_data_abnormal / $document->total_column_in_document) * 100,
                'percentage_discrepancy' => (count($totalDiscrepancy) / $document->total_column_in_document) * 100,
            ]);

            $this->updateDocumentStatus($inputData['code_document']);

            $newProduct = ProductApprove::create($inputData);

            $newProduct->discount_category = $inputData['discount_category'] ?? null;

            DB::commit();

            return new ProductapproveResource(true, true, "New Produk Berhasil ditambah", $newProduct);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}

<?php

namespace App\Http\Controllers\Outbound\Lusi;

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
use App\Models\StagingProduct;
use App\Models\UserScanWeb;
use App\Services\MovementService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;

class BastApprovalController extends Controller
{
    //
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

        $inputData = $this->prepareInputData($request, $status, $userId);

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
                        'quality' => [
                            'is_lolos'     => $oldProduct->is_lolos,
                            'is_damaged'   => $oldProduct->is_damaged,
                            'is_abnormal'  => $oldProduct->is_abnormal,
                            'is_non'       => $oldProduct->is_non,
                        ],
                    ] : null,
                    'new_value' => [
                        'barcode' => $inputData['new_barcode_product'],
                        'name_product' => $inputData['new_name_product'],
                        'qty' => $inputData['new_quantity_product'],
                        'old_price' => $inputData['old_price_product'],
                        'new_price' => $inputData['new_price_product'],
                        'category' => $inputData['new_category_product'] ?? '-',
                        'quality' => [
                            'is_lolos'     => $inputData['is_lolos'],
                            'is_damaged'   => $inputData['is_damaged'],
                            'is_abnormal'  => $inputData['is_abnormal'],
                            'is_non'       => $inputData['is_non'],
                        ],
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

            switch ($status) {

                case 'lolos':

                    $modelClass = ProductApprove::class;

                    break;

                case 'damaged':

                    $modelClass = New_product::class;

                    if ($riwayatCheck->status_file == 1) {
                        ProductDefect::create([
                            'riwayat_check_id'   => $riwayatCheck->id,
                            'code_document'      => $document->code_document,
                            'old_barcode_product' => $inputData['old_barcode_product'],
                            'new_barcode_product' => $inputData['new_barcode_product'],
                            'old_price_product'  => $inputData['old_price_product'],
                            'type'               => 'damaged',
                            'note'               => $inputData['note'],
                        ]);
                    }

                    break;

                case 'abnormal':

                    $modelClass = New_product::class;

                    if ($riwayatCheck->status_file == 1) {
                        ProductDefect::create([
                            'riwayat_check_id'   => $riwayatCheck->id,
                            'code_document'      => $document->code_document,
                            'old_barcode_product' => $inputData['old_barcode_product'],
                            'new_barcode_product' => $inputData['new_barcode_product'],
                            'old_price_product'  => $inputData['old_price_product'],
                            'type'               => 'abnormal',
                            'note'               => $inputData['note'],
                        ]);
                    }

                    break;

                case 'non':

                    $modelClass = New_product::class;

                    if ($riwayatCheck->status_file == 1) {
                        ProductDefect::create([
                            'riwayat_check_id'   => $riwayatCheck->id,
                            'code_document'      => $document->code_document,
                            'old_barcode_product' => $inputData['old_barcode_product'],
                            'new_barcode_product' => $inputData['new_barcode_product'],
                            'old_price_product'  => $inputData['old_price_product'],
                            'type'               => 'non',
                            'note'               => $inputData['note'],
                        ]);
                    }

                    break;
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

    private function prepareInputData($request, $status, $userId)
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
            'is_so',
        ]);

        /*
    |--------------------------------------------------------------------------
    | Jika harga < 100rb gunakan barcode lama
    |--------------------------------------------------------------------------
    */
        if ($inputData['old_price_product'] < 100000) {
            $inputData['new_barcode_product'] = $inputData['old_barcode_product'];
        }

        /*
    |--------------------------------------------------------------------------
    | Category
    |--------------------------------------------------------------------------
    */
        $category = Category::where(
            'name_category',
            $inputData['new_category_product']
        )->first();

        $inputData['discount_category'] = $category?->discount_category;

        /*
    |--------------------------------------------------------------------------
    | Data default
    |--------------------------------------------------------------------------
    */
        $inputData['new_date_in_product'] = Carbon::now('Asia/Jakarta')->toDateString();
        $inputData['actual_old_price_product'] = $inputData['old_price_product'];

        $inputData['type'] = 'type1';
        $inputData['is_so'] = null;
        $inputData['new_discount'] = 0;
        $inputData['user_id'] = $userId;
        $inputData['display_price'] = $inputData['new_price_product'];
        $inputData['note'] = $inputData['deskripsi'] ?? null;

        /*
    |--------------------------------------------------------------------------
    | Reset semua status
    |--------------------------------------------------------------------------
    */
        $inputData['is_lolos'] = null;
        $inputData['is_damaged'] = null;
        $inputData['is_abnormal'] = null;
        $inputData['is_non'] = null;

        /*
    |--------------------------------------------------------------------------
    | Isi hanya SATU status
    |--------------------------------------------------------------------------
    */
        switch ($status) {

            case 'lolos':
                $inputData['is_lolos'] = 'lolos';
                break;

            case 'damaged':
                $inputData['is_damaged'] = $inputData['deskripsi'];
                break;

            case 'abnormal':
                $inputData['is_abnormal'] = $inputData['deskripsi'];
                break;

            case 'non':
                $inputData['is_non'] = $inputData['deskripsi'];
                break;
        }

        /*
    |--------------------------------------------------------------------------
    | Selain lolos tidak memiliki category & price
    |--------------------------------------------------------------------------
    */
        if ($status !== 'lolos') {
            $inputData['new_category_product'] = null;
            $inputData['new_price_product'] = null;
            $inputData['display_price'] = 0;
        }

        return $inputData;
    }

    // public function addProductOld(Request $request)
    // {
    //     $userId = auth()->id();
    //     try {

    //         DB::beginTransaction();
    //         $status = $request->input('condition');

    //         $inputData = $this->prepareInputData(
    //             $request,
    //             $status,
    //             $userId
    //         );
    //         $document = Document::where('code_document', $inputData['code_document'])->first();
    //         $generate = null;

    //         $maxRetry = 5;
    //         for ($i = 0; $i < $maxRetry; $i++) {
    //             if ($document->custom_barcode) {
    //                 $generate = newBarcodeCustom($document->custom_barcode, $userId);
    //             } else {
    //                 $generate = generateNewBarcode($inputData['new_category_product']);
    //             }

    //             if (!ProductApprove::where('new_barcode_product', $generate)->exists()) {
    //                 break;
    //             }

    //             if ($i === $maxRetry - 1) {
    //                 throw new \Exception("Failed to generate unique barcode after multiple attempts.");
    //             }
    //         }

    //         $inputData['new_barcode_product'] = $generate;

    //         // Set display price
    //         $inputData['display_price'] = $inputData['new_price_product'] ?? $inputData['old_price_product'];

    //         $category = Category::where('name_category', $inputData['new_category_product'])->first();
    //         $inputData['discount_category'] = $category ? $category->discount_category : null;


    //         $user = auth()->user();
    //         $isAdminOrSpv = false;
    //         if ($user && $user->role) {
    //             $isAdminOrSpv = in_array($user->role->role_name, ['Admin', 'Spv']);
    //         }

    //         $oldProduct = Product_old::where('old_barcode_product', $inputData['old_barcode_product'])->first();
    //         $isDifferent = false;

    //         if ($oldProduct) {
    //             $nameChanged = trim($request->input('new_name_product')) !== trim($oldProduct->old_name_product);
    //             $qtyChanged = (int)$request->input('new_quantity_product') !== (int)$oldProduct->old_quantity_product;
    //             $isDifferent = ($nameChanged || $qtyChanged);
    //         }

    //         if ($isDifferent) {
    //             $historyData = [
    //                 'code_document' => $inputData['code_document'],
    //                 'barcode_product' => $inputData['new_barcode_product'],
    //                 'old_value' => $oldProduct ? [
    //                     'barcode' => $oldProduct->old_barcode_product,
    //                     'name_product' => $oldProduct->old_name_product,
    //                     'qty' => $oldProduct->old_quantity_product,
    //                     'old_price' => $oldProduct->old_price_product,
    //                     'category' => $oldProduct->new_category_product ?? '-',
    //                     'quality' => [
    //                         'is_lolos'    => $oldProduct->is_lolos,
    //                         'is_damaged'  => $oldProduct->is_damaged,
    //                         'is_abnormal' => $oldProduct->is_abnormal,
    //                         'is_non'      => $oldProduct->is_non,
    //                     ],
    //                 ] : null,
    //                 'new_value' => [
    //                     'barcode' => $inputData['new_barcode_product'],
    //                     'name_product' => $inputData['new_name_product'],
    //                     'qty' => $inputData['new_quantity_product'],
    //                     'old_price' => $inputData['old_price_product'],
    //                     'new_price' => $inputData['new_price_product'],
    //                     'category' => $inputData['new_category_product'] ?? '-',
    //                     'quality' => [
    //                         'is_lolos'    => $inputData['is_lolos'],
    //                         'is_damaged'  => $inputData['is_damaged'],
    //                         'is_abnormal' => $inputData['is_abnormal'],
    //                         'is_non'      => $inputData['is_non'],
    //                     ],
    //                 ],
    //                 'request_user_id' => $userId,
    //             ];

    //             if ($isAdminOrSpv) {
    //                 ProductEditHistory::create($historyData);
    //             } else {
    //                 // legacy bypass
    //                 $inputData['is_pending'] = false;

    //                 $roleName = $user && $user->role ? $user->role->role_name : 'Crew';

    //                 $notification = Notification::create([
    //                     'notification_name' => 'Approval Perubahan Data: ' . $inputData['new_barcode_product'],
    //                     'status' => 'approved',
    //                     'user_id' => $userId,
    //                     'role' => $roleName,
    //                 ]);

    //                 $historyData['notification_id'] = $notification->id;
    //                 $historyData['status'] = 'approved';

    //                 ProductEditHistory::create($historyData);
    //             }
    //         }

    //         $this->deleteOldProduct($inputData['code_document'], $inputData['old_barcode_product']);

    //         $riwayatCheck = RiwayatCheck::where('code_document', $request->input('code_document'))->first();
    //         $totalDataIn = 1 + $riwayatCheck->total_data_in;

    //         switch ($status) {

    //             case 'lolos':
    //                 $riwayatCheck->total_data_lolos += 1;
    //                 break;

    //             case 'damaged':
    //                 $riwayatCheck->total_data_damaged += 1;
    //                 break;

    //             case 'abnormal':
    //                 $riwayatCheck->total_data_abnormal += 1;
    //                 break;

    //             case 'non':
    //                 // Jika ada kolom total_data_non
    //                 // $riwayatCheck->total_data_non += 1;
    //                 break;
    //         }

    //         UserScanWeb::updateOrCreateDailyScan($userId, $document->id);


    //         $totalDiscrepancy = Product_old::where('code_document', $request->input('code_document'))->pluck('code_document');

    //         $riwayatCheck->update([
    //             'total_data_in' => $totalDataIn,
    //             'total_data_lolos' => $riwayatCheck->total_data_lolos,
    //             'total_data_damaged' => $riwayatCheck->total_data_damaged,
    //             'total_data_abnormal' => $riwayatCheck->total_data_abnormal,
    //             'total_discrepancy' => count($totalDiscrepancy),
    //             'status_approve' => 'pending',
    //             // persentase
    //             'percentage_total_data' => ($document->total_column_in_document / $document->total_column_in_document) * 100,
    //             'percentage_in' => ($totalDataIn / $document->total_column_in_document) * 100,
    //             'percentage_lolos' => ($riwayatCheck->total_data_lolos / $document->total_column_in_document) * 100,
    //             'percentage_damaged' => ($riwayatCheck->total_data_damaged / $document->total_column_in_document) * 100,
    //             'percentage_abnormal' => ($riwayatCheck->total_data_abnormal / $document->total_column_in_document) * 100,
    //             'percentage_discrepancy' => (count($totalDiscrepancy) / $document->total_column_in_document) * 100,
    //         ]);

    //         if ($riwayatCheck->status_file == 1 && $status !== 'lolos') {

    //             ProductDefect::create([
    //                 'riwayat_check_id'   => $riwayatCheck->id,
    //                 'code_document'      => $document->code_document,
    //                 'old_barcode_product' => $inputData['old_barcode_product'],
    //                 'new_barcode_product' => $inputData['new_barcode_product'],
    //                 'old_price_product'  => $inputData['old_price_product'],
    //                 'type'               => $status,
    //                 'note'               => $inputData['note'],
    //             ]);
    //         }

    //         $this->updateDocumentStatus($inputData['code_document']);

    //         $newProduct = ProductApprove::create($inputData);

    //         $newProduct->discount_category = $inputData['discount_category'] ?? null;

    //         DB::commit();

    //         return new ProductapproveResource(true, true, "New Produk Berhasil ditambah", $newProduct);
    //     } catch (\Exception $e) {
    //         DB::rollback();
    //         return response()->json(['error' => $e->getMessage()], 500);
    //     }
    // }

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

            $inputData = $this->prepareInputData(
                $request,
                $status,
                $userId
            );
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
                        'quality' => [
                            'is_lolos'    => $oldProduct->is_lolos,
                            'is_damaged'  => $oldProduct->is_damaged,
                            'is_abnormal' => $oldProduct->is_abnormal,
                            'is_non'      => $oldProduct->is_non,
                        ],
                    ] : null,
                    'new_value' => [
                        'barcode' => $inputData['new_barcode_product'],
                        'name_product' => $inputData['new_name_product'],
                        'qty' => $inputData['new_quantity_product'],
                        'old_price' => $inputData['old_price_product'],
                        'new_price' => $inputData['new_price_product'],
                        'category' => $inputData['new_category_product'] ?? '-',
                        'quality' => [
                            'is_lolos'    => $inputData['is_lolos'],
                            'is_damaged'  => $inputData['is_damaged'],
                            'is_abnormal' => $inputData['is_abnormal'],
                            'is_non'      => $inputData['is_non'],
                        ],
                    ],
                    'request_user_id' => $userId,
                ];

                if ($isAdminOrSpv) {
                    $historyData['notification_id'] = null;
                    $historyData['status'] = 'approved';
                    $historyData['approver_id'] = $userId;

                    ProductEditHistory::create($historyData);
                } else {
                    $inputData['is_pending'] = true;
                    $roleName = $user && $user->role ? $user->role->role_name : 'Crew';

                    $notification = Notification::create([
                        'notification_name' => 'Approval Perubahan Data: ' . $inputData['new_barcode_product'],
                        'status' => 'pending_approval',
                        'user_id' => $userId,
                        'role' => $roleName,
                    ]);

                    $historyData['notification_id'] = $notification->id;
                    $historyData['status'] = 'pending';

                    ProductEditHistory::create($historyData);
                }
            }

            $this->deleteOldProduct($inputData['code_document'], $inputData['old_barcode_product']);

            // [Movement] pending → staging_reguler / display_color
            if ($status === 'lolos') {

                $moveTo = $inputData['new_tag_product']
                    ? 'display_color'
                    : 'staging_reguler';

                try {
                    MovementService::log(
                        productId: $inputData['new_barcode_product'],
                        isSku: false,
                        type: 'In',
                        typeOut: null,
                        from: 'pending',
                        to: $moveTo,
                        qty: $inputData['new_quantity_product']
                    );
                } catch (\Exception $e) {
                    Log::error('[Movement] QC scan (addProductOld) log failed: ' . $e->getMessage());
                }
            }

            $riwayatCheck = RiwayatCheck::where('code_document', $request->input('code_document'))->first();
            $totalDataIn = 1 + $riwayatCheck->total_data_in;

            switch ($status) {

                case 'lolos':
                    $riwayatCheck->total_data_lolos++;
                    break;

                case 'damaged':
                    $riwayatCheck->total_data_damaged++;
                    break;

                case 'abnormal':
                    $riwayatCheck->total_data_abnormal++;
                    break;

                case 'non':
                    // jika ada total_data_non
                    // $riwayatCheck->total_data_non++;
                    break;
            }

            if ($riwayatCheck->status_file == 1 && $status !== 'lolos') {

                ProductDefect::create([
                    'riwayat_check_id'    => $riwayatCheck->id,
                    'code_document'       => $document->code_document,
                    'old_barcode_product' => $inputData['old_barcode_product'],
                    'new_barcode_product' => $inputData['new_barcode_product'],
                    'old_price_product'   => $inputData['old_price_product'],
                    'type'                => $status,
                    'note'                => $inputData['note'],
                ]);
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

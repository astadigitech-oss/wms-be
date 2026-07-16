<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\BagProducts;
use App\Models\BklProduct;
use App\Models\BulkyDocument;
use App\Models\BulkySale;
use App\Models\Bundle;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BagController extends Controller
{
    //tambah bag
    public function tambahProdukKeBag(Request $request, $idBag)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'barcode_product' => 'required',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(
                false,
                "Input tidak valid!",
                $validator->errors()
            ))->response()->setStatusCode(422);
        }

        /*
    |--------------------------------------------------------------------------
    | CHECK BAG
    |--------------------------------------------------------------------------
    */

        $bagProduct = BagProducts::where('id', $idBag)
            ->where('user_id', $user->id)
            ->where('status', 'process')
            ->first();

        if (!$bagProduct) {

            return (new ResponseResource(
                false,
                "Tidak bisa akses bag atau bag tidak ditemukan!",
                []
            ))->response()->setStatusCode(403);
        }

        /*
    |--------------------------------------------------------------------------
    | OPTIONAL BULKY DOCUMENT
    |--------------------------------------------------------------------------
    */

        $bulkyDocument = null;

        if ($bagProduct->bulky_document_id) {

            $bulkyDocument = BulkyDocument::where(
                'id',
                $bagProduct->bulky_document_id
            )->first();

            if (!$bulkyDocument) {

                return (new ResponseResource(
                    false,
                    "Cargo tidak ditemukan!",
                    []
                ))->response()->setStatusCode(404);
            }
        }

        /*
    |--------------------------------------------------------------------------
    | LOCK BARCODE
    |--------------------------------------------------------------------------
    */

        $lockKey = "barcode:{$request->barcode_product}";
        $lock = cache()->lock($lockKey, 5);

        if (!$lock->get()) {

            return (new ResponseResource(
                false,
                "Data sedang diproses!",
                []
            ))->response()->setStatusCode(422);
        }

        DB::beginTransaction();

        try {

            /*
        |--------------------------------------------------------------------------
        | CHECK DUPLICATE
        |--------------------------------------------------------------------------
        */

            $productBulkySale = BulkySale::where(
                'barcode_bulky_sale',
                $request->barcode_product
            )
                ->lockForUpdate()
                ->first();

            if ($productBulkySale) {

                DB::rollBack();
                $lock->release();

                return (new ResponseResource(
                    false,
                    "Barcode sudah pernah diinputkan!",
                    []
                ))->response()->setStatusCode(422);
            }

            /*
        |--------------------------------------------------------------------------
        | FIND PRODUCT
        |--------------------------------------------------------------------------
        */

            $models = [
                'new_product' => New_product::where(
                    'new_barcode_product',
                    $request->barcode_product
                )->first(),

                'staging_product' => StagingProduct::where(
                    'new_barcode_product',
                    $request->barcode_product
                )->first(),

                'bundle_product' => Bundle::where(
                    'barcode_bundle',
                    $request->barcode_product
                )->first(),

                'bkl_product' => BklProduct::where(
                    'new_barcode_product',
                    $request->barcode_product
                )->first(),
            ];

            $product = null;
            $foundType = null;
            $foundModel = null;

            foreach ($models as $type => $model) {

                if (!$model) {
                    continue;
                }

                $status = match ($type) {
                    'new_product', 'staging_product', 'bkl_product'
                    => $model->new_status_product,

                    'bundle_product'
                    => $model->product_status,
                };

                if (in_array($status, ['sale', 'cargo'], true)) {

                    DB::rollBack();
                    $lock->release();

                    return (new ResponseResource(
                        false,
                        "Barcode sudah pernah diinputkan (Terjual/Cargo)!",
                        []
                    ))->response()->setStatusCode(422);
                }

                $product = match ($type) {

                    'new_product', 'staging_product', 'bkl_product' => [
                        'barcode' => $model->new_barcode_product,
                        'category' => $model->new_category_product,
                        'tag' => $model->new_tag_product,
                        'name' => $model->new_name_product,
                        'old_price' => $model->old_price_product,
                        'status' => $model->new_status_product,
                        'qty' => $model->new_quantity_product ?? null,
                        'code_document' => $model->code_document ?? null,
                        'old_barcode_product' => $model->old_barcode_product ?? null,
                        'new_date_in_product' => $model->new_date_in_product ?? null,
                        'display_price' => $model->display_price ?? null,
                        'created_at' => $model->created_at,
                        'actual_old_price_product' =>
                        $model->actual_old_price_product
                            ?? $model->old_price_product,
                        'weight' => $model->weight ?? null,
                    ],

                    'bundle_product' => [
                        'barcode' => $model->barcode_bundle,
                        'category' => $model->category,
                        'tag' => $model->name_color,
                        'name' => $model->name_bundle,
                        'old_price' => $model->total_price_bundle,
                        'status' => $model->product_status,
                        'qty' => $model->total_product_bundle ?? null,
                        'code_document' =>
                        $model->product_bundles->first()?->code_document,
                        'old_barcode_product' =>
                        $model->product_bundles->first()?->old_barcode_product,
                        'new_date_in_product' =>
                        $model->product_bundles->first()?->date_in_product,
                        'display_price' =>
                        $model->product_bundles->first()?->display_price,
                        'created_at' => $model->created_at,
                        'actual_old_price_product' =>
                        $model->product_bundles
                            ->sum('actual_old_price_product') ?? 0,
                        'weight' => null,
                    ],
                };

                $foundType = $type;
                $foundModel = $model;

                break;
            }

            if (!$product) {

                DB::rollBack();
                $lock->release();

                return (new ResponseResource(
                    false,
                    "Barcode tidak ditemukan!",
                    []
                ))->response()->setStatusCode(404);
            }

            /*
        |--------------------------------------------------------------------------
        | VALIDASI CATEGORY BAG
        |--------------------------------------------------------------------------
        */

            $bagValue = strtolower(trim($bagProduct->category_bag));

            $productCat = strtolower(trim($product['category'] ?? ''));

            $cleanProductCat = trim(
                preg_replace('/[^a-z]+/i', ' ', $productCat)
            );

            $cleanBagValue = trim(
                preg_replace('/[^a-z]+/i', ' ', $bagValue)
            );

            $productWords = array_filter(
                explode(' ', $cleanProductCat)
            );

            $bagWords = array_filter(
                explode(' ', $cleanBagValue)
            );

            $commonWords = array_intersect(
                $productWords,
                $bagWords
            );

            if (empty($commonWords)) {

                DB::rollBack();
                $lock->release();

                return (new ResponseResource(
                    false,
                    "Kategori produk tidak cocok dengan karung!",
                    []
                ))->response()->setStatusCode(422);
            }

            /*
        |--------------------------------------------------------------------------
        | UPDATE PRODUCT STATUS
        |--------------------------------------------------------------------------
        */

            match ($foundType) {

                'new_product', 'staging_product', 'bkl_product'
                => $foundModel->update([
                    'new_status_product' => 'cargo',
                    'date_out' => now(),
                    'type_out' => 'cargo',
                ]),

                'bundle_product'
                => $foundModel->update([
                    'product_status' => 'cargo'
                ]),
            };

            /*
        |--------------------------------------------------------------------------
        | CALCULATE DISCOUNT
        |--------------------------------------------------------------------------
        */

            $discount = $bulkyDocument?->discount_bulky ?? 0;

            $afterPriceBulkySale =
                $product['old_price']
                - ($product['old_price'] * $discount / 100);

            /*
        |--------------------------------------------------------------------------
        | STORE BULKY SALE
        |--------------------------------------------------------------------------
        */

            $bulkySale = BulkySale::create([
                'bulky_document_id' => $bulkyDocument?->id,
                'bag_product_id' => $bagProduct->id,
                'barcode_bulky_sale' => $product['barcode'],
                'product_category_bulky_sale' =>
                $product['category'] ?? null,
                'name_product_bulky_sale' =>
                $product['name'] ?? null,
                'old_price_bulky_sale' =>
                $product['old_price'] ?? 0,
                'status_product_before' =>
                $product['status'] ?? null,
                'after_price_bulky_sale' =>
                $afterPriceBulkySale,
                'qty' => $product['qty'] ?? null,
                'code_document' =>
                $product['code_document'] ?? null,
                'old_barcode_product' =>
                $product['old_barcode_product'] ?? null,
                'new_date_in_product' =>
                $product['new_date_in_product'] ?? null,
                'display_price' =>
                $product['display_price'] ?? 0,
                'actual_created_at' =>
                $product['created_at'] ?? null,
                'actual_old_price_product' =>
                $product['actual_old_price_product'] ?? null,
                'weight' =>
                $product['weight'] ?? null,
            ]);

            /*
        |--------------------------------------------------------------------------
        | UPDATE BAG
        |--------------------------------------------------------------------------
        */

            $bagProduct->update([
                'total_product' =>
                $bagProduct->bulkySales()->count(),
            ]);

            /*
        |--------------------------------------------------------------------------
        | UPDATE BULKY DOCUMENT IF EXISTS
        |--------------------------------------------------------------------------
        */

            if ($bulkyDocument) {

                $allBagIds = BagProducts::where(
                    'bulky_document_id',
                    $bulkyDocument->id
                )->pluck('id');

                $allBulkySales = BulkySale::whereIn(
                    'bag_product_id',
                    $allBagIds
                );

                $bulkyDocument->update([
                    'total_product_bulky' =>
                    $allBulkySales->count(),

                    'total_old_price_bulky' =>
                    $allBulkySales->sum(
                        'old_price_bulky_sale'
                    ),

                    'after_price_bulky' =>
                    $allBulkySales->sum(
                        'after_price_bulky_sale'
                    ),
                ]);
            }

            DB::commit();
            $lock->release();

            return (new ResponseResource(
                true,
                "Berhasil masuk ke karung!",
                $bulkySale
            ))->response();
        } catch (\Exception $e) {

            DB::rollBack();
            $lock->release();

            Log::error(
                'TAMBAH PRODUK BAG ERROR: '
                    . $e->getMessage()
            );

            return (new ResponseResource(
                false,
                "Gagal menyimpan produk!",
                $e->getMessage()
            ))->response()->setStatusCode(500);
        }
    }
}

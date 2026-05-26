<?php

namespace App\Http\Controllers\Outbound;

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
    public function index(Request $request)
    {
        try {

            $q = $request->q;

            $bag = BagProducts::when($q, function ($query) use ($q) {

                $query->where(function ($subQuery) use ($q) {

                    $subQuery->where('name_bag', 'like', '%' . $q . '%')
                        ->orWhere('barcode_bag', 'like', '%' . $q . '%');
                });
            })
                ->latest()
                ->paginate(10);

            return (new ResponseResource(
                true,
                'Success',
                $bag
            ))->response()->setStatusCode(200);
        } catch (\Exception $e) {

            Log::error('GET BAG ERROR: ' . $e->getMessage());

            return (new ResponseResource(
                false,
                'Failed to get bags',
                $e->getMessage()
            ))->response()->setStatusCode(500);
        }
    }

    public function buatBag(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make(
            $request->all(),
            [
                'category_id' => 'exists:categories,id',
            ]
        );

        if ($validator->fails()) {
            return (new ResponseResource(false, 'Validation error', $validator->errors()))
                ->response()->setStatusCode(422);
        }

        DB::beginTransaction();

        try {

            $bagCategoryId = null;
            $bagCategoryName = null;

            $category = \App\Models\Category::find($request->category_id);
            // dd($category);

            if (!$category) {
                DB::rollBack();

                return (new ResponseResource(false, "Category tidak ditemukan", null))
                    ->response()->setStatusCode(404);
            }

            $bagCategoryId = $category->id;
            $bagCategoryName = $category->name_category;


            $barcode = barcodeBag($user->id);

            if (!$barcode) {

                DB::rollBack();

                return (new ResponseResource(false, "Gagal membuat barcode", null))
                    ->response()->setStatusCode(500);
            }

            $username = strtolower(substr($user->username, 0, 3));

            $lastBag = BagProducts::where('user_id', $user->id)
                ->where('name_bag', 'like', $username . '-%')
                ->orderByDesc('id')
                ->first();

            $nextNumber = 1;

            if (
                $lastBag &&
                preg_match('/^' . $username . '\-(\d+)$/', $lastBag->name_bag, $matches)
            ) {
                $nextNumber = intval($matches[1]) + 1;
            }

            $name_bag = $username . '-' . $nextNumber;

            $bag = new BagProducts();
            $bag->user_id = $user->id;
            $bag->name_bag = $name_bag;
            $bag->type = 'category';
            $bag->barcode_bag = $barcode;
            $bag->category_id = $bagCategoryId;
            $bag->category_bag = $bagCategoryName;
            $bag->total_product = 0;
            $bag->status = 'process';
            $bag->save();

            DB::commit();

            return (new ResponseResource(true, "Berhasil membuat bag", $bag))
                ->response();
        } catch (\Exception $e) {

            DB::rollBack();

            return (new ResponseResource(
                false,
                "Gagal membuat bag: " . $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

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

                if ($status === 'sale') {

                    DB::rollBack();
                    $lock->release();

                    return (new ResponseResource(
                        false,
                        "Barcode sudah pernah diinputkan (Terjual)!",
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
                    'new_status_product' => 'sale',
                    'date_out' => now(),
                    'type_out' => 'cargo',
                ]),

                'bundle_product'
                => $foundModel->update([
                    'product_status' => 'sale'
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

    public function listProdukBag(Request $request, $idBag)
    {
        try {

            $user = auth()->user();

            $q = $request->q;

            $bagProduct = BagProducts::where('id', $idBag)
                ->where('user_id', $user->id)
                ->first();

            if (!$bagProduct) {

                return (new ResponseResource(
                    false,
                    "Karung produk tidak ditemukan!",
                    []
                ))->response()->setStatusCode(404);
            }

            $products = $bagProduct->bulkySales()
                ->select([
                    'id',
                    'barcode_bulky_sale',
                    'name_product_bulky_sale',
                    'product_category_bulky_sale',
                    'old_price_bulky_sale',
                    'qty',
                    'weight',
                    'created_at',
                ])
                ->when($q, function ($query) use ($q) {

                    $query->where(function ($subQuery) use ($q) {

                        $subQuery->where('barcode_bulky_sale', 'like', '%' . $q . '%')
                            ->orWhere('name_product_bulky_sale', 'like', '%' . $q . '%')
                            ->orWhere('product_category_bulky_sale', 'like', '%' . $q . '%');
                    });
                })
                ->latest()
                ->paginate(10);

            return (new ResponseResource(
                true,
                "Daftar produk dalam karung",
                $products
            ))->response();
        } catch (\Exception $e) {

            Log::error('LIST PRODUK BAG ERROR: ' . $e->getMessage());

            return (new ResponseResource(
                false,
                "Gagal mengambil daftar produk!",
                $e->getMessage()
            ))->response()->setStatusCode(500);
        }
    }

    public function infoDetailBag($idBag)
    {
        try {

            $bagProduct = BagProducts::where('id', $idBag)
                ->first();

            if (!$bagProduct) {

                return (new ResponseResource(
                    false,
                    "Karung produk tidak ditemukan!",
                    []
                ))->response()->setStatusCode(404);
            }

            /*
    |--------------------------------------------------------------------------
    | AGGREGATE FROM DB
    |--------------------------------------------------------------------------
    */

            $summary = BulkySale::where('bag_product_id', $bagProduct->id)
                ->selectRaw('
                COUNT(*) as total_product,
                COALESCE(SUM(old_price_bulky_sale), 0) as total_old_price_bulky_sale
            ')
                ->first();

            $data = [
                'id' => $bagProduct->id,
                'name_bag' => $bagProduct->name_bag,
                'barcode_bag' => $bagProduct->barcode_bag,
                'category_bag' => $bagProduct->category_bag,
                'status' => $bagProduct->status,
                'total_product' => (int) $summary->total_product,
                'total_old_price_bulky_sale' => (int) $summary->total_old_price_bulky_sale,
                'created_at' => $bagProduct->created_at,
            ];

            return (new ResponseResource(
                true,
                "Detail karung",
                $data
            ))->response();
        } catch (\Exception $e) {

            Log::error('INFO DETAIL BAG ERROR: ' . $e->getMessage());

            return (new ResponseResource(
                false,
                "Gagal mengambil detail karung!",
                $e->getMessage()
            ))->response()->setStatusCode(500);
        }
    }

    public function takeOutBarangbulky(BulkySale $idProduct)
    {
        DB::beginTransaction();

        try {

            $bagProduct = BagProducts::find($idProduct->bag_product_id);

            if (!$bagProduct) {
                DB::rollBack();

                return (new ResponseResource(
                    false,
                    "Karung tidak ditemukan!",
                    []
                ))->response()->setStatusCode(404);
            }

            /*
        |--------------------------------------------------------------------------
        | CHECK BULKY DOCUMENT
        |--------------------------------------------------------------------------
        */

            $bulkyDocument = null;

            if ($bagProduct->bulky_document_id) {

                $bulkyDocument = BulkyDocument::where('id', $bagProduct->bulky_document_id)
                    ->where('status_bulky', 'proses')
                    ->first();

                if (!$bulkyDocument) {

                    DB::rollBack();

                    return (new ResponseResource(
                        false,
                        "Dokumen bulky sudah selesai / tidak ditemukan!",
                        []
                    ))->response()->setStatusCode(422);
                }
            }

            /*
        |--------------------------------------------------------------------------
        | FIND PRODUCT
        |--------------------------------------------------------------------------
        */

            $models = [
                'new_product' => New_product::where(
                    'new_barcode_product',
                    $idProduct->barcode_bulky_sale
                )->first(),

                'staging_product' => StagingProduct::where(
                    'new_barcode_product',
                    $idProduct->barcode_bulky_sale
                )->first(),

                'bundle_product' => Bundle::where(
                    'barcode_bundle',
                    $idProduct->barcode_bulky_sale
                )->first(),

                'bkl_product' => BklProduct::where(
                    'new_barcode_product',
                    $idProduct->barcode_bulky_sale
                )->first(),
            ];

            /*
        |--------------------------------------------------------------------------
        | ROLLBACK STATUS
        |--------------------------------------------------------------------------
        */

            foreach ($models as $type => $model) {

                if (!$model) {
                    continue;
                }

                match ($type) {

                    'new_product',
                    'staging_product',
                    'bkl_product' => $model->update([
                        'new_status_product' => $idProduct->status_product_before,
                        'date_out' => null,
                        'type_out' => null,
                    ]),

                    'bundle_product' => $model->update([
                        'product_status' => $idProduct->status_product_before
                    ]),
                };

                break;
            }

            /*
        |--------------------------------------------------------------------------
        | DELETE BULKY SALE
        |--------------------------------------------------------------------------
        */

            $idProduct->delete();

            /*
        |--------------------------------------------------------------------------
        | UPDATE BAG TOTAL
        |--------------------------------------------------------------------------
        */

            $bagProduct->update([
                'total_product' => $bagProduct->bulkySales()->count()
            ]);

            /*
        |--------------------------------------------------------------------------
        | UPDATE BULKY DOCUMENT
        |--------------------------------------------------------------------------
        */

            if ($bulkyDocument) {

                $bulkyDocument->update([
                    'total_product_bulky' => $bulkyDocument->bulkySales()->count(),
                    'total_old_price_bulky' => $bulkyDocument->bulkySales()->sum('old_price_bulky_sale'),
                    'after_price_bulky' => $bulkyDocument->bulkySales()->sum('after_price_bulky_sale'),
                ]);
            }

            DB::commit();

            return (new ResponseResource(
                true,
                "Produk berhasil dikeluarkan dari karung!",
                []
            ))->response();
        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('REMOVE PRODUCT BAG ERROR: ' . $e->getMessage());

            return (new ResponseResource(
                false,
                "Gagal menghapus produk dari karung!",
                $e->getMessage()
            ))->response()->setStatusCode(500);
        }
    }
}

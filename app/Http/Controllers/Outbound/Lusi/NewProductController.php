<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Category;
use App\Models\Color_tag;
use App\Models\New_product;
use App\Models\Rack;
use App\Models\SoColor;
use App\Models\StagingProduct;
use App\Models\SummarySoCategory;
use App\Models\SummarySoColor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NewProductController extends Controller
{
    //
    public function updateToDamaged(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id'  => 'required|integer',
            'source'      => 'required|in:staging,display',
            'description' => 'required|string|min:3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = auth()->id();
        $source = $request->source;
        $id = $request->product_id;
        $description = $request->description;

        DB::beginTransaction();

        try {

            $product = ($source === 'staging')
                ? StagingProduct::find($id)
                : New_product::find($id);

            if (!$product) {
                return new ResponseResource(
                    false,
                    "Produk tidak ditemukan di " . ucfirst($source),
                    null
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Ambil status dari JSON new_quality
        |--------------------------------------------------------------------------
        */
            $quality = json_decode($product->new_quality ?? '{}', true);

            $status = null;

            if (($quality['lolos'] ?? null) === 'lolos') {
                $status = 'lolos';
            } elseif (!empty($quality['damaged'])) {
                $status = 'damaged';
            } elseif (!empty($quality['abnormal'])) {
                $status = 'abnormal';
            } elseif (!empty($quality['non'])) {
                $status = 'non';
            }

            /*
        |--------------------------------------------------------------------------
        | Hanya produk LOLOS yang boleh diubah menjadi DAMAGED
        |--------------------------------------------------------------------------
        */
            if ($status !== 'lolos') {
                return new ResponseResource(
                    false,
                    "Gagal: Produk bukan 'Lolos'. Status saat ini : " . ($status ?? 'unknown'),
                    null
                );
            }

            $previousRackId = $product->rack_id;
            $sourceType = $source;

            /*
       /*
|--------------------------------------------------------------------------
| Update Quality
|--------------------------------------------------------------------------
| Sinkronkan JSON dan kolom status
*/
            $newQuality = [
                'lolos'     => null,
                'damaged'   => $description,
                'abnormal'  => null,
                'non'       => null,
            ];

            $product->new_quality = json_encode($newQuality);
            $product->actual_new_quality = json_encode($newQuality);

            // Hanya satu status yang boleh terisi
            $product->is_lolos = null;
            $product->is_damaged = $description;
            $product->is_abnormal = null;
            $product->is_non = null;

            $product->rack_id = null;

            /*
        |--------------------------------------------------------------------------
        | Stock Opname
        |--------------------------------------------------------------------------
        */
            $checkSoCategory = SummarySoCategory::where('type', 'process')->first();
            $checkSoColor = SummarySoColor::where('type', 'process')->first();

            $isAffectedBySO = false;
            $wasAlreadyScanned = ($product->is_so === 'check');

            if ($checkSoCategory && $product->new_category_product) {

                $isAffectedBySO = true;

                if ($wasAlreadyScanned && $checkSoCategory->product_inventory > 0) {
                    $checkSoCategory->decrement('product_inventory');
                }

                $checkSoCategory->increment('product_damaged');
            }

            if ($checkSoColor && $product->new_tag_product) {

                $soColor = SoColor::where(
                    'summary_so_color_id',
                    $checkSoColor->id
                )
                    ->where('color', $product->new_tag_product)
                    ->first();

                if ($soColor) {

                    $isAffectedBySO = true;

                    if ($wasAlreadyScanned && $soColor->total_color > 0) {
                        $soColor->decrement('total_color');
                    }

                    $soColor->increment('product_damaged');
                }
            }

            if ($isAffectedBySO) {
                $product->is_so = 'check';
                $product->user_so = $userId;
            }

            $product->save();

            /*
        |--------------------------------------------------------------------------
        | Update Rack
        |--------------------------------------------------------------------------
        */
            if ($previousRackId) {

                $rack = Rack::find($previousRackId);

                if ($rack) {

                    $products = ($sourceType === 'staging')
                        ? $rack->stagingProducts()
                        : $rack->newProducts();

                    $rack->update([
                        'total_data'                  => $products->count(),
                        'total_new_price_product'     => $products->sum('new_price_product'),
                        'total_old_price_product'     => $products->sum('old_price_product'),
                        'total_display_price_product' => $products->sum('display_price'),
                    ]);
                }
            }

            logUserAction(
                $request,
                $request->user(),
                "Inventory/Damage",
                "Mengubah status product menjadi DAMAGED. Barcode: {$product->new_barcode_product}"
            );

            DB::commit();

            return new ResponseResource(
                true,
                "Produk berhasil diubah statusnya menjadi Damaged",
                $product
            );
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'error' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateRepair(Request $request, $id)
    {
        $user_id = auth()->id();
        $source = $request->query('source', 'new_product');
        try {
            $product = null;

            if ($source === 'staging') {
                $product = StagingProduct::find($id);
            } else {
                $product = New_product::find($id);
            }

            if (!$product) {
                return new ResponseResource(false, "Produk tidak ditemukan di $source", null);
            }

            $quality = json_decode($product->new_quality ?? '{}', true);

            $status = null;

            if (($quality['lolos'] ?? null) === 'lolos') {
                $status = 'lolos';
            } elseif (!empty($quality['damaged'])) {
                $status = 'damaged';
            } elseif (!empty($quality['abnormal'])) {
                $status = 'abnormal';
            } elseif (!empty($quality['non'])) {
                $status = 'non';
            }

            if ($status === 'lolos') {
                return new ResponseResource(
                    false,
                    "Hanya produk yang damaged, abnormal atau non yang bisa di repair",
                    null
                );
            }

            $validator = Validator::make($request->all(), [
                'old_barcode_product' => 'nullable',
                'new_barcode_product' => 'required',
                'new_name_product' => 'required',
                'new_quantity_product' => 'required|integer',
                'new_price_product' => 'required|numeric',
                'old_price_product' => 'required|numeric',
                'new_status_product' => 'required|in:display,expired,promo,bundle,palet',
                'new_category_product' => 'nullable|exists:categories,name_category',
                'new_tag_product' => 'nullable|exists:color_tags,name_color',
                'is_extra' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $inputData = $request->only([
                'old_barcode_product',
                'new_barcode_product',
                'new_name_product',
                'new_quantity_product',
                'new_price_product',
                'old_price_product',
                'new_date_in_product',
                'new_status_product',
                'new_category_product',
                'new_tag_product',
                'type',
                'is_extra',
            ]);

            $indonesiaTime = Carbon::now('Asia/Jakarta');
            $inputData['new_date_in_product'] = $indonesiaTime->toDateString();


            if ($inputData['old_price_product'] >= 100000) {
                $inputData['new_tag_product'] = null;

                if (empty($inputData['new_category_product'])) {
                    return (new ResponseResource(false, "Kategori produk wajib diisi untuk harga di atas 100k.", null))
                        ->response()->setStatusCode(422);
                }

                $category = Category::where('name_category', $inputData['new_category_product'])->first();
                if (!$category) {
                    return (new ResponseResource(false, "Kategori '" . $inputData['new_category_product'] . "' tidak ditemukan.", null))
                        ->response()->setStatusCode(422);
                }

                if (isset($category->discount_category) && $category->discount_category > 0) {
                    $discountAmount = ($category->discount_category / 100) * $inputData['old_price_product'];
                    $calculatedPrice = $inputData['old_price_product'] - $discountAmount;
                    $calculatedPriceFinal = round($calculatedPrice);
                    $inputPrice = $inputData['new_price_product'];

                    if (round($calculatedPriceFinal) != round($inputPrice)) {
                        $errorMsg = "Harga setelah diskon kategori tidak sesuai. Harap periksa kembali.";

                        return (new ResponseResource(false, $errorMsg, null))
                            ->response()->setStatusCode(422);
                    }
                }
            }

            $newQuality = [
                'lolos'     => 'lolos',
                'damaged'   => null,
                'abnormal'  => null,
                'non'       => null,
            ];

            $inputData['new_quality'] = json_encode($newQuality);
            $inputData['actual_new_quality'] = json_encode($newQuality);

            /*
|--------------------------------------------------------------------------
| Sinkronkan kolom baru
|--------------------------------------------------------------------------
*/
            $inputData['is_lolos'] = 'lolos';
            $inputData['is_damaged'] = null;
            $inputData['is_abnormal'] = null;
            $inputData['is_non'] = null;
            $inputData['user_id'] = $user_id;
            $inputData['display_price'] = $inputData['new_price_product'];
            $inputData['is_extra'] = $request->boolean('is_extra');

            $inputData['code_document'] = $product->code_document;
            $inputData['actual_old_price_product'] = $product->actual_old_price_product;
            $inputData['weight'] = $product->weight;

            // ===============================
            // PRIORITAS 1 : HARGA < 100.000
            // ===============================
            if ($inputData['old_price_product'] < 100000) {

                // Wajib kategori null
                $inputData['new_category_product'] = null;

                // Ambil color tag
                $colortag = Color_tag::where('min_price_color', '<=', $inputData['old_price_product'])
                    ->where('max_price_color', '>=', $inputData['old_price_product'])
                    ->select('fixed_price_color', 'name_color')
                    ->first();

                if (!$colortag) {
                    return (new ResponseResource(false, "Color tag tidak ditemukan.", null))
                        ->response()->setStatusCode(422);
                }

                $inputData['new_tag_product'] = $colortag->name_color;
                $inputData['new_price_product'] = $colortag->fixed_price_color;
                $inputData['display_price'] = $colortag->fixed_price_color;

                // Sesuaikan source
                if ($source === 'staging') {
                    // Pindahkan dari staging ke new_product
                    New_product::create($inputData);
                    $product->delete();
                } else {
                    // Sudah di new_product, cukup update
                    $product->update($inputData);
                }
            } else {

                // ===============================
                // PRIORITAS 2 : HARGA >= 100.000
                // ===============================

                $inputData['new_tag_product'] = null;

                // Ikuti flow yang sudah ada
                if ($source === 'staging') {
                    $product->update($inputData);
                } else {
                    StagingProduct::create($inputData);
                    $product->delete();
                }
            }

            return new ResponseResource(true, "Berhasil di repair dan masuk ke staging", $inputData);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), null);
        }
    }
}

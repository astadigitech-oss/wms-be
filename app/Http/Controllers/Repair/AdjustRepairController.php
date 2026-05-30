<?php

namespace App\Http\Controllers\Repair;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Category;
use App\Models\Color_tag;
use App\Models\StagingProduct;
use App\Models\New_product;
use Carbon\Carbon;;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdjustRepairController extends Controller
{

    public function updateRepair(Request $request, $id)
    {
        $user_id = auth()->id();
        $source = $request->query('source', 'new_product');

        DB::beginTransaction();

        try {

            // =========================
            // 1. GET PRODUCT
            // =========================
            $product = $source === 'staging'
                ? StagingProduct::find($id)
                : New_product::find($id);

            if (!$product) {
                DB::rollBack();
                return new ResponseResource(false, "Produk tidak ditemukan di $source", null);
            }

            // =========================
            // 2. QUALITY
            // =========================
            $quality = json_decode($product->new_quality, true);

            if (isset($quality['lolos'])) {
                DB::rollBack();
                return new ResponseResource(false, "Produk sudah lolos, tidak bisa direpair", null);
            }

            unset($quality['damaged'], $quality['abnormal'], $quality['non']);

            // =========================
            // 3. VALIDATION RULES
            // =========================
            if ($request->input('old_price_product') < 100000) {
                $request->request->remove('new_tag_product');
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
                DB::rollBack();
                return response()->json(['errors' => $validator->errors()], 422);
            }

            // =========================
            // 4. INPUT
            // =========================
            $inputData = $request->only([
                'old_barcode_product',
                'new_barcode_product',
                'new_name_product',
                'new_quantity_product',
                'new_price_product',
                'old_price_product',
                'new_status_product',
                'new_category_product',
                'new_tag_product',
                'type',
                'is_extra',
            ]);

            $inputData['new_date_in_product'] = Carbon::now('Asia/Jakarta')->toDateString();

            $oldPrice = $inputData['old_price_product'];

            // =========================
            // 5. RULE >= 100K
            // =========================
            if ($oldPrice >= 100000) {

                $inputData['new_tag_product'] = null;

                if (empty($inputData['new_category_product'])) {
                    DB::rollBack();
                    return (new ResponseResource(false, "Kategori wajib untuk harga ≥ 100k", null))
                        ->response()->setStatusCode(422);
                }

                $category = Category::where('name_category', $inputData['new_category_product'])->first();

                if (!$category) {
                    DB::rollBack();
                    return (new ResponseResource(false, "Kategori tidak ditemukan", null))
                        ->response()->setStatusCode(422);
                }

                if (!empty($category->discount_category)) {

                    $discount = ($category->discount_category / 100) * $oldPrice;
                    $final = round($oldPrice - $discount);

                    if ($final != round($inputData['new_price_product'])) {
                        DB::rollBack();
                        return (new ResponseResource(false, "Harga setelah diskon tidak sesuai", null))
                            ->response()->setStatusCode(422);
                    }
                }
            }

            // =========================
            // 6. FINALIZE QUALITY
            // =========================
            $quality['lolos'] = 'lolos';
            $inputData['new_quality'] = json_encode($quality);

            $inputData['user_id'] = $user_id;
            $inputData['display_price'] = $inputData['new_price_product'];
            $inputData['is_extra'] = $request->boolean('is_extra');

            $inputData['code_document'] = $product->code_document;
            $inputData['actual_new_quality'] = $product->actual_new_quality;
            $inputData['actual_old_price_product'] = $product->actual_old_price_product;
            $inputData['weight'] = $product->weight;

            // =========================
            // 7. RULE < 100K (COLORTAG)
            // =========================
            if ($oldPrice < 100000) {

                $inputData['new_category_product'] = null;

                $colortag = Color_tag::where('min_price_color', '<=', $oldPrice)
                    ->where('max_price_color', '>=', $oldPrice)
                    ->first();

                if ($colortag) {
                    $inputData['new_price_product'] = $colortag->fixed_price_color;
                    $inputData['display_price'] = $colortag->fixed_price_color;
                    $inputData['new_tag_product'] = $colortag->name_color;
                }
            }

            // =========================
            // 8. FLOW LOGIC
            // =========================

            if ($source === 'staging') {

                if ($oldPrice < 100000) {

                    New_product::create($inputData);
                    $product->delete();
                } else {

                    $product->update($inputData);
                }
            } else {

                $product->update($inputData);
            }

            DB::commit();

            return new ResponseResource(true, "Berhasil repair", $inputData);
        } catch (\Exception $e) {

            DB::rollBack();

            return new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), null);
        }
    }
}

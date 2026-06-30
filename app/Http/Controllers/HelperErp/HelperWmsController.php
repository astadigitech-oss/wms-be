<?php

namespace App\Http\Controllers\HelperErp;

use App\Http\Controllers\Controller;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HelperWmsController extends Controller
{

    public function migrateStickerAbnormal()
    {
        DB::beginTransaction();

        try {

            $products = StagingProduct::where('new_barcode_product', 'like', '%331L%')
                ->whereRaw("JSON_EXTRACT(actual_new_quality, '$.abnormal') IS NOT NULL")
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(actual_new_quality, '$.abnormal')) <> 'null'")
                ->whereNotNull('new_tag_product')
                ->whereNull('new_category_product')
                ->orderBy('actual_new_quality')
                ->get();

            $total = 0;

            foreach ($products as $product) {

                $data = $product->toArray();

                unset(
                    $data['id'],
                    $data['stage'],
                    $data['created_at'],
                    $data['updated_at']
                );

                New_product::create($data);

                $product->delete();

                $total++;
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => "Berhasil memindahkan {$total} produk ke new_products."
            ]);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteMigratedStickerAbnormal()
    {
        DB::beginTransaction();

        try {

            $deleted = StagingProduct::where('new_barcode_product', 'like', '%331L%')
                ->whereRaw("JSON_EXTRACT(actual_new_quality, '$.abnormal') IS NOT NULL")
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(actual_new_quality, '$.abnormal')) <> 'null'")
                ->whereNotNull('new_tag_product')
                ->whereNull('new_category_product')
                ->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => "Berhasil menghapus {$deleted} data dari staging_products."
            ]);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

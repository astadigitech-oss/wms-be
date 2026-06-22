<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NewSkuController extends Controller
{
    public function checkSkuPrice()
    {
        $staging = DB::table('staging_products as sp')
            ->join('sku_products as sku', 'sku.barcode_product', '=', 'sp.old_barcode_product')
            ->where('sp.code_document', 'like', 'SKU%')
            ->selectRaw("
            COUNT(
                CASE
                    WHEN sku.price_product > 0
                    AND MOD(sp.old_price_product, sku.price_product) = 0
                    THEN 1
                END
            ) as divisible_count,

            COUNT(
                CASE
                    WHEN sku.price_product > 0
                    AND MOD(sp.old_price_product, sku.price_product) <> 0
                    THEN 1
                END
            ) as not_divisible_count
        ")
            ->first();

        $new = DB::table('new_products as np')
            ->join('sku_products as sku', 'sku.barcode_product', '=', 'np.old_barcode_product')
            ->where('np.code_document', 'like', 'SKU%')
            ->selectRaw("
            COUNT(
                CASE
                    WHEN sku.price_product > 0
                    AND MOD(np.old_price_product, sku.price_product) = 0
                    THEN 1
                END
            ) as divisible_count,

            COUNT(
                CASE
                    WHEN sku.price_product > 0
                    AND MOD(np.old_price_product, sku.price_product) <> 0
                    THEN 1
                END
            ) as not_divisible_count
        ")
            ->first();

        return response()->json([
            'staging_products' => [
                'habis_dibagi' => $staging->divisible_count,
                'tidak_habis_dibagi' => $staging->not_divisible_count,
            ],
            'new_products' => [
                'habis_dibagi' => $new->divisible_count,
                'tidak_habis_dibagi' => $new->not_divisible_count,
            ],
        ]);
    }
}

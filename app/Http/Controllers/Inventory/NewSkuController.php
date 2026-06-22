<?php

namespace App\Http\Controllers\Inventory;

use App\Exports\Inventory\CekSkuExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;

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
                    AND (sp.old_price_product / sku.price_product) = sp.new_quantity_product
                    THEN 1
                END
            ) as sesuai,

            COUNT(
                CASE
                    WHEN sku.price_product > 0
                    AND (sp.old_price_product / sku.price_product) <> sp.new_quantity_product
                    THEN 1
                END
            ) as tidak_sesuai
        ")
            ->first();

        $new = DB::table('new_products as np')
            ->join('sku_products as sku', 'sku.barcode_product', '=', 'np.old_barcode_product')
            ->where('np.code_document', 'like', 'SKU%')
            ->selectRaw("
            COUNT(
                CASE
                    WHEN sku.price_product > 0
                    AND (np.old_price_product / sku.price_product) = np.new_quantity_product
                    THEN 1
                END
            ) as sesuai,

            COUNT(
                CASE
                    WHEN sku.price_product > 0
                    AND (np.old_price_product / sku.price_product) <> np.new_quantity_product
                    THEN 1
                END
            ) as tidak_sesuai
        ")
            ->first();

        return response()->json([
            'staging_products' => [
                'sesuai_qty' => $staging->sesuai,
                'tidak_sesuai_qty' => $staging->tidak_sesuai,
            ],
            'new_products' => [
                'sesuai_qty' => $new->sesuai,
                'tidak_sesuai_qty' => $new->tidak_sesuai,
            ],
        ]);
    }

    public function exportProductValidation()
    {
        set_time_limit(0);
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '1024M');

        $filename = 'product-validation-' .
            now()->format('Ymd_His') . '-' .
            Str::random(8) .
            '.xlsx';

        $path = 'exports/' . $filename;

        Excel::store(
            new CekSkuExport(),
            $path,
            'public'
        );

        return response()->json([
            'success' => true,
            'file_name' => $filename,
            'download_url' => asset('storage/' . $path),
        ]);
    }
}

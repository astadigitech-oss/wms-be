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

    public function checkSkuAdjustment()
    {
        $staging = DB::selectOne("
        SELECT
            COUNT(*) as total_records,

            SUM(
                CASE
                    WHEN ABS(
                        (sp.old_price_product / sku.price_product)
                        - ROUND(sp.old_price_product / sku.price_product)
                    ) < 0.05
                    THEN 1
                    ELSE 0
                END
            ) as auto_fix,

            SUM(
                CASE
                    WHEN ABS(
                        (sp.old_price_product / sku.price_product)
                        - ROUND(sp.old_price_product / sku.price_product)
                    ) >= 0.05
                    THEN 1
                    ELSE 0
                END
            ) as need_review

        FROM staging_products sp
        JOIN sku_products sku
            ON sku.barcode_product = sp.old_barcode_product

        WHERE
            sp.code_document LIKE 'SKU%'
            AND sku.price_product > 0
    ");

        $new = DB::selectOne("
        SELECT
            COUNT(*) as total_records,

            SUM(
                CASE
                    WHEN ABS(
                        (np.old_price_product / sku.price_product)
                        - ROUND(np.old_price_product / sku.price_product)
                    ) < 0.05
                    THEN 1
                    ELSE 0
                END
            ) as auto_fix,

            SUM(
                CASE
                    WHEN ABS(
                        (np.old_price_product / sku.price_product)
                        - ROUND(np.old_price_product / sku.price_product)
                    ) >= 0.05
                    THEN 1
                    ELSE 0
                END
            ) as need_review

        FROM new_products np
        JOIN sku_products sku
            ON sku.barcode_product = np.old_barcode_product

        WHERE
            np.code_document LIKE 'SKU%'
            AND sku.price_product > 0
    ");

        $sampleNeedReview = DB::select("
        SELECT
            'staging_products' as source,
            sp.code_document,
            sp.old_barcode_product,
            sp.old_price_product,
            sp.new_quantity_product,
            sku.price_product,
            ROUND(
                sp.old_price_product / sku.price_product,
                6
            ) as calculated_qty

        FROM staging_products sp
        JOIN sku_products sku
            ON sku.barcode_product = sp.old_barcode_product

        WHERE
            sp.code_document LIKE 'SKU%'
            AND sku.price_product > 0
            AND ABS(
                (sp.old_price_product / sku.price_product)
                - ROUND(sp.old_price_product / sku.price_product)
            ) >= 0.05

        LIMIT 5
    ");

        $totalRecords = $staging->total_records + $new->total_records;
        $totalAutoFix = $staging->auto_fix + $new->auto_fix;
        $totalNeedReview = $staging->need_review + $new->need_review;

        return response()->json([
            'success' => true,

            'summary' => [
                'total_records' => $totalRecords,
                'auto_fix' => $totalAutoFix,
                'need_review' => $totalNeedReview,
                'auto_fix_percentage' => round(
                    ($totalAutoFix / max(1, $totalRecords)) * 100,
                    2
                ),
            ],

            'staging_products' => [
                'total_records' => (int) $staging->total_records,
                'auto_fix' => (int) $staging->auto_fix,
                'need_review' => (int) $staging->need_review,
            ],

            'new_products' => [
                'total_records' => (int) $new->total_records,
                'auto_fix' => (int) $new->auto_fix,
                'need_review' => (int) $new->need_review,
            ],

            'sample_need_review' => $sampleNeedReview,
        ]);
    }
}

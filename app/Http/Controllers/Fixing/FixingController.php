<?php

namespace App\Http\Controllers\Fixing;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class FixingController extends Controller
{
    public function syncNoteFromStaging()
    {
        try {

            DB::update("
                UPDATE product_defects pd

                LEFT JOIN new_products np
                    ON pd.new_barcode_product = np.new_barcode_product

                LEFT JOIN staging_products sp
                    ON pd.new_barcode_product = sp.new_barcode_product

                SET pd.note = COALESCE(
                    np.actual_new_quality,
                    sp.actual_new_quality
                )

                WHERE 
                    np.actual_new_quality IS NOT NULL
                    OR sp.actual_new_quality IS NOT NULL
            ");

            return response()->json([
                'success' => true,
                'message' => 'Note berhasil disync'
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getUnmappedSummary()
    {
        $count =
            DB::table('new_products')
            ->whereNull('new_tag_product')
            ->whereNull('new_category_product')
            ->count()
            +
            DB::table('staging_products')
            ->whereNull('new_tag_product')
            ->whereNull('new_category_product')
            ->count();

        $latestDate = collect([
            DB::table('new_products')
                ->whereNull('new_tag_product')
                ->whereNull('new_category_product')
                ->max('created_at'),

            DB::table('staging_products')
                ->whereNull('new_tag_product')
                ->whereNull('new_category_product')
                ->max('created_at'),
        ])->filter()->max();

        return response()->json([
            'count' => $count,
            'latest_created_at' => $latestDate,
        ]);
    }
}

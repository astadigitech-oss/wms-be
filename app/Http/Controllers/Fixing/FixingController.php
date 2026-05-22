<?php

namespace App\Http\Controllers\Fixing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FixingController extends Controller
{
    public function syncNoteFromStaging()
    {
        try {

            DB::update("
                UPDATE product_defects pd
                INNER JOIN new_products sp
                    ON pd.new_barcode_product = sp.new_barcode_product
                SET pd.note = sp.actual_new_quality
                WHERE sp.actual_new_quality IS NOT NULL
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
}

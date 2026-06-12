<?php

namespace App\Http\Controllers\CheckController;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class FindProductController extends Controller
{
    public function findProduct(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        $rows = Excel::toArray([], $request->file('file'));

        // Sheet pertama
        $sheet = $rows[0];

        $barcodes = [];

        foreach ($sheet as $index => $row) {
            // Skip header (row pertama)
            if ($index === 0) {
                continue;
            }

            if (!empty($row[0])) {
                $barcodes[] = trim($row[0]);
            }
        }

        $barcodes = array_unique($barcodes);

        $newProducts = DB::table('new_products')
            ->whereIn('new_barcode_product', $barcodes)
            ->get();

        $stagingProducts = DB::table('staging_products')
            ->whereIn('new_barcode_product', $barcodes)
            ->get();

        return response()->json([
            'total_barcodes' => count($barcodes),
            'new_products' => $newProducts,
            'staging_products' => $stagingProducts,
        ]);
    }
}

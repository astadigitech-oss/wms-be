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

        $sheet = $rows[0];

        $barcodes = [];

        foreach ($sheet as $index => $row) {
            if ($index === 0) {
                continue; // skip header
            }

            if (!empty($row[0])) {
                $barcodes[] = trim($row[0]);
            }
        }

        $barcodes = array_unique($barcodes);

        $newProducts = DB::table('new_products')
            ->whereIn('new_barcode_product', $barcodes)
            ->pluck('new_barcode_product')
            ->toArray();

        $stagingProducts = DB::table('staging_products')
            ->whereIn('new_barcode_product', $barcodes)
            ->pluck('new_barcode_product')
            ->toArray();

        $foundBarcodes = array_unique(array_merge(
            $newProducts,
            $stagingProducts
        ));

        $notFoundBarcodes = array_values(
            array_diff($barcodes, $foundBarcodes)
        );

        return response()->json([
            'total_excel' => count($barcodes),
            'found_count' => count($foundBarcodes),
            'not_found_count' => count($notFoundBarcodes),
            'not_found_barcodes' => $notFoundBarcodes,
        ]);
    }
}

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

    public function updateHargaExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        $rows = Excel::toArray([], $request->file('file'));

        $sheet = $rows[0];

        $updatedNewProducts = 0;
        $updatedStagingProducts = 0;
        $notFound = [];

        DB::beginTransaction();

        try {

            foreach ($sheet as $index => $row) {

                if ($index === 0) {
                    continue; // skip header
                }

                $barcode = trim($row[0] ?? '');
                $harga   = (int) str_replace(',', '', ($row[1] ?? 0));

                if (!$barcode) {
                    continue;
                }

                // Mapping
                if ($harga <= 19999) {
                    $newPrice = 0;
                    $displayPrice = 0;
                    $tag = 'Brown';
                } elseif ($harga <= 49999) {
                    $newPrice = 12000;
                    $displayPrice = 12000;
                    $tag = 'Small';
                } elseif ($harga <= 99999) {
                    $newPrice = 24000;
                    $displayPrice = 24000;
                    $tag = 'Big';
                } else {
                    // skip jika diluar range
                    continue;
                }

                $payload = [
                    'old_price_product' => $harga,
                    'new_price_product' => $newPrice,
                    'display_price' => $displayPrice,
                    'new_tag_product' => $tag,
                    'updated_at' => now(),
                ];

                $newAffected = DB::table('new_products')
                    ->where('new_barcode_product', $barcode)
                    ->update($payload);

                $stagingAffected = DB::table('staging_products')
                    ->where('new_barcode_product', $barcode)
                    ->update($payload);

                if ($newAffected > 0) {
                    $updatedNewProducts++;
                }

                if ($stagingAffected > 0) {
                    $updatedStagingProducts++;
                }

                if ($newAffected == 0 && $stagingAffected == 0) {
                    $notFound[] = $barcode;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'updated_new_products' => $updatedNewProducts,
                'updated_staging_products' => $updatedStagingProducts,
                'not_found_count' => count($notFound),
                'not_found_barcodes' => $notFound,
            ]);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

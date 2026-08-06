<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\BulkySale;
use App\Models\Bundle;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateStatusCargoController extends Controller
{
    /**
     * Mengubah status produk dari 'sale' menjadi 'cargo'
     * berdasarkan barcode yang terdaftar pada tabel bulky_sales.
     *
     * Konsep sama dengan API adjust-quality-staging:
     *  - Setiap hit memproses 5.000 data bulky_sales yang belum diproses
     *    (is_status_cargo = false), dari bawah ke atas (id terbesar dahulu).
     *  - Setelah diproses, data ditandai is_status_cargo = true sehingga
     *    hit berikutnya otomatis melanjutkan 5.000 data berikutnya.
     *  - Barcode hasil ambilan dipecah per 1.000 agar query
     *    tidak melebihi batas parameter SQL.
     *  - Untuk setiap tabel (new_products, staging_products, bundles),
     *    data diproses per 5.000 record dari bawah ke atas (id terbesar dahulu).
     */
    public function updateStatusToCargo(Request $request)
    {
        try {
            // 1. Ambil 5.000 data bulky_sales yang belum diproses, dari bawah ke atas
            $bulkySales = BulkySale::select('id', 'barcode_bulky_sale')
                ->where('is_status_cargo', false)
                ->orderByDesc('id')
                ->limit(5000)
                ->lockForUpdate()
                ->get();

            if ($bulkySales->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'processed' => 0,
                    'message' => 'No data to process',
                ]);
            }

            $summary = [
                'new_products'      => ['processed' => 0, 'updated' => 0, 'barcodes_updated' => 0],
                'staging_products'  => ['processed' => 0, 'updated' => 0, 'barcodes_updated' => 0],
                'bundles'           => ['processed' => 0, 'updated' => 0, 'barcodes_updated' => 0],
            ];

            DB::transaction(function () use ($bulkySales, &$summary) {

                $barcodes = $bulkySales->pluck('barcode_bulky_sale')
                    ->filter()
                    ->map(fn ($barcode) => trim((string) $barcode))
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                // 2. Proses barcode per batch 1.000 agar aman terhadap batas parameter SQL
                foreach (array_chunk($barcodes, 1000) as $barcodeBatch) {
                    // 2a. new_products
                    New_product::whereIn('new_barcode_product', $barcodeBatch)
                        ->where('new_status_product', 'sale')
                        ->orderByDesc('id')
                        ->chunk(5000, function ($products) use (&$summary) {
                            $summary['new_products']['processed'] += $products->count();
                            $summary['new_products']['updated'] += New_product::whereIn('id', $products->pluck('id'))
                                ->update(['new_status_product' => 'cargo']);
                            $summary['new_products']['barcodes_updated'] += $products->pluck('new_barcode_product')
                                ->filter()->unique()->count();
                        });

                    // 2b. staging_products
                    StagingProduct::whereIn('new_barcode_product', $barcodeBatch)
                        ->where('new_status_product', 'sale')
                        ->orderByDesc('id')
                        ->chunk(5000, function ($products) use (&$summary) {
                            $summary['staging_products']['processed'] += $products->count();
                            $summary['staging_products']['updated'] += StagingProduct::whereIn('id', $products->pluck('id'))
                                ->update(['new_status_product' => 'cargo']);
                            $summary['staging_products']['barcodes_updated'] += $products->pluck('new_barcode_product')
                                ->filter()->unique()->count();
                        });

                    // 2c. bundles
                    Bundle::whereIn('barcode_bundle', $barcodeBatch)
                        ->where('product_status', 'sale')
                        ->orderByDesc('id')
                        ->chunk(5000, function ($products) use (&$summary) {
                            $summary['bundles']['processed'] += $products->count();
                            $summary['bundles']['updated'] += Bundle::whereIn('id', $products->pluck('id'))
                                ->update(['product_status' => 'cargo']);
                            $summary['bundles']['barcodes_updated'] += $products->pluck('barcode_bundle')
                                ->filter()->unique()->count();
                        });
                }

                // 3. Tandai data bulky_sales yang sudah diproses
                BulkySale::whereIn('id', $bulkySales->pluck('id'))
                    ->update(['is_status_cargo' => true]);
            });

            $totalBarcodesUpdated = $summary['new_products']['barcodes_updated']
                + $summary['staging_products']['barcodes_updated']
                + $summary['bundles']['barcodes_updated'];

            return response()->json([
                'success' => true,
                'processed' => $bulkySales->count(),
                'total_barcodes_updated' => $totalBarcodesUpdated,
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update status cargo',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

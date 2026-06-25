<?php

namespace App\Http\Controllers\HelperErp;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdjustQualityController extends Controller
{
    public function stagingAdjustQuality()
    {
        try {

            $products = DB::table('staging_products')
                ->select('id', 'new_quality')
                ->where('is_adjusted_quality', false)
                ->orderByDesc('id')
                ->limit(10000)
                ->get();

            if ($products->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'processed' => 0,
                    'message' => 'No data to process'
                ]);
            }

            DB::transaction(function () use ($products) {

                foreach ($products as $product) {

                    $quality = json_decode($product->new_quality, true);

                    if (!is_array($quality)) {
                        $quality = [];
                    }

                    DB::table('staging_products')
                        ->where('id', $product->id)
                        ->update([
                            'is_lolos' => $quality['lolos'] ?? null,
                            'is_damaged' => $quality['damaged'] ?? null,
                            'is_abnormal' => $quality['abnormal'] ?? null,
                            'is_non' => $quality['non'] ?? null,
                            'is_adjusted_quality' => true,
                        ]);
                }
            });

            return response()->json([
                'success' => true,
                'processed' => $products->count(),
            ]);
        } catch (\Throwable $e) {

            Log::error('Adjust quality staging products failed', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to adjust quality',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function displayAdjustQuality()
    {
        try {

            $products = DB::table('new_products')
                ->select('id', 'new_quality')
                ->where('is_adjusted_quality', false)
                ->orderByDesc('id')
                ->limit(50000)
                ->get();

            if ($products->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'processed' => 0,
                    'message' => 'No data to process'
                ]);
            }

            DB::transaction(function () use ($products) {

                foreach ($products as $product) {

                    $quality = json_decode($product->new_quality, true);

                    if (!is_array($quality)) {
                        $quality = [];
                    }

                    DB::table('new_products')
                        ->where('id', $product->id)
                        ->update([
                            'is_lolos' => $quality['lolos'] ?? null,
                            'is_damaged' => $quality['damaged'] ?? null,
                            'is_abnormal' => $quality['abnormal'] ?? null,
                            'is_non' => $quality['non'] ?? null,
                            'is_adjusted_quality' => true,
                        ]);
                }
            });

            return response()->json([
                'success' => true,
                'processed' => $products->count(),
            ]);
        } catch (\Throwable $e) {

            Log::error('Adjust quality new products failed', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to adjust quality',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

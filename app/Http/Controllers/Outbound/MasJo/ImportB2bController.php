<?php

namespace App\Http\Controllers\Outbound\MasJo;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\BagProducts;
use App\Models\BklProduct;
use App\Models\BulkyDocument;
use App\Models\BulkySale;
use App\Models\Bundle;
use App\Models\Category;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class ImportB2bController extends Controller
{
    public function importB2B(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:xls,xlsx',
            'bulky_document_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $bulky = BulkyDocument::find($request->bulky_document_id);

        if (!$bulky) {
            return response()->json([
                'message' => 'Bulky document not found'
            ], 404);
        }

        $rows = Excel::toArray([], $request->file('file'));

        // Array penampung pelaporan
        $successCount = 0;
        $failedList = [];

        DB::beginTransaction();

        try {
            foreach ($rows[0] as $index => $row) {
                // Skip Header
                if ($index == 0) continue;

                // Ambil value, ganti null/empty string dengan string kosong bersih
                $barcodeBag = isset($row[0]) ? trim((string)$row[0]) : '';
                $barcode    = isset($row[1]) ? trim((string)$row[1]) : '';

                // 1. Validasi jika Barcode Bag Kosong / Null
                if ($barcodeBag === '') {
                    $failedList[] = [
                        'row' => $index + 1,
                        'barcode_bag' => '-',
                        'barcode' => $barcode ?: '-',
                        'reason' => 'Barcode Bag kosong / null pada file Excel'
                    ];
                    continue;
                }

                // 2. Validasi jika Barcode Produk Kosong / Null
                if ($barcode === '') {
                    $failedList[] = [
                        'row' => $index + 1,
                        'barcode_bag' => $barcodeBag,
                        'barcode' => '-',
                        'reason' => 'Barcode Produk kosong / null pada file Excel'
                    ];
                    continue;
                }

                /*
            |--------------------------------------------------------------------------
            | FIND PRODUCT
            |--------------------------------------------------------------------------
            */
                $models = [
                    'new_product' => New_product::where('new_barcode_product', $barcode)->first(),
                    'staging_product' => StagingProduct::where('new_barcode_product', $barcode)->first(),
                    'bundle_product' => Bundle::with('product_bundles')->where('barcode_bundle', $barcode)->first(),
                    'bkl_product' => BklProduct::where('new_barcode_product', $barcode)->first(),
                ];

                $product = null;
                $foundType = null;
                $foundModel = null;
                $failReason = null;

                foreach ($models as $type => $model) {
                    if (!$model) {
                        continue;
                    }

                    $status = match ($type) {
                        'new_product',
                        'staging_product',
                        'bkl_product' => $model->new_status_product,
                        'bundle_product' => $model->product_status,
                    };

                    // Filter status jika produk sudah tidak valid
                    if (in_array($status, ['cargo', 'sale'], true)) {
                        $failReason = "Status produk sudah '{$status}'";
                        continue;
                    }

                    // Format data produk
                    $product = match ($type) {
                        'new_product',
                        'staging_product',
                        'bkl_product' => [
                            'barcode' => $model->new_barcode_product,
                            'category' => $model->new_category_product,
                            'name' => $model->new_name_product,
                            'status' => $model->new_status_product,
                            'old_price' => $model->old_price_product,
                            'qty' => $model->new_quantity_product,
                            'code_document' => $model->code_document,
                            'old_barcode_product' => $model->old_barcode_product,
                            'new_date_in_product' => $model->new_date_in_product,
                            'display_price' => $model->display_price,
                            'created_at' => $model->created_at,
                            'actual_old_price_product' => $model->actual_old_price_product ?? $model->old_price_product,
                            'weight' => $model->weight,
                        ],

                        'bundle_product' => [
                            'barcode' => $model->barcode_bundle,
                            'category' => $model->category,
                            'name' => $model->name_bundle,
                            'status' => $model->product_status,
                            'old_price' => $model->total_price_bundle,
                            'qty' => $model->total_product_bundle,
                            'code_document' => $model->product_bundles->first()?->code_document,
                            'old_barcode_product' => $model->product_bundles->first()?->old_barcode_product,
                            'new_date_in_product' => $model->product_bundles->first()?->date_in_product,
                            'display_price' => $model->product_bundles->first()?->display_price,
                            'created_at' => $model->created_at,
                            'actual_old_price_product' => $model->product_bundles->sum('actual_old_price_product'),
                            'weight' => null,
                        ],
                    };

                    $foundType = $type;
                    $foundModel = $model;

                    break;
                }

                // 2. Catat jika produk gagal diproses / tidak ditemukan
                if (!$product) {
                    $failedList[] = [
                        'row' => $index + 1,
                        'barcode' => $barcode,
                        'reason' => $failReason ?? 'Barcode tidak ditemukan di semua jenis produk'
                    ];
                    continue;
                }

                /*
            |--------------------------------------------------------------------------
            | FIND CATEGORY & BAG
            |--------------------------------------------------------------------------
            */
                $category = Category::whereRaw(
                    'LOWER(name_category) = ?',
                    [strtolower(trim($product['category']))]
                )->first();

                $categoryId = $category?->id;

                $bag = BagProducts::where('barcode_bag', $barcodeBag)->first();

                if (!$bag) {
                    $bag = BagProducts::create([
                        'barcode_bag' => $barcodeBag,
                        'name_bag' => $barcodeBag,
                        'user_id' => 160,
                        'bulky_document_id' => $bulky->id,
                        'status' => 'done',
                        'total_product' => 0,
                        'category_id' => $categoryId
                    ]);
                }

                /*
            |--------------------------------------------------------------------------
            | STORE BULKY SALE & UPDATE PRODUCT
            |--------------------------------------------------------------------------
            */
                $afterPrice = $product['old_price'] - ($product['old_price'] * $bulky->discount_bulky / 100);

                BulkySale::create([
                    'bulky_document_id' => $bulky->id,
                    'bag_product_id' => $bag->id,
                    'barcode_bulky_sale' => $product['barcode'],
                    'product_category_bulky_sale' => $product['category'],
                    'name_product_bulky_sale' => $product['name'],
                    'status_product_before' => $product['status'],
                    'old_price_bulky_sale' => $product['old_price'],
                    'after_price_bulky_sale' => $afterPrice,
                    'code_document' => $product['code_document'],
                    'old_barcode_product' => $product['old_barcode_product'],
                    'new_date_in_product' => $product['new_date_in_product'],
                    'display_price' => $product['display_price'],
                    'actual_created_at' => $product['created_at'],
                    'actual_old_price_product' => $product['actual_old_price_product'],
                    'qty' => $product['qty'],
                    'weight' => $product['weight'],
                ]);

                match ($foundType) {
                    'new_product',
                    'staging_product',
                    'bkl_product' => $foundModel->update([
                        'new_status_product' => 'sale',
                        'date_out' => now(),
                        'type_out' => 'cargo',
                    ]),

                    'bundle_product' => $foundModel->update([
                        'product_status' => 'sale',
                    ]),
                };

                // Tambah hitungan berhasil
                $successCount++;
            }

            /*
        |--------------------------------------------------------------------------
        | UPDATE TOTAL BAG & BULKY DOCUMENT
        |--------------------------------------------------------------------------
        */
            BagProducts::where('bulky_document_id', $bulky->id)
                ->get()
                ->each(function ($bag) {
                    $bag->update([
                        'total_product' => BulkySale::where('bag_product_id', $bag->id)->count()
                    ]);
                });

            $bagIds = BagProducts::where('bulky_document_id', $bulky->id)->pluck('id');
            $sales = BulkySale::whereIn('bag_product_id', $bagIds);

            $bulky->update([
                'total_product_bulky' => $sales->count(),
                'total_old_price_bulky' => $sales->sum('old_price_bulky_sale'),
                'after_price_bulky' => $sales->sum('after_price_bulky_sale')
            ]);

            DB::commit();

            // Response Detail
            return response()->json([
                'status' => 'success',
                'message' => 'Proses import selesai.',
                'summary' => [
                    'total_processed' => $successCount + count($failedList),
                    'total_success'   => $successCount,
                    'total_failed'    => count($failedList),
                ],
                'failed_details' => $failedList
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Gagal memproses import: ' . $e->getMessage()
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Category;
use App\Models\Color_tag;
use App\Models\HistoryBundling;
use App\Models\New_product;
use App\Models\SkuDocument;
use App\Models\SkuProduct;
use App\Models\StagingProduct;
use App\Services\MovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SkuProductController extends Controller
{
    //
    public function storeBundle(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'items_per_bundle' => 'required|integer|min:1',
            'bundle_quantity' => 'required|integer|min:1',
            'bundle_type' => 'nullable|in:regular,big,small',
            'new_category_product' => 'nullable|exists:categories,name_category',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try {
            $product = SkuProduct::lockForUpdate()->find($id);
            if (!$product) {
                return response()->json(['message' => 'Produk tidak ditemukan'], 404);
            }

            $itemsPerBundle = $request->items_per_bundle;
            $bundleQty = $request->bundle_quantity;
            $totalQtyNeeded = $itemsPerBundle * $bundleQty;

            if ($product->quantity_product < $totalQtyNeeded) {
                return response()->json([
                    'message' => 'Stok actual tidak mencukupi.',
                    'butuh' => $totalQtyNeeded,
                    'sisa' => $product->quantity_product
                ], 400);
            }

            $unitPrice = $product->price_product;
            $bundlePrice = $unitPrice * $itemsPerBundle;

            $qtyBefore = $product->quantity_product;
            $totalValueBefore = $qtyBefore * $unitPrice;
            $document = SkuDocument::where('code_document', $product->code_document)->first();
            $customBarcode = $document ? $document->custom_barcode : null;
            $userId = auth()->id();
            $timestamp = now();
            $qualityJson = $this->makeQualityJson('lolos');

            $insertedData = [];
            $generatedProducts = [];
            $destination = '';

            $warningMessage = null;
            $bundleNamePrefix = $request->items_per_bundle > 1 ? 'Bundling ' : '';

            if ($request->bundle_type === 'regular') {
                if ($bundlePrice < 100000) {
                    return response()->json(['message' => 'Bundle Regular (Kategori) hanya untuk harga >= 100.000.'], 422);
                }
                if (!$request->has('new_category_product') || empty($request->new_category_product)) {
                    return response()->json(['message' => 'Untuk tipe Reguler, wajib memilih kategori.'], 422);
                }

                $category = Category::where('name_category', $request->new_category_product)->first();
                $discount = $category ? $category->discount_category : 0;
                $finalPrice = $bundlePrice - ($bundlePrice * ($discount / 100));

                for ($i = 0; $i < $bundleQty; $i++) {
                    $newBarcode = $this->generateBarcodeWithCustom($customBarcode, $userId, $request->new_category_product);

                    $generatedProducts[] = [
                        'new_barcode_product' => $newBarcode,
                        'new_name_product' => $bundleNamePrefix . $product->name_product,
                        'new_price_product' => $finalPrice,
                        'category' => $category->name_category,
                        'tag' => null
                    ];

                    $insertedData[] = [
                        'code_document' => $product->code_document,
                        'old_barcode_product' => $product->barcode_product,
                        'new_barcode_product' => $newBarcode,
                        'new_name_product' => $bundleNamePrefix . $product->name_product,
                        'new_quantity_product' => $request->items_per_bundle,
                        'new_price_product' => $finalPrice,
                        'old_price_product' => $bundlePrice,
                        'new_status_product' => 'display',
                        'new_quality' => $qualityJson,
                        'actual_new_quality' => $qualityJson,
                        'new_category_product' => $category->name_category,
                        'new_tag_product' => null,
                        'new_discount' => 0,
                        'new_date_in_product' => $timestamp,
                        'user_id' => $userId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                        'display_price' => $finalPrice,
                        'type' => 'type1',
                    ];
                }

                foreach (array_chunk($insertedData, 500) as $chunk) {
                    StagingProduct::insert($chunk);
                }
                $destination = 'Staging Product';
            } else {
                $colorTag = null;
                $tagNameSearch = null;

                if ($request->has('bundle_type') && in_array($request->bundle_type, ['big', 'small'])) {
                    $tagNameSearch = ucfirst($request->bundle_type); // 'big' -> 'Big'
                    $colorTag = Color_tag::where('name_color', $tagNameSearch)->first();

                    if (!$colorTag) {
                        return response()->json(['message' => "Tag {$tagNameSearch} tidak ditemukan di database."], 422);
                    }

                    if ($bundlePrice >= 100000) {
                        $warningMessage = "Regular price (>= 100k) converted to {$tagNameSearch}";
                    } else {
                        // Cari tag "seharusnya" (Natural Tag)
                        $naturalTag = Color_tag::where('min_price_color', '<=', $bundlePrice)
                            ->where('max_price_color', '>=', $bundlePrice)
                            ->where(function ($q) {
                                $q->where('name_color', 'LIKE', '%Big%')->orWhere('name_color', 'LIKE', '%Small%');
                            })->first();

                        if ($naturalTag) {
                            $naturalName = $naturalTag->name_color;

                            // Jika seharusnya Big tapi dipilih Small
                            if (stripos($naturalName, 'Big') !== false && stripos($tagNameSearch, 'Small') !== false) {
                                $warningMessage = "Big price converted to Small";
                            }
                            // Jika seharusnya Small tapi dipilih Big
                            elseif (stripos($naturalName, 'Small') !== false && stripos($tagNameSearch, 'Big') !== false) {
                                $warningMessage = "Small price converted to Big";
                            }
                        }
                    }
                } else {
                    if ($bundlePrice >= 100000) {
                        return response()->json(['message' => 'Untuk harga bundle >= 100.000, wajib memilih tipe bundle (regular, big, atau small).'], 422);
                    }

                    $colorTag = Color_tag::where('min_price_color', '<=', $bundlePrice)
                        ->where('max_price_color', '>=', $bundlePrice)
                        ->where(function ($q) {
                            $q->where('name_color', 'LIKE', '%Big%')->orWhere('name_color', 'LIKE', '%Small%');
                        })->first();

                    if (!$colorTag) {
                        return response()->json(['message' => 'Tidak ditemukan Color Tag (Big/Small) yang sesuai untuk range harga ini.'], 422);
                    }
                }

                $fixPrice = $colorTag->fixed_price_color;
                $tagName = $colorTag->name_color;

                for ($i = 0; $i < $bundleQty; $i++) {
                    $newBarcode = $this->generateBarcodeWithCustom($customBarcode, $userId, null);

                    $generatedProducts[] = [
                        'new_barcode_product' => $newBarcode,
                        'new_name_product' => $bundleNamePrefix . $product->name_product,
                        'new_price_product' => $fixPrice,
                        'category' => null,
                        'tag' => $tagName
                    ];

                    $insertedData[] = [
                        'code_document' => $product->code_document,
                        'old_barcode_product' => $product->barcode_product,
                        'new_barcode_product' => $newBarcode,
                        'new_name_product' => $bundleNamePrefix . $product->name_product,
                        'new_quantity_product' => $request->items_per_bundle,
                        'new_price_product' => $fixPrice,
                        'display_price' => $fixPrice,
                        'old_price_product' => $bundlePrice,
                        'new_status_product' => 'display',
                        'new_quality' => $qualityJson,
                        'actual_new_quality' => $qualityJson,
                        'new_tag_product' => $tagName,
                        'new_discount' => 0,
                        'new_date_in_product' => $timestamp,
                        'new_category_product' => null,
                        'user_id' => $userId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                        'type' => 'type1',
                    ];
                }

                foreach (array_chunk($insertedData, 500) as $chunk) {
                    New_product::insert($chunk);
                }
                $destination = 'New Product';
            }

            $product->decrement('quantity_product', $totalQtyNeeded);

            $qtyAfter = $product->fresh()->quantity_product;
            $totalValueAfter = $qtyAfter * $unitPrice;

            HistoryBundling::create([
                'user_id' => $userId,
                'code_document' => $product->code_document,
                'barcode_product' => $product->barcode_product,
                'name_product' => $product->name_product,
                'price_before' => $totalValueBefore,
                'price_after' => $totalValueAfter,
                'qty_before' => $qtyBefore,
                'qty_after' => $qtyAfter,
                'total_qty_bundle' => $bundleQty,
                'items_per_bundle' => $itemsPerBundle,
                'type' => 'bundling'
            ]);

            DB::commit();

            // [Movement] staging_sku → staging_reguler / display_color
            try {
                $movementRows = [];
                $moveTo = $request->bundle_type === 'regular' ? 'staging_reguler' : 'display_color';
                $movementRows[] = [
                    'product_id' => $product->barcode_product,
                    'is_sku' => true,
                    'type' => 'Bundler',
                    'type_out' => null,
                    'from' => 'staging_sku',
                    'to' => $moveTo,
                    'qty' => $totalQtyNeeded,
                ];
                foreach ($generatedProducts as $gen) {
                    $genTo = $gen['tag'] ? 'display_color' : 'staging_reguler';
                    $movementRows[] = [
                        'product_id' => $gen['new_barcode_product'],
                        'is_sku' => false,
                        'type' => 'Bundled',
                        'type_out' => null,
                        'from' => 'staging_sku',
                        'to' => $genTo,
                        'qty' => 1,
                    ];
                }
                MovementService::logBulk($movementRows);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('[Movement] SKU storeBundle log failed: ' . $e->getMessage());
            }

            return new ResponseResource(true, "Berhasil membuat $bundleQty bundle.", [
                'total_item_used' => $totalQtyNeeded,
                'destination' => $destination,
                'warning_message' => $warningMessage,
                'generated_products' => $generatedProducts
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function generateBarcodeWithCustom($customBarcode, $userId, $category = null)
    {
        if ($customBarcode) {
            return newBarcodeCustom($customBarcode, $userId);
        }

        return generateNewBarcode($category);
    }

    private function makeQualityJson($status, $description = null)
    {
        return json_encode([
            'lolos' => $status === 'lolos' ? 'lolos' : null,
            'damaged' => $status === 'damaged' ? ($description ?? 'damaged') : null,
            'abnormal' => $status === 'abnormal' ? ($description ?? 'abnormal') : null,
            'non' => $status === 'non' ? ($description ?? 'non') : null,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Bundle;
use App\Models\ColorRack;
use App\Models\ColorRackHistory;
use App\Models\ColorRackProduct;
use App\Models\New_product;
use App\Models\Product_Bundle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ColorRackController extends Controller
{
    public function addProduct(Request $request, $id)
    {
        $request->validate([
            'barcode' => 'required|string',
            'tag' => 'nullable|in:Small,Big',
        ]);

        $rack = ColorRack::find($id);
        if (!$rack) {
            return (new ResponseResource(false, 'Data Rack tidak ditemukan', null))->response()->setStatusCode(404);
        }

        if (in_array($rack->status, ['process', 'migrate'])) {
            return (new ResponseResource(false, 'Tidak bisa menambah produk ke rak yang sudah process/migrate', null))->response()->setStatusCode(400);
        }

        $barcode = $request->barcode;

        $recentDuplicate = ColorRackHistory::where('color_rack_id', $rack->id)
            ->where('barcode', $barcode)
            ->where('action', 'IN')
            ->where('created_at', '>=', now()->subHours(2))
            ->exists();

        if ($recentDuplicate) {
            return (new ResponseResource(false, 'Item sudah pernah ditambahkan ke rak ini dalam 2 jam terakhir', null))
                ->response()->setStatusCode(409);
        }

        DB::beginTransaction();
        try {
            $bundle = Bundle::where(function ($query) use ($barcode) {
                $query->where('barcode_bundle', $barcode)
                    ->orWhere('old_barcode_bundle', $barcode);
            })
                ->whereDoesntHave('colorRackProduct')
                ->where('product_status', 'not sale')
                ->whereNull('category')
                ->lockForUpdate()
                ->first();

            if (!$bundle) {
                $productBundle = Product_Bundle::where('new_barcode_product', $barcode)->first();
                if ($productBundle) {
                    $bundle = Bundle::where('id', $productBundle->bundle_id)
                        ->whereDoesntHave('colorRackProduct')
                        ->where('product_status', 'not sale')
                        ->whereNull('category')
                        ->lockForUpdate()
                        ->first();
                }
            }

            $product = null;
            if (!$bundle) {
                $product = New_product::where(function ($query) use ($barcode) {
                    $query->where('old_barcode_product', $barcode)
                        ->orWhere('new_barcode_product', $barcode);
                })
                    ->whereDoesntHave('colorRackProduct')
                    ->whereIn('new_status_product', ['display', 'expired', 'slow_moving'])
                    ->whereNotNull('new_tag_product')
                    ->where(function ($query) {
                        $query->whereNull('new_category_product')
                            ->orWhere('new_category_product', '');
                    })
                    ->where(function ($query) {
                        $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
                    })
                    ->where('is_pending', false)
                    ->lockForUpdate()
                    ->first();
            }

            if ($product && strtolower($product->new_tag_product) === 'brown' && !$request->filled('tag')) {
                DB::rollBack();
                return (new ResponseResource(false, 'produk brown perlu pilih tag Big atau Small', $product))
                    ->response()->setStatusCode(422);
            }

            if (!$bundle && !$product) {
                DB::rollBack();
                return (new ResponseResource(false, 'Item tidak ditemukan atau tidak memenuhi kriteria', null))
                    ->response()->setStatusCode(404);
            }

            $responseData = null;
            if ($product && strtolower($product->new_tag_product) === 'brown' && $request->filled('tag')) {
                $size = strtolower($request->tag);
                $price = ($size === 'small') ? 12000 : 24000;
                $product->update([
                    'new_tag_product' => $size,
                    'new_price_product' => $price,
                    'display_price' => $price,
                ]);
            }

            if ($bundle) {
                $existingRackProduct = ColorRackProduct::where('color_rack_id', $rack->id)
                    ->where('bundle_id', $bundle->id)
                    ->exists();

                if ($existingRackProduct) {
                    DB::rollBack();
                    return (new ResponseResource(false, 'Item sudah ada di rak ini', null))->response()->setStatusCode(409);
                }

                $rackProduct = ColorRackProduct::create([
                    'color_rack_id' => $rack->id,
                    'new_product_id' => null,
                    'bundle_id' => $bundle->id,
                ]);
                $rackProduct->load('bundle');
                $responseData = $rackProduct->bundle;
            } else {
                $existingRackProduct = ColorRackProduct::where('color_rack_id', $rack->id)
                    ->where('new_product_id', $product->id)
                    ->exists();

                if ($existingRackProduct) {
                    DB::rollBack();
                    return (new ResponseResource(false, 'Item sudah ada di rak ini', null))->response()->setStatusCode(409);
                }

                $rackProduct = ColorRackProduct::create([
                    'color_rack_id' => $rack->id,
                    'new_product_id' => $product->id,
                    'bundle_id' => null,
                ]);
                $rackProduct->load('newProduct');
                $responseData = $rackProduct->newProduct;
            }

            $historyBarcode = $bundle ? $bundle->barcode_bundle : $product->new_barcode_product;
            $historyName = $bundle ? "[BUNDLE] " . $bundle->name_bundle : $product->new_name_product;

            $hasRecentHistory = ColorRackHistory::where('color_rack_id', $rack->id)
                ->where('barcode', $historyBarcode)
                ->where('action', 'IN')
                ->where('user_id', Auth::id())
                ->where('created_at', '>=', now()->subSeconds(10))
                ->exists();

            if (!$hasRecentHistory) {
                ColorRackHistory::create([
                    'user_id' => Auth::id(),
                    'color_rack_id' => $rack->id,
                    'new_product_id' => $bundle ? null : $product->id,
                    'bundle_id' => $bundle ? $bundle->id : null,
                    'source_type' => $bundle ? 'bundle' : 'product',
                    'source_key' => $bundle ? $bundle->barcode_bundle : $product->new_barcode_product,
                    'barcode' => $historyBarcode,
                    'product_name' => $historyName,
                    'action' => 'IN',
                ]);
            }

            DB::commit();
            return (new ResponseResource(true, 'Item berhasil ditambahkan ke Color Rack', $responseData))->response()->setStatusCode(201);
        } catch (\Exception $e) {
            DB::rollBack();

            if ($e instanceof \Illuminate\Database\QueryException && str_contains($e->getMessage(), 'Duplicate entry')) {
                Log::warning('Color rack duplicate add-product', [
                    'rack_id' => $id,
                    'barcode' => $request->barcode,
                    'user_id' => Auth::id(),
                    'message' => $e->getMessage(),
                ]);

                return (new ResponseResource(false, 'Item sudah ada di rak ini', null))->response()->setStatusCode(409);
            }

            Log::error('Color rack add-product failed', [
                'rack_id' => $id,
                'barcode' => $request->barcode,
                'user_id' => Auth::id(),
                'message' => $e->getMessage(),
            ]);

            return (new ResponseResource(false, 'Terjadi kesalahan sistem: ' . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }
}

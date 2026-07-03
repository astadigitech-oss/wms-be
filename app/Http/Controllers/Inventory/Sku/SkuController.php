<?php

namespace App\Http\Controllers\Inventory\Sku;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\SkuProduct;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class SkuController extends Controller
{
    public function editHargaDanQty(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'harga' => 'required|numeric',
            'qty' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, 'Validation Error', $validator->errors());
        }

        DB::beginTransaction();

        try {

            $skuProduk = SkuProduct::find($id);

            if (!$skuProduk) {
                DB::rollBack();

                return new ResponseResource(false, 'SKU Product not found', null);
            }

            $skuProduk->price_product = $request->input('harga');
            $skuProduk->quantity_product = $request->input('qty');
            $skuProduk->save();

            DB::commit();

            return new ResponseResource(
                true,
                'SKU Product updated successfully',
                $skuProduk
            );
        } catch (Throwable $e) {

            DB::rollBack();

            return new ResponseResource(
                false,
                'Failed to update SKU Product',
                [
                    'error' => $e->getMessage()
                ]
            );
        }
    }
}

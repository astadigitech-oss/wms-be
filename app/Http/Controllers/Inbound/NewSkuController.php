<?php

namespace App\Http\Controllers\Inbound;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\SkuBatch;
use App\Models\SkuProduct;
use App\Models\SkuProductOld;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NewSkuController extends Controller
{
    public function melakukanBatch(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'actual_quantity_batch' => 'required|integer|min:0',
            'damaged_quantity_batch' => 'required|integer|min:0',
            'note' => 'nullable|string',
        ]);

        $user_id = auth()->user()->id;

        if ($validator->fails()) {
            return (new ResponseResource(
                'error',
                $validator->errors()->first(),
                null
            ))->response()->setStatusCode(422);
        }

        DB::beginTransaction();

        try {
            $productOld = SkuProductOld::find($id);

            if (!$productOld) {
                DB::rollBack();

                return (new ResponseResource(
                    'error',
                    'Product Old not found',
                    null
                ))->response()->setStatusCode(404);
            }

            $createBatch = SkuBatch::create([
                'code' => 'BATCH-' . strtoupper(uniqid()),
                'sku_product_old_id' => $productOld->id,
                'actual_quantity_batch' => $request->input('actual_quantity_batch'),
                'damaged_quantity_batch' => $request->input('damaged_quantity_batch'),
                'type' => 'entry',
                'note' => $request->input('note'),
                'created_by' => $user_id,
            ]);

            $productOld->update([
                'actual_quantity_product' => $productOld->actual_quantity_product + $request->input('actual_quantity_batch'),
                'damaged_quantity_product' => $productOld->damaged_quantity_product + $request->input('damaged_quantity_batch'),
            ]);

            $isThereSkuProduct = SkuProduct::where('code_document', $productOld->code_document)
                ->where('barcode_product', $productOld->old_barcode_product)
                ->first();

            if (!$isThereSkuProduct) {
                SkuProduct::create([
                    'code_document' => $productOld->code_document,
                    'barcode_product' => $productOld->old_barcode_product,
                    'name_product' => $productOld->old_name_product,
                    'price_product' => $productOld->old_price_product,
                    'quantity_product' => $request->input('actual_quantity_batch'),
                ]);
            } else {
                $isThereSkuProduct->update([
                    'quantity_product' => $isThereSkuProduct->quantity_product + $request->input('actual_quantity_batch'),
                ]);
            }

            DB::commit();

            $createBatch->load('createdBy');

            return (new ResponseResource(
                'success',
                'Batch berhasil dibuat',
                [
                    'code' => $createBatch->code,
                    'sku_product_old_id' => $createBatch->sku_product_old_id,
                    'actual_quantity_batch' => $createBatch->actual_quantity_batch,
                    'damaged_quantity_batch' => $createBatch->damaged_quantity_batch,
                    'type' => $createBatch->type,
                    'note' => $createBatch->note,
                    'created_by' => $createBatch->createdBy?->name,
                ]
            ))->response()->setStatusCode(200);
        } catch (\Exception $e) {
            DB::rollBack();

            return (new ResponseResource(
                'error',
                $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function melakukanRollback(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'actual_quantity_batch' => 'required|integer|min:0',
            'damaged_quantity_batch' => 'required|integer|min:0',
            'note' => 'nullable|string',
        ]);

        $user_id = auth()->user()->id;

        if ($validator->fails()) {
            return (new ResponseResource(
                'error',
                $validator->errors()->first(),
                null
            ))->response()->setStatusCode(422);
        }

        DB::beginTransaction();

        try {
            $productOld = SkuProductOld::find($id);

            if (!$productOld) {
                DB::rollBack();

                return (new ResponseResource(
                    'error',
                    'Product Old not found',
                    null
                ))->response()->setStatusCode(404);
            }

            if ($request->input('actual_quantity_batch') > $productOld->actual_quantity_product) {
                DB::rollBack();

                return (new ResponseResource(
                    'error',
                    'Rollback qty harus kurang dari atau sama dengan qty yang ada di Product Old',
                    null
                ))->response()->setStatusCode(400);
            }

            if ($request->input('damaged_quantity_batch') > $productOld->damaged_quantity_product) {
                DB::rollBack();

                return (new ResponseResource(
                    'error',
                    'Rollback damaged qty harus kurang dari atau sama dengan damaged qty yang ada di Product Old',
                    null
                ))->response()->setStatusCode(400);
            }

            $createBatch = SkuBatch::create([
                'code' => 'BATCH-' . strtoupper(uniqid()),
                'sku_product_old_id' => $productOld->id,
                'actual_quantity_batch' => $request->input('actual_quantity_batch'),
                'damaged_quantity_batch' => $request->input('damaged_quantity_batch'),
                'type' => 'rollback',
                'note' => $request->input('note'),
                'created_by' => $user_id,
            ]);

            $productOld->update([
                'actual_quantity_product' => $productOld->actual_quantity_product - $request->input('actual_quantity_batch'),
                'damaged_quantity_product' => $productOld->damaged_quantity_product - $request->input('damaged_quantity_batch'),
            ]);

            $isThereSkuProduct = SkuProduct::where('code_document', $productOld->code_document)
                ->where('barcode_product', $productOld->old_barcode_product)
                ->first();

            if ($isThereSkuProduct && $isThereSkuProduct->quantity_product < $request->input('actual_quantity_batch')) {
                DB::rollBack();

                return (new ResponseResource(
                    'error',
                    'Rollback qty harus kurang dari atau sama dengan qty yang ada di SKU Product',
                    null
                ))->response()->setStatusCode(400);
            }

            if (!$isThereSkuProduct) {
                return (new ResponseResource(
                    'error',
                    'SKU Product tidak ditemukan untuk melakukan rollback',
                    null
                ))->response()->setStatusCode(404);
            } else {
                $isThereSkuProduct->update([
                    'quantity_product' => $isThereSkuProduct->quantity_product - $request->input('actual_quantity_batch'),
                ]);
            }

            DB::commit();

            $createBatch->load('createdBy');

            return (new ResponseResource(
                'success',
                'Rollback berhasil dibuat',
                [
                    'code' => $createBatch->code,
                    'sku_product_old_id' => $createBatch->sku_product_old_id,
                    'actual_quantity_batch' => $createBatch->actual_quantity_batch,
                    'damaged_quantity_batch' => $createBatch->damaged_quantity_batch,
                    'type' => $createBatch->type,
                    'note' => $createBatch->note,
                    'created_by' => $createBatch->createdBy?->name,
                ]
            ))->response()->setStatusCode(200);
        } catch (\Exception $e) {
            DB::rollBack();

            return (new ResponseResource(
                'error',
                $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function getBatchByProductOld($id)
    {
        $productOld = SkuProductOld::find($id);

        if (!$productOld) {
            return (new ResponseResource(
                'error',
                'Product Old not found',
                null
            ))->response()->setStatusCode(404);
        }

        $batches = $productOld->skuBatches()->with('createdBy')->get();

        return (new ResponseResource(
            'success',
            'List Batch untuk Product Old berhasil diambil',
            $batches->map(function ($batch) {
                return [
                    'code' => $batch->code,
                    'sku_product_old_id' => $batch->sku_product_old_id,
                    'actual_quantity_batch' => $batch->actual_quantity_batch,
                    'damaged_quantity_batch' => $batch->damaged_quantity_batch,
                    'type' => $batch->type,
                    'note' => $batch->note,
                    'created_by' => $batch->createdBy?->name,
                ];
            })
        ))->response()->setStatusCode(200);
    }
}

<?php

namespace App\Http\Controllers\Inbound;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\ScanPending;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class BastApprovalController extends Controller
{
    public function mintaApproveDataAsal(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'id_asal'     => 'required|exists:product_olds,id',
                'edited_name' => 'nullable|string',
                'edited_qty'  => 'nullable|integer',
            ]
        );

        if ($validator->fails()) {
            return (new ResponseResource(
                false,
                'Validation error',
                $validator->errors()
            ))->response()->setStatusCode(422);
        }

        DB::beginTransaction();

        try {
            $user = auth()->user();

            $data = ScanPending::where('source_model', 'Product_old')
                ->where('source_id', $request->id_asal)
                ->where('status', 'pending')
                ->first();

            if ($data) {
                return (new ResponseResource(
                    false,
                    'Request approve untuk data ini sudah dibuat',
                    null
                ))->response()->setStatusCode(422);
            }

            $scanPending = ScanPending::create([
                'source_model' => 'Product_old',
                'source_id'    => $request->id_asal,
                'edited_name'  => $request->edited_name,
                'edited_qty'   => $request->edited_qty,
                'editor_id'    => $user->id,
            ]);

            DB::commit();

            return (new ResponseResource(
                true,
                'Request approve berhasil dibuat',
                $scanPending
            ))->response()->setStatusCode(200);
        } catch (Throwable $e) {

            DB::rollBack();

            Log::error('Gagal membuat approval data asal', [
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
                'file'  => $e->getFile(),
            ]);

            return (new ResponseResource(
                false,
                'Terjadi kesalahan pada server',
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function checkScanPaused()
    {
        try {

            $user = auth()->user();

            $hasPendingApproval = \App\Models\ScanPending::where('editor_id', $user->id)
                ->where('status', 'pending')
                ->exists();

            $idSourceProducts = \App\Models\ScanPending::where('editor_id', $user->id)
                ->where('status', 'pending')
                ->where('source_model', 'Product_old')
                ->pluck('source_id')
                ->toArray();

            $barcodeOldProducts = \App\Models\Product_old::whereIn('id', $idSourceProducts)->first();
            // dd($barcodeOldProducts->old_barcode_product);

            return (new ResponseResource(
                true,
                'Success',
                [
                    'barcode_old_product' => $barcodeOldProducts?->old_barcode_product,
                    'scan_paused' => $hasPendingApproval
                ]
            ))->response()->setStatusCode(200);
        } catch (\Throwable $e) {

            Log::error('Gagal check scan paused', [
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
                'file'  => $e->getFile(),
            ]);
            // dd($e->getMessage());

            return (new ResponseResource(
                false,
                'Terjadi kesalahan pada server',
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function scannerBaru(Request $request)
    {
        $codeDocument = $request->input('code_document');
        $oldBarcode = $request->input('old_barcode_product');

        if (!$codeDocument) {
            return new ResponseResource(false, "Code document tidak boleh kosong.", null);
        }

        if (!$oldBarcode) {
            return new ResponseResource(false, "Barcode tidak boleh kosong.", null);
        }

        $isProcessed = \App\Models\New_product::where('code_document', $codeDocument)
            ->where('old_barcode_product', $oldBarcode)
            ->exists();

        if ($isProcessed) {
            return new ResponseResource(false, "Produk ini sudah selesai diproses.", []);
        }

        $product = \App\Models\Product_old::where('code_document', $codeDocument)
            ->where('old_barcode_product', $oldBarcode)
            ->first();

        if (!$product) {
            return new ResponseResource(false, "Produk ini sudah selesai di scan", []);
        }

        $approvedPending = \App\Models\ScanPending::where('source_model', 'Product_old')
            ->where('source_id', $product->id)
            ->where('status', 'approved')
            ->latest()
            ->first();
        dd($approvedPending, $product->id);

        $product->edited_name_product =
            $approvedPending?->edited_name ?? $product->old_name_product;
        dd($product->edited_name_product);

        $product->edited_quantity_product =
            $approvedPending?->edited_qty ?? $product->old_quantity_product;

        $product->approval_status = $approvedPending?->status;

        $response = [
            'product' => $product
        ];

        if ($product->old_price_product <= 99999) {
            $response['color_tags'] = \App\Models\Color_tag::where('min_price_color', '<=', $product->old_price_product)
                ->where('max_price_color', '>=', $product->old_price_product)
                ->get();
        }

        return new ResponseResource(true, "Produk ditemukan.", $response);
    }
}

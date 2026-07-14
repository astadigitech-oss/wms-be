<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\MigrateBulkyProduct;
use App\Models\New_product;
use App\Models\StagingProduct;
use App\Services\MovementService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MigrateBulkyProductController extends Controller
{
    //
    public function toDisplay(Request $request, $id)
    {
        $user = auth()->user();
        DB::beginTransaction();
        try {
            $migrateProduct = MigrateBulkyProduct::find($id);
            if (!$migrateProduct) return (new ResponseResource(false, "Produk tidak ditemukan", null))->response()->setStatusCode(404);

            $parentDocumentId = $migrateProduct->migrate_bulky_id;
            $parentDocument = $migrateProduct->migrateBulky;

            $validator = Validator::make($request->all(), [
                'new_barcode_product' => 'required',
                'new_price_product' => 'required|numeric',
                'old_price_product' => 'required|numeric',
                'new_category_product' => 'nullable',
                'new_discount' => 'nullable|numeric',
                'is_extra' => 'required|boolean',
            ]);

            if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

            $inputData = $request->except(['condition', 'deskripsi']);
            $inputData['new_date_in_product'] = Carbon::now('Asia/Jakarta')->toDateString();
            $inputData['new_status_product'] = 'display';

            $newQuality = [
                'lolos'    => 'lolos',
                'damaged'  => null,
                'abnormal' => null,
                'non'      => null,
            ];

            $inputData['new_quality'] = json_encode($newQuality);
            $inputData['actual_new_quality'] = json_encode($newQuality);

            // Sinkronkan kolom status
            $inputData['is_lolos'] = 'lolos';
            $inputData['is_damaged'] = null;
            $inputData['is_abnormal'] = null;
            $inputData['is_non'] = null;

            $inputData['user_id'] = $user->id;
            $inputData['weight'] = $migrateProduct->weight ?? null;
            $inputData['code_document'] = $migrateProduct->code_document_inbound ?? null;
            $inputData['actual_old_price_product'] = $migrateProduct->old_price_product ?? null;
            //  $inputData['is_so'] = "done";
            // $inputData['user_so'] = $user->id;
            $inputData['is_extra'] = $request->boolean('is_extra');

            $manualDiscount = $request->input('new_discount', 0);
            $inputData['new_discount'] = $manualDiscount;
            $inputData['display_price'] = ($manualDiscount > 0)
                ? $inputData['new_price_product'] - $manualDiscount
                : $inputData['new_price_product'];

            if ($inputData['old_price_product'] < 100000) {
                $inputData['new_category_product'] = null;
            } else {
                $inputData['new_tag_product'] = null;
            }

            $targetBarcode = $inputData['new_barcode_product'];
            $existingProduct = null;

            $existingDisplay = New_product::where('new_barcode_product', $targetBarcode)->first();

            if ($existingDisplay) {
                $existingDisplay->update($inputData);
                $existingProduct = $existingDisplay;
            } else {
                $existingStaging = StagingProduct::where('new_barcode_product', $targetBarcode)->first();

                if ($existingStaging) {
                    $existingStaging->delete();
                    $existingProduct = New_product::create($inputData);
                } else {
                    $existingProduct = New_product::create($inputData);
                }
            }

            $migrateProduct->delete();

            $remainingItems = MigrateBulkyProduct::where('migrate_bulky_id', $parentDocumentId)->count();

            if ($remainingItems === 0 && $parentDocument) {
                $parentDocument->delete();
            }

            if (function_exists('logUserAction')) {
                logUserAction($request, $user, "migrate-bulky/to-display", "Moved to Display: $targetBarcode");
            }

            DB::commit();

            // [Movement] repair → display_reguler / display_color
            try {
                $to = $inputData['old_price_product'] < 100000 ? 'display_color' : 'display_reguler';
                MovementService::log(
                    productId: $existingProduct->new_barcode_product,
                    isSku: false,
                    type: 'Move',
                    typeOut: null,
                    from: 'repair',
                    to: $to,
                    qty: $existingProduct->new_quantity_product
                );
            } catch (\Exception $movEx) {
                Log::error('[Movement] migrate-bulky toDisplay log failed: ' . $movEx->getMessage());
            }

            return new ResponseResource(true, "Produk dipindahkan ke Display", $existingProduct);
        } catch (Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Error: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }
}

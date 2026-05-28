<?php

namespace App\Http\Controllers\Outbound;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\BagProducts;
use App\Models\BulkyDocument;
use App\Models\BulkySale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CargoController extends Controller
{
    public function listBagCargo(Request $request, $idCargo)
    {
        try {

            $q = $request->q;

            /*
    |--------------------------------------------------------------------------
    | CHECK CARGO
    |--------------------------------------------------------------------------
    */
            // dd($idCargo);
            $bulkyDocument = BulkyDocument::where('id', $idCargo)
                ->first();

            if (!$bulkyDocument) {

                return (new ResponseResource(
                    false,
                    "Cargo tidak ditemukan!",
                    []
                ))->response()->setStatusCode(404);
            }

            /*
    |--------------------------------------------------------------------------
    | GET BAGS
    |--------------------------------------------------------------------------
    */

            $bags = BagProducts::where('bulky_document_id', $idCargo)
                ->when($q, function ($query) use ($q) {

                    $query->where(function ($subQuery) use ($q) {

                        $subQuery->where('name_bag', 'like', '%' . $q . '%')
                            ->orWhere('barcode_bag', 'like', '%' . $q . '%');
                    });
                })
                ->latest()
                ->paginate(10);

            return (new ResponseResource(
                true,
                "Daftar bag dalam cargo",
                $bags
            ))->response();
        } catch (\Exception $e) {

            Log::error('LIST BAG CARGO ERROR: ' . $e->getMessage());

            return (new ResponseResource(
                false,
                "Gagal mengambil data bag cargo!",
                $e->getMessage()
            ))->response()->setStatusCode(500);
        }
    }

    public function buatCargo(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make(
            $request->all(),
            [
                'name' => 'required|string|max:255',
                'type' => 'required|in:' . BulkyDocument::TYPE_OFFLINE . ',' . BulkyDocument::TYPE_ONLINE,
            ]
        );

        if ($validator->fails()) {
            $errors = new ResponseResource(
                false,
                "Input tidak valid!",
                $validator->errors()
            );

            return $errors->response()->setStatusCode(422);
        }

        DB::beginTransaction();

        try {

            // Ambil data terakhir dan lock
            $lastCargo = BulkyDocument::orderByDesc('id')
                ->lockForUpdate()
                ->first();

            // Ambil nomor terakhir
            if (
                $lastCargo &&
                preg_match('/^(\d+)[\.\-]/', $lastCargo->name_document, $matches)
            ) {
                $nextNumber = intval($matches[1]) + 1;
            } else {
                $nextNumber = 1;
            }

            // Format nama final
            $finalName = $nextNumber . '-' . $request->name;

            // Cek duplicate
            if (BulkyDocument::where('name_document', $finalName)->exists()) {

                DB::rollBack();

                return (new ResponseResource(
                    false,
                    "Nama cargo sudah digunakan!",
                    null
                ))->response()->setStatusCode(409);
            }

            // Simpan cargo
            $cargo = new BulkyDocument();
            $cargo->user_id = $user->id;
            $cargo->name_user = $user->name;
            $cargo->name_document = $finalName;
            $cargo->type = $request->type;
            $cargo->save();

            DB::commit();

            // Hide timestamp
            $cargo->makeHidden([
                'created_at',
                'updated_at'
            ]);

            $response = new ResponseResource(
                true,
                "Berhasil membuat cargo!",
                $cargo
            );

            return $response->response();
        } catch (\Exception $e) {

            DB::rollBack();

            $response = new ResponseResource(
                false,
                "Gagal membuat cargo!",
                $e->getMessage()
            );

            return $response->response()->setStatusCode(500);
        }
    }

    public function tambahBag(Request $request, $idCargo)
    {
        DB::beginTransaction();

        try {

            /*
        |--------------------------------------------------------------------------
        | VALIDATOR
        |--------------------------------------------------------------------------
        */

            $validator = Validator::make($request->all(), [
                'barcode_bag' => 'required',
            ]);

            if ($validator->fails()) {

                return (new ResponseResource(
                    false,
                    "Input tidak valid!",
                    $validator->errors()
                ))->response()->setStatusCode(422);
            }

            /*
        |--------------------------------------------------------------------------
        | CHECK BULKY DOCUMENT
        |--------------------------------------------------------------------------
        */

            $bulkyDocument = BulkyDocument::where('id', $idCargo)
                ->where('status_bulky', 'proses')
                ->where('is_sale', 'not sale')
                ->first();
            // dd($bulkyDocument);

            if (!$bulkyDocument) {

                DB::rollBack();

                return (new ResponseResource(
                    false,
                    "Cargo tidak valid atau sudah sale!",
                    []
                ))->response()->setStatusCode(422);
            }

            /*
        |--------------------------------------------------------------------------
        | CHECK BAG
        |--------------------------------------------------------------------------
        */

            $bagProduct = BagProducts::where('barcode_bag', $request->barcode_bag)
                ->first();
            // dd($bagProduct);

            if (!$bagProduct) {

                DB::rollBack();

                return (new ResponseResource(
                    false,
                    "Bag product tidak ditemukan!",
                    []
                ))->response()->setStatusCode(404);
            }

            /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

            // bag sudah masuk cargo lain
            if ($bagProduct->bulky_document_id) {

                DB::rollBack();

                return (new ResponseResource(
                    false,
                    "Bag sudah terhubung dengan cargo lain!",
                    []
                ))->response()->setStatusCode(422);
            }

            // bag kosong
            if ($bagProduct->total_product <= 0) {

                DB::rollBack();

                return (new ResponseResource(
                    false,
                    "Bag kosong, tidak bisa discan!",
                    []
                ))->response()->setStatusCode(422);
            }

            // status bag harus process
            if ($bagProduct->status !== 'process') {

                DB::rollBack();

                return (new ResponseResource(
                    false,
                    "Bag tidak dalam status process, tidak bisa discan!",
                    []
                ))->response()->setStatusCode(422);
            }

            /*
        |--------------------------------------------------------------------------
        | UPDATE BAG
        |--------------------------------------------------------------------------
        */

            $bagProduct->update([
                'bulky_document_id' => $bulkyDocument->id,
                'status' => 'done',
            ]);
            // dd($bagProduct);

            /*
        |--------------------------------------------------------------------------
        | RECALCULATE BULKY DOCUMENT
        |--------------------------------------------------------------------------
        */

            $allBagIds = BagProducts::where('bulky_document_id', $bulkyDocument->id)
                ->pluck('id');

            $bulkySales = BulkySale::whereIn('bag_product_id', $allBagIds);

            $bulkySales->update([
                'bulky_document_id' => $bulkyDocument->id,
            ]);
            // dd($bulkySales);

            $bulkyDocument->update([
                'total_product_bulky' => $bulkySales->count(),
                'total_old_price_bulky' => $bulkySales->sum('old_price_bulky_sale'),
                'after_price_bulky' => $bulkySales->sum('after_price_bulky_sale'),
            ]);

            DB::commit();

            return (new ResponseResource(
                true,
                "Bag berhasil dimasukkan ke cargo!",
                [
                    'cargo_id' => $bulkyDocument->id,
                    'bag_product_id' => $bagProduct->id,
                    'total_product_bulky' => $bulkyDocument->total_product_bulky,
                    'total_old_price_bulky' => $bulkyDocument->total_old_price_bulky,
                    'after_price_bulky' => $bulkyDocument->after_price_bulky,
                ]
            ))->response();
        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('TAMBAH BAG CARGO ERROR: ' . $e->getMessage());

            return (new ResponseResource(
                false,
                "Gagal scan bag ke cargo!",
                $e->getMessage()
            ))->response()->setStatusCode(500);
        }
    }

    public function takeoutBag(Request $request, $idCargo)
    {
        DB::beginTransaction();

        try {

            /*
    |--------------------------------------------------------------------------
    | VALIDATOR
    |--------------------------------------------------------------------------
    */

            $validator = Validator::make($request->all(), [
                'bag_product_id' => 'required|exists:bag_products,id',
            ]);

            if ($validator->fails()) {

                return (new ResponseResource(
                    false,
                    "Input tidak valid!",
                    $validator->errors()
                ))->response()->setStatusCode(422);
            }

            /*
    |--------------------------------------------------------------------------
    | CHECK CARGO
    |--------------------------------------------------------------------------
    */

            $bulkyDocument = BulkyDocument::where('id', $idCargo)
                ->where('status_bulky', 'proses')
                ->where('is_sale', 'not sale')
                ->first();
            // dd($bulkyDocument);
            if (!$bulkyDocument) {

                DB::rollBack();

                return (new ResponseResource(
                    false,
                    "Cargo tidak ditemukan atau sudah selesai!",
                    []
                ))->response()->setStatusCode(404);
            }

            /*
    |--------------------------------------------------------------------------
    | CHECK BAG
    |--------------------------------------------------------------------------
    */

            $bagProduct = BagProducts::where('id', $request->bag_product_id)
                ->where('bulky_document_id', $bulkyDocument->id)
                ->first();

            if (!$bagProduct) {

                DB::rollBack();

                return (new ResponseResource(
                    false,
                    "Bag tidak ditemukan di cargo ini!",
                    []
                ))->response()->setStatusCode(404);
            }

            /*
    |--------------------------------------------------------------------------
    | UPDATE BAG
    |--------------------------------------------------------------------------
    */

            $bagProduct->update([
                'bulky_document_id' => null,
                'status' => 'process',
            ]);

            /*
    |--------------------------------------------------------------------------
    | UPDATE BULKY SALES
    |--------------------------------------------------------------------------
    */

            BulkySale::where('bag_product_id', $bagProduct->id)
                ->update([
                    'bulky_document_id' => null,
                ]);

            /*
    |--------------------------------------------------------------------------
    | RECALCULATE CARGO
    |--------------------------------------------------------------------------
    */

            $allBagIds = BagProducts::where('bulky_document_id', $bulkyDocument->id)
                ->pluck('id');

            $bulkySales = BulkySale::whereIn('bag_product_id', $allBagIds);

            $bulkyDocument->update([
                'total_product_bulky' => $bulkySales->count(),
                'total_old_price_bulky' => $bulkySales->sum('old_price_bulky_sale'),
                'after_price_bulky' => $bulkySales->sum('after_price_bulky_sale'),
            ]);

            DB::commit();

            return (new ResponseResource(
                true,
                "Bag berhasil dikeluarkan dari cargo!",
                [
                    'cargo_id' => $bulkyDocument->id,
                    'bag_product_id' => $bagProduct->id,
                    'total_product_bulky' => $bulkyDocument->fresh()->total_product_bulky,
                    'total_old_price_bulky' => $bulkyDocument->fresh()->total_old_price_bulky,
                    'after_price_bulky' => $bulkyDocument->fresh()->after_price_bulky,
                ]
            ))->response();
        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('TAKEOUT BAG CARGO ERROR: ' . $e->getMessage());

            return (new ResponseResource(
                false,
                "Gagal mengeluarkan bag dari cargo!",
                $e->getMessage()
            ))->response()->setStatusCode(500);
        }
    }

    public function infoDetailCargo($idCargo)
    {
        try {

            /*
    |--------------------------------------------------------------------------
    | CHECK CARGO
    |--------------------------------------------------------------------------
    */

            $cargo = BulkyDocument::where('id', $idCargo)
                ->first();

            if (!$cargo) {

                return (new ResponseResource(
                    false,
                    "Cargo tidak ditemukan!",
                    []
                ))->response()->setStatusCode(404);
            }

            /*
    |--------------------------------------------------------------------------
    | GET ALL BAG IDS
    |--------------------------------------------------------------------------
    */

            $bagIds = BagProducts::where('bulky_document_id', $cargo->id)
                ->pluck('id');

            /*
    |--------------------------------------------------------------------------
    | AGGREGATE FROM DB
    |--------------------------------------------------------------------------
    */

            $summary = BulkySale::whereIn('bag_product_id', $bagIds)
                ->selectRaw('
                COUNT(*) as total_product,
                COALESCE(SUM(old_price_bulky_sale), 0) as total_old_price
            ')
                ->first();

            $totalBag = $bagIds->count();

            $data = [
                'id' => $cargo->id,
                'name_document' => $cargo->name_document,
                'type' => $cargo->type,
                'status_bulky' => $cargo->status_bulky,
                'is_sale' => $cargo->is_sale,

                // total bag
                'total_bag' => $totalBag,

                // total product dari bulky_sales
                'total_product' => (int) $summary->total_product,

                // total harga dari bulky_sales
                'total_old_price' => (int) $summary->total_old_price,

                // volume
                'length' => $cargo->length,
                'width' => $cargo->width,
                'height' => $cargo->height,
                'weight' => $cargo->weight,

                'created_at' => $cargo->created_at,
            ];

            return (new ResponseResource(
                true,
                "Detail cargo",
                $data
            ))->response();
        } catch (\Exception $e) {

            Log::error('INFO DETAIL CARGO ERROR: ' . $e->getMessage());

            return (new ResponseResource(
                false,
                "Gagal mengambil detail cargo!",
                $e->getMessage()
            ))->response()->setStatusCode(500);
        }
    }

    public function setVolumeDanBerat(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'length' => 'required|numeric',
            'width'  => 'required|numeric',
            'height' => 'required|numeric',
            'weight' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(
                false,
                "Input dimensi tidak valid!",
                $validator->errors()
            ))->response()->setStatusCode(422);
        }

        $doc = BulkyDocument::findOrFail($id);

        // 1. hanya boleh kalau NOT SALE
        if ($doc->is_sale !== BulkyDocument::SALE_NOT) {
            return (new ResponseResource(
                false,
                "Dokumen sudah dalam status sale dan tidak bisa diubah",
                null
            ))->response()->setStatusCode(400);
        }

        DB::beginTransaction();
        try {
            $doc->update([
                'length'           => $request->length,
                'width'            => $request->width,
                'height'           => $request->height,
                'weight'           => $request->weight,
                'fleet_estimation' => $request->fleet_estimation ?? null,
            ]);

            DB::commit();

            return (new ResponseResource(
                true,
                "Berhasil diupdate",
                [
                    'id' => $doc->id,
                    'length' => $doc->length,
                    'width' => $doc->width,
                    'height' => $doc->height,
                    'weight' => $doc->weight,
                    'fleet_estimation' => $doc->fleet_estimation,
                    'status_bulky' => $doc->status_bulky,
                ]
            ))->response();
        } catch (\Exception $e) {
            DB::rollBack();

            return (new ResponseResource(
                false,
                "Terjadi kesalahan sistem: " . $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function toggleStatusBulky($idCargo)
    {
        $doc = BulkyDocument::with('bagProducts')->findOrFail($idCargo);

        // 1. Validasi is_sale
        if ($doc->is_sale !== BulkyDocument::SALE_NOT) {
            return (new ResponseResource(
                false,
                "Status tidak bisa diubah karena sudah/sedang dalam proses sale",
                null
            ))->response()->setStatusCode(400);
        }

        DB::beginTransaction();
        try {

            // 2. PROSES -> SELESAI
            if ($doc->status_bulky === 'proses') {

                if (
                    is_null($doc->length) ||
                    is_null($doc->width) ||
                    is_null($doc->height) ||
                    is_null($doc->weight)
                ) {
                    return (new ResponseResource(
                        false,
                        "Lengkapi dimensi dan berat sebelum mengubah status ke selesai",
                        null
                    ))->response()->setStatusCode(422);
                }

                $doc->status_bulky = 'selesai';
                $doc->save();

                // 🔥 update semua bag_products jadi done
                $doc->bagProducts()->update([
                    'status' => 'done'
                ]);
            }
            // 3. SELESAI -> PROSES
            else {
                $doc->status_bulky = 'proses';
                $doc->save();
            }

            DB::commit();

            return (new ResponseResource(
                true,
                "Status berhasil diupdate",
                [
                    'id' => $doc->id,
                    'status_bulky' => $doc->status_bulky
                ]
            ))->response();
        } catch (\Exception $e) {
            DB::rollBack();

            return (new ResponseResource(
                false,
                "Terjadi kesalahan sistem: " . $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }
}

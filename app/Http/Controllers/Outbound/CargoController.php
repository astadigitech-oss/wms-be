<?php

namespace App\Http\Controllers\Outbound;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\BulkyDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CargoController extends Controller
{
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
}

<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Buyer;
use App\Models\BulkyDocument;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CargoNewController extends Controller
{
    public function createBulkyDocumentNew(Request $request)
    {
        try {
            $user = auth()->user();

            $isOffline = $request->input('type') === BulkyDocument::TYPE_OFFLINE;
            $isOnline = $request->input('type') === BulkyDocument::TYPE_ONLINE;

            $validator = Validator::make(
                $request->all(),
                [
                    'discount_bulky' => 'nullable|numeric|min:0|max:100',
                    'buyer_id' => 'nullable|exists:buyers,id',
                    'type' => ['required', Rule::in([BulkyDocument::TYPE_OFFLINE, BulkyDocument::TYPE_ONLINE])],
                    'name_document' => $isOffline ? 'required|string|max:255' : 'nullable|string|max:255',
                    'category_bulky_id' => $isOnline ? 'required|string|max:255' : 'nullable|string|max:255',
                    'category_bulky_name' => $isOnline ? 'required|string|max:255' : 'nullable|string|max:255',
                ]
            );

            if ($validator->fails()) {
                $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
                return $resource->response()->setStatusCode(422);
            }

            $buyer = null;
            if ($request->filled('buyer_id')) {
                $buyer = Buyer::find($request->buyer_id);
            }

            if ($isOffline) {
                DB::beginTransaction();

                $baseName = trim(preg_replace('/\s+/', ' ', (string) $request->name_document));

                $existingNames = BulkyDocument::where(function ($query) use ($baseName) {
                    $query->where('name_document', $baseName)
                        ->orWhere('name_document', 'LIKE', $baseName . ' %')
                        ->orWhere('name_document', 'LIKE', $baseName . ' (%)');
                })
                    ->lockForUpdate()
                    ->pluck('name_document');

                $nextNumber = 1;
                foreach ($existingNames as $name) {
                    if (preg_match('/^' . preg_quote($baseName, '/') . '(?: \((\d+)\)| (\d+))?$/i', $name, $matches)) {
                        $matchedNumber = $matches[1] ?? $matches[2] ?? null;
                        $nextNumber = max($nextNumber, $matchedNumber !== null ? intval($matchedNumber) + 1 : 2);
                    }
                }

                $finalName = $baseName . ' ' . $nextNumber;

                if (BulkyDocument::where('name_document', $finalName)->exists()) {
                    DB::rollBack();
                    return (new ResponseResource(false, "Nama dokumen sudah digunakan, silakan coba lagi.", null))->response()->setStatusCode(409);
                }

                $bulkyDocument = BulkyDocument::create([
                    'user_id'               => $user->id,
                    'name_user'             => $user->name,
                    'total_product_bulky'   => 0,
                    'total_old_price_bulky' => 0,
                    'buyer_id'              => $buyer?->id,
                    'name_buyer'            => $buyer?->name_buyer,
                    'discount_bulky'        => $request->discount_bulky ?? 0,
                    'after_price_bulky'     => 0,
                    'category_bulky'        => null,
                    'status_bulky'          => 'proses',
                    'name_document'         => $finalName,
                    'is_sale'               => BulkyDocument::SALE_NOT,
                    'type'                  => $request->type,
                ]);

                DB::commit();

                $resource = new ResponseResource(true, "Data dokumen Cargo berhasil dibuat!", $bulkyDocument);
                return $resource->response();
            }

            $categoryName = trim((string) $request->input('category_bulky_name'));
            if ($categoryName === '') {
                return (new ResponseResource(false, "category_bulky_name wajib diisi untuk cargo online!", null))
                    ->response()
                    ->setStatusCode(422);
            }

            $cleanCategoryName = preg_replace('/\s+/', ' ', $categoryName);
            if (preg_match('/\S+\s+[A-Z0-9]{2,}$/', $cleanCategoryName)) {
                $cleanCategoryName = preg_replace('/\s+[A-Z0-9]{2,}$/', '', $cleanCategoryName);
            }
            $cleanCategoryName = trim($cleanCategoryName);

            $baseName = trim('Palet ' . $cleanCategoryName);

            $existingNames = BulkyDocument::where(function ($query) use ($baseName) {
                $query->where('name_document', $baseName)
                    ->orWhere('name_document', 'LIKE', $baseName . ' %')
                    ->orWhere('name_document', 'LIKE', $baseName . ' (%)');
            })
                ->lockForUpdate()
                ->pluck('name_document');

            $nextNumber = 1;
            foreach ($existingNames as $name) {
                if (preg_match('/^' . preg_quote($baseName, '/') . '(?: \((\d+)\)| (\d+))?$/i', $name, $matches)) {
                    $matchedNumber = $matches[1] ?? $matches[2] ?? null;
                    $nextNumber = max($nextNumber, $matchedNumber !== null ? intval($matchedNumber) + 1 : 2);
                }
            }

            $finalName = $baseName . ' ' . $nextNumber;

            $categoryPayload = [
                'category_bulky' => null,
                'category_bulky_id' => $request->category_bulky_id,
                'category_bulky_name' => $categoryName,
            ];

            DB::beginTransaction();

            $bulkyDocument = BulkyDocument::create([
                'user_id' => $user->id,
                'name_user' => $user->name,
                'total_product_bulky' => 0,
                'total_old_price_bulky' => 0,
                'buyer_id' => $buyer?->id,
                'name_buyer' => $buyer?->name_buyer,
                'discount_bulky' => $request->discount_bulky ?? 0,
                'after_price_bulky' => 0,
                'status_bulky' => 'proses',
                'name_document' => $finalName,
                'is_sale' => BulkyDocument::SALE_NOT,
                'type' => $request->type,
            ] + $categoryPayload);

            DB::commit();

            $resource = new ResponseResource(true, "Data dokumen Cargo berhasil dibuat!", $bulkyDocument);
            return $resource->response();
        } catch (\Exception $e) {
            DB::rollBack();

            $resource = new ResponseResource(false, "Gagal membuat dokumen cargo!", $e->getMessage());
            return $resource->response()->setStatusCode(500);
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

        // Hanya boleh diubah jika status masih NOT SALE
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
                // is_sale sengaja tidak diubah agar tetap SALE_NOT
            ]);

            DB::commit();

            // Refresh agar data yang dikembalikan merupakan data terbaru
            $doc->refresh();

            return (new ResponseResource(
                true,
                "Berhasil diupdate",
                [
                    'id'               => $doc->id,
                    'length'           => $doc->length,
                    'width'            => $doc->width,
                    'height'           => $doc->height,
                    'weight'           => $doc->weight,
                    'fleet_estimation' => $doc->fleet_estimation,
                    'status_bulky'     => $doc->status_bulky,
                    'is_sale'          => $doc->is_sale,
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

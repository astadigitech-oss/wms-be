<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Buyer;
use App\Models\BulkyDocument;
use App\Models\BulkySale;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class CargoNewController extends Controller
{
    private function normalizeDocumentName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    private function generateUniqueDocumentName(?int $ignoreId, string $requestedName): string
    {
        $baseName = $this->normalizeDocumentName($requestedName);

        $existingNames = BulkyDocument::when($ignoreId !== null, function ($query) use ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            })
            ->where(function ($query) use ($baseName) {
                $query->where('name_document', $baseName)
                    ->orWhere('name_document', 'LIKE', $baseName . ' %');
            })
            ->lockForUpdate()
            ->pluck('name_document');

        $hasMatch = false;
        $maxNumber = 0;

        foreach ($existingNames as $name) {
            if ($name === $baseName) {
                $hasMatch = true;
                continue;
            }

            if (preg_match('/^' . preg_quote($baseName, '/') . '(?: \((\d+)\)| (\d+))$/i', $name, $matches)) {
                $hasMatch = true;
                $matchedNumber = !empty($matches[1]) ? intval($matches[1]) : intval($matches[2]);
                $maxNumber = max($maxNumber, $matchedNumber);
            }
        }

        if (!$hasMatch) {
            return $baseName;
        }

        $nextNumber = $maxNumber > 0 ? $maxNumber + 1 : 1;

        return $baseName . ' ' . $nextNumber;
    }

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
                $finalName = $this->generateUniqueDocumentName(null, (string) $request->name_document);

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
            $finalName = $this->generateUniqueDocumentName(null, $baseName);

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

        $doc = BulkyDocument::with('bulkySales')->findOrFail($id);

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
            // Update dimensi
            $doc->update([
                'length'           => $request->length,
                'width'            => $request->width,
                'height'           => $request->height,
                'weight'           => $request->weight,
                'fleet_estimation' => $request->fleet_estimation ?? null,
            ]);

            // Reload data terbaru
            $doc->refresh();
            $doc->load('bulkySales');

            // Generate PDF
            $pdf = Pdf::loadView('pdf.cargo_online', [
                'doc' => $doc,
            ]);

            // Hapus file lama jika ada
            if (!empty($doc->cargo_file) && Storage::exists($doc->cargo_file)) {
                Storage::delete($doc->cargo_file);
            }

            // Generate nama file unik
            $fileName = 'Cargo-Online-' . Str::uuid() . '.pdf';
            $filePath = 'public/pdfs/cargo/' . $fileName;

            // Simpan PDF
            Storage::put($filePath, $pdf->output());

            // Simpan path file ke database
            $doc->update([
                'cargo_file' => $filePath,
            ]);

            DB::commit();

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
                    'cargo_file'       => $doc->cargo_file,
                ]
            ))->response();
        } catch (\Exception $e) {
            DB::rollBack();

            // Hapus file jika sempat dibuat tetapi transaksi gagal
            if (isset($filePath) && Storage::exists($filePath)) {
                Storage::delete($filePath);
            }

            return (new ResponseResource(
                false,
                "Terjadi kesalahan sistem: " . $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

    // public function updateSalePrice(Request $request)
    // {
    //     DB::beginTransaction();

    //     try {

    //         $validator = Validator::make($request->all(), [
    //             'bulky_document_id' => 'required|exists:bulky_documents,id',
    //             'discount'          => 'required|numeric|min:0|max:100',
    //         ]);

    //         if ($validator->fails()) {
    //             return (new ResponseResource(
    //                 false,
    //                 'Validasi gagal.',
    //                 $validator->errors()
    //             ))->response()->setStatusCode(422);
    //         }

    //         $bulkyDocument = BulkyDocument::find($request->bulky_document_id);

    //         if (!$bulkyDocument) {
    //             DB::rollBack();

    //             return (new ResponseResource(
    //                 false,
    //                 'Bulky Document tidak ditemukan.',
    //                 null
    //             ))->response()->setStatusCode(404);
    //         }

    //         $bulkySales = BulkySale::where(
    //             'bulky_document_id',
    //             $bulkyDocument->id
    //         )->get();

    //         if ($bulkySales->isEmpty()) {
    //             DB::rollBack();

    //             return (new ResponseResource(
    //                 false,
    //                 'Data Bulky Sale tidak ditemukan.',
    //                 null
    //             ))->response()->setStatusCode(404);
    //         }

    //         $discount = $request->discount;

    //         $totalAfterPriceBulky = 0;

    //         foreach ($bulkySales as $sale) {

    //             $afterPriceBulkySale = round(
    //                 $sale->old_price_bulky_sale * (1 - ($discount / 100))
    //             );

    //             $sale->update([
    //                 'after_price_bulky_sale' => $afterPriceBulkySale,
    //                 'display_price'          => $afterPriceBulkySale,
    //             ]);

    //             $totalAfterPriceBulky += $afterPriceBulkySale;
    //         }

    //         $bulkyDocument->update([
    //             'after_price_bulky' => $totalAfterPriceBulky,
    //             'is_sale'           => 'ready',
    //         ]);

    //         DB::commit();

    //         return (new ResponseResource(
    //             true,
    //             'Harga jual berhasil diperbarui.',
    //             [
    //                 'bulky_document_id' => $bulkyDocument->id,
    //                 'discount'          => $discount,
    //                 'after_price_bulky' => $totalAfterPriceBulky,
    //                 'is_sale'           => 'ready',
    //             ]
    //         ))->response()->setStatusCode(200);
    //     } catch (\Exception $e) {

    //         DB::rollBack();

    //         return (new ResponseResource(
    //             false,
    //             $e->getMessage(),
    //             null
    //         ))->response()->setStatusCode(500);
    //     }
    // }

    public function updateSalePrice(Request $request)
    {
        DB::beginTransaction();

        try {

            $validator = Validator::make($request->all(), [
                'bulky_document_id' => 'required|exists:bulky_documents,id',
                'discount'          => 'required|numeric|min:0|max:100',
            ]);

            if ($validator->fails()) {
                return (new ResponseResource(
                    false,
                    'Validasi gagal.',
                    $validator->errors()
                ))->response()->setStatusCode(422);
            }

            $bulkyDocument = BulkyDocument::find($request->bulky_document_id);

            if (!$bulkyDocument) {
                DB::rollBack();

                return (new ResponseResource(
                    false,
                    'Bulky Document tidak ditemukan.',
                    null
                ))->response()->setStatusCode(404);
            }

            // Hanya boleh update jika status masih "not sale"
            if ($bulkyDocument->is_sale !== BulkyDocument::SALE_NOT) {
                DB::rollBack();

                return (new ResponseResource(
                    false,
                    'Harga jual tidak dapat diperbarui karena status penjualan sudah ready atau sale.',
                    [
                        'current_status'  => $bulkyDocument->is_sale,
                        'required_status' => BulkyDocument::SALE_NOT,
                    ]
                ))->response()->setStatusCode(422);
            }

            $bulkySales = BulkySale::where(
                'bulky_document_id',
                $bulkyDocument->id
            )->get();

            if ($bulkySales->isEmpty()) {
                DB::rollBack();

                return (new ResponseResource(
                    false,
                    'Data Bulky Sale tidak ditemukan.',
                    null
                ))->response()->setStatusCode(404);
            }

            $discount = $request->discount;

            $totalAfterPriceBulky = 0;

            foreach ($bulkySales as $sale) {

                $afterPriceBulkySale = round(
                    $sale->old_price_bulky_sale * (1 - ($discount / 100))
                );

                $sale->update([
                    'after_price_bulky_sale' => $afterPriceBulkySale,
                    'display_price'          => $afterPriceBulkySale,
                ]);

                $totalAfterPriceBulky += $afterPriceBulkySale;
            }

            $bulkyDocument->update([
                'after_price_bulky' => $totalAfterPriceBulky,
                'is_sale'           => BulkyDocument::SALE_READY,
            ]);

            DB::commit();

            return (new ResponseResource(
                true,
                'Harga jual berhasil diperbarui.',
                [
                    'bulky_document_id' => $bulkyDocument->id,
                    'discount'          => $discount,
                    'after_price_bulky' => $totalAfterPriceBulky,
                    'is_sale'           => BulkyDocument::SALE_READY,
                ]
            ))->response()->setStatusCode(200);
        } catch (\Exception $e) {

            DB::rollBack();

            return (new ResponseResource(
                false,
                $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function getPaletBelumDikasihHarga(Request $request)
    {
        $bulkyDocuments = BulkyDocument::query()
            ->where('type', 'Cargo Online')
            ->where('status_bulky', 'selesai')
            ->where('is_sale', 'not sale')
            ->latest()
            ->get();

        return new ResponseResource(
            true,
            'Berhasil mendapatkan daftar palet belum dikasih harga.',
            $bulkyDocuments
        );
    }

    public function getPaletSudahDikasihHarga(Request $request)
    {
        $bulkyDocuments = BulkyDocument::query()
            ->where('type', 'Cargo Online')
            ->where('status_bulky', 'selesai')
            ->where('is_sale', 'ready')
            ->where('is_sync', false)
            ->latest()
            ->get();

        return new ResponseResource(
            true,
            'Berhasil mendapatkan daftar palet sudah dikasih harga.',
            $bulkyDocuments
        );
    }

    public function updateStatusSale(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'is_sale' => 'required|in:ready,sale',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(
                false,
                "Input tidak valid!",
                $validator->errors()
            ))->response()->setStatusCode(422);
        }

        $cargo = BulkyDocument::find($id);

        if (!$cargo) {
            return (new ResponseResource(
                false,
                "Cargo tidak ditemukan!",
                null
            ))->response()->setStatusCode(404);
        }

        // hanya cargo online
        if ($cargo->type !== BulkyDocument::TYPE_ONLINE) {
            return (new ResponseResource(
                false,
                "Hanya cargo online yang dapat diupdate.",
                null
            ))->response()->setStatusCode(400);
        }

        // cargo harus selesai
        if ($cargo->status_bulky !== 'selesai') {
            return (new ResponseResource(
                false,
                "Cargo belum selesai.",
                null
            ))->response()->setStatusCode(400);
        }

        DB::beginTransaction();

        try {

            if ($request->is_sale === 'sale') {

                // hanya boleh dari READY -> SALE
                if ($cargo->is_sale !== 'ready') {
                    DB::rollBack();

                    return (new ResponseResource(
                        false,
                        "Status cargo harus READY sebelum menjadi SALE.",
                        null
                    ))->response()->setStatusCode(400);
                }

                $cargo->update([
                    'is_sale' => 'sale',
                    'date_penjualan_sale' => Carbon::now(),
                ]);

                $message = "Cargo berhasil ditandai sudah terjual.";
            } else {

                // hanya boleh dari SALE -> READY
                if ($cargo->is_sale !== 'sale') {
                    DB::rollBack();

                    return (new ResponseResource(
                        false,
                        "Cargo belum berstatus SALE.",
                        null
                    ))->response()->setStatusCode(400);
                }

                $cargo->update([
                    'is_sale' => 'ready',
                    'date_penjualan_sale' => null,
                ]);

                $message = "Status cargo berhasil dikembalikan menjadi READY.";
            }

            DB::commit();

            return (new ResponseResource(
                true,
                $message,
                [
                    'id' => $cargo->id,
                    'name_document' => $cargo->name_document,
                    'status_bulky' => $cargo->status_bulky,
                    'is_sale' => $cargo->is_sale,
                    'date_penjualan_sale' => $cargo->date_penjualan_sale,
                ]
            ))->response();
        } catch (\Exception $e) {

            DB::rollBack();

            return (new ResponseResource(
                false,
                "Terjadi kesalahan sistem.",
                $e->getMessage()
            ))->response()->setStatusCode(500);
        }
    }

    public function updateSyncCargo($id)
    {
        $cargo = BulkyDocument::find($id);

        if (!$cargo) {
            return (new ResponseResource(
                false,
                "Cargo tidak ditemukan!",
                null
            ))->response()->setStatusCode(404);
        }

        $cargo->update([
            'is_sync' => true,
        ]);

        return (new ResponseResource(
            true,
            "Cargo berhasil ditandai sudah sync.",
            [
                'id' => $cargo->id,
                'is_sync' => $cargo->is_sync,
            ]
        ))->response();
    }

    public function updateSoldCargo(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'price_sale_sold' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return (new ResponseResource(
                false,
                "Input tidak valid!",
                $validator->errors()
            ))->response()->setStatusCode(422);
        }

        $cargo = BulkyDocument::find($id);

        if (!$cargo) {
            return (new ResponseResource(
                false,
                "Cargo tidak ditemukan!",
                null
            ))->response()->setStatusCode(404);
        }

        if ($cargo->type !== BulkyDocument::TYPE_ONLINE) {
            return (new ResponseResource(
                false,
                "Hanya cargo online yang dapat ditandai sold.",
                null
            ))->response()->setStatusCode(400);
        }

        if ($cargo->is_sale !== BulkyDocument::SALE_READY) {
            return (new ResponseResource(
                false,
                "Cargo harus berstatus READY sebelum ditandai SOLD.",
                [
                    'current_status' => $cargo->is_sale,
                    'required_status' => BulkyDocument::SALE_READY,
                ]
            ))->response()->setStatusCode(400);
        }

        if ($cargo->is_sale === BulkyDocument::SALE) {
            return (new ResponseResource(
                false,
                "Cargo sudah berstatus sale.",
                null
            ))->response()->setStatusCode(400);
        }

        $cargo->update([
            'is_sale' => BulkyDocument::SALE,
            'price_sale_sold' => $request->price_sale_sold,
            'date_penjualan_sale' => Carbon::now(),
        ]);

        return (new ResponseResource(
            true,
            "Cargo berhasil ditandai terjual.",
            [
                'id' => $cargo->id,
                'is_sale' => $cargo->is_sale,
                'price_sale_sold' => $cargo->price_sale_sold,
                'date_penjualan_sale' => $cargo->date_penjualan_sale,
            ]
        ))->response();
    }
}

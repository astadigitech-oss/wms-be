<?php

namespace App\Http\Controllers\Inbound;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\CogsChannel;
use App\Models\CogsReference;
use App\Models\Document;
use App\Models\Generate;
use App\Models\Product_old;
use App\Models\RiwayatCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class NewBastController extends Controller
{
    public function mapAndMergeHeaders(Request $request)
    {
        $userId = auth()->id();
        set_time_limit(3600);
        ini_set('memory_limit', '2048M');

        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'headerMappings' => 'required|array',
                'code_document'  => 'required',
                'channel_id'     => 'nullable|string|exists:cogs_channels,id'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $headerMappings = $request->input('headerMappings');

            $mergedData = [
                'old_barcode_product' => [],
                'old_name_product' => [],
                'old_quantity_product' => [],
                'old_price_product' => []
            ];

            $ekspedisiData = Generate::all()->map(function ($item) {
                return json_decode($item->data, true);
            });

            foreach ($headerMappings as $templateHeader => $selectedHeaders) {
                foreach ($selectedHeaders as $userSelectedHeader) {
                    $ekspedisiData->each(function ($dataItem) use ($userSelectedHeader, &$mergedData, $templateHeader) {
                        if (isset($dataItem[$userSelectedHeader])) {
                            array_push($mergedData[$templateHeader], $dataItem[$userSelectedHeader]);
                        }
                    });
                }
            }

            $dataToInsert = [];
            foreach ($mergedData['old_barcode_product'] as $index => $noResi) {
                $nama = $mergedData['old_name_product'][$index] ?? null;

                $qty = is_numeric($mergedData['old_quantity_product'][$index]) ? (int)$mergedData['old_quantity_product'][$index] : 0;

                if ($nama && strlen($nama) > 2000) {
                    Log::error("Nama produk terlalu panjang, lebih dari 2000 karakter: " . substr($nama, 0, 50) . "...");

                    // Potong nama menjadi maksimal 250 karakter
                    $nama = substr($nama, 0, 250);
                }

                $harga = isset($mergedData['old_price_product'][$index]) && is_numeric($mergedData['old_price_product'][$index])
                    ? (float)$mergedData['old_price_product'][$index]
                    : 0.0;

                $dataToInsert[] = [
                    'code_document' => $request['code_document'],
                    'old_barcode_product' => $noResi,
                    'old_name_product' => $nama,
                    'old_quantity_product' => $qty,
                    'old_price_product' => $harga,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }


            $chunkSize = 500;
            $totalInsertedRows = 0;

            foreach (array_chunk($dataToInsert, $chunkSize) as $chunkIndex => $chunk) {
                $insertResult = Product_old::insert($chunk);
                if ($insertResult) {
                    $insertedRows = count($chunk);
                    $totalInsertedRows += $insertedRows;
                } else {
                    Log::error("Failed to insert chunk {$chunkIndex} into product_olds");
                }
            }
            Generate::query()->delete();

            $product = Product_old::select("old_price_product")->where('code_document', $request['code_document'])->get();
            $totalPrice = $product->sum('old_price_product');
            $totalPrice = ceil($totalPrice);
            $document = Document::where('code_document', $request['code_document'])->first();

            if ($request['channel_id']) {
                $cogsChannel = CogsChannel::where('id', $request['channel_id'])->first();
                if (!$cogsChannel) {
                    DB::rollBack();
                    return new ResponseResource(false, "Channel tidak ditemukan", null);
                }

                $document->update([
                    'cogs_type' => $cogsChannel->type,
                    'cogs_amount' => $cogsChannel->amount,
                ]);

                CogsReference::create([
                    'cogs_channel_id'  => $request['channel_id'],
                    'type'        => 'reguler',
                    'document_id' => $document->id,
                    'user_id'     => $userId,
                ]);
            }
            $riwayat_check = RiwayatCheck::create([
                'user_id' => $userId,
                'code_document' => $request['code_document'],
                'base_document' => $document->base_document,
                'total_data' => $document->total_column_in_document,
                'total_data_in' => 0,
                'total_data_lolos' => 0,
                'total_data_damaged' => 0,
                'total_data_abnormal' => 0,
                'total_discrepancy' => 0,
                'status_approve' => 'done',

                // persentase
                'precentage_total_data' => 0,
                'percentage_in' => 0,
                'percentage_lolos' => 0,
                'percentage_damaged' => 0,
                'percentage_abnormal' => 0,
                'percentage_discrepancy' => 0,
                'total_price' => $totalPrice,
                'value_data_lolos' => 0,
                'value_data_damaged' => 0,
                'value_data_abnormal' => 0,
                'value_data_discrepancy' => 0,
                'status_file' => true,

            ]);


            logUserAction($request, $request->user(), "inbound/data_process/data_input", "Upload inbound batch " . $request['code_document'] . "->" . $userId);

            DB::commit();

            return new ResponseResource(true, "Berhasil menggabungkan data", ['inserted_rows' => $totalInsertedRows, 'riwayat check' => $riwayat_check]);
        } catch (\Illuminate\Database\QueryException $qe) {
            DB::rollBack();
            return response()->json(['error' => 'Database query error: ' . $qe->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Exception in mapAndMergeHeaders: ' . $e->getMessage());
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function searchByDocument(Request $request)
    {
        $query = $request->input('q');
        $search = $request->input('search');

        $code_documents = Product_old::where('code_document', $search)
            ->where(function ($subQuery) use ($query) {
                $subQuery->where('old_barcode_product', 'LIKE', '%' . $query . '%')
                    ->orWhere('old_name_product', 'LIKE', '%' . $query . '%');
            })
            ->paginate(50);

        $document = Document::where('code_document', $search)->first();

        if ($document) {
            foreach ($code_documents as $code_document) {
                $code_document->custom_barcode = $document->custom_barcode ?? null;
            }

            return new ResponseResource(true, "Data Document products", [
                'document_name' => $document->base_document ?? 'N/A',
                'status' => $document->status_document ?? 'N/A',
                'total_columns' => $document->total_column_in_document ?? 0,
                'custom_barcode' => $document->custom_barcode ?? null,
                'code_document' => $document->code_document ?? 'N/A',
                'data' => $code_documents ?? null,
            ]);
        } else {
            // Dokumen tidak ditemukan
            return (new ResponseResource(false, "code document tidak ada", null))
                ->response()
                ->setStatusCode(404);
        }
    }
}

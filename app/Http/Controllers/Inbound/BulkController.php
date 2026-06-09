<?php

namespace App\Http\Controllers\Inbound;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\New_product;
use App\Models\Document;
use App\Models\Color_tag;
use App\Models\SummarySoColor;
use App\Models\SoColor;
use App\Models\StagingProduct;
use App\Models\RiwayatCheck;
use App\Http\Resources\ResponseResource;

class BulkController extends Controller
{
    protected function generateDocumentCode()
    {
        $latestDocument = Document::latest()->first();
        $newId = $latestDocument ? $latestDocument->id + 1 : 1;
        $id_document = str_pad($newId, 4, '0', STR_PAD_LEFT);
        $month = date('m');
        $year = date('Y');
        return $id_document . '/' . $month . '/' . $year;
    }

    public function processExcelFilesTagColor(Request $request)
    {
        set_time_limit(900);
        ini_set('memory_limit', '1024M');
        $user_id = auth()->id();

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
            'is_extra' => 'nullable|string',
        ], [
            'file.required' => 'File harus diunggah.',
            'file.file' => 'File yang diunggah tidak valid.',
            'file.mimes' => 'File harus berupa file Excel dengan ekstensi .xlsx atau .xls.',
        ]);

        $file = $request->file('file');
        $filePath = $file->getPathname();
        $fileName = $file->getClientOriginalName();
        $file->storeAs('public/ekspedisis', $fileName);

        // Convert string to boolean: "true", "1", "yes" → true, sisanya → false
        $isExtra = filter_var($request->input('is_extra', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $ekspedisiData = $sheet->toArray(null, true, true, true);

            // Ambil header dari file
            $headersFromFile = $ekspedisiData[1]; // baris pertama (index 1) adalah header

            // Header yang diharapkan
            $expectedHeaders = [
                'Waybill',
                'Isi Barang',
                'Qty',
                'Nilai Barang Satuan',
                'Tag',
            ];

            $missingHeaders = array_diff($expectedHeaders, $headersFromFile);

            if (!empty($missingHeaders)) {
                $response = new ResponseResource(false, "Header tidak sesuai, berikut header yang benar : ", $expectedHeaders);
                return $response->response()->setStatusCode(422);
            }

            $chunkSize = 100;
            $count = 0;
            $headerMappings = [
                'old_barcode_product' => 'Waybill',
                'new_name_product' => 'Isi Barang',
                'new_quantity_product' => 'Qty',
                'old_price_product' => 'Nilai Barang Satuan',
                'tag_from_excel' => 'Tag',
                'new_category_product' => null,
                'new_date_in_product' => Carbon::now('Asia/Jakarta')->toDateString(),
                'new_discount' => 0,
                'display_price' => 'Nilai Barang Satuan',
                'weight' => 'Weight',
            ];

            // Ensure unique code_document before starting the process
            $code_document = $this->generateDocumentCode();
            while (Document::where('code_document', $code_document)->exists()) {
                $code_document = $this->generateDocumentCode(); // Generate a new one if a duplicate is found
            }

            // Validasi tag yang tidak ditemukan
            $invalidTagBarcodes = collect();
            $skippedBarcodes = collect();

            // Process in chunks
            for ($i = 1; $i < count($ekspedisiData); $i += $chunkSize) {
                $chunkData = array_slice($ekspedisiData, $i, $chunkSize);
                $newProductsToInsert = [];

                foreach ($chunkData as $dataItem) {
                    $newProductDataToInsert = [];
                    foreach ($headerMappings as $key => $headerName) {
                        $columnKey = array_search($headerName, $ekspedisiData[1]);
                        if ($columnKey !== false) {
                            $value = trim($dataItem[$columnKey]);
                            if ($key === 'new_quantity_product') {
                                $quantity = $value !== '' ? (int)$value : 0;
                                $newProductDataToInsert[$key] = $quantity;
                            } elseif ($key === 'old_price_product' || $key === 'display_price') {
                                $newProductDataToInsert[$key] = $value !== '' ? (float)str_replace(',', '', $value) : 0;
                            } elseif ($key === 'weight') {
                                $newProductDataToInsert[$key] = $value !== '' ? (float)str_replace(',', '', $value) : null;
                            } else {
                                $newProductDataToInsert[$key] = $value;
                            }
                        } else {
                            $newProductDataToInsert[$key] = null;
                        }
                    }

                    $price = $newProductDataToInsert['old_price_product'] ?? 0;
                    $tagFromExcel = trim($newProductDataToInsert['tag_from_excel'] ?? '');

                    // Jika harga >= 100rb maka wajib ada tag
                    // Jika harga >= 100rb maka wajib ada tag
                    if ($price >= 100000) {

                        if (empty($tagFromExcel)) {
                            $barcode = $newProductDataToInsert['old_barcode_product'] ?? 'Unknown';

                            $skippedBarcodes->push(
                                $barcode . ' - Harga >= 100000 tanpa tag, data di-skip'
                            );

                            continue;
                        }

                        $colors = Color_tag::where('name_color', $tagFromExcel)->first();

                        if ($colors) {
                            $newProductDataToInsert['new_tag_product'] = $colors->name_color;
                            $newProductDataToInsert['display_price'] = $colors->fixed_price_color;
                            $newProductDataToInsert['new_price_product'] = $colors->fixed_price_color;
                        } else {
                            $barcode = $newProductDataToInsert['old_barcode_product'] ?? 'Unknown';
                            $invalidTagBarcodes->push($barcode . ' - Tag: ' . $tagFromExcel . ' (tidak ditemukan)');
                            continue;
                        }
                    }

                    // Harga < 100rb
                    else {

                        // Jika tag diisi, pakai tag
                        if (!empty($tagFromExcel)) {

                            $colors = Color_tag::where('name_color', $tagFromExcel)->first();

                            if ($colors) {
                                $newProductDataToInsert['new_tag_product'] = $colors->name_color;
                                $newProductDataToInsert['display_price'] = $colors->fixed_price_color;
                                $newProductDataToInsert['new_price_product'] = $colors->fixed_price_color;
                            } else {
                                $barcode = $newProductDataToInsert['old_barcode_product'] ?? 'Unknown';
                                $invalidTagBarcodes->push($barcode . ' - Tag: ' . $tagFromExcel . ' (tidak ditemukan)');
                                continue;
                            }
                        } else {

                            // Logic lama berdasarkan range harga
                            $colors = Color_tag::where('min_price_color', '<=', $price)
                                ->where('max_price_color', '>=', $price)
                                ->first();

                            if ($colors) {
                                $newProductDataToInsert['new_tag_product'] = $colors->name_color;
                                $newProductDataToInsert['display_price'] = $colors->fixed_price_color;
                                $newProductDataToInsert['new_price_product'] = $colors->fixed_price_color;
                            }
                        }
                    }

                    // Hapus field temporary tag_from_excel
                    unset($newProductDataToInsert['tag_from_excel']);

                    $newProductDataToInsert['new_discount'] = 0;
                    $newProductDataToInsert['new_price_product'] = $newProductDataToInsert['new_price_product'] ?? $newProductDataToInsert['display_price'] ?? 0;
                    if ($newProductDataToInsert['new_price_product'] === '') {
                        $newProductDataToInsert['new_price_product'] = 0;
                    }

                    $newProductDataToInsert = array_merge($newProductDataToInsert, [
                        'code_document' => $code_document,
                        'type' => 'type1',
                        'user_id' => $user_id,
                        'is_so' => null,
                        'is_extra' => $isExtra,
                        'new_tag_product' => $newProductDataToInsert['new_tag_product'] ?? null,
                        'new_quality' => json_encode(['lolos' => 'lolos']),
                        'actual_new_quality' => json_encode(['lolos' => 'lolos']),
                        'actual_old_price_product' => $newProductDataToInsert['old_price_product'] ?? 0,
                        'new_status_product' => 'display',
                        'new_barcode_product' => newBarcodeScan(),
                        'created_at' => Carbon::now('Asia/Jakarta')->toDateString(),
                    ]);

                    if (isset($newProductDataToInsert['old_barcode_product'], $newProductDataToInsert['new_name_product'])) {
                        $newProductsToInsert[] = $newProductDataToInsert;
                        $count++;
                    }
                }

                // Validasi sebelum insert: cek apakah ada tag yang tidak valid
                if ($invalidTagBarcodes->isNotEmpty()) {
                    DB::rollBack();
                    $response = new ResponseResource(
                        false,
                        "Tag warna tidak ditemukan di database",
                        $invalidTagBarcodes
                    );
                    return $response->response()->setStatusCode(422);
                }

                if (!empty($newProductsToInsert)) {
                    New_product::insert($newProductsToInsert);
                }
            }

            $getColor = New_product::where('code_document', $code_document)
                ->select('new_tag_product', DB::raw('COUNT(*) as total'))
                ->groupBy('new_tag_product')
                ->orderBy('new_tag_product')
                ->get();

            $checkSoColor = SummarySoColor::where('type', 'process')->first();
            if ($checkSoColor) {
                foreach ($getColor as $color) {
                    $soColor = SoColor::where('summary_so_color_id', $checkSoColor->id)
                        ->where('color', $color->new_tag_product)
                        ->first();
                    if ($soColor) {
                        $soColor->increment('total_color', $color->total);
                    }
                }
            }

            // Insert into the documents table after processing each chunk
            Document::create([
                'code_document' => $code_document,
                'base_document' => $fileName,
                'status_document' => 'done',
                'total_column_document' => count($headerMappings) - 1, // Exclude tag_from_excel
                'total_column_in_document' => count($ekspedisiData) - 1, // Exclude header
                'date_document' => Carbon::now('Asia/Jakarta')->toDateString()
            ]);
            $totalData = count($ekspedisiData) - 1;
            $totalDataIn = count($ekspedisiData) - 1;

            // Hitung total harga dari semua produk yang diproses
            $totalPrice = StagingProduct::where('code_document', $code_document)->sum('old_price_product');

            // Hitung persentase total data yang masuk
            $percentageTotalData = $totalData > 0 ? ($totalDataIn / $totalData) * 100 : 0;
            $history = RiwayatCheck::create([
                'user_id' => $user_id,
                'code_document' => $code_document,
                'base_document' => $fileName,
                'total_data' => $totalData,
                'total_data_in' => $totalDataIn,
                'total_data_lolos' => $totalDataIn,
                'total_data_damaged' => 0,
                'total_data_abnormal' => 0,
                'total_discrepancy' => 0,
                'status_approve' => 'display',
                'precentage_total_data' => round($percentageTotalData, 2),
                'percentage_in' => round($percentageTotalData, 2),
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


            DB::commit();

            return new ResponseResource(true, "Data berhasil diproses dan disimpan", [
                'code_document' => $code_document,
                'file_name' => $fileName,
                'total_column_count' => count($headerMappings) - 1, // Exclude tag_from_excel
                'total_row_count' => count($ekspedisiData) - 1, // Exclude header
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error importing data: ' . $e->getMessage()], 500);
        }
    }
}

<?php

namespace App\Http\Controllers\Outbound\Lusi;

use Carbon\Carbon;
use App\Models\Sale;
use App\Models\Document;
use App\Models\New_product;
use App\Models\Product_old;
use App\Models\ProductApprove;
use App\Models\DamagedDocument;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\MovementService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BulkController extends Controller
{
    /**
     * Upload excel berisi produk yang sudah diketahui damaged.
     * Mirip dengan StagingProductController::processExcelFilesCategoryStaging (endpoint /excelOld),
     * bedanya:
     * - Header excel yang dipakai hanya: Waybill, Isi Barang, Qty, Nilai Barang Satuan.
     * - membuat record baru di table documents dan damaged_documents.
     * - Setiap baris dipisah berdasarkan harga (Nilai Barang Satuan):
     *      - harga < 100000   => disimpan ke table `new_products`
     *      - harga >= 100000  => disimpan ke table `staging_products`
     *   Produk yang tersimpan langsung ditandai quality-nya sebagai damaged dan langsung
     *   di-attach ke damaged document (relasi polymorphic sesuai tabel tujuannya).
     * - Status damaged_documents langsung ditandai `proses`.
     */
    public function excelDamaged(Request $request)
    {
        $user_id = auth()->id();
        set_time_limit(3600);
        ini_set('memory_limit', '2048M');

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ], [
            'file.required' => 'File harus diunggah.',
            'file.file' => 'File yang diunggah tidak valid.',
            'file.mimes' => 'File harus berupa file Excel dengan ekstensi .xlsx atau .xls.',
        ]);

        $file = $request->file('file');
        $filePath = $file->getPathname();
        $fileName = $file->getClientOriginalName();
        $file->storeAs('public/ekspedisis', $fileName);

        DB::beginTransaction();
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $ekspedisiData = $sheet->toArray(null, true, true, true);

            $headersFromFile = array_filter(array_map(function ($header) {
                return $header !== null ? trim($header) : null;
            }, $ekspedisiData[1]));

            // Header yang diharapkan untuk endpoint ini
            $expectedHeaders = [
                'Waybill',
                'Isi Barang',
                'Qty',
                'Nilai Barang Satuan',
            ];

            $missingHeaders = array_diff($expectedHeaders, $headersFromFile);

            if (!empty($missingHeaders)) {
                $response = new ResponseResource(false, "Header tidak sesuai, berikut header yang benar : ", $expectedHeaders);
                return $response->response()->setStatusCode(422);
            }

            $chunkSize = 500;
            $headerMappings = [
                'old_barcode_product' => 'Waybill',
                'new_barcode_product' => 'Waybill',
                'new_name_product' => 'Isi Barang',
                'new_quantity_product' => 'Qty',
                'new_price_product' => 'Nilai Barang Satuan',
                'old_price_product' => 'Nilai Barang Satuan',
                'display_price' => 'Nilai Barang Satuan',
            ];

            $initBarcode = collect($ekspedisiData)->pluck('A');
            $duplicateInitBarcode = $initBarcode->duplicates();
            $barcodesOnly = $duplicateInitBarcode->values();

            if ($duplicateInitBarcode->isNotEmpty()) {
                $response = new ResponseResource(false, "barcode duplikat dari excel", $barcodesOnly);
                return $response->response()->setStatusCode(422);
            }

            // Buat dokumen damaged baru
            $code_document_damaged = $this->generateUniqueDamagedDocumentCode();

            $damagedDocument = DamagedDocument::create([
                'code_document_damaged' => $code_document_damaged,
                'user_id' => $user_id,
                'status' => 'proses',
            ]);

            // code_document mengikuti format yang sama dengan GET /documents (seperti pada POST /excelOld),
            // dipakai juga sebagai kode grouping di new_products / staging_products
            $code_document = $this->generateUniqueDocumentCode();

            // Buat juga record di table `documents` agar muncul di GET /documents, langsung ditandai selesai (done)
            Document::create([
                'code_document' => $code_document,
                'base_document' => $fileName,
                'status_document' => 'done',
                'total_column_document' => count($headerMappings),
                'total_column_in_document' => count($ekspedisiData) - 1,
                'date_document' => Carbon::now('Asia/Jakarta')->toDateString(),
            ]);

            // Batas harga penentu tabel tujuan (100 ribu)
            $priceThreshold = 100000;

            $duplicateBarcodes = collect();
            $movementRows = [];
            $totalProduct = 0;
            $totalNewPrice = 0;
            $totalOldPrice = 0;

            for ($i = 1; $i < count($ekspedisiData); $i += $chunkSize) {
                $chunkData = array_slice($ekspedisiData, $i, $chunkSize);
                $newProductRowsForDisplay = [];
                $newProductRowsForStaging = [];

                foreach ($chunkData as $dataItem) {
                    $newProductDataToInsert = [];

                    foreach ($headerMappings as $key => $headerName) {
                        $columnKey = array_search($headerName, $ekspedisiData[1]);
                        if ($columnKey !== false) {
                            $value = trim($dataItem[$columnKey]);

                            if ($key === 'new_quantity_product') {
                                $quantity = $value !== '' ? (int) $value : 0;
                                $newProductDataToInsert[$key] = $quantity;
                            } elseif (in_array($key, ['old_price_product', 'display_price', 'new_price_product'])) {
                                $cleanedValue = str_replace(',', '', $value);
                                $newProductDataToInsert[$key] = (float) $cleanedValue;
                            } else {
                                $newProductDataToInsert[$key] = $value;
                            }
                        }
                    }

                    if (isset($newProductDataToInsert['new_barcode_product'])) {
                        $barcodeToCheck = $newProductDataToInsert['new_barcode_product'];
                        $sources = $this->checkDuplicateBarcode($barcodeToCheck);

                        if (!empty($sources)) {
                            $duplicateBarcodes->push($barcodeToCheck . ' - ' . implode(', ', $sources));
                        }
                    }

                    if (isset($newProductDataToInsert['old_barcode_product'], $newProductDataToInsert['new_name_product'])) {
                        $qualityDamaged = json_encode([
                            'lolos' => null,
                            'damaged' => 'damaged',
                            'abnormal' => null,
                        ]);

                        $price = $newProductDataToInsert['old_price_product'] ?? 0;
                        $isStaging = $price >= $priceThreshold;

                        $rowToInsert = array_merge($newProductDataToInsert, [
                            'code_document' => $code_document,
                            'new_discount' => 0,
                            'new_status_product' => 'display',
                            'is_so' => null,
                            'new_tag_product' => null,
                            'new_date_in_product' => Carbon::now('Asia/Jakarta')->toDateString(),
                            'type' => 'type1',
                            'user_id' => $user_id,
                            'new_quality' => $qualityDamaged,
                            'actual_new_quality' => $qualityDamaged,
                            'actual_old_price_product' => $newProductDataToInsert['old_price_product'],
                            'created_at' => Carbon::now('Asia/Jakarta'),
                            'updated_at' => Carbon::now('Asia/Jakarta'),
                        ]);

                        if ($isStaging) {
                            $newProductRowsForStaging[] = $rowToInsert;
                        } else {
                            $newProductRowsForDisplay[] = $rowToInsert;
                        }

                        $totalProduct++;
                        $totalNewPrice += $newProductDataToInsert['new_price_product'] ?? 0;
                        $totalOldPrice += $newProductDataToInsert['old_price_product'] ?? 0;

                        $movementRows[] = [
                            'product_id' => $newProductDataToInsert['new_barcode_product'],
                            'is_sku'     => false,
                            'type'       => 'In',
                            'type_out'   => null,
                            'from'       => '-',
                            'to'         => $isStaging ? 'staging_reguler' : 'display_reguler',
                            'qty'        => $newProductDataToInsert['new_quantity_product'] ?? 1,
                            'dateTime'   => now(),
                        ];
                    }
                }

                if ($duplicateBarcodes->isNotEmpty()) {
                    $response = new ResponseResource(false, "List data barcode yang duplikat", $duplicateBarcodes);
                    return $response->response()->setStatusCode(422);
                }

                if (!empty($newProductRowsForDisplay)) {
                    New_product::insert($newProductRowsForDisplay);
                }

                if (!empty($newProductRowsForStaging)) {
                    StagingProduct::insert($newProductRowsForStaging);
                }
            }

            if ($totalProduct === 0) {
                DB::rollBack();
                return (new ResponseResource(false, "Tidak ada data produk yang valid untuk diproses", null))
                    ->response()->setStatusCode(422);
            }

            // Ambil kembali id new_products & staging_products yang baru saja diinsert untuk di-attach ke damaged document
            $newProductIds = New_product::where('code_document', $code_document)->pluck('id');
            $stagingProductIds = StagingProduct::where('code_document', $code_document)->pluck('id');

            if ($newProductIds->isNotEmpty()) {
                $damagedDocument->newProducts()->syncWithoutDetaching($newProductIds->toArray());
            }

            if ($stagingProductIds->isNotEmpty()) {
                $damagedDocument->stagingProducts()->syncWithoutDetaching($stagingProductIds->toArray());
            }

            // Langsung tandai dokumen damaged sebagai proses
            $damagedDocument->update([
                'total_product' => $totalProduct,
                'total_new_price' => $totalNewPrice,
                'total_old_price' => $totalOldPrice,
                'status' => 'proses',
            ]);

            DB::commit();

            // [Movement] pending -> display_reguler / staging_reguler (bulk)
            try {
                if (!empty($movementRows)) {
                    MovementService::logBulk($movementRows);
                }
            } catch (\Exception $movEx) {
                Log::error('[Movement] BulkController@excelDamaged log failed: ' . $movEx->getMessage());
            }

            return new ResponseResource(true, "Data berhasil diproses dan disimpan sebagai Dokumen Damaged", [
                'damaged_document_id' => $damagedDocument->id,
                'code_document_damaged' => $damagedDocument->code_document_damaged,
                'file_name' => $fileName,
                'total_row_count' => $totalProduct,
                'total_new_products' => $newProductIds->count(),
                'total_staging_products' => $stagingProductIds->count(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error importing data: ' . $e->getMessage()], 500);
        }
    }

    private function checkDuplicateBarcode($barcode)
    {
        $sources = [];

        if (StagingProduct::where('new_barcode_product', $barcode)->exists()) {
            $sources[] = 'Product-Staging';
        }

        if (New_product::where('new_barcode_product', $barcode)->exists()) {
            $sources[] = 'Product-Inventory';
        }

        if (Sale::where('product_barcode_sale', $barcode)->exists()) {
            $sources[] = 'sale';
        }

        return $sources;
    }

    protected function generateDocumentCode()
    {
        // Generate 4 digit random number (1000-9999), sama seperti StagingProductController::generateDocumentCode (/excelOld)
        $randomId = rand(1000, 9999);
        $month = date('m');
        $year = date('Y');
        return $randomId . '/' . $month . '/' . $year;
    }

    protected function isCodeDocumentExists($code_document)
    {
        // Check di semua model yang menggunakan code_document, sama seperti StagingProductController::isCodeDocumentExists
        return Document::where('code_document', $code_document)->exists() ||
            StagingProduct::where('code_document', $code_document)->exists() ||
            New_product::where('code_document', $code_document)->exists() ||
            ProductApprove::where('code_document', $code_document)->exists() ||
            Product_old::where('code_document', $code_document)->exists() ||
            Sale::where('code_document', $code_document)->exists();
    }

    protected function generateUniqueDocumentCode()
    {
        $maxAttempts = 100; // Hindari infinite loop
        $attempts = 0;

        do {
            $code_document = $this->generateDocumentCode();
            $attempts++;

            if ($attempts >= $maxAttempts) {
                throw new \Exception("Tidak dapat generate code_document yang unik setelah {$maxAttempts} percobaan");
            }
        } while ($this->isCodeDocumentExists($code_document));

        return $code_document;
    }

    private function generateUniqueDamagedDocumentCode()
    {
        $now = now();
        $month = $now->format('m');
        $year = $now->format('Y');
        $monthYear = $month . '/' . $year;

        $lastDoc = DamagedDocument::where('code_document_damaged', 'LIKE', '%/DMG/' . $monthYear)
            ->latest('id')
            ->first();

        $nextNumber = 1;
        if ($lastDoc) {
            preg_match('/^(\d+)\//', $lastDoc->code_document_damaged, $matches);
            if (isset($matches[1])) {
                $nextNumber = (int) $matches[1] + 1;
            }
        }

        return str_pad($nextNumber, 4, '0', STR_PAD_LEFT) . '/DMG/' . $monthYear;
    }
}

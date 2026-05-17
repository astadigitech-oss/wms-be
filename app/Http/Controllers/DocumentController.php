<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\BulkySale;
use App\Models\Document;
use App\Models\FilterStaging;
use App\Models\New_product;
use App\Models\ProductApprove;
use App\Models\Product_Bundle;
use App\Models\Product_old;
use App\Models\ProductDefect;
use App\Models\RepairFilter;
use App\Models\RepairProduct;
use App\Models\RiwayatCheck;
use App\Models\Sale;
use App\Models\SkuDocument;
use App\Models\SkuProductOld;
use App\Models\StagingApprove;
use App\Models\StagingProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->input('q');
        $status = $request->input('f');

        $documents = Document::latest();

        if ($query) {
            $documents->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('code_document', 'LIKE', '%' . $query . '%')
                    ->orWhere('base_document', 'LIKE', '%' . $query . '%');
            });
        }
        if ($status) {
            $documents->where('status_document', 'LIKE', '%' . $status . '%');
        }
        $paginated = $documents->paginate(50);

        return new ResponseResource(true, "List Documents", $paginated);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        //
    }

    public function show(Document $document)
    {
        return new ResponseResource(true, "detail document", $document);
    }

    public function edit(Document $document)
    {
        //
    }

    public function update(Request $request, Document $document)
    {
        //
    }

    public function destroy(Document $document, Request $request)
    {
        $user = auth()->user()->email;
        try {
            $product_old = Product_old::where('code_document', $document->code_document)->delete();
            $approve = ProductApprove::where('code_document', $document->code_document)->delete();
            $document->delete();

            logUserAction($request, $request->user(), "inbound/check_product/list_data", "code document " . $document->code_document . "name" . $document->base_document . " deleted by " . $user);

            return new ResponseResource(true, "data berhasil dihapus", $document);
        } catch (\Exception $e) {
            return new ResponseResource(false, "terjadi kesalahan saat menghapus data", null);
        }
    }

    public function deleteAll()
    {
        try {
            Document::truncate();
            return new ResponseResource(true, "data berhasil dihapus", null);
        } catch (\Exception $e) {
            return new ResponseResource(false, "terjadi kesalahan saat menghapus data", null);
        }
    }

    public function documentInProgress(Request $request)
    {
        $query = $request->input('q');
        $status = $request->input('f');

        $documents = Document::latest();

        if (!empty($query)) {
            $documents = $documents->where(function ($search) use ($query) {
                $search->where('status_document', '!=', 'pending')
                    ->where(function ($baseCode) use ($query) {
                        $baseCode->where('base_document', 'LIKE', '%' . $query . '%')
                            ->orWhere('status_document', $query)
                            ->orWhere('code_document', 'LIKE', '%' . $query . '%');
                    });
            });
        }

        if (!empty($status)) {
            $documents->where('status_document', 'LIKE', '%' . $status . '%');
        }

        if (empty($query) && empty($status)) {
            $documents = $documents->where('status_document', '!=', 'pending');
        }

        return new ResponseResource(true, "List document progress", $documents->paginate(30));
    }

    public function documentDone(Request $request) // halaman list product staging by doc
    {
        $query = $request->input('q');

        $documents = Document::latest()->where('status_document', 'done');

        // Jika query pencarian tidak kosong, tambahkan kondisi pencarian
        if (!empty($query)) {
            $documents = $documents->where(function ($search) use ($query) {
                $search->where(function ($baseCode) use ($query) {
                    $baseCode->where('base_document', 'LIKE', '%' . $query . '%')
                        ->orWhere('code_document', 'LIKE', '%' . $query . '%');
                });
            });
        }

        // Mengembalikan hasil dalam bentuk paginasi
        return new ResponseResource(true, "list document progress", $documents->paginate(50));
    }

    private function changeBarcodeByDocument($code_document, $init_barcode)
    {
        DB::beginTransaction();
        try {
            $document = Document::where('code_document', $code_document)->first();
            $document->custom_barcode = $init_barcode;
            $document->save();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating barcodes: ' . $e->getMessage());
            return false;
        }
    }

    public function changeBarcodeDocument(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code_document' => 'required',
            'init_barcode' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $generate = $this->changeBarcodeByDocument($request->code_document, $request->init_barcode);

        if ($generate) {
            return new ResponseResource(true, "berhasil mengganti barcode", $request->init_barcode);
        } else {
            return "gagal";
        }
    }

    public function deleteCustomBarcode(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), ['code_document' => 'required']);
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            $document = Document::where('code_document', $request->input('code_document'))->first();
            $document->update(['custom_barcode' => null]);
            return new ResponseResource(true, "custom barcode dihapus", null);
        } catch (\Exception $e) {
            return new ResponseResource(false, "gagal di hapus", $e->getMessage());
        }
    }

    public function checkDocumentOnGoing(Request $request)
    {
        $documents = Document::where('status_document', 'pending')->orWhere('status_document', 'in progress')->latest()->paginate(50);
        return new ResponseResource(true, "list docs", $documents);
    }

    public function findDataDocs(Request $request, $code_document)
    {
        $userId = auth()->id();
        set_time_limit(600);
        ini_set('memory_limit', '1024M');
        DB::beginTransaction();

        $document = Document::where('code_document', $code_document)->first();
        $isSku = false;

        if (!$document) {
            $document = \App\Models\SkuDocument::where('code_document', $code_document)->first();
            $isSku = true;
        }

        if (!$document) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Code Document tidak ditemukan di database Document maupun SKU Document'
            ], 404);
        }

        $notSale = function ($q) {
            $q->where('new_status_product', '!=', 'sale')->orWhereNull('new_status_product');
        };

        if ($isSku) {
            $manifestStats = \App\Models\SkuProductOld::where('code_document', $code_document)
                ->selectRaw("SUM(old_quantity_product) as total_qty, SUM(old_price_product * old_quantity_product) as total_val")
                ->first();

            $totalColumnInDoc = (int) ($manifestStats->total_qty ?? 0);
            $totalPrice = $manifestStats->total_val ?? 0;

            $discrepancyStats = \App\Models\SkuProduct::where('code_document', $code_document)
                ->where('quantity_product', '>', 0)
                ->selectRaw("SUM(quantity_product) as total_count, SUM(price_product * quantity_product) as total_price")
                ->first();

            $countDiscrepancy = (int) max(0, $discrepancyStats->total_count ?? 0);
            $totalDiscrepancyPrice = max(0, $discrepancyStats->total_price ?? 0);
            $totalPriceIn = max(0, $totalPrice - $totalDiscrepancyPrice);

            $inventoryCount = (int) New_product::where('code_document', $code_document)->where($notSale)->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');
            $stagingCount = (int) StagingProduct::where('code_document', $code_document)->where($notSale)->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');
            $bundleCount = (int) Product_Bundle::where('code_document', $code_document)->where($notSale)->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');
            $approveCount = (int) ProductApprove::where('code_document', $code_document)->where($notSale)->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');
            $repairCount = (int) RepairProduct::where('code_document', $code_document)->where($notSale)->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');

            $salesCount = (int) (
                New_product::where('code_document', $code_document)->where('new_status_product', 'sale')->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total') +
                StagingProduct::where('code_document', $code_document)->where('new_status_product', 'sale')->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total')
            );
            $b2bCount = 0;
            $migrateBulkyCount = (int) \App\Models\MigrateBulkyProduct::where('code_document_inbound', $code_document)->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');

            $allData = $countDiscrepancy + $inventoryCount + $stagingCount + $bundleCount + $approveCount + $repairCount + $salesCount + $b2bCount + $migrateBulkyCount;

            $models = [
                New_product::where('code_document', $code_document)->where($notSale),
                StagingProduct::where('code_document', $code_document)->where($notSale),
                Product_Bundle::where('code_document', $code_document)->where($notSale),
                ProductApprove::where('code_document', $code_document)->where($notSale),
                RepairProduct::where('code_document', $code_document)->where($notSale)
            ];

            $countDataDamaged = 0;
            $countDataAbnormal = 0;
            $countDataNon = 0;

            foreach ($models as $model) {
                $countDataDamaged += (int) (clone $model)->where(function ($q) {
                    $q->whereNotNull('actual_new_quality->damaged')
                        ->orWhere(function ($sub) {
                            $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->damaged');
                        });
                })->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');

                $countDataAbnormal += (int) (clone $model)->where(function ($q) {
                    $q->whereNotNull('actual_new_quality->abnormal')
                        ->orWhere(function ($sub) {
                            $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->abnormal');
                        });
                })->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');

                $countDataNon += (int) (clone $model)->where(function ($q) {
                    $q->whereNotNull('actual_new_quality->non')
                        ->orWhere(function ($sub) {
                            $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->non');
                        });
                })->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');
            }

            $countDataLolos = $allData - $countDiscrepancy - $countDataDamaged - $countDataAbnormal - $countDataNon;

            $lolosPrice = \App\Models\SkuProductOld::where('code_document', $code_document)->selectRaw("SUM(actual_quantity_product * old_price_product)")->value('SUM(actual_quantity_product * old_price_product)') ?? 0;
            $damagedPrice = \App\Models\SkuProductOld::where('code_document', $code_document)->selectRaw("SUM(damaged_quantity_product * old_price_product)")->value('SUM(damaged_quantity_product * old_price_product)') ?? 0;

            if ($lolosPrice == 0 && $damagedPrice == 0) {
                $lolosPrice = $totalPriceIn;
            }
            $abnormalPrice = 0;
            $nonPrice = 0;
        } else {
            $statsExpr = "COUNT(*) as total_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as total_price";
            $lolosExpr = "COUNT(*) as lolos_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as lolos_price";
            $damagedExpr = "COUNT(*) as damaged_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as damaged_price";
            $abnormalExpr = "COUNT(*) as abnormal_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as abnormal_price";
            $nonExpr = "COUNT(*) as non_count, SUM(COALESCE(actual_old_price_product, old_price_product)) as non_price";

            $discrepancy = Product_old::where('code_document', $code_document)->get();
            $countDiscrepancy = (int) count($discrepancy);
            $totalDiscrepancyPrice = $discrepancy->sum('old_price_product');

            $inventoryStats = New_product::where('code_document', $code_document)->where($notSale)->selectRaw($statsExpr)->first();
            $inventoryLolosStats = New_product::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->lolos')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->lolos')))->selectRaw($lolosExpr)->first();
            $inventoryDamagedStats = New_product::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->damaged')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->damaged')))->selectRaw($damagedExpr)->first();
            $inventoryAbnormalStats = New_product::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->abnormal')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->abnormal')))->selectRaw($abnormalExpr)->first();
            $inventoryNonStats = New_product::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->non')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->non')))->selectRaw($nonExpr)->first();

            $stagingStats = StagingProduct::where('code_document', $code_document)->where($notSale)->selectRaw($statsExpr)->first();
            $stagingLolosStats = StagingProduct::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->lolos')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->lolos')))->selectRaw($lolosExpr)->first();
            $stagingDamagedStats = StagingProduct::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->damaged')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->damaged')))->selectRaw($damagedExpr)->first();
            $stagingAbnormalStats = StagingProduct::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->abnormal')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->abnormal')))->selectRaw($abnormalExpr)->first();
            $stagingNonStats = StagingProduct::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->non')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->non')))->selectRaw($nonExpr)->first();

            $productBundleStats = Product_Bundle::where('code_document', $code_document)->where($notSale)->selectRaw($statsExpr)->first();
            $productBundleLolosStats = Product_Bundle::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->lolos')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->lolos')))->selectRaw($lolosExpr)->first();
            $productBundleDamagedStats = Product_Bundle::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->damaged')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->damaged')))->selectRaw($damagedExpr)->first();
            $productBundleAbnormalStats = Product_Bundle::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->abnormal')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->abnormal')))->selectRaw($abnormalExpr)->first();
            $productBundleNonStats = Product_Bundle::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->non')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->non')))->selectRaw($nonExpr)->first();

            $productApproveStats = ProductApprove::where('code_document', $code_document)->where($notSale)->selectRaw($statsExpr)->first();
            $productApproveLolosStats = ProductApprove::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->lolos')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->lolos')))->selectRaw($lolosExpr)->first();
            $productApproveDamagedStats = ProductApprove::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->damaged')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->damaged')))->selectRaw($damagedExpr)->first();
            $productApproveAbnormalStats = ProductApprove::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->abnormal')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->abnormal')))->selectRaw($abnormalExpr)->first();
            $productApproveNonStats = ProductApprove::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->non')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->non')))->selectRaw($nonExpr)->first();

            $repairProductStats = RepairProduct::where('code_document', $code_document)->where($notSale)->selectRaw($statsExpr)->first();
            $repairProductLolosStats = RepairProduct::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->lolos')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->lolos')))->selectRaw($lolosExpr)->first();
            $repairProductDamagedStats = RepairProduct::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->damaged')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->damaged')))->selectRaw($damagedExpr)->first();
            $repairProductAbnormalStats = RepairProduct::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->abnormal')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->abnormal')))->selectRaw($abnormalExpr)->first();
            $repairProductNonStats = RepairProduct::where('code_document', $code_document)->where($notSale)->where(fn($q) => $q->whereNotNull('actual_new_quality->non')->orWhere(fn($sub) => $sub->whereNull('actual_new_quality')->whereNotNull('new_quality->non')))->selectRaw($nonExpr)->first();

            $salesStats = Sale::where('code_document', $code_document)->selectRaw($statsExpr)->first();
            $b2bStats = BulkySale::where('code_document', $code_document)->selectRaw("COUNT(*) as total_count, SUM(COALESCE(old_price_bulky_sale, actual_old_price_product)) as total_price")->first();

            $b2bLolosStats = BulkySale::where('code_document', $code_document)->where('status_product_before', 'display')->selectRaw("COUNT(*) as lolos_count, SUM(COALESCE(old_price_bulky_sale, actual_old_price_product)) as lolos_price")->first();

            $migrateBulkyStats = \App\Models\MigrateBulkyProduct::where('code_document_inbound', $code_document)->selectRaw("COUNT(*) as total_count, SUM(old_price_product) as total_price")->first();
            $migrateCount = (int) ($migrateBulkyStats->total_count ?? 0);
            $migratePrice = $migrateBulkyStats->total_price ?? 0;

            $inventoryCount = (int) ($inventoryStats->total_count ?? 0);
            $stagingCount = (int) ($stagingStats->total_count ?? 0);
            $bundleCount = (int) ($productBundleStats->total_count ?? 0);
            $approveCount = (int) ($productApproveStats->total_count ?? 0);
            $repairCount = (int) ($repairProductStats->total_count ?? 0);
            $salesCount = (int) ($salesStats->total_count ?? 0);
            $b2bCount = (int) ($b2bStats->total_count ?? 0);

            $allData = $inventoryCount + $stagingCount + $bundleCount + $approveCount + $repairCount + $salesCount + $b2bCount + $migrateCount;

            $totalPrice = ($inventoryStats->total_price ?? 0) + ($stagingStats->total_price ?? 0) + ($productBundleStats->total_price ?? 0) + ($salesStats->total_price ?? 0) + ($productApproveStats->total_price ?? 0) + ($repairProductStats->total_price ?? 0) + $totalDiscrepancyPrice + ($b2bStats->total_price ?? 0) + $migratePrice;
            $totalPriceIn = ($inventoryStats->total_price ?? 0) + ($stagingStats->total_price ?? 0) + ($productBundleStats->total_price ?? 0) + ($salesStats->total_price ?? 0) + ($productApproveStats->total_price ?? 0) + ($repairProductStats->total_price ?? 0) + ($b2bStats->total_price ?? 0) + $migratePrice;

            $countDataLolos = (int) (($inventoryLolosStats->lolos_count ?? 0) + ($stagingLolosStats->lolos_count ?? 0) + ($productBundleLolosStats->lolos_count ?? 0) + ($productApproveLolosStats->lolos_count ?? 0) + ($repairProductLolosStats->lolos_count ?? 0) + $salesCount + ($b2bLolosStats->lolos_count ?? 0) + $migrateCount);
            $countDataDamaged = (int) (($inventoryDamagedStats->damaged_count ?? 0) + ($stagingDamagedStats->damaged_count ?? 0) + ($productBundleDamagedStats->damaged_count ?? 0) + ($productApproveDamagedStats->damaged_count ?? 0) + ($repairProductDamagedStats->damaged_count ?? 0));
            $countDataAbnormal = (int) (($inventoryAbnormalStats->abnormal_count ?? 0) + ($stagingAbnormalStats->abnormal_count ?? 0) + ($productBundleAbnormalStats->abnormal_count ?? 0) + ($productApproveAbnormalStats->abnormal_count ?? 0) + ($repairProductAbnormalStats->abnormal_count ?? 0));
            $countDataNon = (int) (($inventoryNonStats->non_count ?? 0) + ($stagingNonStats->non_count ?? 0) + ($productBundleNonStats->non_count ?? 0) + ($productApproveNonStats->non_count ?? 0) + ($repairProductNonStats->non_count ?? 0));

            $lolosPrice = ($inventoryLolosStats->lolos_price ?? 0) + ($stagingLolosStats->lolos_price ?? 0) + ($productBundleLolosStats->lolos_price ?? 0) + ($productApproveLolosStats->lolos_price ?? 0) + ($repairProductLolosStats->lolos_price ?? 0) + ($salesStats->total_price ?? 0) + ($b2bLolosStats->lolos_price ?? 0) + $migratePrice;
            $damagedPrice = ($inventoryDamagedStats->damaged_price ?? 0) + ($stagingDamagedStats->damaged_price ?? 0) + ($productBundleDamagedStats->damaged_price ?? 0) + ($productApproveDamagedStats->damaged_price ?? 0) + ($repairProductDamagedStats->damaged_price ?? 0);
            $abnormalPrice = ($inventoryAbnormalStats->abnormal_price ?? 0) + ($stagingAbnormalStats->abnormal_price ?? 0) + ($productBundleAbnormalStats->abnormal_price ?? 0) + ($productApproveAbnormalStats->abnormal_price ?? 0) + ($repairProductAbnormalStats->abnormal_price ?? 0);
            $nonPrice = ($inventoryNonStats->non_price ?? 0) + ($stagingNonStats->non_price ?? 0) + ($productBundleNonStats->non_price ?? 0) + ($productApproveNonStats->non_price ?? 0) + ($repairProductNonStats->non_price ?? 0);

            $totalColumnInDoc = (int) ($document->total_column_in_document ?? 0);
        }

        $riwayatCheck = RiwayatCheck::where('code_document', $code_document)->first();

        if ($riwayatCheck === null) {
            $riwayatCheck = RiwayatCheck::create([
                'user_id' => $userId,
                'code_document' => $code_document,
                'base_document' => $document->base_document,
                'total_data' => $totalColumnInDoc,
                'status_approve' => 'done',
                'status_file' => 0,
            ]);
        }

        $riwayatCheck->update([
            'total_data_in' => $allData,
            'total_data_lolos' => $countDataLolos,
            'total_data_damaged' => $countDataDamaged,
            'total_data_abnormal' => $countDataAbnormal,
            'total_data_non' => $countDataNon,
            'total_discrepancy' => $countDiscrepancy,
            'total_price' => $totalPrice,
            'total_price_in' => $totalPriceIn,
            'precentage_total_data' => $totalColumnInDoc > 0 ? ($allData / $totalColumnInDoc) * 100 : 0,
            'percentage_in' => $totalPrice > 0 ? ($totalPriceIn / $totalPrice) * 100 : 0,
            'percentage_lolos' => $totalColumnInDoc > 0 ? ($countDataLolos / $totalColumnInDoc) * 100 : 0,
            'percentage_damaged' => $totalColumnInDoc > 0 ? ($countDataDamaged / $totalColumnInDoc) * 100 : 0,
            'percentage_abnormal' => $totalColumnInDoc > 0 ? ($countDataAbnormal / $totalColumnInDoc) * 100 : 0,
            'percentage_non' => $totalColumnInDoc > 0 ? ($countDataNon / $totalColumnInDoc) * 100 : 0,
            'percentage_discrepancy' => $totalColumnInDoc > 0 ? ($countDiscrepancy / $totalColumnInDoc) * 100 : 0,
            'value_data_lolos' => $lolosPrice,
            'value_data_damaged' => $damagedPrice,
            'value_data_abnormal' => $abnormalPrice,
            'value_data_non' => $nonPrice,
            'value_data_discrepancy' => $totalDiscrepancyPrice,
        ]);

        DB::commit();

        return new ResponseResource(true, "list", [
            "code_document" => $code_document,
            "all data" => $allData,
            "breakdown_all_data" => [
                "inventory" => $isSku ? $inventoryCount : $inventoryCount,
                "staging" => $isSku ? $stagingCount : $stagingCount,
                "product_bundle" => $isSku ? $bundleCount : $bundleCount,
                "product_approve" => $isSku ? $approveCount : $approveCount,
                "repair_product" => $isSku ? ($repairCount + $migrateBulkyCount) : ($repairCount + $migrateCount),
                "sales" => $salesCount,
                "b2b" => $b2bCount,
                "discrepancy" => $countDiscrepancy,
            ],
            "lolos" => $countDataLolos,
            "abnormal" => $countDataAbnormal,
            "damaged" => $countDataDamaged,
            "non" => $countDataNon,
            "value_data_non" => $nonPrice,
            'total_price' => $totalPrice,
        ]);
    }
}

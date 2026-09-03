<?php

namespace App\Http\Controllers;

use App\Exports\RiwayatCheckExport;
use Carbon\Carbon;
use App\Models\User;
use App\Mail\TestEmail;
use App\Models\Document;
use App\Models\New_product;
use App\Models\Product_old;
use App\Models\Notification;
use App\Models\RiwayatCheck;
use Illuminate\Http\Request;
use App\Models\ProductApprove;
use App\Models\StagingProduct;
use App\Mail\AdminNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\ResponseResource;
use App\Models\BulkySale;
use App\Models\Product_Bundle;
use App\Models\ProductDefect;
use App\Models\RepairProduct;
use App\Models\Sale;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RiwayatCheckController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->input('q');

        $riwayats = RiwayatCheck::select('id', 'code_document', 'base_document', 'total_data', 'total_data_in', 'status_approve', 'created_at')
            ->latest()->where(function ($search) use ($query) {
                $search->where('code_document', 'LIKE', '%' . $query . '%')
                    ->orWhere('base_document', 'LIKE', '%' . $query . '%');
            })->paginate(50);
        return new ResponseResource(true, "list riwayat", $riwayats);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $user = User::find(auth()->id());

        if (!$user) {
            $resource = new ResponseResource(false, "User tidak dikenali", null);
            return $resource->response()->setStatusCode(422);
        }

        $validator = Validator::make($request->all(), [
            'code_document' => 'required|exists:documents,code_document',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $document = Document::where('code_document', $request['code_document'])->firstOrFail();

        if ($document->status_document === 'done') {
            $resource = new ResponseResource(false, "Gagal: Status document ini sudah selesai (done).", null);
            return $resource->response()->setStatusCode(422);
        }

        DB::beginTransaction();

        try {
            $document->update(['status_document' => 'done']);

            logUserAction($request, $request->user(), "inbound/check_product/finish", "Set document status to done: " . $request['code_document']);

            DB::commit();

            return new ResponseResource(true, "Status document berhasil diubah menjadi done", $document);
        } catch (\Exception $e) {
            DB::rollBack();
            $resource = new ResponseResource(false, "Gagal mengubah status, terjadi kesalahan pada server : " . $e->getMessage(), null);
            return $resource->response()->setStatusCode(500);
        }
    }

    public function show(RiwayatCheck $history)
    {
        $code_document = $history->code_document;

        $isSku = str_contains($code_document, 'SKU') || \App\Models\SkuDocument::where('code_document', $code_document)->exists();

        $totalDataAkurat = (int) $history->total_data;
        $totalPriceAcuan = $history->total_price;

        $notSale = function ($q) {
            $q->where('new_status_product', '!=', 'sale')->orWhereNull('new_status_product');
        };

        if ($isSku) {
            $skuManifest = \App\Models\SkuProductOld::where('code_document', $code_document)
                ->selectRaw("SUM(old_quantity_product) as total_qty, SUM(old_price_product * old_quantity_product) as total_val")
                ->first();

            if ($skuManifest && $skuManifest->total_qty > 0) {
                $totalDataAkurat = (int) $skuManifest->total_qty;
                $totalPriceAcuan = $skuManifest->total_val;
            }
        }

        $productCategoryCount = 0;
        $productColorCount = 0;
        $countSalesItems = 0;
        $totalStagings = 0;
        $totalPA = 0;
        $countBundleItems = 0;
        $migrateBulkyCount = 0;

        if ($isSku) {
            $discrepancyStats = \App\Models\SkuProduct::where('code_document', $code_document)
                ->where('quantity_product', '>', 0)
                ->selectRaw("SUM(quantity_product) as disc_count, SUM(price_product * quantity_product) as total_price")
                ->first();
            $valDiscrepancy = max(0, $discrepancyStats->total_price ?? 0);
            $countDiscrepancy = (int) max(0, $discrepancyStats->disc_count ?? 0);

            $productCategoryCount = (int) New_product::where('code_document', $code_document)->whereNotNull('new_category_product')->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');
            $productColorCount = (int) New_product::where('code_document', $code_document)->whereNotNull('new_tag_product')->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');

            $countSalesItems = (int) (
                New_product::where('code_document', $code_document)->where('new_status_product', 'sale')->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total') +
                StagingProduct::where('code_document', $code_document)->where('new_status_product', 'sale')->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total')
            );

            $totalStagings = (int) StagingProduct::where('code_document', $code_document)->where($notSale)->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');
            $totalPA = (int) ProductApprove::where('code_document', $code_document)->where($notSale)->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');
            $countBundleItems = (int) Product_Bundle::where('code_document', $code_document)->where($notSale)->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');
            $repairCount = (int) RepairProduct::where('code_document', $code_document)->where($notSale)->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');

            $migrateBulkyCount = (int) \App\Models\MigrateBulkyProduct::where('code_document_inbound', $code_document)->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total');

            $totalDataInSku = $countDiscrepancy + New_product::where('code_document', $code_document)->where($notSale)->selectRaw('SUM(COALESCE(new_quantity_product, 1)) as total')->value('total') + $totalStagings + $countBundleItems + $totalPA + $repairCount + $countSalesItems + $migrateBulkyCount;

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

            $countDataLolos = $totalDataInSku - $countDiscrepancy - $countDataDamaged - $countDataAbnormal - $countDataNon;

            $valueDataLolos = \App\Models\SkuProductOld::where('code_document', $code_document)->selectRaw("SUM(actual_quantity_product * old_price_product)")->value('SUM(actual_quantity_product * old_price_product)') ?? 0;
            $valueDataDamaged = \App\Models\SkuProductOld::where('code_document', $code_document)->selectRaw("SUM(damaged_quantity_product * old_price_product)")->value('SUM(damaged_quantity_product * old_price_product)') ?? 0;
            $valueDataAbnormal = 0;
            $valueDataNon = 0;

            $totalPriceSales = 0;
            $salesNew = New_product::where('code_document', $code_document)->where('new_status_product', 'sale')->get();
            $salesStaging = StagingProduct::where('code_document', $code_document)->where('new_status_product', 'sale')->get();
            foreach ($salesNew->concat($salesStaging) as $s) {
                $totalPriceSales += (($s->actual_old_price_product ?? $s->old_price_product ?? 0) * ($s->new_quantity_product ?? 1));
            }

            $totalPriceProductBundle = 0;
            $bundleProducts = Product_Bundle::where('code_document', $code_document)->get();
            foreach ($bundleProducts as $bp) {
                $totalPriceProductBundle += (($bp->actual_old_price_product ?? $bp->old_price_product) * ($bp->new_quantity_product ?? 1));
            }

            if ($valueDataLolos == 0 && $valueDataDamaged == 0) {
                $valueDataLolos = $history->value_data_lolos;
                $valueDataDamaged = $history->value_data_damaged;
            }
        } else {
            $getProduct = New_product::where('code_document', $code_document)
                ->selectRaw("new_category_product, new_tag_product, COALESCE(actual_old_price_product, old_price_product) as actual_old_price_product")
                ->cursor();

            foreach ($getProduct as $product) {
                if ($product->new_category_product !== null) $productCategoryCount++;
                if ($product->new_tag_product !== null) $productColorCount++;
            }

            $totalOldPriceDamaged = 0;
            if ($history->status_file === 1) {
                $totalOldPriceDamaged = ProductDefect::where('riwayat_check_id', $history->id)->whereNotNull('new_barcode_product')->where('type', 'damaged')->sum('old_price_product');
            } else {
                $totalOldPriceDamaged = New_product::where('code_document', $code_document)->where($notSale)->whereNotNull('actual_new_quality->damaged')->selectRaw('COALESCE(actual_old_price_product, old_price_product) as price')->cursor()->sum('price');
            }

            $totalOldPriceLolos = New_product::where('code_document', $code_document)->where($notSale)->whereNotNull('actual_new_quality->lolos')->selectRaw('COALESCE(actual_old_price_product, old_price_product) as price')->cursor()->sum('price');

            $totalOldPriceAbnormal = 0;
            if ($history->status_file === 1) {
                $totalOldPriceAbnormal = ProductDefect::where('riwayat_check_id', $history->id)->whereNotNull('new_barcode_product')->where('type', 'abnormal')->sum('old_price_product');
            } else {
                $totalOldPriceAbnormal = New_product::where('code_document', $code_document)->where($notSale)->whereNotNull('actual_new_quality->abnormal')->selectRaw('COALESCE(actual_old_price_product, old_price_product) as price')->cursor()->sum('price');
            }

            $totalOldPriceNon = 0;
            if ($history->status_file === 1) {
                $totalOldPriceNon = ProductDefect::where('riwayat_check_id', $history->id)->whereNotNull('new_barcode_product')->where('type', 'non')->sum('old_price_product');
            } else {
                $totalOldPriceNon = New_product::where('code_document', $code_document)->where($notSale)->whereNotNull('actual_new_quality->non')->selectRaw('COALESCE(actual_old_price_product, old_price_product) as price')->cursor()->sum('price');
                $tablesNon = [StagingProduct::class, ProductApprove::class, Product_Bundle::class];
                foreach ($tablesNon as $model) {
                    $totalOldPriceNon += $model::where('code_document', $code_document)->where($notSale)->whereNotNull('actual_new_quality->non')->selectRaw('COALESCE(actual_old_price_product, old_price_product) as price')->cursor()->sum('price');
                }
            }

            $totalPriceDamagedStg = 0;
            $totalPriceLolosStg = 0;
            $totalPriceAbnormalStg = 0;
            $getProductStg = StagingProduct::where('code_document', $code_document)->where($notSale)->cursor();
            foreach ($getProductStg as $product) {
                $quality = json_decode($product->actual_new_quality);
                $price = $product->actual_old_price_product ?? $product->old_price_product;
                if (isset($quality->damaged) && $quality->damaged !== null) {
                    $totalPriceDamagedStg += $price;
                    $totalStagings++;
                }
                if (isset($quality->lolos) && $quality->lolos !== null) {
                    $totalPriceLolosStg += $price;
                    $totalStagings++;
                }
                if (isset($quality->abnormal) && $quality->abnormal !== null) {
                    $totalPriceAbnormalStg += $price;
                    $totalStagings++;
                }
            }

            $totalPriceDamagedAp = 0;
            $totalPriceLolosAp = 0;
            $totalPriceAbnormalAp = 0;
            $getProductAp = ProductApprove::where('code_document', $code_document)->where($notSale)->cursor();
            foreach ($getProductAp as $product) {
                $quality = json_decode($product->actual_new_quality);
                $price = $product->actual_old_price_product ?? $product->old_price_product;
                if (isset($quality->damaged) && $quality->damaged !== null) {
                    $totalPriceDamagedAp += $price;
                    $totalPA++;
                }
                if (isset($quality->lolos) && $quality->lolos !== null) {
                    $totalPriceLolosAp += $price;
                    $totalPA++;
                }
                if (isset($quality->abnormal) && $quality->abnormal !== null) {
                    $totalPriceAbnormalAp += $price;
                    $totalPA++;
                }
            }

            $totalPriceProductBundle = Product_Bundle::where('code_document', $code_document)->where($notSale)->selectRaw('COALESCE(actual_old_price_product, old_price_product) as price')->cursor()->sum('price');
            $countBundleItems = (int) Product_Bundle::where('code_document', $code_document)->where($notSale)->count();

            $totalPriceSales = Sale::where('code_document', $code_document)->selectRaw('COALESCE(actual_product_old_price_sale, product_old_price_sale) as price')->cursor()->sum('price');
            $countSalesItems = (int) Sale::where('code_document', $code_document)->count();

            $migrateBulkyCount = (int) \App\Models\MigrateBulkyProduct::where('code_document_inbound', $code_document)->count();
            $migrateBulkyPrice = (float) \App\Models\MigrateBulkyProduct::where('code_document_inbound', $code_document)->sum('old_price_product');

            $valueDataLolos = round($totalOldPriceLolos + $totalPriceLolosStg + $totalPriceLolosAp + $totalPriceSales + $totalPriceProductBundle + $migrateBulkyPrice, 2);

            if ($history->status_file === 1) {
                $valueDataDamaged = round($totalOldPriceDamaged, 2);
                $valueDataAbnormal = round($totalOldPriceAbnormal, 2);
            } else {
                $valueDataDamaged = round($totalOldPriceDamaged + $totalPriceDamagedStg + $totalPriceDamagedAp, 2);
                $valueDataAbnormal = round($totalOldPriceAbnormal + $totalPriceAbnormalStg + $totalPriceAbnormalAp, 2);
            }
            $valueDataNon = round($totalOldPriceNon, 2);

            $valDiscrepancy = $history->value_data_discrepancy ?? 0;
            $countDiscrepancy = (int) $history->total_discrepancy;
        }

        $totalPercentageLolos = $totalPriceAcuan != 0 ? ($valueDataLolos / $totalPriceAcuan) * 100 : 0;
        $totalPercentageDamaged = $totalPriceAcuan != 0 ? ($valueDataDamaged / $totalPriceAcuan) * 100 : 0;
        $totalPercentageAbnormal = $totalPriceAcuan != 0 ? ($valueDataAbnormal / $totalPriceAcuan) * 100 : 0;
        $totalPercentageNon = $totalPriceAcuan != 0 ? ($valueDataNon / $totalPriceAcuan) * 100 : 0;
        $totalPercentageSales = $totalPriceAcuan != 0 ? ($totalPriceSales / $totalPriceAcuan) * 100 : 0;
        $totalPercentageProductBundle = $totalPriceAcuan != 0 ? ($totalPriceProductBundle / $totalPriceAcuan) * 100 : 0;
        $totalPercentageDiscrepancy = $totalPriceAcuan != 0 ? ($valDiscrepancy / $totalPriceAcuan) * 100 : 0;

        return (new ResponseResource(true, "Riwayat Check", [
            'id' => $history->id,
            'user_id' => $history->user_id,
            'code_document' => $history->code_document,
            'base_document' => $history->base_document,
            'total_product_category' => $productCategoryCount,
            'total_product_color' => $productColorCount,
            'total_product_sales' => $countSalesItems,
            'total_product_stagings' => $totalStagings,
            'total_product_approve' => $totalPA,
            'total_product_bundle' => $countBundleItems,
            'total_data' => $totalDataAkurat,
            'total_data_in' => $isSku ? $totalDataInSku : (int) $history->total_data_in,
            'total_price_in' => $isSku ? max(0, $totalPriceAcuan - $valDiscrepancy) : ($history->total_price_in ?? null),
            'total_data_lolos' => $isSku ? $countDataLolos : (int) $history->total_data_lolos,
            'total_data_damaged' => $isSku ? $countDataDamaged : (int) $history->total_data_damaged,
            'total_data_abnormal' => $isSku ? $countDataAbnormal : (int) $history->total_data_abnormal,
            'total_data_non' => $isSku ? $countDataNon : (int) ($history->total_data_non ?? 0),
            'total_discrepancy' => $countDiscrepancy,
            'status_approve' => $history->status_approve,
            'precentage_total_data' => $history->precentage_total_data,
            'percentage_in' => $history->percentage_in,
            'percentage_lolos' => $history->percentage_lolos,
            'percentage_damaged' => $history->percentage_damaged,
            'percentage_abnormal' => $history->percentage_abnormal,
            'percentage_non' => $history->percentage_non,
            'percentage_discrepancy' => $history->percentage_discrepancy,
            'total_price' => $totalPriceAcuan,
            'created_at' => $history->created_at,
            'updated_at' => $history->updated_at,
            'value_data_lolos' => $valueDataLolos,
            'value_data_damaged' => $valueDataDamaged,
            'value_data_abnormal' => $valueDataAbnormal,
            'value_data_non' => $valueDataNon,
            'damaged' => [
                'total_old_price' => $valueDataDamaged,
                'price_percentage' => round($totalPercentageDamaged, 2),
            ],
            'lolos' => [
                'total_old_price' => $valueDataLolos,
                'price_percentage' => round($totalPercentageLolos, 2),
            ],
            'abnormal' => [
                'total_old_price' => $valueDataAbnormal,
                'price_percentage' => round($totalPercentageAbnormal, 2),
            ],
            'non' => [
                'total_old_price' => $valueDataNon,
                'price_percentage' => round($totalPercentageNon, 2),
            ],
            'lolosSale' => [
                'total_old_price' => $totalPriceSales,
                'price_percentage' => round($totalPercentageSales, 2),
            ],
            'lolosBundle' => [
                'total_old_price' => $totalPriceProductBundle,
                'price_percentage' => round($totalPercentageProductBundle, 2),
            ],
            'priceDiscrepancy' =>  $valDiscrepancy,
            'price_percentage' => round($totalPercentageDiscrepancy, 2),
        ]))->response();
    }

    public function exportRiwayatCheck(Request $request)
    {
        try {
            $fileName = 'riwayat-check-' . now()->format('Ymd_His_u') . '-' . uniqid() . '.xlsx';

            $path = 'exports/' . $fileName;

            Excel::store(
                new RiwayatCheckExport(),
                $path,
                'public'
            );

            $url = asset('storage/' . $path);

            return (new ResponseResource(
                true,
                'Export berhasil',
                [
                    'url' => $url,
                    'filename' => $fileName,
                ]
            ))->response();
        } catch (\Throwable $e) {
            return (new ResponseResource(
                false,
                'Gagal export: ' . $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

    public function getByDocument(Request $request)
    {
        $codeDocument = RiwayatCheck::where('code_document', $request['code_document']);
        return new ResponseResource(true, "Riwayat Check", $codeDocument);
    }

    public function edit(RiwayatCheck $riwayatCheck)
    {
        //
    }

    public function update(Request $request, RiwayatCheck $riwayatCheck) {}

    public function destroy(RiwayatCheck $history)
    {
        DB::beginTransaction();
        try {
            Notification::where('riwayat_check_id', $history->id)->delete();
            $history->delete();
            DB::commit();
            return new ResponseResource(true, 'data berhasil di hapus', $history);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, 'data gagal di hapus', $e->getMessage());
        }
    }

    public function exportToExcel(Request $request)
    {
        set_time_limit(900);
        ini_set('memory_limit', '1024M');

        $input_data = $request->input('code_document');

        $codeDocumentStr = '';
        if (is_array($input_data)) {
            if (isset($input_data['code_document'])) {
                $codeDocumentStr = (string) $input_data['code_document'];
            } else {
                $codeDocumentStr = (string) ($input_data[0] ?? '');
            }
        } else {
            $codeDocumentStr = (string) $input_data;
        }

        if ($codeDocumentStr === '') {
            return response()->json(['status' => false, 'message' => "Parameter code_document tidak valid atau kosong."], 400);
        }

        $getHistory = RiwayatCheck::where('code_document', $codeDocumentStr)->first();

        if (!$getHistory) {
            return response()->json(['status' => false, 'message' => "Data RiwayatCheck tidak ditemukan untuk dokumen: " . $codeDocumentStr], 404);
        }

        $isSku = str_contains($codeDocumentStr, 'SKU') || \App\Models\SkuDocument::where('code_document', $codeDocumentStr)->exists();
        $code_document = $codeDocumentStr;

        $fileName = $getHistory->base_document . '.xlsx';
        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;

        if (!file_exists(public_path($publicPath))) {
            mkdir(public_path($publicPath), 0777, true);
        }

        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $getProductDamaged = [];
        $totalOldPriceDamaged = 0;
        $getProductAbnormal = [];
        $totalOldPriceAbnormal = 0;
        $getProductNon = [];
        $totalOldPriceNon = 0;
        $getProductDiscrepancy = [];
        $totalOldPriceDiscrepancy = 0;
        $getProductLolos = [];
        $totalOldPriceLolos = 0;
        $getProductStagings = [];
        $totalOldPriceStaging = 0;
        $getProductPA = [];
        $totalOldPricePA = 0;
        $getProductBundle = [];
        $totalOldPriceBundle = 0;
        $getProductRepair = [];
        $totalOldPriceRepair = 0;

        $notSale = function ($q) {
            $q->where('new_status_product', '!=', 'sale')->orWhereNull('new_status_product');
        };

        $processedDefectBarcodes = [];

        if ($getHistory->status_file === 1) {
            $defects = ProductDefect::where('riwayat_check_id', $getHistory->id)->whereNotNull('new_barcode_product')->get();

            $defectBarcodes = $defects->pluck('new_barcode_product')->filter()->toArray();
            $processedDefectBarcodes = $defectBarcodes;

            $newProductsRef = \App\Models\New_product::where('code_document', $code_document)
                ->whereIn('new_barcode_product', $defectBarcodes)
                ->get()->keyBy('new_barcode_product');

            $stagingProductsRef = \App\Models\StagingProduct::where('code_document', $code_document)
                ->whereIn('new_barcode_product', $defectBarcodes)
                ->get()->keyBy('new_barcode_product');

            foreach ($defects as $product) {
                $product->actual_old_price_product = $product->old_price_product;

                // Cek apakah barcode ada di New_product atau StagingProduct
                $reference = $newProductsRef->get($product->new_barcode_product) ?? $stagingProductsRef->get($product->new_barcode_product);

                // Set value nama dan qty berdasarkan referensi yang ditemukan
                $product->new_name_product = $reference ? ($reference->new_name_product ?? $reference->old_name_product) : 'null';
                $product->new_quantity_product = $reference ? ($reference->new_quantity_product ?? 1) : 1;
                $product->old_name_product = $product->new_name_product;

                // if ($product->type === 'damaged') {
                //     // Gunakan note yang diinput user sebagai keterangan,
                //     // fallback ke 'damaged' jika note kosong
                //     $product->damaged_value = $product->note ?? 'damaged';
                //     $getProductDamaged[] = $product;
                //     $totalOldPriceDamaged += $product->old_price_product;
                // } elseif ($product->type === 'abnormal') {
                //     $product->abnormal_value = $product->note ?? 'abnormal';
                //     $getProductAbnormal[] = $product;
                //     $totalOldPriceAbnormal += $product->old_price_product;
                // } elseif ($product->type === 'non') {
                //     $product->non_value = $product->note ?? 'non';
                //     $getProductNon[] = $product;
                //     $totalOldPriceNon += $product->old_price_product;
                // }
                if ($product->type === 'damaged') {
                    $product->damaged_value = 'damaged';
                    $getProductDamaged[] = $product;
                    $totalOldPriceDamaged += $product->old_price_product;
                } elseif ($product->type === 'abnormal') {
                    $product->abnormal_value = 'abnormal';
                    $getProductAbnormal[] = $product;
                    $totalOldPriceAbnormal += $product->old_price_product;
                } elseif ($product->type === 'non') {
                    $product->non_value = 'non';
                    $getProductNon[] = $product;
                    $totalOldPriceNon += $product->old_price_product;
                }
            }
            $totalOldPriceDiscrepancy = $getHistory->value_data_discrepancy ?? 0;
        }

        // 9. Discrepancy 
        if ($getHistory->status_file !== 1) {
            if ($isSku) {
                \App\Models\SkuProduct::where('code_document', $code_document)
                    ->where('quantity_product', '>', 0)
                    ->chunk(2000, function ($products) use (&$getProductDiscrepancy, &$totalOldPriceDiscrepancy) {
                        foreach ($products as $product) {
                            $product->old_barcode_product = $product->barcode_product;
                            $product->old_name_product = $product->name_product;
                            $product->old_quantity_product = $product->quantity_product;
                            $product->old_price_product = $product->price_product;

                            $getProductDiscrepancy[] = $product;
                            $qty = $product->quantity_product ?? 1;
                            $totalOldPriceDiscrepancy += ($product->price_product * $qty);
                        }
                    });
            } else {
                \App\Models\Product_old::where('code_document', $code_document)
                    ->chunk(2000, function ($products) use (&$getProductDiscrepancy, &$totalOldPriceDiscrepancy) {
                        foreach ($products as $product) {
                            $getProductDiscrepancy[] = $product;
                            $totalOldPriceDiscrepancy += $product->old_price_product;
                        }
                    });
            }
        }

        $tablesToFetch = [
            'new' => New_product::where('code_document', $code_document)->where($notSale),
            'staging' => StagingProduct::where('code_document', $code_document)->where($notSale),
            'approve' => ProductApprove::where('code_document', $code_document)->where($notSale),
            'bundle' => Product_Bundle::where('code_document', $code_document)->where($notSale),
        ];

        foreach ($tablesToFetch as $tableKey => $query) {
            $query->chunk(2000, function ($products) use (
                &$getProductDamaged,
                &$totalOldPriceDamaged,
                &$getProductAbnormal,
                &$totalOldPriceAbnormal,
                &$getProductNon,
                &$totalOldPriceNon,
                &$getProductLolos,
                &$totalOldPriceLolos,
                &$getProductStagings,
                &$totalOldPriceStaging,
                &$getProductPA,
                &$totalOldPricePA,
                &$getProductBundle,
                &$totalOldPriceBundle,
                $isSku,
                $tableKey,
                $processedDefectBarcodes
            ) {
                foreach ($products as $product) {
                    $product->actual_old_price_product = $product->actual_old_price_product ?? $product->old_price_product;

                    $quality = is_array($product->actual_new_quality ?? $product->new_quality)
                        ? ($product->actual_new_quality ?? $product->new_quality)
                        : (json_decode($product->actual_new_quality ?? $product->new_quality, true) ?? []);

                    $qty = $isSku ? ($product->new_quantity_product ?? 1) : 1;
                    $price = $product->actual_old_price_product;

                    // Cek apakah data cacat ini sudah masuk via ProductDefect
                    $inDefect = in_array($product->new_barcode_product, $processedDefectBarcodes);

                    if ($inDefect) {
                        continue;
                    }

                    $isDamaged = !empty($quality['damaged']);
                    $isAbnormal = !empty($quality['abnormal']);
                    $isNon = !empty($quality['non']);

                    if (($tableKey === 'new' || $tableKey === 'staging') && $isDamaged) {
                        $product->damaged_value = $quality['damaged'];
                        $getProductDamaged[] = $product;
                        $totalOldPriceDamaged += ($price * $qty);
                    } elseif (($tableKey === 'new' || $tableKey === 'staging') && $isAbnormal) {
                        $product->abnormal_value = $quality['abnormal'];
                        $getProductAbnormal[] = $product;
                        $totalOldPriceAbnormal += ($price * $qty);
                    } elseif (($tableKey === 'new' || $tableKey === 'staging') && $isNon) {
                        $product->non_value = $quality['non'];
                        $getProductNon[] = $product;
                        $totalOldPriceNon += ($price * $qty);
                    } else {
                        if ($tableKey === 'new') {
                            $product->lolos_value = $quality['lolos'] ?? 'lolos';
                            $getProductLolos[] = $product;
                            $totalOldPriceLolos += ($price * $qty);
                        } elseif ($tableKey === 'staging') {
                            $product->lolos_value = $quality['lolos'] ?? 'pending';
                            $getProductStagings[] = $product;
                            $totalOldPriceStaging += ($price * $qty);
                        } elseif ($tableKey === 'approve') {
                            $getProductPA[] = $product;
                            $totalOldPricePA += ($price * $qty);
                        } elseif ($tableKey === 'bundle') {
                            $getProductBundle[] = $product;
                            $totalOldPriceBundle += ($price * $qty);
                        }
                    }
                }
            });
        }

        // 8. Repair => Migrate Bulky Product
        \App\Models\MigrateBulkyProduct::where('code_document_inbound', $code_document)
            ->chunk(2000, function ($products) use (&$getProductRepair, &$totalOldPriceRepair, $isSku) {
                foreach ($products as $product) {
                    $price = $product->old_price_product ?? 0;
                    $qty = $isSku ? ($product->new_quantity_product ?? 1) : 1;

                    $product->actual_old_price_product = $price;
                    $product->lolos_value = 'lolos';

                    $getProductRepair[] = $product;
                    $totalOldPriceRepair += ($price * $qty);
                }
            });

        // 7. Sales => Sales & Bulky Sales 
        $totalPriceSales = 0;
        $allSalesData = collect();

        if ($isSku) {
            $salesNew = New_product::where('code_document', $code_document)->where('new_status_product', 'sale')->get();
            $salesStaging = StagingProduct::where('code_document', $code_document)->where('new_status_product', 'sale')->get();

            foreach ($salesNew->concat($salesStaging) as $item) {
                $price = $item->actual_old_price_product ?? $item->old_price_product ?? 0;
                $qty = $item->new_quantity_product ?? 1;

                $item->actual_product_old_price_sale = $price;
                $item->product_qty_sale = $qty;
                $item->product_name_sale = $item->new_name_product ?? $item->old_name_product;
                $item->product_barcode_sale = $item->new_barcode_product;
                $item->product_category_sale = $item->new_category_product;
                $item->product_price_sale = $item->new_price_product;
                $item->status_product = $item->new_status_product;

                $allSalesData->push($item);
                $totalPriceSales += ($price * $qty);
            }
        } else {
            $getProductSales = Sale::where('code_document', $getHistory->code_document)->get();
            $getBulkySales = BulkySale::where('code_document', $getHistory->code_document)->get();
            $allSalesData = $getProductSales->concat($getBulkySales);

            foreach ($allSalesData as $item) {
                $item->actual_product_old_price_sale = $item->actual_product_old_price_sale ?? $item->product_old_price_sale ?? $item->old_price_bulky_sale;
                $totalPriceSales += ($item->actual_product_old_price_sale * 1);
            }
        }

        // Kalkulasi Persentase
        $totalH = $getHistory->total_price;
        $price_persentage_damaged = $totalH != 0 ? round(($totalOldPriceDamaged / $totalH) * 100, 2) : 0;
        $price_persentage_abnormal = $totalH != 0 ? round(($totalOldPriceAbnormal / $totalH) * 100, 2) : 0;
        $price_persentage_non = $totalH != 0 ? round(($totalOldPriceNon / $totalH) * 100, 2) : 0;
        $price_persentage_lolos = $totalH != 0 ? round(($totalOldPriceLolos / $totalH) * 100, 2) : 0;
        $price_persentage_staging = $totalH != 0 ? round(($totalOldPriceStaging / $totalH) * 100, 2) : 0;
        $price_persentage_product_approve = $totalH != 0 ? round(($totalOldPricePA / $totalH) * 100, 2) : 0;
        $price_persentage_bundle = $totalH != 0 ? round(($totalOldPriceBundle / $totalH) * 100, 2) : 0;
        $totalPercentageSales = $totalH != 0 ? round(($totalPriceSales / $totalH) * 100, 2) : 0;
        $price_persentage_dp = $totalH != 0 ? round(($totalOldPriceDiscrepancy / $totalH) * 100, 2) : 0;
        $price_persentage_repair = $totalH != 0 ? round(($totalOldPriceRepair / $totalH) * 100, 2) : 0;

        $checkHistory = RiwayatCheck::where('code_document', $code_document)->get();
        if ($checkHistory->isEmpty()) {
            return response()->json(['status' => false, 'message' => "Data kosong, tidak bisa di export"], 422);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'Code Document',
            'Base Document',
            'Total Data',
            'Total Data In',
            'Total Data Lolos',
            'Total Data Damaged',
            'Total Data Abnormal',
            'Total Data Non',
            'Total Discrepancy',
            'Status Approve',
            'Percentage Total Data',
            'Percentage In',
            'Percentage Lolos',
            'Percentage Damaged',
            'Percentage Abnormal',
            'Percentage Non',
            'Percentage Discrepancy',
            'Total Price'
        ];

        $currentRow = 1;
        foreach ($checkHistory as $riwayatCheck) {
            foreach ($headers as $index => $header) {
                $columnName = strtolower(str_replace(' ', '_', $header));
                $cellValue = $riwayatCheck->$columnName;

                if ($isSku && $columnName === 'total_data') {
                    $cellValue = \App\Models\SkuProductOld::where('code_document', $code_document)->sum('old_quantity_product') ?? $cellValue;
                }

                $sheet->setCellValueByColumnAndRow($index + 1, $currentRow, $header);
                $sheet->setCellValueByColumnAndRow($index + 1, $currentRow + 1, $cellValue);
            }
            $currentRow += 3;
        }

        $this->createExcelSheet($spreadsheet, 'Damaged-Inventory', $getProductDamaged, $totalOldPriceDamaged, $price_persentage_damaged);
        $this->createExcelSheet($spreadsheet, 'Lolos-Inventory', $getProductLolos, $totalOldPriceLolos, $price_persentage_lolos);
        $this->createExcelSheetAbnormal($spreadsheet, 'Abnormal-Inventory', $getProductAbnormal, $totalOldPriceAbnormal, $price_persentage_abnormal);
        $this->createExcelSheet($spreadsheet, 'Non', $getProductNon, $totalOldPriceNon, $price_persentage_non);
        $this->createExcelSheet($spreadsheet, 'Staging', $getProductStagings, $totalOldPriceStaging, $price_persentage_staging);
        $this->createExcelSheet($spreadsheet, 'Product Approve', $getProductPA, $totalOldPricePA, $price_persentage_product_approve);
        $this->createExcelSheet($spreadsheet, 'Product-bundle', $getProductBundle, $totalOldPriceBundle, $price_persentage_bundle);

        $this->createExcelSheet($spreadsheet, 'Repair', $getProductRepair, $totalOldPriceRepair, $price_persentage_repair);

        $this->createExcelSale($spreadsheet, 'Sales', $allSalesData, $totalPriceSales, $totalPercentageSales);
        $this->createExcelSheetDiscrepancy($spreadsheet, 'Discrepancy', $getProductDiscrepancy, $totalOldPriceDiscrepancy, $price_persentage_dp);

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        $downloadUrl = url($publicPath . '/' . $fileName) . '?v=' . time();

        return new ResponseResource(true, "File siap diunduh.", $downloadUrl);
    }

    private function createExcelSheet($spreadsheet, $title, $data, $totalOldPrice, $pricePercentage)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        $headers = [
            'Code Document',
            'Old Barcode',
            'New Barcode',
            'Name Product',
            'Keterangan',
            'Qty',
            'Unit Price',
            'Category',
            'Diskon',
            'After Diskon',
            'Price Percentage',
            'Weight'
        ];

        $sheet->fromArray($headers, null, 'A1');

        $dataArray = [];
        foreach ($data as $item) {
            $item = is_array($item) ? (object) $item : $item;

            $actualOldPrice = $item->actual_old_price_product ?? $item->old_price_product ?? 0;
            $newPrice = $item->new_price_product ?? 0;

            $diskon = $actualOldPrice != 0
                ? (($actualOldPrice - $newPrice) / $actualOldPrice) * 100
                : 0;

            $keterangan = $item->lolos_value ?? $item->damaged_value ?? $item->abnormal_value ?? $item->non_value ??  'null';

            // Masukkan data ke array sementara (row)
            $row = [
                $item->code_document ?? $item->code_document_inbound ?? 'null',
                $item->old_barcode_product ?? 'null',
                $item->new_barcode_product ?? 'null',
                $item->new_name_product ?? $item->old_name_product ?? 'null',
                $keterangan,
                $item->new_quantity_product ?? $item->old_quantity_product ?? 1,
                $actualOldPrice,
                $item->new_category_product ?? 'null',
                round($diskon, 2),
                $newPrice,
                $pricePercentage,
                $item->weight ?? 'null'
            ];

            // Bersihkan karakter UTF-8 yang rusak pada setiap string di row ini
            $dataArray[] = array_map(function ($value) {
                return is_string($value) ? mb_convert_encoding($value, 'UTF-8', 'UTF-8') : $value;
            }, $row);
        }

        $sheet->fromArray($dataArray, null, 'A2');

        $totalRow = count($dataArray) + 2;
        $sheet->setCellValue("A{$totalRow}", 'Total Price');
        $sheet->setCellValue("B{$totalRow}", $totalOldPrice);
        $sheet->setCellValue("C{$totalRow}", 'Price Percentage');
        $sheet->setCellValue("D{$totalRow}", $pricePercentage);
    }

    private function createExcelSheetAbnormal($spreadsheet, $title, $data, $totalOldPrice, $pricePercentage)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        // Menetapkan header
        $headers = [
            'Code Document',
            'Old Barcode',
            'New Barcode',
            'Name Product',
            'Keterangan',
            'Qty',
            'Unit Price',
            'Category',
            'Diskon',
            'After Diskon',
            'Price Percentage',
            'Keterangan',
            'Detail',
            'Weight'
        ];

        // Menulis header langsung ke lembar kerja
        $sheet->fromArray($headers, null, 'A1');

        // Memproses data dan menyiapkan array untuk dimasukkan ke Excel
        $dataArray = [];
        foreach ($data as $item) {
            // Gunakan actual_old_price_product dengan fallback
            $actualOldPrice = $item->actual_old_price_product ?? $item->old_price_product;

            $diskon = $actualOldPrice != 0
                ? (($actualOldPrice - $item->new_price_product) / $actualOldPrice) * 100
                : 0;

            $keterangan = $item->lolos_value ?? $item->damaged_value ?? $item->abnormal_value ?? 'null';

            // Menambahkan data ke array sementara
            $row = [
                $item->code_document ?? 'null',
                $item->old_barcode_product ?? 'null',
                $item->new_barcode_product ?? 'null',
                $item->new_name_product ?? 'null',
                $keterangan,
                $item->new_quantity_product ?? 'null',
                $actualOldPrice ?? 'null',
                $item->new_category_product ?? 'null',
                $diskon ?? 'null',
                $item->new_price_product ?? 'null',
                $pricePercentage,
                'Abnormal',
                $item->note ?? '',
                $item->weight ?? 'null'
            ];

            // Bersihkan karakter UTF-8 yang rusak
            $dataArray[] = array_map(function ($value) {
                return is_string($value) ? mb_convert_encoding($value, 'UTF-8', 'UTF-8') : $value;
            }, $row);
        }

        // Menulis data dalam bentuk array ke lembar Excel mulai dari baris ke-2
        $sheet->fromArray($dataArray, null, 'A2');

        // Menambahkan total dan persentase di bagian akhir
        $totalRow = count($dataArray) + 2; // Baris setelah data
        $sheet->setCellValue("A{$totalRow}", 'Total Price');
        $sheet->setCellValue("B{$totalRow}", $totalOldPrice);
        $sheet->setCellValue("C{$totalRow}", 'Price Percentage');
        $sheet->setCellValue("D{$totalRow}", $pricePercentage);
    }

    private function createExcelSale($spreadsheet, $title, $data, $totalOldPrice, $pricePercentage)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        $headers = [
            'Code Document',
            'Name Product',
            'New Barcode',
            'Qty',
            'Unit Price',
            'Category',
            'Price After Diskon',
            'Price Percentage',
            'Old Barcode',
            'Status Product',
            'Weight'
        ];

        $sheet->fromArray($headers, null, 'A1');

        $dataArray = [];
        foreach ($data as $item) {
            $actualOldPriceSale = $item->actual_product_old_price_sale ??
                $item->product_old_price_sale ??
                $item->actual_old_price_product ??
                $item->old_price_bulky_sale;

            $row = [
                $item->code_document_sale ?? $item->code_document ?? 'null',
                $item->product_name_sale ?? $item->name_product_bulky_sale ?? 'null',
                $item->product_barcode_sale ?? $item->barcode_bulky_sale ?? 'null',
                $item->product_qty_sale ?? $item->qty ?? 'null',
                $actualOldPriceSale ?? 'null',
                $item->product_category_sale ?? $item->product_category_bulky_sale ?? 'null',
                $item->product_price_sale ?? $item->after_price_bulky_sale ?? 'null',
                $pricePercentage,
                $item->old_barcode_product ?? 'null',
                $item->status_product ?? $item->status_product_before ?? 'null',
                $item->weight ?? 'null'
            ];

            // Bersihkan karakter UTF-8 yang rusak
            $dataArray[] = array_map(function ($value) {
                return is_string($value) ? mb_convert_encoding($value, 'UTF-8', 'UTF-8') : $value;
            }, $row);
        }

        $sheet->fromArray($dataArray, null, 'A2');

        // Menambahkan total dan persentase di bagian akhir
        $totalRow = count($dataArray) + 2;
        $sheet->setCellValue("A{$totalRow}", 'Total Price');
        $sheet->setCellValue("B{$totalRow}", $totalOldPrice);
        $sheet->setCellValue("C{$totalRow}", 'Price Percentage');
        $sheet->setCellValue("D{$totalRow}", $pricePercentage);
    }

    private function createExcelSheetDiscrepancy($spreadsheet, $title, $data, $totalOldPrice, $pricePercentage)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        // Menetapkan header
        $headers = [
            'Code Document',
            'Old Barcode',
            'Name Product',
            'Qty',
            'Unit Price',
            'Weight'
        ];

        // Menulis header langsung ke lembar kerja
        $sheet->fromArray($headers, null, 'A1');

        // Memproses data dan menyiapkan array untuk dimasukkan ke Excel
        $dataArray = [];
        foreach ($data as $item) {
            $diskon = $item->old_price_product != 0
                ? (($item->old_price_product - $item->new_price_product) / $item->old_price_product) * 100
                : 0;

            $keterangan = $item->lolos_value ?? $item->damaged_value ?? $item->abnormal_value ?? 'null';

            // Menambahkan data ke array sementara
            $row = [
                $item->code_document ?? 'null',
                $item->old_barcode_product ?? 'null',
                $item->old_name_product ?? 'null',
                $item->old_quantity_product ?? 'null',
                $item->old_price_product ?? 'null',
                $item->weight ?? 'null'
            ];

            // Bersihkan karakter UTF-8 yang rusak
            $dataArray[] = array_map(function ($value) {
                return is_string($value) ? mb_convert_encoding($value, 'UTF-8', 'UTF-8') : $value;
            }, $row);
        }

        // Menulis data dalam bentuk array ke lembar Excel mulai dari baris ke-2
        $sheet->fromArray($dataArray, null, 'A2');

        // Menambahkan total dan persentase di bagian akhir
        $totalRow = count($dataArray) + 2; // Baris setelah data
        $sheet->setCellValue("A{$totalRow}", 'Total Price');
        $sheet->setCellValue("B{$totalRow}", $totalOldPrice);
    }

    // kita akan membuat function yang mengechek old_barcode_product dan old_price_product sama dari patokan kita mencari dari tabel product_olds ke barcode_damageds
    public function updatePricesFromExcel(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        // Validasi input
        $validator = Validator::make($request->all(), [
            'code_document' => 'required|string'
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, "Validation failed: " . implode(', ', $validator->errors()->all()), null);
        }

        $codeDocument = $request->input('code_document');

        try {
            // Ambil data dari barcode_damageds sebagai reference (Excel data)
            // Filter hanya data dengan old_price_product yang valid (numeric) dan code_document yang sesuai
            $excelData = \App\Models\BarcodeDamaged::select('old_barcode_product', 'old_price_product', 'code_document')
                ->where('code_document', $codeDocument)
                ->whereNotNull('old_price_product')
                ->where('old_price_product', '!=', '')
                ->get()
                ->filter(function ($item) {
                    // Filter hanya yang old_price_product numeric
                    return is_numeric($item->old_price_product) && $item->old_price_product > 0;
                })
                ->keyBy('old_barcode_product');

            if ($excelData->isEmpty()) {
                return new ResponseResource(false, "Tidak ada data Excel yang valid untuk code_document '{$codeDocument}'. Pastikan kolom old_price_product berisi angka yang valid.", null);
            }

            // Define semua tabel yang akan diupdate
            $tablesToUpdate = [
                'new_products' => \App\Models\New_product::class,
                'staging_products' => \App\Models\StagingProduct::class,
                // 'staging_approves' => \App\Models\StagingApprove::class,
                // 'filter_stagings' => \App\Models\FilterStaging::class,
                'product_bundles' => \App\Models\Product_Bundle::class,
                'product_approves' => \App\Models\ProductApprove::class,
                // 'repair_filters' => \App\Models\RepairFilter::class,
                'repair_products' => \App\Models\RepairProduct::class,
                // 'product_olds' => \App\Models\Product_old::class,
                'sales' => \App\Models\Sale::class,
            ];

            $updateResults = [];
            $invalidData = [];
            $summary = [
                'total_excel_records' => $excelData->count(),
                'total_records_updated' => 0,
                'total_records_not_found' => 0,
                'total_invalid_prices' => 0,
                'tables_updated' => [],
            ];

            // Mulai transaction setelah validasi data awal
            DB::beginTransaction();

            // Loop through setiap barcode dari Excel
            foreach ($excelData as $barcode => $excelRecord) {
                $foundAndUpdated = false;
                $newPrice = $excelRecord->actual_old_price_product;

                // Validasi tambahan untuk price
                if (!is_numeric($newPrice) || $newPrice <= 0) {
                    $summary['total_invalid_prices']++;
                    $invalidData[] = [
                        'barcode' => $barcode,
                        'invalid_price' => $newPrice,
                        'reason' => 'Price is not numeric or less than or equal to 0'
                    ];
                    continue;
                }

                // Convert ke float untuk memastikan format yang benar
                $newPrice = (float) $newPrice;

                // Update di semua tabel sistem
                foreach ($tablesToUpdate as $tableName => $modelClass) {
                    $priceColumn = ($tableName === 'sales') ? 'actual_product_old_price_sale' : 'actual_old_price_product';

                    try {
                        // Prepare data untuk update
                        $updateData = [$priceColumn => $newPrice];

                        // Tambahan update untuk new_quality atau status_product
                        if ($tableName === 'sales') {
                            // Untuk tabel sales, update status_product
                            $updateData['status_product'] = 'abnormal';
                        } else {
                            // Untuk tabel lainnya, update new_quality
                            $updateData['new_quality'] = json_encode([
                                'lolos' => null,
                                'damaged' => null,
                                'abnormal' => 'FRAUD & OVERPRICE'
                            ]);
                        }

                        // Cari dan update record yang sesuai berdasarkan barcode dan code_document
                        $updatedCount = $modelClass::where('old_barcode_product', $barcode)
                            ->where('code_document', $codeDocument)
                            ->update($updateData);

                        if ($updatedCount > 0) {
                            $foundAndUpdated = true;
                            $summary['total_records_updated'] += $updatedCount;

                            // Track tabel mana yang diupdate
                            if (!isset($summary['tables_updated'][$tableName])) {
                                $summary['tables_updated'][$tableName] = 0;
                            }
                            $summary['tables_updated'][$tableName] += $updatedCount;

                            $updateResults[] = [
                                'barcode' => $barcode,
                                'table' => $tableName,
                                'new_price' => $newPrice,
                                'updated_count' => $updatedCount,
                                'status' => 'updated',
                                'additional_updates' => $tableName === 'sales'
                                    ? ['status_product' => 'abnormal']
                                    : ['new_quality' => 'FRAUD & OVERPRICE']
                            ];
                        }
                    } catch (\Exception $tableError) {
                        // Log error untuk tabel tertentu tapi lanjutkan ke tabel lain
                        Log::error("Error updating table {$tableName} for barcode {$barcode}: " . $tableError->getMessage());
                        $invalidData[] = [
                            'barcode' => $barcode,
                            'table' => $tableName,
                            'price' => $newPrice,
                            'error' => $tableError->getMessage()
                        ];
                    }
                }

                // Jika tidak ditemukan di sistem
                if (!$foundAndUpdated) {
                    $summary['total_records_not_found']++;
                    $updateResults[] = [
                        'barcode' => $barcode,
                        'table' => null,
                        'new_price' => $newPrice,
                        'updated_count' => 0,
                        'status' => 'not_found'
                    ];
                }
            }

            DB::commit();

            return new ResponseResource(true, "Update prices completed successfully", [
                'summary' => $summary,
                'details' => $updateResults,
                'invalid_data' => $invalidData
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error during update: " . $e->getMessage(), null);
        }
    }

    //ini membuat new_quality nya menjadi abnormal
    public function updatePricesFromExcel2(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        // Validasi input
        $validator = Validator::make($request->all(), [
            'code_document' => 'required|string'
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, "Validation failed: " . implode(', ', $validator->errors()->all()), null);
        }

        $codeDocument = $request->input('code_document');

        try {
            // Ambil barcode dari barcode_damageds untuk code_document yang diminta
            $barcodes = \App\Models\BarcodeDamaged::where('code_document', $codeDocument)
                ->pluck('old_barcode_product')
                ->unique()
                ->values();

            if ($barcodes->isEmpty()) {
                return new ResponseResource(false, "Tidak ada barcode untuk code_document '{$codeDocument}'.", null);
            }

            // Define semua tabel yang akan diupdate
            $tablesToUpdate = [
                'new_products' => \App\Models\New_product::class,
                'staging_products' => \App\Models\StagingProduct::class,
                'product_bundles' => \App\Models\Product_Bundle::class,
                'product_approves' => \App\Models\ProductApprove::class,
                'repair_products' => \App\Models\RepairProduct::class,
                'sales' => \App\Models\Sale::class,
            ];

            $summary = [
                'total_barcodes' => $barcodes->count(),
                'total_records_updated' => 0,
                'tables_updated' => [],
            ];

            // Mulai transaction
            DB::beginTransaction();

            // Update di semua tabel
            foreach ($tablesToUpdate as $tableName => $modelClass) {
                try {
                    if ($tableName === 'sales') {
                        // Untuk tabel sales, update status_product dengan filter code_document_sale
                        $updatedCount = $modelClass::whereIn('old_barcode_product', $barcodes)
                            ->where('code_document_sale', $codeDocument)
                            ->update(['status_product' => 'abnormal']);
                    } else {
                        // Untuk tabel lainnya, update actual_new_quality dengan filter code_document
                        $updatedCount = $modelClass::whereIn('old_barcode_product', $barcodes)
                            ->where('code_document', $codeDocument)
                            ->update([
                                'actual_new_quality' => json_encode([
                                    'lolos' => null,
                                    'damaged' => null,
                                    'abnormal' => 'FRAUD & OVERPRICE'
                                ])
                            ]);
                    }

                    if ($updatedCount > 0) {
                        $summary['total_records_updated'] += $updatedCount;
                        $summary['tables_updated'][$tableName] = $updatedCount;
                    }
                } catch (\Exception $tableError) {
                    Log::error("Error updating table {$tableName}: " . $tableError->getMessage());
                }
            }

            DB::commit();

            return new ResponseResource(true, "Update completed successfully", [
                'summary' => $summary
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Error during update: " . $e->getMessage(), null);
        }
    }

    public function validateExcelData(Request $request)
    {
        // Validasi input
        $validator = Validator::make($request->all(), [
            'code_document' => 'sometimes|string'
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, "Validation failed: " . implode(', ', $validator->errors()->all()), null);
        }

        $codeDocument = $request->input('code_document');

        try {
            // Ambil data dari barcode_damageds dengan filter code_document jika ada
            $query = \App\Models\BarcodeDamaged::select('old_barcode_product', 'old_price_product', 'code_document');

            if ($codeDocument) {
                $query->where('code_document', $codeDocument);
            }

            $allData = $query->get();

            $validData = [];
            $invalidData = [];

            foreach ($allData as $record) {
                $barcode = $record->old_barcode_product;
                $price = $record->old_price_product;

                // Cek validitas data
                if (empty($barcode)) {
                    $invalidData[] = [
                        'barcode' => $barcode,
                        'price' => $price,
                        'code_document' => $record->code_document,
                        'issue' => 'Barcode is empty'
                    ];
                } elseif (empty($price) || !is_numeric($price) || $price <= 0) {
                    $invalidData[] = [
                        'barcode' => $barcode,
                        'price' => $price,
                        'code_document' => $record->code_document,
                        'issue' => 'Price is invalid (not numeric, empty, or <= 0)'
                    ];
                } else {
                    $validData[] = [
                        'barcode' => $barcode,
                        'price' => (float) $price,
                        'code_document' => $record->code_document
                    ];
                }
            }

            $summary = [
                'code_document_filter' => $codeDocument ?? 'All documents',
                'total_records' => $allData->count(),
                'valid_records' => count($validData),
                'invalid_records' => count($invalidData),
                'validation_percentage' => $allData->count() > 0 ? (count($validData) / $allData->count()) * 100 : 0
            ];

            return new ResponseResource(true, "Data validation completed", [
                'summary' => $summary,
                'invalid_data' => $invalidData,
                'sample_valid_data' => array_slice($validData, 0, 10) // Sample 10 data valid
            ]);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Error during validation: " . $e->getMessage(), null);
        }
    }

    public function compareExcelWithSystem(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        // Validasi input
        $validator = Validator::make($request->all(), [
            'code_document' => 'sometimes|string'
        ]);

        if ($validator->fails()) {
            return new ResponseResource(false, "Validation failed: " . implode(', ', $validator->errors()->all()), null);
        }

        $codeDocument = $request->input('code_document');

        try {
            // Ambil data dari barcode_damageds sebagai reference (Excel data)
            $query = \App\Models\BarcodeDamaged::select('old_barcode_product', 'old_price_product', 'code_document');

            if ($codeDocument) {
                $query->where('code_document', $codeDocument);
            }

            $excelData = $query->get()->keyBy('old_barcode_product');

            if ($excelData->isEmpty()) {
                $message = $codeDocument
                    ? "Tidak ada data Excel untuk code_document '{$codeDocument}' untuk dibandingkan."
                    : "Tidak ada data Excel untuk dibandingkan.";
                return new ResponseResource(false, $message . " Silakan upload file Excel terlebih dahulu.", null);
            }

            // Define semua tabel yang akan dicek
            $tablesToCheck = [
                // 'new_products' => \App\Models\New_product::class,
                // 'staging_products' => \App\Models\StagingProduct::class,
                // 'staging_approves' => \App\Models\StagingApprove::class,
                // 'filter_stagings' => \App\Models\FilterStaging::class,
                // 'product_bundles' => \App\Models\Product_Bundle::class,
                // 'product_approves' => \App\Models\ProductApprove::class,
                // 'repair_filters' => \App\Models\RepairFilter::class,
                // 'repair_products' => \App\Models\RepairProduct::class,
                // 'sales' => \App\Models\Sale::class,
                'product_olds' => \App\Models\Product_old::class,
            ];

            $discrepancies = [];
            $summary = [
                'code_document_filter' => $codeDocument ?? 'All documents',
                'total_excel_records' => $excelData->count(),
                'total_system_records_found' => 0,
                'total_price_mismatches' => 0,
                'total_missing_in_system' => 0,
                'excel_total_price' => $excelData->sum('old_price_product'),
                'system_total_price' => 0,
            ];

            // Loop through setiap barcode dari Excel
            foreach ($excelData as $barcode => $excelRecord) {
                $foundInSystem = false;
                $systemPrice = 0;
                $foundInTable = null;

                // Cari di semua tabel sistem
                foreach ($tablesToCheck as $tableName => $modelClass) {
                    $priceColumn = ($tableName === 'sales') ? 'product_old_price_sale' : 'old_price_product';

                    // Query dengan filter code_document jika ada
                    $query = $modelClass::where('old_barcode_product', $barcode);

                    if ($codeDocument) {
                        $query->where('code_document', $codeDocument);
                    }

                    $systemRecord = $query->select('old_barcode_product', $priceColumn)->first();

                    if ($systemRecord) {
                        $foundInSystem = true;
                        $systemPrice = $systemRecord->{$priceColumn};
                        $foundInTable = $tableName;
                        $summary['total_system_records_found']++;
                        $summary['system_total_price'] += $systemPrice;
                        break; // Stop searching setelah ditemukan
                    }
                }

                // Cek apakah ada discrepancy
                if (!$foundInSystem) {
                    // Barcode tidak ditemukan di sistem
                    $discrepancies[] = [
                        'barcode' => $barcode,
                        'excel_price' => $excelRecord->old_price_product,
                        'system_price' => null,
                        'found_in_table' => null,
                        'price_difference' => $excelRecord->old_price_product,
                        'status' => 'missing_in_system',
                        'issue' => 'Barcode tidak ditemukan di sistem'
                    ];
                    $summary['total_missing_in_system']++;
                } else if ($excelRecord->old_price_product != $systemPrice) {
                    // Barcode ditemukan tapi harga berbeda
                    $discrepancies[] = [
                        'barcode' => $barcode,
                        'excel_price' => $excelRecord->old_price_product,
                        'system_price' => $systemPrice,
                        'found_in_table' => $foundInTable,
                        'price_difference' => $excelRecord->old_price_product - $systemPrice,
                        'status' => 'price_mismatch',
                        'issue' => 'Harga tidak sesuai antara Excel dan sistem'
                    ];
                    $summary['total_price_mismatches']++;
                }
            }

            // Hitung total price difference
            $summary['total_price_difference'] = $summary['excel_total_price'] - $summary['system_total_price'];
            $summary['total_discrepancies'] = count($discrepancies);

            // Hitung total selisih dari semua discrepancies
            $totalSelisih = 0;
            foreach ($discrepancies as $discrepancy) {
                $totalSelisih += abs($discrepancy['price_difference']);
            }

            // Extract hanya barcode dari discrepancies
            $onlyBarcodes = array_column($discrepancies, 'barcode');

            return new ResponseResource(true, "Comparison completed", [
                'summary' => $summary,
                'total_selisih_harga' => $totalSelisih,
                'barcodes' => $onlyBarcodes,
                'jumlah_barcode_bermasalah' => count($onlyBarcodes)
            ]);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Error during comparison: " . $e->getMessage(), null);
        }
    }
}

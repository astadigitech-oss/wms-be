<?php

namespace App\Http\Controllers;

use App\Exports\BundleExport;
use App\Models\Bundle;
use App\Models\New_product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\ResponseResource;
use App\Services\MovementService;
use App\Models\Product_Bundle;
use App\Models\ProductInput;
use App\Models\StagingProduct;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

class BundleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');

        $bundles = Bundle::with(['product_bundles.user'])
            ->where(function ($q) {
                $q->whereNull('type')
                    ->orWhereIn('type', ['type1', 'type2']);
            })
            ->where('product_status', '=', 'not sale')
            ->latest();

        if ($query) {
            $bundles->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('name_bundle', 'LIKE', '%' . $query . '%')
                    ->orWhere('barcode_bundle', 'LIKE', '%' . $query . '%')
                    ->orWhere('category', 'LIKE', '%' . $query . '%')
                    ->orWhere('old_barcode_bundle', 'LIKE', '%' . $query . '%')
                    ->orWhereHas('product_bundles', function ($subQueryBuilder) use ($query) {
                        $subQueryBuilder->where('new_name_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_category_product', 'LIKE', '%' . $query . '%');
                    });
            });
        }

        $paginatedBundles = $bundles->paginate(50);

        $paginatedBundles->getCollection()->transform(function ($bundle) {

            $user = null;
            if ($bundle->product_bundles && $bundle->product_bundles->isNotEmpty()) {
                $firstItem = $bundle->product_bundles->first();
                $user = $firstItem->user ? $firstItem->user->name : null;
            }

            $bundle->user = $user;

            return $bundle;
        });

        return new ResponseResource(true, "list bundle", $paginatedBundles);
    }

    /**
     * Show the form for creating a new resource. 
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Bundle $bundle)
    {
        $query = $request->input('q');

        $bundle->load(['product_bundles' => function ($productBundles) use ($query) {
            if (!empty($query)) {
                $productBundles->where(function ($q) use ($query) {
                    $q->where('new_name_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('old_barcode_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                        ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%');
                });
            }
        }]);

        $category = \App\Models\Category::where('name_category', $bundle->category)->first();

        $bundle->discount_category = $category ? $category->discount_category : null;

        return new ResponseResource(true, "detail bundle", $bundle);
    }

    /**
     * Show the form for editing the specified resource.
     */

    public function edit(Bundle $bundle)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Bundle $bundle)
    {
        if ($bundle->product_status === 'sale') {
            return (new ResponseResource(false, "Bundle sudah terjual (sale) dan detailnya tidak dapat diubah!", []))
                ->response()->setStatusCode(422);
        }

        $validator = Validator::make($request->all(), [
            'name_bundle' => 'required',
            'category' => 'nullable',
            'total_price_bundle' => 'required|numeric',
            'total_price_custom_bundle' => 'required|numeric',
            // 'total_product_bundle' => 'nullable',
            'name_color' => 'nullable'
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        DB::beginTransaction();
        try {
            $totalProductInBundle = Product_Bundle::where('bundle_id', $bundle->id)->count();

            // Melakukan update pada data bundle
            $bundle->update([
                'name_bundle' => $request->name_bundle,
                'category' => $request->has('category') ? $request->category : null,
                'total_price_bundle' => $request->total_price_bundle,
                'total_price_custom_bundle' => $request->total_price_custom_bundle,
                'total_product_bundle' => $totalProductInBundle,
                'name_color' => $request->has('name_color') ? $request->name_color : null,
                'source' => $bundle->source
            ]);

            DB::commit();
            return new ResponseResource(true, "Bundle berhasil di edit", $bundle);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Bundle gagal di edit: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Bundle gagal di edit', 'error' => $e->getMessage()], 500);
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Bundle $bundle)
    {
        if ($bundle->product_status === 'sale') {
            return (new ResponseResource(false, "Bundle sudah terjual (sale) dan tidak dapat di-unbundle!", []))
                ->response()->setStatusCode(422);
        }

        if ($bundle->source === 'display' && $bundle->category != null) {
            return (new ResponseResource(false, "Bundle sudah display dan tidak dapat di-unbundle!", []))
                ->response()->setStatusCode(422);
        }

        DB::beginTransaction();
        try {
            $productBundles = $bundle->product_bundles;
            $movementRows = [];

            foreach ($productBundles as $product) {
                $source = $product->source ?? 'display';
                $movementRows[] = [
                    'product_id' => $product->new_barcode_product,
                    'is_sku' => false,
                    'type' => 'Unbundler',
                    'type_out' => null,
                    'from' => 'bundle',
                    'to' => $source === 'staging' ? 'staging_reguler' : ($product->new_tag_product ? 'display_color' : 'display_reguler'),
                    'qty' => $product->new_quantity_product,
                ];
                $productData = [
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->old_barcode_product,
                    'new_barcode_product' => $product->new_barcode_product,
                    'new_name_product' => $product->new_name_product,
                    'new_quantity_product' => $product->new_quantity_product,
                    'new_price_product' => $product->new_price_product,
                    'old_price_product' => $product->old_price_product,
                    'actual_old_price_product' => $product->actual_old_price_product,
                    'new_date_in_product' => $product->new_date_in_product,
                    'new_status_product' => 'display',
                    'new_quality' => $product->new_quality,
                    'actual_new_quality' => $product->actual_new_quality,
                    'new_category_product' => $product->new_category_product,
                    'new_tag_product' => $product->new_tag_product,
                    'display_price' => $product->display_price,
                    'new_discount' => $product->new_discount,
                    'type' => $product->type,
                    'is_extra' => $product->is_extra,
                    'weight' => $product->weight ?? null
                ];

                if ($source === 'staging') {
                    StagingProduct::create($productData);
                } else {
                    New_product::create($productData);
                }

                $product->delete();
            }

            $bundle->delete();

            DB::commit();

            // [Movement] bundle → display/staging (Unbundler)
            try {
                MovementService::logBulk($movementRows);
            } catch (\Exception $e) {
                Log::error('[Movement] Bundle destroy log failed: ' . $e->getMessage());
            }

            return new ResponseResource(true, "Produk bundle berhasil di-unbundle dan dikembalikan ke tabel asal", null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus bundle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function unbundleScan(Bundle $bundle)
    {
        DB::beginTransaction();
        try {
            $productBundles = $bundle->product_bundles;

            foreach ($productBundles as $product) {
                ProductInput::create([
                    'code_document' => $product->code_document,
                    'old_barcode_product' => $product->old_barcode_product,
                    'new_barcode_product' => $product->new_barcode_product,
                    'new_name_product' => $product->new_name_product,
                    'new_quantity_product' => $product->new_quantity_product,
                    'new_price_product' => $product->new_price_product,
                    'old_price_product' => $product->old_price_product,
                    'actual_old_price_product' => $product->actual_old_price_product,
                    'new_date_in_product' => $product->new_date_in_product,
                    'new_status_product' => 'display',
                    'new_quality' => $product->new_quality,
                    'actual_new_quality' => $product->actual_new_quality,
                    'new_category_product' => $product->new_category_product,
                    'new_tag_product' => $product->new_tag_product,
                    'display_price' => $product->display_price,
                    'new_discount' => $product->new_discount,
                    'type' => $product->type,
                    'weight' => $product->weight ?? null
                ]);

                $product->delete();
            }

            $bundle->delete();

            DB::commit();
            return new ResponseResource(true, " Unbundle berhasil ", null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['success' => false, 'message' => 'Gagal menghapus bundle', 'error' => $e->getMessage()], 500);
        }
    }

    public function listBundleScan(Request $request)
    {
        $query = $request->input('q');

        $bundles = Bundle::Where('type', 'type2')->latest()->with('product_bundles');

        if ($query) {
            $bundles->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('name_bundle', 'LIKE', '%' . $query . '%')
                    ->orWhereHas('product_bundles', function ($subQueryBuilder) use ($query) {
                        $subQueryBuilder->where('new_name_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_barcode_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_category_product', 'LIKE', '%' . $query . '%')
                            ->orWhere('new_tag_product', 'LIKE', '%' . $query . '%');
                    });
            });
        }

        $paginatedBundles = $bundles->paginate(50);

        return new ResponseResource(true, "list bundle", $paginatedBundles);
    }

    public function exportBundles(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        try {
            $user = auth()->user();

            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $query = Bundle::where('product_status', 'not sale')
                ->with(['product_bundles.user'])
                ->latest();

            if ($startDate && $endDate) {
                $query->whereDate('created_at', '>=', $startDate)
                    ->whereDate('created_at', '<=', $endDate);
            }

            // 4. Eksekusi Query
            $bundles = $query->get();

            if ($bundles->isEmpty()) {
                return (new ResponseResource(false, "Tidak ada data bundle pada rentang tanggal tersebut", null))->response()->setStatusCode(404);
            }

            if ($startDate && $endDate) {
                $fileName = 'Export_Bundles_' . $startDate . '_to_' . $endDate . '.xlsx';
            } else {
                $fileName = 'Export_Bundles.xlsx';
            }

            $publicPath = 'exports/bundles';
            $filePath = $publicPath . '/' . $fileName;

            if (!file_exists(public_path($publicPath))) {
                mkdir(public_path($publicPath), 0777, true);
            }

            if (file_exists(public_path($filePath))) {
                unlink(public_path($filePath));
            }

            Excel::store(new \App\Exports\BundleExport($bundles, $user), $filePath, 'public_direct');

            return new ResponseResource(true, "File bundle berhasil digenerate", [
                'download_url' => url($filePath) . '?t=' . time(),
                'file_name' => $fileName
            ]);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal export: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    public function exportBundlesDetail($id)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        try {
            $user = auth()->user();

            $bundle = Bundle::with(['product_bundles.user'])
                ->where('id', $id)
                ->where('product_status', 'not sale')
                ->get();

            if ($bundle->isEmpty()) {
                return (new ResponseResource(false, "Bundle tidak ditemukan", null))->response()->setStatusCode(404);
            }

            $safeName = preg_replace('/[^a-zA-Z0-9]+/', '_', $bundle->first()->name_bundle);
            $fileName = 'Export_Bundle_' . $safeName . '.xlsx';
            $publicPath = 'exports/bundles';
            $filePath = $publicPath . '/' . $fileName;

            if (!file_exists(public_path($publicPath))) {
                mkdir(public_path($publicPath), 0777, true);
            }
            if (file_exists(public_path($filePath))) {
                unlink(public_path($filePath));
            }

            Excel::store(new \App\Exports\BundleExport($bundle, $user), $filePath, 'public_direct');

            return new ResponseResource(true, "Detail Bundle berhasil diunduh", [
                'download_url' => url($filePath) . '?t=' . time(),
                'file_name' => $fileName
            ]);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal export: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    public function bundleColor(Request $request)
    {
        DB::beginTransaction();
        $userId = auth()->id();
        try {

            $bundle = Bundle::create([
                'name_bundle' => $request->name_bundle,
                'total_price_bundle' => $request->total_price_custom_bundle,
                'total_price_custom_bundle' => $request->total_price_custom_bundle,
                'total_product_bundle' => $request->total_product_bundle,
                'barcode_bundle' => $request->barcode_bundle,
                'product_status' => "not sale",
                'category' => $request->category,
                'name_color' => $request->name_color,
            ]);

            $insertData = New_product::where('new_tag_product', $bundle->total_product_bundle)->get();
            $bundleMovementRows = $insertData->map(fn($item) => [
                'product_id' => $item->new_barcode_product,
                'is_sku' => false,
                'type' => 'Bundler',
                'type_out' => null,
                'from' => 'display_color',
                'to' => 'bundle',
                'qty' => $item->new_quantity_product,
            ])->toArray();

            // Menggunakan chunk untuk memproses data dalam kelompok 100 item
            $insertData->chunk(100)->each(function ($chunkedData) use ($bundle) {
                // Mapping data untuk disiapkan sebelum insert
                $dataToInsert = $chunkedData->map(function ($item) use ($bundle) {
                    return [
                        'bundle_id' => $bundle->id,
                        'code_document' => $item->code_document,
                        'old_barcode_product' => $item->old_barcode_product,
                        'new_barcode_product' => $item->new_barcode_product,
                        'new_name_product' => $item->new_name_product,
                        'new_quantity_product' => $item->new_quantity_product,
                        'new_price_product' => $item->new_price_product,
                        'old_price_product' => $item->old_price_product,
                        'actual_old_price_product' => $item->actual_old_price_product,
                        'new_date_in_product' => $item->new_date_in_product,
                        'new_status_product' => 'bundle',
                        'new_quality' => $item->new_quality,
                        'actual_new_quality' => $item->actual_new_quality,
                        'new_category_product' => $item->new_category_product,
                        'new_tag_product' => $item->new_tag_product,
                        'new_discount' => $item->new_discount,
                        'display_price' => $item->display_price,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'type' => $item->type
                    ];
                })->toArray();

                Product_Bundle::insert($dataToInsert);
            });



            logUserAction($request, $request->user(), "storage/moving_product/create_bundle", "Create bundle color");

            DB::commit();

            // [Movement] display_color → bundle (Bundler)
            try {
                MovementService::logBulk($bundleMovementRows);
            } catch (\Exception $e) {
                Log::error('[Movement] Bundle bundleColor log failed: ' . $e->getMessage());
            }

            return new ResponseResource(true, "Bundle berhasil dibuat", $bundle);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal membuat bundle: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memindahkan product ke bundle', 'error' => $e->getMessage()], 500);
        }
    }
}

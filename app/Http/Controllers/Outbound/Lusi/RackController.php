<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Exports\RackDataExport;
use App\Http\Controllers\RackController as BaseRackController;
use App\Http\Resources\ResponseResource;
use App\Models\Bundle;
use App\Models\New_product;
use App\Models\Rack;
use App\Models\StagingProduct;
use App\Models\User;
use App\Services\MovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class RackController extends BaseRackController
{
    public function index(Request $request)
    {
        $excludedStatuses = ['dump', 'migrate', 'scrap_qcd', 'sale', 'repair'];

        $query = Rack::query();

        if ($request->has('q')) {
            $search = $request->q;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('barcode', 'like', '%' . $search . '%')
                    ->orWhereHas('stagingProducts', function ($subQ) use ($search) {
                        $subQ->where('new_name_product', 'like', '%' . $search . '%')
                            ->orWhere('new_barcode_product', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('newProducts', function ($subQ) use ($search) {
                        $subQ->where('new_name_product', 'like', '%' . $search . '%')
                            ->orWhere('new_barcode_product', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('bundles', function ($subQ) use ($search) {
                        $subQ->where('name_bundle', 'like', '%' . $search . '%')
                            ->orWhere('barcode_bundle', 'like', '%' . $search . '%');
                    });
            });
        }

        if ($request->has('source')) {
            $query->where('source', $request->source);
            if ($request->source === 'staging') {
                $query->where('status', 'progress');
            }
        }

        $query->withCount([
            'stagingProducts as staging_count' => function ($q) use ($excludedStatuses) {
                $q->whereNotIn('new_status_product', $excludedStatuses);
            },
            'newProducts as display_count' => function ($q) use ($excludedStatuses) {
                $q->whereNotIn('new_status_product', $excludedStatuses);
            },
            'bundles as bundle_count' => function ($q) {
                $q->where('product_status', '!=', 'sale');
            }
        ]);

        $racks = $query->latest()->paginate(10);

        $racks->getCollection()->transform(function ($rack) {
            $countStaging = $rack->staging_count ?? 0;
            $countDisplay = $rack->display_count ?? 0;
            $countBundle  = $rack->bundle_count ?? 0;

            $rack->total_data = $countStaging + $countDisplay + $countBundle;
            $rack->is_so = (int) $rack->is_so;
            $rack->status_so = $rack->is_so == 1 ? 'Sudah SO' : 'Belum SO';

            return $rack;
        });

        $totalRacks = Rack::when($request->has('source'), function ($q) use ($request) {
            $q->where('source', $request->source);
            if ($request->source === 'staging') {
                $q->where('status', 'progress');
            }
        })->count();

        $totalProductsInRacks = 0;

        if ($request->has('source')) {
            if ($request->source == 'staging') {
                $count1 = StagingProduct::whereHas('rack', function ($q) {
                    $q->where('source', 'staging')->where('status', 'progress');
                })->whereNotIn('new_status_product', $excludedStatuses)->count();

                $count2 = New_product::whereHas('rack', function ($q) {
                    $q->where('source', 'staging')->where('status', 'progress');
                })->whereNotIn('new_status_product', $excludedStatuses)->count();

                $count3 = Bundle::whereHas('rack', function ($q) {
                    $q->where('source', 'staging')->where('status', 'progress');
                })->where('product_status', '!=', 'sale')->count();

                $totalProductsInRacks = $count1 + $count2 + $count3;
            } elseif ($request->source == 'display') {
                $count1 = New_product::whereHas('rack', function ($q) {
                    $q->where('source', 'display');
                })->whereNotIn('new_status_product', $excludedStatuses)->count();

                $count2 = Bundle::whereHas('rack', function ($q) {
                    $q->where('source', 'display');
                })->where('product_status', '!=', 'sale')->count();

                $totalProductsInRacks = $count1 + $count2;
            }
        } else {
            $countStaging = StagingProduct::whereNotNull('rack_id')->whereNotIn('new_status_product', $excludedStatuses)->count();
            $countDisplay = New_product::whereNotNull('rack_id')->whereNotIn('new_status_product', $excludedStatuses)->count();
            $countBundle  = Bundle::whereNotNull('rack_id')->where('product_status', '!=', 'sale')->count();

            $totalProductsInRacks = $countStaging + $countDisplay + $countBundle;
        }

        return new ResponseResource(true, 'List Data Rak', [
            'racks' => $racks,
            'total_racks' => $totalRacks,
            'total_products_in_racks' => (int) $totalProductsInRacks
        ]);
    }

    public function moveAllProductsInRackToDisplay($rack_id)
    {
        $stagingRack = Rack::find($rack_id);

        if (!$stagingRack || $stagingRack->source !== 'staging') {
            return response()->json(['status' => false, 'message' => 'Rak asal bukan tipe Staging atau tidak ditemukan.'], 422);
        }

        if (!$stagingRack->display_rack_id) {
            return response()->json(['status' => false, 'message' => 'Rak ini tidak memiliki tujuan Display (Lost link).'], 422);
        }

        $displayRack = Rack::find($stagingRack->display_rack_id);

        if (!$displayRack) {
            return response()->json(['status' => false, 'message' => 'Rak Display tujuan sudah dihapus.'], 404);
        }

        $countStaging = $stagingRack->stagingProducts()->count();
        $countInventory = $stagingRack->newProducts()->count();
        $countBundle = $stagingRack->bundles()->count();

        if ($countStaging === 0 && $countInventory === 0 && $countBundle === 0) {
            return response()->json(['status' => false, 'message' => 'Rak kosong.'], 422);
        }

        $validUserIds = User::pluck('id')->toArray();
        $defaultUserId = 14;
        $movementRows = [];

        try {
            DB::beginTransaction();

            $queryStaging = $stagingRack->stagingProducts();

            if ($queryStaging->count() > 0) {
                $queryStaging->chunkById(100, function ($products) use ($displayRack, $validUserIds, $defaultUserId, &$movementRows) {
                    $dataToInsert = [];
                    $idsToDelete = [];
                    $now = now();

                    foreach ($products as $stagingProduct) {
                        $userId = in_array($stagingProduct->user_id, $validUserIds) ? $stagingProduct->user_id : $defaultUserId;
                        $dataToInsert[] = [
                            'code_document'            => $stagingProduct->code_document,
                            'old_barcode_product'      => $stagingProduct->old_barcode_product,
                            'new_barcode_product'      => $stagingProduct->new_barcode_product,
                            'new_name_product'         => $stagingProduct->new_name_product,
                            'new_quantity_product'     => $stagingProduct->new_quantity_product,
                            'new_price_product'        => $stagingProduct->new_price_product,
                            'old_price_product'        => $stagingProduct->old_price_product,
                            'new_date_in_product'      => $stagingProduct->new_date_in_product,
                            'new_status_product'       => $stagingProduct->new_status_product,
                            'new_quality'              => is_array($stagingProduct->new_quality) ? json_encode($stagingProduct->new_quality) : $stagingProduct->new_quality,
                            'new_category_product'     => $stagingProduct->new_category_product,
                            'new_tag_product'          => $stagingProduct->new_tag_product,
                            'display_price'            => $stagingProduct->display_price,
                            'new_discount'             => $stagingProduct->new_discount,
                            'type'                     => $stagingProduct->type,
                            'user_id'                  => $userId,
                            'is_so'                    => $stagingProduct->is_so,
                            'user_so'                  => $stagingProduct->user_so,
                            'actual_old_price_product' => $stagingProduct->actual_old_price_product,
                            'actual_new_quality'       => is_array($stagingProduct->actual_new_quality) ? json_encode($stagingProduct->actual_new_quality) : $stagingProduct->actual_new_quality,
                            'rack_id'                  => $displayRack->id,
                            'is_extra'                 => $stagingProduct->is_extra,
                            'created_at'               => $stagingProduct->created_at,
                            'updated_at'               => $now,
                            'weight'                   => $stagingProduct->weight,
                        ];
                        $idsToDelete[] = $stagingProduct->id;
                        $to = $stagingProduct->new_tag_product ? 'display_color' : 'display_reguler';
                        $movementRows[] = [
                            'product_id' => $stagingProduct->new_barcode_product,
                            'is_sku'     => false,
                            'type'       => 'Move',
                            'type_out'   => null,
                            'from'       => 'staging_reguler',
                            'to'         => $to,
                            'qty'        => $stagingProduct->new_quantity_product,
                            'dateTime'   => $now,
                        ];
                    }

                    if (!empty($dataToInsert)) {
                        New_product::upsert(
                            $dataToInsert,
                            ['new_barcode_product'],
                            [
                                'code_document',
                                'old_barcode_product',
                                'new_name_product',
                                'new_quantity_product',
                                'new_price_product',
                                'old_price_product',
                                'new_date_in_product',
                                'new_status_product',
                                'new_quality',
                                'new_category_product',
                                'new_tag_product',
                                'display_price',
                                'new_discount',
                                'type',
                                'user_id',
                                'is_so',
                                'user_so',
                                'actual_old_price_product',
                                'actual_new_quality',
                                'rack_id',
                                'is_extra',
                                'updated_at',
                                'weight'
                            ]
                        );
                    }

                    if (!empty($idsToDelete)) {
                        StagingProduct::whereIn('id', $idsToDelete)->delete();
                    }
                });
            }

            New_product::where('rack_id', $stagingRack->id)->update(['rack_id' => $displayRack->id]);
            Bundle::where('rack_id', $stagingRack->id)->update(['rack_id' => $displayRack->id]);

            $stagingRack->update([
                'moved_to_display_at' => now(),
                'user_display' => auth()->id(),
                'status' => 'done'
            ]);

            // Jangan recalculate staging agar nilai total tetap tersimpan
            $this->recalculateRackTotals($displayRack);

            DB::commit();

            try {
                if (!empty($movementRows)) {
                    MovementService::logBulk($movementRows);
                }
            } catch (\Exception $movEx) {
                Log::error('[Movement] moveAllProductsInRackToDisplay log failed: ' . $movEx->getMessage());
            }

            return new ResponseResource(true, "Produk dan Bundle berhasil dipindah ke " . $displayRack->name, null);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function exportRacks(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source' => 'required|in:staging,display'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()
            ], 422);
        }

        try {
            $source = $request->source;
            $sourceName = strtoupper($source);

            $folderName = 'exports/racks';
            $fileName = "DATA_RAK_{$sourceName}_" . date('Ymd') . ".xlsx";
            $filePath = $folderName . '/' . $fileName;

            if (!Storage::disk('public_direct')->exists($folderName)) {
                Storage::disk('public_direct')->makeDirectory($folderName);
            }

            if (Storage::disk('public_direct')->exists($filePath)) {
                Storage::disk('public_direct')->delete($filePath);
            }

            // Filter status progress untuk staging dilakukan di RackDataExport
            Excel::store(
                new RackDataExport($source),
                $filePath,
                'public_direct'
            );

            return new ResponseResource(
                true,
                'File Data Rak berhasil diexport',
                [
                    'download_url' => url($filePath) . '?t=' . time(),
                    'file_name'    => $fileName,
                ]
            );
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Gagal export: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function recalculateRackTotals($rack)
    {
        $excludedStatuses = ['dump', 'migrate', 'scrap_qcd', 'sale', 'repair'];

        $stagingQuery = $rack->stagingProducts()->whereNotIn('new_status_product', $excludedStatuses);
        $inventoryQuery = $rack->newProducts()->whereNotIn('new_status_product', $excludedStatuses);
        $bundleQuery = $rack->bundles()->where('product_status', '!=', 'sale');

        $totalData = $stagingQuery->count() + $inventoryQuery->count() + $bundleQuery->count();

        $totalNewPrice = $stagingQuery->sum('new_price_product')
            + $inventoryQuery->sum('new_price_product')
            + $bundleQuery->sum('total_price_custom_bundle');

        $totalOldPrice = $stagingQuery->sum('old_price_product')
            + $inventoryQuery->sum('old_price_product')
            + $bundleQuery->sum('total_price_bundle');

        $totalDisplayPrice = $stagingQuery->sum('display_price')
            + $inventoryQuery->sum('display_price')
            + $bundleQuery->sum('total_price_custom_bundle');

        $rack->update([
            'total_data' => $totalData,
            'total_new_price_product' => (string) $totalNewPrice,
            'total_old_price_product' => (string) $totalOldPrice,
            'total_display_price_product' => (string) $totalDisplayPrice,
        ]);
    }
}

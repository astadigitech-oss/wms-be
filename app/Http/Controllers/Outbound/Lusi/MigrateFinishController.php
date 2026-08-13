<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\Bundle;
use App\Models\ColorRack;
use App\Models\ColorRackProduct;
use App\Models\Destination;
use App\Models\Migrate;
use App\Models\MigrateDocument;
use App\Models\New_product;
use App\Services\Pos\PosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateFinishController extends Controller
{
    public function MigrateDocumentFinish(Request $request)
    {
        $user = auth()->user();
        $userId = $user->id;

        $migrateDocuments = MigrateDocument::with([
            'migrates.colorRack.colorRackProducts.newProduct',
            'migrates.colorRack.colorRackProducts.bundle',
        ])
            ->where('user_id', $userId)
            ->where('status_document_migrate', 'proses')
            ->get();

        if ($migrateDocuments->isEmpty()) {
            return (new ResponseResource(false, 'Tidak ada dokumen yang perlu diproses.', null))
                ->response()
                ->setStatusCode(404);
        }

        $shopNames = $migrateDocuments->pluck('destiny_document_migrate')->unique();
        $destinations = Destination::whereIn('shop_name', $shopNames)->get()->keyBy('shop_name');

        $allRackIds = $migrateDocuments->flatMap->migrates->pluck('color_rack_id')->filter()->unique();

        $allRackProducts = ColorRackProduct::with(['newProduct', 'bundle', 'colorRack'])
            ->whereIn('color_rack_id', $allRackIds)
            ->get()
            ->groupBy('color_rack_id');

        $racks = ColorRack::whereIn('id', $allRackIds)->get()->keyBy('id');

        $bundleIdsToUpdate = [];
        $productIdsToUpdate = [];
        $rackIdsToUpdate = [];
        $apiPayloads = [];
        $documentsByCode = [];
        $storeTokenCache = [];
        $posService = new PosService();

        DB::beginTransaction();
        try {
            foreach ($migrateDocuments as $migrateDocument) {
                $shopName = $migrateDocument->destiny_document_migrate;
                $destination = $destinations->get($shopName);

                if (!$destination || empty($destination->pos_token)) {
                    throw new \Exception("Toko tujuan '{$shopName}' tidak ditemukan atau belum memiliki POS Token.");
                }

                if (!array_key_exists($shopName, $storeTokenCache)) {
                    $freshStoreToken = $posService->getStoreTokenByShopName($shopName);
                    $storeTokenCache[$shopName] = $freshStoreToken ?: $destination->pos_token;
                }

                $resolvedStoreToken = $storeTokenCache[$shopName];

                if (empty($resolvedStoreToken)) {
                    throw new \Exception("Token toko POS untuk '{$shopName}' tidak ditemukan di sinkronisasi terbaru.");
                }

                if ($resolvedStoreToken !== $destination->pos_token) {
                    $destination->update(['pos_token' => $resolvedStoreToken]);
                }

                $items = collect();
                $rackIds = $migrateDocument->migrates
                    ->pluck('color_rack_id')
                    ->filter()
                    ->unique()
                    ->values();

                foreach ($rackIds as $rackId) {
                    $rackIdsToUpdate[] = $rackId;

                    $rack = $racks->get($rackId);
                    $rackProducts = $allRackProducts->get($rackId, collect());

                    if (!$rack || $rackProducts->isEmpty()) {
                        continue;
                    }

                    $products = collect();

                    foreach ($rackProducts as $item) {
                        $mappedProduct = $this->mapRackProductToPayload($item);

                        if ($mappedProduct === null) {
                            continue;
                        }

                        if (!empty($mappedProduct['source_type'])) {
                            if ($mappedProduct['source_type'] === 'bundle' && !empty($mappedProduct['source_id'])) {
                                $bundleIdsToUpdate[] = $mappedProduct['source_id'];
                            }

                            if ($mappedProduct['source_type'] === 'product' && !empty($mappedProduct['source_id'])) {
                                $productIdsToUpdate[] = $mappedProduct['source_id'];
                            }
                        }

                        unset($mappedProduct['source_type'], $mappedProduct['source_id']);
                        $products->push($mappedProduct);
                    }

                    if ($products->isEmpty()) {
                        continue;
                    }

                    $items->push([
                        'barcode' => $rack->barcode ?? '-',
                        'bag_name' => $rack->name ?? 'Rack',
                        'products' => $products->values()->all(),
                    ]);
                }

                $itemsPayload = $items->map(function ($item) {
                    return (object) [
                        'barcode'  => $item['barcode'],
                        'bag_name' => $item['bag_name'],
                        'products'  => collect($item['products'])->map(function ($product) {
                            return (object) $product;
                        })->values()->all(),
                    ];
                })->values()->all();

                $apiPayloads[] = [
                    'document_code' => $migrateDocument->code_document_migrate,
                    'store_token'   => $resolvedStoreToken,
                    'items'         => $itemsPayload,
                ];
                $documentsByCode[$migrateDocument->code_document_migrate] = $migrateDocument;

                $migrateDocument->update([
                    'total_product_document_migrate' => $items->sum(fn ($item) => count($item['products'])),
                    'status_document_migrate'        => 'selesai',
                ]);

                Migrate::where('code_document_migrate', $migrateDocument->code_document_migrate)
                    ->update(['status_migrate' => 'selesai']);
            }

            if (!empty($bundleIdsToUpdate)) {
                Bundle::whereIn('id', array_values(array_unique($bundleIdsToUpdate)))
                    ->update(['product_status' => 'migrate']);
            }

            if (!empty($productIdsToUpdate)) {
                New_product::whereIn('id', array_values(array_unique($productIdsToUpdate)))
                    ->update(['new_status_product' => 'migrate']);
            }

            if (!empty($rackIdsToUpdate)) {
                ColorRack::whereIn('id', array_values(array_unique($rackIdsToUpdate)))
                    ->update(['status' => 'migrate']);
            }

            $successCount = 0;
            $processedDocuments = [];

            foreach ($apiPayloads as $payload) {
                $doc = $documentsByCode[$payload['document_code']] ?? null;
                $items = $payload['items'];

                if ($items->isEmpty()) {
                    $successCount++;
                    if ($doc) {
                        $processedDocuments[] = $doc;
                    }
                    continue;
                }

                try {
                    $posService->sendIntegrationProducts(
                        $payload['document_code'],
                        $payload['store_token'],
                        array_values($items->toArray())
                    );

                    $successCount++;
                    if ($doc) {
                        $processedDocuments[] = $doc;
                    }

                    $docCode = $doc->code_document_migrate ?? $payload['document_code'];
                    $pesanLog = "Berhasil mengirim {$items->sum(fn ($item) => count($item['products']))} item ke POS untuk dokumen {$docCode}.";
                    Log::info($pesanLog);
                    logUserAction($request, $user, 'Migrate Document Finish', $pesanLog);
                } catch (\Exception $e) {
                    $docCode = $doc->code_document_migrate ?? $payload['document_code'];
                    throw new \Exception("POS API Error pada dokumen {$docCode}: " . $e->getMessage());
                }
            }

            DB::commit();

            $pesanSummary = "Berhasil memproses {$successCount} dari " . count($apiPayloads) . " dokumen migrasi.";
            logUserAction($request, $user, 'Migrate Document Summary', $pesanSummary);

            return new ResponseResource(true, $pesanSummary, $processedDocuments);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error("Migrate Gagal Diselesaikan: " . $e->getMessage());

            return (new ResponseResource(false, 'Gagal menyelesaikan migrasi: ' . $e->getMessage(), []))
                ->response()
                ->setStatusCode(500);
        }
    }

    private function mapRackProductToPayload(ColorRackProduct $item): ?array
    {
        if ($item->bundle_id && $item->bundle) {
            return [
                'source_type'      => 'bundle',
                'source_id'        => $item->bundle_id,
                'old_barcode'      => $item->bundle->old_barcode_bundle,
                'barcode'          => $item->bundle->barcode_bundle,
                'code_document'    => $item->bundle->code_document_bundle ?? '-',
                'old_price'        => (float) $item->bundle->total_price_bundle,
                'price'            => (float) $item->bundle->total_price_custom_bundle,
                'name'             => '[BUNDLE] ' . $item->bundle->name_bundle,
                'qty'              => 1,
                'tag_color'        => $item->bundle->name_color ?? 'bundle',
                'actual_price'     => (float) $item->bundle->total_price_bundle,
                'is_extra_product' => false,
            ];
        }

        if ($item->new_product_id && $item->newProduct) {
            return [
                'source_type'      => 'product',
                'source_id'        => $item->new_product_id,
                'old_barcode'      => $item->newProduct->old_barcode_product,
                'barcode'          => $item->newProduct->new_barcode_product,
                'code_document'    => $item->newProduct->code_document ?? '-',
                'old_price'        => (float) $item->newProduct->old_price_product,
                'price'            => (float) $item->newProduct->new_price_product,
                'name'             => $item->newProduct->new_name_product,
                'qty'              => (int) ($item->newProduct->new_quantity_product ?? 1),
                'tag_color'        => $item->newProduct->new_tag_product ?? 'color',
                'actual_price'     => (float) $item->newProduct->actual_old_price_product,
                'is_extra_product' => (bool) $item->newProduct->is_extra,
            ];
        }

        return null;
    }
}

<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseResource;
use App\Models\ColorRack;
use App\Models\ColorRackProduct;
use App\Models\Destination;
use App\Models\Migrate;
use App\Models\MigrateDocument;
use App\Services\Pos\PosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateDocumentController extends Controller
{
    public function MigrateDocumentFinish(Request $request)
    {
        $user = auth()->user();
        $userId = $user->id;

        $migrateDocuments = MigrateDocument::with('migrates')
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

        $allRackProducts = ColorRackProduct::with(['newProduct', 'bundle'])
            ->whereIn('color_rack_id', $allRackIds)
            ->get()
            ->groupBy('color_rack_id');

        $bundleIdsToUpdate = [];
        $productIdsToUpdate = [];
        $rackIdsToUpdate = [];
        $apiPayloads = [];
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

                $allProductsToSend = collect();

                foreach ($migrateDocument->migrates as $migrateItem) {
                    if (!$migrateItem->color_rack_id) {
                        continue;
                    }

                    $rackIdsToUpdate[] = $migrateItem->color_rack_id;
                    $rackProducts = $allRackProducts->get($migrateItem->color_rack_id, collect());

                    foreach ($rackProducts as $item) {
                        if ($item->bundle_id && $item->bundle) {
                            $bundleIdsToUpdate[] = $item->bundle_id;
                            $allProductsToSend->push([
                                'code_document'    => $item->bundle->code_document_bundle ?? '-',
                                'old_barcode'      => $item->bundle->old_barcode_bundle,
                                'old_price'        => (float) $item->bundle->total_price_bundle,
                                'actual_price'     => (float) $item->bundle->total_price_bundle,
                                'barcode'          => $item->bundle->barcode_bundle,
                                'name'             => '[BUNDLE] ' . $item->bundle->name_bundle,
                                'price'            => (float) $item->bundle->total_price_custom_bundle,
                                'quantity'         => 1,
                                'status'           => 'active',
                                'tag_color'        => $item->bundle->name_color ?? 'bundle',
                                'is_so'            => $item->bundle->is_so,
                                'is_extra_product' => false,
                                'user_so'          => $item->bundle->user_so,
                            ]);
                        } elseif ($item->new_product_id && $item->newProduct) {
                            $productIdsToUpdate[] = $item->new_product_id;
                            $product = $item->newProduct;
                            $allProductsToSend->push([
                                'code_document'    => $product->code_document ?? '-',
                                'old_barcode'      => $product->old_barcode_product,
                                'old_price'        => (float) $product->old_price_product,
                                'actual_price'     => (float) $product->actual_old_price_product,
                                'barcode'          => $product->new_barcode_product,
                                'name'             => $product->new_name_product,
                                'price'            => (float) $product->new_price_product,
                                'quantity'         => $product->new_quantity_product ?? 1,
                                'status'           => 'active',
                                'tag_color'        => $product->new_tag_product ?? 'color',
                                'is_so'            => $product->is_so,
                                'is_extra_product' => (bool) $product->is_extra,
                                'user_so'          => $product->user_so,
                            ]);
                        }
                    }
                }

                $apiPayloads[] = [
                    'document' => $migrateDocument,
                    'token'    => $resolvedStoreToken,
                    'products' => $allProductsToSend,
                ];

                $migrateDocument->update([
                    'total_product_document_migrate' => $allProductsToSend->count(),
                    'status_document_migrate'        => 'selesai',
                ]);

                Migrate::where('code_document_migrate', $migrateDocument->code_document_migrate)
                    ->update(['status_migrate' => 'selesai']);
            }

            if (!empty($bundleIdsToUpdate)) {
                \App\Models\Bundle::whereIn('id', array_unique($bundleIdsToUpdate))->update(['product_status' => 'migrate']);
            }
            if (!empty($productIdsToUpdate)) {
                \App\Models\New_product::whereIn('id', array_unique($productIdsToUpdate))->update(['new_status_product' => 'migrate']);
            }
            if (!empty($rackIdsToUpdate)) {
                ColorRack::whereIn('id', array_unique($rackIdsToUpdate))->update(['status' => 'migrate']);
            }

            $successCount = 0;
            $processedDocuments = [];

            foreach ($apiPayloads as $payload) {
                $doc = $payload['document'];
                $products = $payload['products'];

                if ($products->isEmpty()) {
                    $successCount++;
                    $processedDocuments[] = $doc;
                    continue;
                }

                try {
                    $posService->sendBatchProducts(
                        $doc->code_document_migrate,
                        $payload['token'],
                        array_values($products->toArray())
                    );

                    $successCount++;
                    $processedDocuments[] = $doc;

                    $pesanLog = "Berhasil mengirim {$products->count()} Item sekaligus ke POS untuk Dokumen {$doc->code_document_migrate}.";
                    Log::info($pesanLog);
                    logUserAction($request, $user, 'Migrate Document Finish', $pesanLog);
                } catch (\Exception $e) {
                    throw new \Exception("POS API Error pada dokumen {$doc->code_document_migrate}: " . $e->getMessage());
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
}

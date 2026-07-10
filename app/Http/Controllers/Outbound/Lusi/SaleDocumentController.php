<?php

namespace App\Http\Controllers\Outbound\Lusi;

use App\Http\Controllers\SaleDocumentController as BaseSaleDocumentController;
use App\Http\Resources\ResponseResource;
use App\Models\BagProducts;
use App\Models\Basket;
use App\Models\Buyer;
use App\Models\BuyerPoint;
use App\Models\Bundle;
use App\Models\Category;
use App\Models\LogFinance;
use App\Models\New_product;
use App\Models\Notification;
use App\Models\Sale;
use App\Models\SaleDocument;
use App\Models\StagingProduct;
use App\Services\LoyaltyService;
use App\Services\MovementService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SaleDocumentController extends BaseSaleDocumentController
{
    private function calculateSaleDocumentTotals(
        SaleDocument $saleDocument,
        ?float $voucherValue = null,
        ?float $cardboxTotalPrice = null,
        ?float $tax = null,
        ?int $isTax = null
    ): array {
        $totalProductPriceSale = (float) Sale::where('code_document_sale', $saleDocument->code_document_sale)
            ->sum('product_price_sale');

        $voucherValue = $voucherValue ?? (float) ($saleDocument->voucher ?? 0);
        $voucherRankValue = (float) ($saleDocument->voucher_rank_value ?? 0);
        $cardboxTotalPrice = $cardboxTotalPrice ?? (float) ($saleDocument->cardbox_total_price ?? 0);
        $tax = $tax ?? (float) ($saleDocument->tax ?? 0);
        $isTax = $isTax ?? (int) ($saleDocument->is_tax ?? 0);

        $totalPriceDocumentSale = max(0, $totalProductPriceSale - $voucherValue - $voucherRankValue);
        $grandTotal = $totalPriceDocumentSale + $cardboxTotalPrice;

        $priceAfterTax = $grandTotal;
        if ($isTax === 1 && $tax > 0) {
            $priceAfterTax = $grandTotal + ($grandTotal * ($tax / 100));
        }

        return [
            'total_product_price_sale' => $totalProductPriceSale,
            'voucher_value' => $voucherValue,
            'voucher_rank_value' => $voucherRankValue,
            'cardbox_total_price' => $cardboxTotalPrice,
            'total_price_document_sale' => $totalPriceDocumentSale,
            'grand_total' => $grandTotal,
            'price_after_tax' => ceil($priceAfterTax),
        ];
    }

    public function saleFinish(Request $request)
    {
        try {
            DB::beginTransaction();

            $user = $request->user();
            if (!$user) {
                throw new Exception("User tidak terautentikasi!");
            }

            $userId = $user->id;
            $saleDocument = SaleDocument::where('status_document_sale', 'proses')
                ->where('user_id', $userId)
                ->first();

            if ($saleDocument == null) {
                throw new Exception("Data sale belum dibuat!");
            }

            $validator = Validator::make($request->all(), [
                'voucher' => 'nullable|numeric',
                'cardbox_qty' => 'nullable|numeric|required_with:cardbox_unit_price',
                'cardbox_unit_price' => 'nullable|numeric|required_with:cardbox_qty',
                'tax' => 'nullable|numeric|min:0|max:50',
            ]);

            if ($validator->fails()) {
                return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
            }

            $sales = Sale::where('code_document_sale', $saleDocument->code_document_sale)->get();

            if ($sales->isEmpty()) {
                throw new Exception("Tidak ada produk dalam sale document {$saleDocument->code_document_sale}!");
            }

            $approved = '0';
            if ($request->filled('voucher')) {
                foreach ($sales as $sale) {
                    if ($sale->gabor_sale !== null || $sale->product_update_price_sale !== null) {
                        $sale->update(['approved' => '1']);
                        $approved = '1';
                    } else {
                        $sale->update(['approved' => '0']);
                    }
                }
            } else {
                foreach ($sales as $sale) {
                    if ($sale->gabor_sale !== null || $sale->product_update_price_sale !== null) {
                        $sale->update(['approved' => '1']);
                        $approved = '1';
                    } else {
                        $sale->update(['approved' => '0']);
                        $approved = '0';
                    }
                }
            }
            if ($request->filled('voucher') && $request->input('voucher') !== '0') {
                $approved = '1';
            }
            if ($saleDocument->new_discount_sale > 0) {
                $approved = '1';
            }

            if ($approved === '1') {
                if (!$user || !$user->id) {
                    throw new Exception("User ID tidak valid untuk membuat notifikasi!");
                }

                if (!$saleDocument || !$saleDocument->id) {
                    throw new Exception("Sale Document ID tidak valid untuk membuat notifikasi!");
                }

                Notification::create([
                    'user_id' => $userId,
                    'notification_name' => 'approve discount sale',
                    'status' => 'sale',
                    'role' => 'Spv',
                    'external_id' => $saleDocument->id
                ]);

                $saleDocument->update(['approved' => '1']);
            }

            $totalDisplayPrice = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('display_price');
            $totalProductOldPriceSale = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('product_old_price_sale');

            $totalCardBoxPrice = (float) ($request->cardbox_qty ?? 0) * (float) ($request->cardbox_unit_price ?? 0);
            $voucherValue = $request->filled('voucher')
                ? (float) $request->input('voucher')
                : (float) ($saleDocument->voucher ?? 0);

            $calculation = $this->calculateSaleDocumentTotals(
                $saleDocument,
                $voucherValue,
                $totalCardBoxPrice,
                $request->input('tax') !== null ? (float) $request->input('tax') : null,
                $request->input('tax') !== null ? 1 : null
            );

            $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

            if (!$buyer) {
                throw new Exception("Buyer dengan ID {$saleDocument->buyer_id_document_sale} tidak ditemukan!");
            }

            $rankDiscount = LoyaltyService::processLoyalty($buyer->id, $totalDisplayPrice);

            $productBarcodes = $sales->pluck('product_barcode_sale');
            Bundle::whereIn('barcode_bundle', $productBarcodes)->update(['product_status' => 'sale']);
            $sales->each->update(['status_sale' => 'selesai']);

            $earnPoint = 0;
            if ($totalDisplayPrice >= 5000000) {
                $earnPoint = floor($calculation['total_price_document_sale'] / 1000);
            }

            $saleDocument->update([
                'buyer_point_document_sale' => $earnPoint,
                'total_product_document_sale' => count($sales),
                'total_old_price_document_sale' => $totalProductOldPriceSale,
                'total_price_document_sale' => $calculation['total_price_document_sale'],
                'total_display_document_sale' => $totalDisplayPrice,
                'status_document_sale' => 'selesai',
                'cardbox_qty' => $request->cardbox_qty ?? 0,
                'cardbox_unit_price' => $request->cardbox_unit_price ?? 0,
                'cardbox_total_price' => $calculation['cardbox_total_price'],
                'voucher' => $calculation['voucher_value'],
                'approved' => $approved,
                'is_tax' => $request->filled('tax') ? 1 : 0,
                'tax' => $request->filled('tax') ? $request->input('tax') : 0,
                'price_after_tax' => $calculation['price_after_tax'],
            ]);

            $avgPurchaseBuyer = SaleDocument::where('status_document_sale', 'selesai')
                ->where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
                ->avg('total_price_document_sale');

            $saleDocumentCountWithBuyerId = SaleDocument::where('buyer_id_document_sale', $buyer->id)->count();

            if ($saleDocumentCountWithBuyerId == 2 || $saleDocumentCountWithBuyerId == 3) {
                $typeBuyer = 'Repeat';
            } else if ($saleDocumentCountWithBuyerId > 3) {
                $typeBuyer = 'Reguler';
            }

            $buyer->update([
                'type_buyer' => $typeBuyer ?? "Biasa",
                'amount_transaction_buyer' => $buyer->amount_transaction_buyer + 1,
                'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer + $saleDocument->total_price_document_sale, 2, '.', ''),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', ''),
                'point_buyer' => $buyer->point_buyer + $earnPoint,
            ]);

            if (!$buyer || !$buyer->id) {
                throw new Exception("Buyer ID tidak valid untuk membuat buyer point!");
            }

            if ($earnPoint > 0) {
                BuyerPoint::create([
                    'buyer_id' => $buyer->id,
                    'earn' => $earnPoint,
                    'year' => Carbon::now()->year,
                ]);
            }

            logUserAction($request, $request->user(), "outbound/sale/kasir", "Menekan tombol sale", $saleDocument->code_document_sale);

            DB::commit();

            try {
                $newProductsForMovement = New_product::whereIn('new_barcode_product', $productBarcodes)
                    ->where('new_status_product', 'sale')
                    ->get(['new_barcode_product', 'new_category_product', 'new_tag_product', 'new_quantity_product']);

                $stagingForMovement = StagingProduct::whereIn('new_barcode_product', $productBarcodes)
                    ->where('new_status_product', 'sale')
                    ->get(['new_barcode_product', 'new_quantity_product']);

                $movementRows = [];
                foreach ($newProductsForMovement as $p) {
                    $from = $p->new_tag_product ? 'display_color' : 'display_reguler';
                    $movementRows[] = [
                        'product_id' => $p->new_barcode_product,
                        'is_sku'     => false,
                        'type'       => 'Out',
                        'type_out'   => 'reguler_sales',
                        'from'       => $from,
                        'to'         => 'reguler_sales',
                        'qty'        => $p->new_quantity_product,
                    ];
                }
                foreach ($stagingForMovement as $p) {
                    $movementRows[] = [
                        'product_id' => $p->new_barcode_product,
                        'is_sku'     => false,
                        'type'       => 'Out',
                        'type_out'   => 'reguler_sales',
                        'from'       => 'staging_reguler',
                        'to'         => 'reguler_sales',
                        'qty'        => $p->new_quantity_product,
                    ];
                }
                MovementService::logBulk($movementRows);
            } catch (\Exception $e) {
                Log::error('[Movement] saleFinish log failed: ' . $e->getMessage());
            }

            $resource = new ResponseResource(true, "Data berhasil disimpan!", $saleDocument->load('sales', 'user', 'buyer:id,point_buyer'));
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error in saleFinish method:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
                'request_data' => $request->all()
            ]);

            $resource = new ResponseResource(false, "Data gagal disimpan!", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $resource->response()->setStatusCode(500);
        }
        return $resource->response();
    }

    public function addProductSaleInDocument(Request $request)
    {
        DB::beginTransaction();

        $validator = Validator::make(
            $request->all(),
            [
                'sale_barcode' => 'required',
                'sale_document_id' => 'required|numeric',
                'type_discount' => 'nullable|in:old,new'
            ]
        );

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
        }

        try {
            $saleDocument = SaleDocument::find($request->sale_document_id);

            if (!$saleDocument) {
                return (new ResponseResource(false, "sale_document_id tidak di temukan!", []))->response()->setStatusCode(404);
            }

            $productSale = Sale::where('product_barcode_sale', $request->input('sale_barcode'))->first();
            if ($productSale) {
                $resource = new ResponseResource(false, "Data sudah dimasukkan!", $productSale);
                return $resource->response()->setStatusCode(422);
            }

            $newProduct = New_product::where('new_barcode_product', $request->sale_barcode)->first();
            $staging = StagingProduct::where('new_barcode_product', $request->sale_barcode)->first();
            $bundle = Bundle::where('barcode_bundle', $request->sale_barcode)->first();

            if (!$newProduct && !$bundle && !$staging) {
                return (new ResponseResource(false, "Data Buyer tidak ditemukan!", []))->response()->setStatusCode(404);
            }

            if ($newProduct) {
                $data = [
                    $newProduct->new_name_product,
                    $newProduct->new_category_product,
                    $newProduct->new_barcode_product,
                    $newProduct->display_price,
                    $newProduct->new_price_product,
                    $newProduct->new_discount,
                    $newProduct->old_price_product,
                    $newProduct->code_document,
                    $newProduct->type,
                    $newProduct->old_barcode_product
                ];
                $newProduct->update(['new_status_product' => 'sale']);
            } else if ($staging) {
                $data = [
                    $staging->new_name_product,
                    $staging->new_category_product,
                    $staging->new_barcode_product,
                    $staging->display_price,
                    $staging->new_price_product,
                    $staging->new_discount,
                    $staging->old_price_product,
                    $staging->code_document,
                    $staging->type,
                    $staging->old_barcode_product
                ];
                $staging->update(['new_status_product' => 'sale']);
            } elseif ($bundle) {
                $data = [
                    $bundle->name_bundle,
                    $bundle->category,
                    $bundle->barcode_bundle,
                    $bundle->total_price_custom_bundle,
                    $bundle->total_price_bundle,
                    $bundle->type
                ];
                $bundle->update(['product_status' => 'sale']);
            } else {
                return (new ResponseResource(false, "Barcode tidak ditemukan!", []))->response()->setStatusCode(404);
            }

            $productAdd = 0;
            $priceAfterDiscount = 0;
            $productAddDiscount = 0;

            if ($saleDocument->new_discount_sale > 0) {
                if ($saleDocument->type_discount == 'new') {
                    $productAdd = $data[4];
                    $discount = $saleDocument->new_discount_sale;
                    $productAddDiscount = $productAdd * (1 - ($discount / 100));
                    $priceAfterDiscount = $productAddDiscount + $saleDocument->total_price_document_sale;
                } else if ($saleDocument->type_discount == 'old') {
                    $productAdd = $data[6];
                    $discount = $saleDocument->new_discount_sale;
                    $productAddDiscount = $productAdd * (1 - ($discount / 100));
                    $priceAfterDiscount = $productAddDiscount + $saleDocument->total_price_document_sale;
                } else {
                    $productAdd = $data[4];
                    $discount = $saleDocument->new_discount_sale;
                    $productAddDiscount = $productAdd * (1 - ($discount / 100));
                    $priceAfterDiscount = $productAddDiscount + $saleDocument->total_price_document_sale;
                }
            } else {
                $productAdd =  $data[4];
                $discount = $saleDocument->new_discount_sale;
                $productAddDiscount = $productAdd * (1 - ($discount / 100));
                $priceAfterDiscount = $productAddDiscount + $saleDocument->total_price_document_sale;
            }

            $karton = $saleDocument->cardbox_total_price;
            $priceAfterKarton = $priceAfterDiscount + $karton;
            $tax = $priceAfterKarton * ($saleDocument->tax / 100);
            $grandTotal = $priceAfterKarton + $tax;

            $sale = Sale::create(
                [
                    'user_id' => auth()->id(),
                    'code_document_sale' => $saleDocument->code_document_sale,
                    'product_name_sale' => $data[0],
                    'product_category_sale' => $data[1],
                    'product_barcode_sale' => $data[2],
                    'product_old_price_sale' => ceil($data[6]) ?? ceil($data[4]),
                    'product_price_sale' => ceil($productAddDiscount),
                    'product_qty_sale' => 1,
                    'status_sale' => 'selesai',
                    'total_discount_sale' => ceil($productAddDiscount),
                    'new_discount' => $saleDocument->new_discount_sale ?? NULL,
                    'display_price' => ceil($data[3]),
                    'type' => $data[8],
                    'old_barcode_product' => $data[9],
                    'type_discount' => $saleDocument->type_discount
                ]
            );

            $totalDisplayPrice = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('display_price');

            $calculation = $this->calculateSaleDocumentTotals($saleDocument);

            $saleDocument->update([
                'total_product_document_sale' => $saleDocument->total_product_document_sale + 1,
                'total_old_price_document_sale' => $data[6] + $saleDocument->total_old_price_document_sale,
                'total_price_document_sale' => $calculation['total_price_document_sale'],
                'total_display_document_sale' => ceil($totalDisplayPrice),
                'price_after_tax' => $calculation['price_after_tax'],
            ]);

            $avgPurchaseBuyer = SaleDocument::where('status_document_sale', 'selesai')
                ->where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
                ->avg('total_price_document_sale');
            $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

            $buyer->update([
                'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer - $sale->product_price_sale, 2, '.', ''),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', ''),
            ]);

            $buyer->update([
                'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer + $saleDocument->total_price_document_sale, 2, '.', ''),
            ]);

            DB::commit();
            return new ResponseResource(true, "data berhasil di tambahkan!", $saleDocument->load('sales', 'user'));
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Data gagal ditambahkan!", $e->getMessage()))->response()->setStatusCode(500);
        }
    }

    public function deleteProductSaleInDocument(SaleDocument $sale_document, Sale $sale)
    {
        DB::beginTransaction();
        try {
            $allSale = Sale::where('code_document_sale', $sale_document->code_document_sale)
                ->where('status_sale', 'selesai')
                ->get();

            $calculation = $this->calculateSaleDocumentTotals($sale_document);
            $priceBeforeTax = max(0, $calculation['total_price_document_sale'] - $sale->product_price_sale);
            $cardboxTotalPrice = (float) ($sale_document->cardbox_total_price ?? 0);
            $grandTotal = $priceBeforeTax + $cardboxTotalPrice;
            $tax = (float) ($sale_document->tax ?? 0);
            $priceAfterTax = $sale_document->is_tax == 1 && $tax > 0
                ? $grandTotal + ($grandTotal * ($tax / 100))
                : $grandTotal;

            $sale_document->update([
                'total_product_document_sale' => $sale_document->total_product_document_sale - 1,
                'total_old_price_document_sale' => $sale_document->total_old_price_document_sale - $sale->product_old_price_sale,
                'total_price_document_sale' => ceil($priceBeforeTax),
                'total_display_document_sale' => ceil($sale_document->total_display_document_sale - $sale->display_price),
                'price_after_tax' => ceil($priceAfterTax)
            ]);

            $avgPurchaseBuyer = SaleDocument::where('status_document_sale', 'selesai')
                ->where('buyer_id_document_sale', $sale_document->buyer_id_document_sale)
                ->avg('total_price_document_sale');

            $buyer = Buyer::findOrFail($sale_document->buyer_id_document_sale);

            $buyer->update([
                'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer - $sale->product_price_sale, 2, '.', ''),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', ''),
            ]);

            if ($allSale->count() <= 1) {
                $buyer->update([
                    'amount_transaction_buyer' => $buyer->amount_transaction_buyer - 1,
                ]);
                $sale_document->delete();
            }

            $sale->delete();

            $bundle = Bundle::where('barcode_bundle', $sale->product_barcode_sale)->first();
            if (!empty($bundle)) {
                $bundle->update(['product_status' => 'not sale']);
            } else {
                $lolos = json_encode(['lolos' => 'lolos']);
                New_product::insert([
                    'code_document' => $sale->code_document,
                    'old_barcode_product' => $sale->product_barcode_sale,
                    'new_barcode_product' => $sale->product_barcode_sale,
                    'new_name_product' => $sale->product_name_sale,
                    'new_quantity_product' => $sale->product_qty_sale,
                    'new_price_product' => $sale->product_old_price_sale,
                    'old_price_product' => $sale->product_old_price_sale,
                    'new_date_in_product' => $sale->created_at,
                    'new_status_product' => 'display',
                    'new_quality' => $lolos,
                    'new_category_product' => $sale->product_category_sale,
                    'new_tag_product' => null,
                    'created_at' => $sale->created_at,
                    'updated_at' => $sale->updated_at,
                    'new_discount' => 0,
                    'display_price' => $sale->product_price_sale,
                    'user_id' => auth()->id(),
                ]);
            }

            $resource = new ResponseResource(true, "data berhasil di hapus", $sale_document->load('sales', 'user'));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            $resource = new ResponseResource(false, "data gagal di hapus", $e->getMessage());
        }
        return $resource->response();
    }

    public function rejectProduct($id_sale)
    {
        $sale = Sale::where('id', $id_sale)
            ->where(function ($query) {
                $query->where('approved', '1')
                    ->orWhere('approved', '2');
            })
            ->first();

        if (!$sale) {
            return (new ResponseResource(false, "Product tidak ditemukan!", null))->response()->setStatusCode(404);
        }

        $saleDocument = SaleDocument::where('code_document_sale', $sale->code_document_sale)->first();
        $oldTotalPrice = $saleDocument->total_price_document_sale;

        $sale->approved = '0';
        $sale->product_price_sale = $sale->display_price;
        $sale->save();

        $calculation = $this->calculateSaleDocumentTotals($saleDocument);

        $saleDocument->total_price_document_sale = $calculation['total_price_document_sale'];
        $saleDocument->price_after_tax = $calculation['price_after_tax'];
        $saleDocument->save();

        $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

        $avgPurchaseBuyer = SaleDocument::where('buyer_id_document_sale', $buyer->id)
            ->avg('total_price_document_sale');

        $buyer->update([
            'amount_purchase_buyer' => number_format(
                ($buyer->amount_purchase_buyer - $oldTotalPrice) + $saleDocument->total_price_document_sale,
                2,
                '.',
                ''
            ),
            'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', '')
        ]);

        $response = [
            'code_document_sale' => $sale->code_document_sale,
            'product_name_sale' => $sale->product_name_sale,
            'product_category_sale' => $sale->product_category_sale,
            'product_barcode_sale' => $sale->product_barcode_sale,
            'product_price_sale' => $sale->product_price_sale,
            'display_price' => $sale->display_price,
            'approved' => $sale->approved,
            'total_price_document_sale' => $saleDocument->total_price_document_sale,
            'total_display_document_sale' => $saleDocument->total_display_document_sale,
            'grand_total' => $saleDocument->grand_total
        ];

        return new ResponseResource(true, "Berhasil reject discount", $response);
    }

    public function rejectAllDiscounts($id_sale_document)
    {
        $saleDocument = SaleDocument::where('id', $id_sale_document)->first();

        if (!$saleDocument) {
            return (new ResponseResource(false, "Dokumen penjualan tidak ditemukan!", null))->response()->setStatusCode(404);
        }

        $oldTotalPrice = $saleDocument->total_price_document_sale;

        try {
            DB::beginTransaction();

            $updatedSales = Sale::where('code_document_sale', $saleDocument->code_document_sale)
                ->where(function ($query) {
                    $query->where('approved', '1');
                })
                ->get();

            foreach ($updatedSales as $sale) {
                $sale->approved = '0';
                $sale->product_price_sale = $sale->display_price;
                $sale->save();
            }

            $calculation = $this->calculateSaleDocumentTotals($saleDocument, 0);

            $saleDocument->voucher = 0;
            $saleDocument->total_price_document_sale = $calculation['total_price_document_sale'];
            $saleDocument->price_after_tax = $calculation['price_after_tax'];
            $saleDocument->approved = '0';
            $saleDocument->save();

            $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

            $avgPurchaseBuyer = SaleDocument::where('buyer_id_document_sale', $buyer->id)
                ->avg('total_price_document_sale');

            $buyer->update([
                'amount_purchase_buyer' => number_format(
                    ($buyer->amount_purchase_buyer - $oldTotalPrice) + $saleDocument->total_price_document_sale,
                    2,
                    '.',
                    ''
                ),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', '')
            ]);

            $notif = Notification::where('status', 'sale')->where('external_id', $id_sale_document)->first();
            if (!$notif) {
                return (new ResponseResource(false, "Notification tidak tidak ditemukan!", null))->response()->setStatusCode(404);
            }
            $notif->update(['approved' => '1']);

            DB::commit();

            return new ResponseResource(true, "Berhasil reject semua diskon", [
                'sale_document' => $saleDocument->fresh('sales', 'user'),
                'sales' => $updatedSales->map(function ($sale) {
                    return [
                        'code_document_sale' => $sale->code_document_sale,
                        'product_name_sale' => $sale->product_name_sale,
                        'product_category_sale' => $sale->product_category_sale,
                        'product_barcode_sale' => $sale->product_barcode_sale,
                        'old_price' => $sale->getOriginal('product_price_sale'),
                        'new_price' => $sale->product_price_sale,
                        'display_price' => $sale->display_price
                    ];
                })
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Gagal reject diskon: " . $e->getMessage(), null))->response()->setStatusCode(500);
        }
    }
}

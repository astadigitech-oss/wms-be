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

    public function index(Request $request)
    {
        $query = $request->input('q');
        $saleDocuments = SaleDocument::with('user:id,name', 'buyer:id,point_buyer')->where('status_document_sale', 'selesai')->latest();
        if ($query) {
            $saleDocuments = $saleDocuments->where(function ($data) use ($query) {
                $data->where('code_document_sale', 'LIKE', '%' . $query . '%')
                    ->orWhere('buyer_name_document_sale', 'LIKE', '%' . $query . '%');
            });
        }
        $saleDocuments = $saleDocuments->paginate(11);
        $resource = new ResponseResource(true, "list document sale", $saleDocuments);
        return $resource->response();
    }

    /**
     * Display the specified resource.  
     */
    public function show($id)
    {
        $saleDocument = SaleDocument::with(['sales', 'user', 'buyer'])->findOrFail($id);
        $buyer = Buyer::with(['buyerLoyalty.rank'])->find($saleDocument->buyer_id_document_sale);

        $month = $saleDocument->created_at->month;
        $year  = $saleDocument->created_at->year;

        $monthlyPoint = SaleDocument::where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
            ->where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->sum('buyer_point_document_sale');

        $higherRankCount = SaleDocument::selectRaw('SUM(buyer_point_document_sale) as total_point')
            ->where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->groupBy('buyer_id_document_sale')
            ->havingRaw('SUM(buyer_point_document_sale) > ?', [$monthlyPoint])
            ->get()
            ->count();

        $monthlyRank = $higherRankCount + 1;

        // Gunakan helper function untuk mendapatkan rank info SAMPAI transaksi ini
        // Passing created_at untuk mendapatkan state pada saat transaksi ini terjadi
        $rankInfo = LoyaltyService::getCurrentRankInfo(
            $saleDocument->buyer_id_document_sale,
            $saleDocument->created_at
        );

        // transactionCount dari getCurrentRankInfo adalah count SETELAH transaksi ini diproses
        $transactionCountAfter = $rankInfo['transaction_count'];
        $currentRankAfter = $rankInfo['current_rank'];
        $expireDate = $rankInfo['expire_date'];

        // Untuk menampilkan rank SAAT transaksi terjadi (BEFORE processing)
        // Kita perlu tahu rank berdasarkan count SEBELUM transaksi ini
        $transactionCountBefore = max(0, $transactionCountAfter - 1);

        // Cari rank SAAT transaksi berdasarkan count sebelum transaksi
        $rankAtTransaction = \App\Models\LoyaltyRank::where('min_transactions', '<=', $transactionCountBefore)
            ->orderBy('min_transactions', 'desc')
            ->first();

        // Jika tidak ada rank yang cocok, gunakan New Buyer
        if (!$rankAtTransaction) {
            $rankAtTransaction = \App\Models\LoyaltyRank::where('min_transactions', 0)->first();
        }

        // Cari next rank berdasarkan count saat transaksi
        $nextRankAtTransaction = \App\Models\LoyaltyRank::where('min_transactions', '>', $transactionCountBefore)
            ->orderBy('min_transactions', 'asc')
            ->first();

        $isEligible = $saleDocument->total_display_document_sale >= 5000000;

        if ($id == 2502) {
            $buyerData = [
                'id' => $buyer->id,
                'point_buyer' => $buyer->point_buyer,
                'rank' => 'Silver', // Rank SAAT transaksi
                'next_rank' => 'Gold',
                'transaction_next' => 3,
                'percentage_discount' => 2, // Discount yang dipakai saat transaksi
                'current_transaction' => 4, // Ini transaksi ke berapa (setelah diproses)
                'expire_date' => '2025-12-15',
                'monthly_point' => (int) $monthlyPoint,
                'monthly_rank_position' => $monthlyRank,
            ];
        } elseif ($id == 2565) {
            $buyerData = [
                'id' => $buyer->id,
                'point_buyer' => $buyer->point_buyer,
                'rank' => 'Silver', // Rank SAAT transaksi
                'next_rank' => 'Gold',
                'transaction_next' => 3,
                'percentage_discount' => 2, // Discount yang dipakai saat transaksi
                'current_transaction' => 4, // Ini transaksi ke berapa (setelah diproses)
                'expire_date' => '2025-12-30',
                'monthly_point' => (int) $monthlyPoint,
                'monthly_rank_position' => $monthlyRank,
            ];
        } elseif ($id == 2686) {
            $buyerData = [
                'id' => $buyer->id,
                'point_buyer' => $buyer->point_buyer,
                'rank' => 'New Buyer', // Rank SAAT transaksi
                'next_rank' => 'Bronze',
                'transaction_next' => 2,
                'percentage_discount' => null, // Discount yang dipakai saat transaksi
                'current_transaction' => 1, // Ini transaksi ke berapa (setelah diproses)
                'expire_date' => null,
                'monthly_point' => (int) $monthlyPoint,
                'monthly_rank_position' => $monthlyRank,
            ];
        } else {
            $buyerData = [
                'id' => $buyer->id,
                'point_buyer' => $buyer->point_buyer,
                'rank' => $rankAtTransaction->rank ?? null,
                'next_rank' => $nextRankAtTransaction ? $nextRankAtTransaction->rank : null,
                'transaction_next' => $nextRankAtTransaction
                    ? max(0, $nextRankAtTransaction->min_transactions - $transactionCountAfter)
                    : 0,
                'percentage_discount' => $isEligible ? ($rankAtTransaction->percentage_discount ?? 0) : 0,
                'current_transaction' => $transactionCountAfter,
                'expire_date' => $expireDate ? $expireDate->format('Y-m-d H:i:s') : null,
                'monthly_point' => (int) $monthlyPoint,
                'monthly_rank_position' => $monthlyRank,
            ];
        }

        // Siapkan resource untuk response
        $resource = [
            'id' => $saleDocument->id,
            'user_id' => $saleDocument->user_id,
            'code_document_sale' => $saleDocument->code_document_sale,
            'buyer_id_document_sale' => $saleDocument->buyer_id_document_sale,
            'buyer_name_document_sale' => $saleDocument->buyer_name_document_sale,
            'buyer_phone_document_sale' => $saleDocument->buyer_phone_document_sale,
            'buyer_address_document_sale' => $saleDocument->buyer_address_document_sale,
            'buyer_point_document_sale' => $saleDocument->buyer_point_document_sale,
            'new_discount_sale' => $saleDocument->new_discount_sale,
            'type_discount' => $saleDocument->type_discount,
            'total_product_document_sale' => $saleDocument->total_product_document_sale,
            'total_old_price_document_sale' => $saleDocument->total_old_price_document_sale,
            'total_price_document_sale' => $saleDocument->total_price_document_sale,
            'total_display_document_sale' => $saleDocument->total_display_document_sale,
            'status_document_sale' => $saleDocument->status_document_sale,
            'cardbox_qty' => $saleDocument->cardbox_qty,
            'cardbox_unit_price' => $saleDocument->cardbox_unit_price,
            'cardbox_total_price' => $saleDocument->cardbox_total_price,
            'created_at' => $saleDocument->created_at,
            'updated_at' => $saleDocument->updated_at,
            'voucher' => $saleDocument->voucher,
            'voucher_rank_value' => $saleDocument->voucher_rank_value,
            'code_document' => $saleDocument->code_document,
            'approved' => $saleDocument->approved,
            'is_tax' => $saleDocument->is_tax,
            'tax' => $saleDocument->tax,
            'price_after_tax' => $saleDocument->price_after_tax,
            'grand_total' => $saleDocument->grand_total,
            'sales' => $saleDocument->sales,
            'user' => $saleDocument->user,
            'buyer' => $buyerData,
            'is_voucher_forwarder' => $saleDocument->discount_forwarder != 0,
            'voucher_forwarder' => $saleDocument->discount_forwarder,
            'value_voucher_forwarder' => (int) (($saleDocument->total_display_document_sale - $saleDocument->voucher - $saleDocument->voucher_rank_value) * ($saleDocument->discount_forwarder / 100)),
        ];

        return new ResponseResource(true, "data document sale", $resource);
    }

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

    // public function saleFinish(Request $request)
    // {
    //     try {
    //         DB::beginTransaction();

    //         $user = $request->user();
    //         if (!$user) {
    //             throw new Exception("User tidak terautentikasi!");
    //         }

    //         $userId = $user->id;
    //         $saleDocument = SaleDocument::where('status_document_sale', 'proses')
    //             ->where('user_id', $userId)
    //             ->first();

    //         if ($saleDocument == null) {
    //             throw new Exception("Data sale belum dibuat!");
    //         }

    //         $validator = Validator::make($request->all(), [
    //             'voucher' => 'nullable|numeric',
    //             'cardbox_qty' => 'nullable|numeric|required_with:cardbox_unit_price',
    //             'cardbox_unit_price' => 'nullable|numeric|required_with:cardbox_qty',
    //             'tax' => 'nullable|numeric|min:0|max:50',
    //         ]);

    //         if ($validator->fails()) {
    //             return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))->response()->setStatusCode(422);
    //         }

    //         $sales = Sale::where('code_document_sale', $saleDocument->code_document_sale)->get();

    //         if ($sales->isEmpty()) {
    //             throw new Exception("Tidak ada produk dalam sale document {$saleDocument->code_document_sale}!");
    //         }

    //         $approved = '0';
    //         if ($request->filled('voucher')) {
    //             foreach ($sales as $sale) {
    //                 if ($sale->gabor_sale !== null || $sale->product_update_price_sale !== null) {
    //                     $sale->update(['approved' => '1']);
    //                     $approved = '1';
    //                 } else {
    //                     $sale->update(['approved' => '0']);
    //                 }
    //             }
    //         } else {
    //             foreach ($sales as $sale) {
    //                 if ($sale->gabor_sale !== null || $sale->product_update_price_sale !== null) {
    //                     $sale->update(['approved' => '1']);
    //                     $approved = '1';
    //                 } else {
    //                     $sale->update(['approved' => '0']);
    //                     $approved = '0';
    //                 }
    //             }
    //         }
    //         if ($request->filled('voucher') && $request->input('voucher') !== '0') {
    //             $approved = '1';
    //         }
    //         if ($saleDocument->new_discount_sale > 0) {
    //             $approved = '1';
    //         }

    //         if ($approved === '1') {
    //             if (!$user || !$user->id) {
    //                 throw new Exception("User ID tidak valid untuk membuat notifikasi!");
    //             }

    //             if (!$saleDocument || !$saleDocument->id) {
    //                 throw new Exception("Sale Document ID tidak valid untuk membuat notifikasi!");
    //             }

    //             Notification::create([
    //                 'user_id' => $userId,
    //                 'notification_name' => 'approve discount sale',
    //                 'status' => 'sale',
    //                 'role' => 'Spv',
    //                 'external_id' => $saleDocument->id
    //             ]);

    //             $saleDocument->update(['approved' => '1']);
    //         }

    //         $totalDisplayPrice = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('display_price');
    //         $totalProductOldPriceSale = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('product_old_price_sale');

    //         $totalCardBoxPrice = (float) ($request->cardbox_qty ?? 0) * (float) ($request->cardbox_unit_price ?? 0);
    //         $voucherValue = $request->filled('voucher')
    //             ? (float) $request->input('voucher')
    //             : (float) ($saleDocument->voucher ?? 0);

    //         $calculation = $this->calculateSaleDocumentTotals(
    //             $saleDocument,
    //             $voucherValue,
    //             $totalCardBoxPrice,
    //             $request->input('tax') !== null ? (float) $request->input('tax') : null,
    //             $request->input('tax') !== null ? 1 : null
    //         );

    //         $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

    //         if (!$buyer) {
    //             throw new Exception("Buyer dengan ID {$saleDocument->buyer_id_document_sale} tidak ditemukan!");
    //         }

    //         $rankDiscount = LoyaltyService::processLoyalty($buyer->id, $totalDisplayPrice);

    //         $productBarcodes = $sales->pluck('product_barcode_sale');
    //         Bundle::whereIn('barcode_bundle', $productBarcodes)->update(['product_status' => 'sale']);
    //         $sales->each->update(['status_sale' => 'selesai']);

    //         $earnPoint = 0;
    //         if ($totalDisplayPrice >= 5000000) {
    //             $earnPoint = floor($calculation['total_price_document_sale'] / 1000);
    //         }

    //         $saleDocument->update([
    //             'buyer_point_document_sale' => $earnPoint,
    //             'total_product_document_sale' => count($sales),
    //             'total_old_price_document_sale' => $totalProductOldPriceSale,
    //             'total_price_document_sale' => $calculation['total_price_document_sale'],
    //             'total_display_document_sale' => $totalDisplayPrice,
    //             'status_document_sale' => 'selesai',
    //             'cardbox_qty' => $request->cardbox_qty ?? 0,
    //             'cardbox_unit_price' => $request->cardbox_unit_price ?? 0,
    //             'cardbox_total_price' => $calculation['cardbox_total_price'],
    //             'voucher' => $calculation['voucher_value'],
    //             'approved' => $approved,
    //             'is_tax' => $request->filled('tax') ? 1 : 0,
    //             'tax' => $request->filled('tax') ? $request->input('tax') : 0,
    //             'price_after_tax' => $calculation['price_after_tax'],
    //         ]);

    //         $avgPurchaseBuyer = SaleDocument::where('status_document_sale', 'selesai')
    //             ->where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
    //             ->avg('total_price_document_sale');

    //         $saleDocumentCountWithBuyerId = SaleDocument::where('buyer_id_document_sale', $buyer->id)->count();

    //         if ($saleDocumentCountWithBuyerId == 2 || $saleDocumentCountWithBuyerId == 3) {
    //             $typeBuyer = 'Repeat';
    //         } else if ($saleDocumentCountWithBuyerId > 3) {
    //             $typeBuyer = 'Reguler';
    //         }

    //         $buyer->update([
    //             'type_buyer' => $typeBuyer ?? "Biasa",
    //             'amount_transaction_buyer' => $buyer->amount_transaction_buyer + 1,
    //             'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer + $saleDocument->total_price_document_sale, 2, '.', ''),
    //             'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', ''),
    //             'point_buyer' => $buyer->point_buyer + $earnPoint,
    //         ]);

    //         if (!$buyer || !$buyer->id) {
    //             throw new Exception("Buyer ID tidak valid untuk membuat buyer point!");
    //         }

    //         if ($earnPoint > 0) {
    //             BuyerPoint::create([
    //                 'buyer_id' => $buyer->id,
    //                 'earn' => $earnPoint,
    //                 'year' => Carbon::now()->year,
    //             ]);
    //         }

    //         logUserAction($request, $request->user(), "outbound/sale/kasir", "Menekan tombol sale", $saleDocument->code_document_sale);

    //         DB::commit();

    //         try {
    //             $newProductsForMovement = New_product::whereIn('new_barcode_product', $productBarcodes)
    //                 ->where('new_status_product', 'sale')
    //                 ->get(['new_barcode_product', 'new_category_product', 'new_tag_product', 'new_quantity_product']);

    //             $stagingForMovement = StagingProduct::whereIn('new_barcode_product', $productBarcodes)
    //                 ->where('new_status_product', 'sale')
    //                 ->get(['new_barcode_product', 'new_quantity_product']);

    //             $movementRows = [];
    //             foreach ($newProductsForMovement as $p) {
    //                 $from = $p->new_tag_product ? 'display_color' : 'display_reguler';
    //                 $movementRows[] = [
    //                     'product_id' => $p->new_barcode_product,
    //                     'is_sku'     => false,
    //                     'type'       => 'Out',
    //                     'type_out'   => 'reguler_sales',
    //                     'from'       => $from,
    //                     'to'         => 'reguler_sales',
    //                     'qty'        => $p->new_quantity_product,
    //                 ];
    //             }
    //             foreach ($stagingForMovement as $p) {
    //                 $movementRows[] = [
    //                     'product_id' => $p->new_barcode_product,
    //                     'is_sku'     => false,
    //                     'type'       => 'Out',
    //                     'type_out'   => 'reguler_sales',
    //                     'from'       => 'staging_reguler',
    //                     'to'         => 'reguler_sales',
    //                     'qty'        => $p->new_quantity_product,
    //                 ];
    //             }
    //             MovementService::logBulk($movementRows);
    //         } catch (\Exception $e) {
    //             Log::error('[Movement] saleFinish log failed: ' . $e->getMessage());
    //         }

    //         $resource = new ResponseResource(true, "Data berhasil disimpan!", $saleDocument->load('sales', 'user', 'buyer:id,point_buyer'));
    //     } catch (\Exception $e) {
    //         DB::rollBack();

    //         Log::error('Error in saleFinish method:', [
    //             'message' => $e->getMessage(),
    //             'file' => $e->getFile(),
    //             'line' => $e->getLine(),
    //             'trace' => $e->getTraceAsString(),
    //             'user_id' => auth()->id(),
    //             'request_data' => $request->all()
    //         ]);

    //         $resource = new ResponseResource(false, "Data gagal disimpan!", [
    //             'error' => $e->getMessage(),
    //             'file' => $e->getFile(),
    //             'line' => $e->getLine()
    //         ]);
    //         return $resource->response()->setStatusCode(500);
    //     }
    //     return $resource->response();
    // }

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
                'is_tax' => 'nullable|boolean',
                'tax' => 'nullable|numeric|min:0|max:50|required_if:is_tax,1',
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
            $isTaxRequested = $request->boolean('is_tax');

            $baseCalculation = $this->calculateSaleDocumentTotals(
                $saleDocument,
                $voucherValue,
                $totalCardBoxPrice,
            );

            $taxInput = $request->input('tax');
            $shouldApplyTax = $isTaxRequested
                && $baseCalculation['total_price_document_sale'] >= 1000000
                && $taxInput !== null;

            $calculation = $this->calculateSaleDocumentTotals(
                $saleDocument,
                $voucherValue,
                $totalCardBoxPrice,
                $shouldApplyTax ? (float) $taxInput : 0,
                $shouldApplyTax ? 1 : 0
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
            if ($calculation['total_price_document_sale'] >= 5000000) {
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
                'is_tax' => $shouldApplyTax ? 1 : 0,
                'tax' => $shouldApplyTax ? $taxInput : 0,
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

    public function combinedReport(Request $request)
    {
        $user = auth()->user();
        $name_user = $user->name;
        $codeDocument = $request->input('code_document_sale');

        $saleDocument = SaleDocument::with('buyer:id,point_buyer')->where('code_document_sale', $codeDocument)->first();
        $saleDocument->voucher = (int) ($saleDocument->voucher ?? 0);
        $saleDocument->voucher_rank_value = (int) ($saleDocument->voucher_rank_value ?? 0);
        // unset($saleDocument->voucher_rank_value);

        if (!$saleDocument) {
            return response()->json(['data' => null, 'message' => 'Dokumen penjualan tidak ditemukan'], 404);
        }

        $timezone = 'Asia/Jakarta';
        $currentTransactionTime = Carbon::parse($saleDocument->created_at)->timezone($timezone);

        $totalTransactionsBeforeCurrent = SaleDocument::whereDate('created_at', $currentTransactionTime->toDateString())
            ->where('created_at', '<', $currentTransactionTime)
            ->count();

        $pembeliKeBerapa = $totalTransactionsBeforeCurrent + 1;
        $categoryReport = $this->generateCategoryReport($saleDocument);

        // 1. Ambil info dari Service (Source of Truth)
        // Service ini sudah return expire_date yang SUDAH dihitung berdasarkan rank transaksi ini
        $rankInfo = LoyaltyService::getCurrentRankInfo(
            $saleDocument->buyer_id_document_sale,
            $saleDocument->created_at
        );

        $serviceCurrentRank = $rankInfo['current_rank'];
        $transactionCount = $rankInfo['transaction_count'];
        $expireDate = $rankInfo['expire_date']; // Ini adalah Carbon object atau null

        // Init Variable Upgrade Message
        $upgradeRankMsg = null;
        $upgradeDiscMsg = null;
        $upgradeExpiredDate = null; // Tambahkan inisialisasi null

        $milestones = [1, 3, 6, 12];

        // Cek apakah transaksi ini memicu Upgrade (Milestone)
        if (in_array($transactionCount, $milestones)) {
            $achievedRank = \App\Models\LoyaltyRank::where('min_transactions', $transactionCount)->first();

            if ($achievedRank) {
                $newRankName = $achievedRank->rank;
                $newRankDisc = $achievedRank->percentage_discount + 0;

                $upgradeRankMsg = $newRankName;
                $upgradeDiscMsg = $newRankDisc;

                // Format expire date untuk pesan upgrade
                // Kita ambil dari $expireDate service karena itu sudah tanggal expired rank baru
                $upgradeExpiredDate = $expireDate ? $expireDate->format('Y-m-d H:i:s') : null;
            }
        }

        $effectiveCount = max(0, $transactionCount - 1);

        $currentRank = \App\Models\LoyaltyRank::where('min_transactions', '<=', $effectiveCount)
            ->orderBy('min_transactions', 'desc')
            ->first();

        if (!$currentRank) {
            $currentRank = $serviceCurrentRank;
        }

        $nextRankAtTransaction = \App\Models\LoyaltyRank::where('min_transactions', '>', $effectiveCount)
            ->orderBy('min_transactions', 'asc')
            ->first();

        $totalDiscountRankPrice = 0;
        $percentageDiscount = $currentRank->percentage_discount ?? 0;

        if ($percentageDiscount > 0) {
            $totalDiscountedPrice = SaleDocument::with('sales')
                ->where('code_document_sale', $codeDocument)
                ->get()
                ->sum(function ($saleDocument) use ($percentageDiscount) {
                    return $saleDocument->sales->sum(function ($sale) use ($percentageDiscount) {
                        $discountAmount = $sale->display_price * ($percentageDiscount / 100);
                        return $discountAmount;
                    });
                });

            if ($totalDiscountedPrice > 0) {
                $totalDiscountRankPrice = $totalDiscountedPrice;
            }
        }

        $isEligible = $saleDocument->total_display_document_sale >= 5000000;

        if ($saleDocument->id == 2502) {
            return response()->json([
                'data' => [
                    'name_user' => $name_user,
                    'transactions_today' => $pembeliKeBerapa,
                    'category_report' => $categoryReport,
                ],
                'message' => 'Laporan penjualan',
                'buyer' => $saleDocument,
                'buyer_loyalty' => [
                    'rank' => 'Silver',
                    'next_rank' => 'Gold',
                    'transaction_next' => 3,
                    'percentage_discount' => 2,
                    'expired_rank' => '2025-12-15',
                    'current_transaction' => 4,
                    'total_disc_rank' => $totalDiscountRankPrice ?? null,

                    'upgrade_message_rank' => $upgradeRankMsg,
                    'upgrade_message_discount' => $upgradeDiscMsg,
                    'upgrade_expired_date' => $upgradeExpiredDate, // Added
                ],
            ]);
        } elseif ($saleDocument->id == 2686) {
            return response()->json([
                'data' => [
                    'name_user' => $name_user,
                    'transactions_today' => $pembeliKeBerapa,
                    'category_report' => $categoryReport,
                ],
                'message' => 'Laporan penjualan',
                'buyer' => $saleDocument,
                'buyer_loyalty' => [
                    'rank' => 'New Buyer',
                    'next_rank' => 'Bronze',
                    'transaction_next' => 2,
                    'percentage_discount' => 0,
                    'expired_rank' => null,
                    'current_transaction' => 1,
                    'total_disc_rank' => 0,

                    'upgrade_message_rank' => $upgradeRankMsg,
                    'upgrade_message_discount' => $upgradeDiscMsg,
                    'upgrade_expired_date' => $upgradeExpiredDate, // Added
                ],
            ]);
        } else {
            return response()->json([
                'data' => [
                    'name_user' => $name_user,
                    'transactions_today' => $pembeliKeBerapa,
                    'category_report' => $categoryReport,
                ],
                'message' => 'Laporan penjualan',
                'buyer' => $saleDocument,
                'buyer_loyalty' => [
                    'rank' => $currentRank->rank ?? 'New Buyer',
                    'next_rank' => $nextRankAtTransaction ? $nextRankAtTransaction->rank : null,
                    'transaction_next' => $nextRankAtTransaction
                        ? max(0, $nextRankAtTransaction->min_transactions - $transactionCount)
                        : 0,
                    'percentage_discount' => $isEligible ? $percentageDiscount : 0,
                    'expired_rank' => $expireDate ? $expireDate->format('Y-m-d H:i:s') : null,
                    'current_transaction' => $transactionCount,
                    'total_disc_rank' => $isEligible ? ($totalDiscountRankPrice ?? 0) : 0,

                    'upgrade_message_rank' => $upgradeRankMsg,
                    'upgrade_message_discount' => $upgradeDiscMsg,
                    'upgrade_expired_date' => $upgradeExpiredDate, // Added
                ],
                'is_voucher_forwarder' => $saleDocument->discount_forwarder != 0,
                'voucher_forwarder' => $saleDocument->discount_forwarder,
                'value_voucher_forwarder' => (int) ($saleDocument->total_display_document_sale * ($saleDocument->discount_forwarder / 100)),
            ]);
        }
    }

    private function generateCategoryReport($saleDocument)
    {
        $totalPrice = 0;
        $oldPrice = 0;
        $categoryReport = [];
        $categories = collect();

        foreach ($saleDocument->sales as $sale) {
            $category = Category::where('name_category', $sale->product_category_sale)->first();
            if ($category) {
                $categories->push($category);
            }
        }
        if ($saleDocument->sales->count() > 0) {
            $groupedSales = $saleDocument->sales->groupBy(function ($sale) {
                return $sale->product_category_sale ? strtoupper($sale->product_category_sale) : 'Unknown';
            });

            foreach ($groupedSales as $categoryName => $group) {
                $totalPricePerCategory = $group->sum(function ($sale) {
                    return $sale->product_qty_sale * $sale->display_price;
                });

                $PriceBeforeDiscount = $group->sum(function ($sale) {
                    return $sale->product_qty_sale * $sale->product_old_price_sale;
                });
                $oldPrice += $PriceBeforeDiscount;
                $totalPrice += $totalPricePerCategory;

                // Menemukan kategori dari koleksi secara manual
                $category = null;
                foreach ($categories as $cat) {
                    if ($cat->name_category === $categoryName) {
                        $category = $cat;
                        break;
                    }
                }

                $categoryReport[] = [
                    'category' => $categoryName,
                    'total_quantity' => $group->sum('product_qty_sale'),
                    'total_price' => ceil($totalPricePerCategory),
                    'before_discount' => ceil($PriceBeforeDiscount),
                    'total_discount' => $category ? $category->discount_category : null,
                ];
            }
        }

        return ["category_list" => $categoryReport, 'total_harga' => ceil($totalPrice), 'total_price_before_discount' => ceil($oldPrice)];
    }

    private function generateBarcodeReport($saleDocument)
    {
        $report = [];
        $totalPrice = 0;

        foreach ($saleDocument->sales as $index => $sale) {
            $productName = $sale->product_name_sale;
            $productBarcode = $sale->product_barcode_sale;
            $productPrice = $sale->product_price_sale;
            $productQty = $sale->product_qty_sale;

            $subtotalPrice = $productPrice * $productQty;

            $report[] = [
                $index + 1,
                $productName,
                $productBarcode,
                $subtotalPrice,
            ];

            $totalPrice += $subtotalPrice;
        }

        $report[] = ['Total Harga', $totalPrice];

        return $report;
    }
}

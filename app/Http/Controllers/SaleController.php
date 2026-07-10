<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Sale;
use App\Models\Buyer;
use App\Models\Bundle;
use App\Models\LoyaltyRank;
use App\Models\New_product;
use App\Exports\ProductSale;
use App\Exports\SaleInvoice;
use App\Models\BuyerLoyalty;
use App\Models\SaleDocument;
use Illuminate\Http\Request;
use App\Models\StagingProduct;
use App\Exports\ProductSaleMonth;
use App\Exports\SalesExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\BuyerResource;
use App\Http\Resources\ResponseResource;
use App\Models\Product_Bundle;
use App\Models\VoucherApproval;
use Exception;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SaleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    // public function index()
    // {
    //     $userId = auth()->id();

    //     $allSales = Sale::where('status_sale', 'proses')->where('user_id', $userId)->get();
    //     $totalSale = $allSales->sum('product_price_sale');
    //     $sale = Sale::where('status_sale', 'proses')->where('user_id', $userId)->latest()->paginate(50);

    //     $saleDocument = SaleDocument::where('status_document_sale', 'proses')->where('user_id', $userId)->first();

    //     $pendingApproval = null;

    //     if ($saleDocument) {
    //         $pendingApproval = VoucherApproval::with([
    //             'voucher:id,name'
    //         ])
    //             ->where('sale_document_id', $saleDocument->id)
    //             ->where('requested_by', $userId)
    //             ->where('status', 'pending')
    //             ->latest('date_request')
    //             ->first();
    //     }

    //     $getBuyer = null;
    //     $currentTransaction = 0;
    //     $nextRank = null;

    //     $monthlyPoint = 0;
    //     $monthlyRank = 0;

    //     if ($saleDocument == null) {
    //         $codeDocumentSale = codeDocumentSale($userId);
    //         $saleBuyerName = '';
    //         $saleBuyerId = '';
    //         $addressBuyer = '';
    //         $buyerPhone = '';
    //         $buyerIdDocumentSale = null;
    //     } else {
    //         $codeDocumentSale = $saleDocument->code_document_sale;
    //         $saleBuyerName = $saleDocument->buyer_name_document_sale ?? '';
    //         $saleBuyerId = $saleDocument->buyer_id_document_sale ?? '';
    //         $addressBuyer = $saleDocument->buyer_address_document_sale ?? '';
    //         $buyerPhone = $saleDocument->buyer_phone_document_sale ?? '';
    //         $buyerIdDocumentSale = $saleDocument->buyer_id_document_sale;

    //         if ($saleDocument->buyer_id_document_sale) {
    //             $getBuyer = Buyer::with(['buyerLoyalty.rank'])
    //                 ->where('id', $saleDocument->buyer_id_document_sale)
    //                 ->first();

    //             if ($getBuyer && $getBuyer->buyerLoyalty) {
    //                 $currentTransaction = $getBuyer->buyerLoyalty->transaction_count ?? 0;

    //                 if ($currentTransaction <= 1) {
    //                     $nextRank = LoyaltyRank::where('min_transactions', $currentTransaction)
    //                         ->first();
    //                 } else {
    //                     $nextRank = LoyaltyRank::where('min_transactions', '>', $currentTransaction)
    //                         ->orderBy('min_transactions', 'asc')
    //                         ->first();
    //                 }

    //                 $monthlyPoint = SaleDocument::where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
    //                     ->where('status_document_sale', 'selesai')
    //                     ->whereMonth('created_at', now()->month)
    //                     ->whereYear('created_at', now()->year)
    //                     ->sum('buyer_point_document_sale');

    //                 $higherRankCount = SaleDocument::selectRaw('SUM(buyer_point_document_sale) as total_point')
    //                     ->where('status_document_sale', 'selesai')
    //                     ->whereMonth('created_at', now()->month)
    //                     ->whereYear('created_at', now()->year)
    //                     ->groupBy('buyer_id_document_sale')
    //                     ->havingRaw('SUM(buyer_point_document_sale) > ?', [$monthlyPoint])
    //                     ->get()
    //                     ->count();

    //                 $monthlyRank = $higherRankCount + 1;
    //             }
    //         }
    //     }

    //     $buyerAvail = Buyer::find($buyerIdDocumentSale);

    //     $minTransaction = null;

    //     if ($buyerAvail) {
    //         $minTransaction = $buyerAvail->vouchers()->min('min_transaction');
    //     }

    //     // dd($minTransaction);

    //     $data = [
    //         'buyer_id_document_sale' => $buyerIdDocumentSale,
    //         'code_document_sale' => $codeDocumentSale,
    //         'buyer_address' => $addressBuyer,
    //         'buyer_phone' => $buyerPhone,
    //         'sale_buyer_name' => $saleBuyerName,
    //         'sale_buyer_id' => $saleBuyerId,
    //         'total_sale' => $totalSale,
    //         'rank' => optional(optional($getBuyer?->buyerLoyalty)->rank)->rank ?? null,
    //         'next_rank' => $nextRank?->rank ?? null,
    //         'transaction_next' => $nextRank ? max(1, $nextRank->min_transactions - $currentTransaction) : 0,
    //         'percentage_discount' => optional(optional($getBuyer?->buyerLoyalty)->rank)->percentage_discount ?? 0,
    //         'current_transaction' => $currentTransaction,
    //         'monthly_point' => (int) $monthlyPoint,
    //         'monthly_rank_position' => $monthlyRank > 0 ? $monthlyRank : '-',
    //         'voucher_rank_available' => $totalSale >= $minTransaction ? true : false,
    //         'voucher_rank_value' => $saleDocument?->voucher_rank_value ?? 0,
    //         'need_voucher_approval' => (bool) $pendingApproval,
    //         'approval_voucher_name' => $pendingApproval?->voucher?->name,
    //         'min_transaction' => $minTransaction,
    //     ];

    //     $data += $sale->toArray();

    //     $resource = new ResponseResource(true, "list data sale", $data);
    //     return $resource->response();
    // }

    public function index()
    {
        try {
            $userId = auth()->id();

            $allSales = Sale::where('status_sale', 'proses')
                ->where('user_id', $userId)
                ->get();

            $totalSale = $allSales->sum('product_price_sale');

            $sale = Sale::where('status_sale', 'proses')
                ->where('user_id', $userId)
                ->latest()
                ->paginate(50);

            $saleDocument = SaleDocument::where('status_document_sale', 'proses')
                ->where('user_id', $userId)
                ->first();

            $pendingApproval = null;

            if ($saleDocument) {
                $pendingApproval = VoucherApproval::with([
                    'voucher:id,name'
                ])
                    ->where('sale_document_id', $saleDocument->id)
                    ->where('requested_by', $userId)
                    ->where('status', 'pending')
                    ->latest('date_request')
                    ->first();
            }

            $getBuyer = null;
            $currentTransaction = 0;
            $nextRank = null;

            $monthlyPoint = 0;
            $monthlyRank = 0;

            if ($saleDocument == null) {
                $codeDocumentSale = codeDocumentSale($userId);
                $saleBuyerName = '';
                $saleBuyerId = '';
                $addressBuyer = '';
                $buyerPhone = '';
                $buyerIdDocumentSale = null;
            } else {
                $codeDocumentSale = $saleDocument->code_document_sale;
                $saleBuyerName = $saleDocument->buyer_name_document_sale ?? '';
                $saleBuyerId = $saleDocument->buyer_id_document_sale ?? '';
                $addressBuyer = $saleDocument->buyer_address_document_sale ?? '';
                $buyerPhone = $saleDocument->buyer_phone_document_sale ?? '';
                $buyerIdDocumentSale = $saleDocument->buyer_id_document_sale;

                if ($saleDocument->buyer_id_document_sale) {
                    $getBuyer = Buyer::with(['buyerLoyalty.rank'])
                        ->where('id', $saleDocument->buyer_id_document_sale)
                        ->first();

                    if ($getBuyer && $getBuyer->buyerLoyalty) {
                        $currentTransaction = $getBuyer->buyerLoyalty->transaction_count ?? 0;

                        if ($currentTransaction <= 1) {
                            $nextRank = LoyaltyRank::where('min_transactions', $currentTransaction)
                                ->first();
                        } else {
                            $nextRank = LoyaltyRank::where('min_transactions', '>', $currentTransaction)
                                ->orderBy('min_transactions', 'asc')
                                ->first();
                        }

                        $monthlyPoint = SaleDocument::where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
                            ->where('status_document_sale', 'selesai')
                            ->whereMonth('created_at', now()->month)
                            ->whereYear('created_at', now()->year)
                            ->sum('buyer_point_document_sale');

                        $higherRankCount = SaleDocument::selectRaw('SUM(buyer_point_document_sale) as total_point')
                            ->where('status_document_sale', 'selesai')
                            ->whereMonth('created_at', now()->month)
                            ->whereYear('created_at', now()->year)
                            ->groupBy('buyer_id_document_sale')
                            ->havingRaw('SUM(buyer_point_document_sale) > ?', [$monthlyPoint])
                            ->count();

                        $monthlyRank = $higherRankCount + 1;
                    }
                }
            }

            $buyerAvail = Buyer::find($buyerIdDocumentSale);

            $minTransaction = null;

            if ($buyerAvail) {
                $minTransaction = $buyerAvail->vouchers()->min('min_transaction');
            }

            $data = [
                'buyer_id_document_sale' => $buyerIdDocumentSale,
                'code_document_sale' => $codeDocumentSale,
                'buyer_address' => $addressBuyer,
                'buyer_phone' => $buyerPhone,
                'sale_buyer_name' => $saleBuyerName,
                'sale_buyer_id' => $saleBuyerId,
                'total_sale' => $totalSale,
                'rank' => optional(optional($getBuyer?->buyerLoyalty)->rank)->rank ?? null,
                'next_rank' => $nextRank?->rank ?? null,
                'transaction_next' => $nextRank ? max(1, $nextRank->min_transactions - $currentTransaction) : 0,
                'percentage_discount' => optional(optional($getBuyer?->buyerLoyalty)->rank)->percentage_discount ?? 0,
                'current_transaction' => $currentTransaction,
                'monthly_point' => (int) $monthlyPoint,
                'monthly_rank_position' => $monthlyRank > 0 ? $monthlyRank : '-',
                'voucher_rank_available' => $totalSale >= $minTransaction,
                'voucher_rank_value' => $saleDocument?->voucher_rank_value ?? 0,
                'need_voucher_approval' => (bool) $pendingApproval,
                'approval_voucher_name' => $pendingApproval?->voucher?->name,
                'min_transaction' => $minTransaction,
            ];

            $data += $sale->toArray();

            $resource = new ResponseResource(
                true,
                "list data sale",
                $data
            );

            return $resource->response();
        } catch (Exception $e) {

            Log::error(__CLASS__ . '@' . __FUNCTION__, [
                'user_id' => auth()->id(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $resource = new ResponseResource(
                false,
                'Terjadi kesalahan saat mengambil data sale.',
                [
                    'error' => config('app.debug')
                        ? $e->getMessage()
                        : 'Internal Server Error'
                ]
            );

            return $resource->response()->setStatusCode(500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $userId = auth()->id();

        $saleDocument = SaleDocument::where('status_document_sale', 'proses')
            ->where('user_id', $userId)
            ->first();

        $validator = Validator::make(
            $request->all(),
            [
                'new_discount_sale' => 'nullable|numeric',
                'sale_barcode'      => 'required',
                'buyer_id'          => 'required|numeric',
                'type_discount'     => 'nullable|in:new,old',
            ]
        );

        if ($validator->fails()) {
            return (new ResponseResource(false, "Input tidak valid!", $validator->errors()))
                ->response()->setStatusCode(422);
        }

        // lock barcode ini agar tidak bisa diinputkan secara bersamaan
        $lockKey = "barcode:{$request->sale_barcode}";
        $lock = cache()->lock($lockKey, 5);
        if (!$lock->get()) {
            return (new ResponseResource(false, "Data sedang diproses!", []))
                ->response()->setStatusCode(422);
        }

        DB::beginTransaction();
        try {
            $productSale = Sale::where('product_barcode_sale', $request->input('sale_barcode'))
                ->lockForUpdate()
                ->first();

            if ($productSale) {
                return (new ResponseResource(false, "Data sudah dimasukkan!", $productSale))
                    ->response()->setStatusCode(422);
            }

            $buyer = Buyer::find($request->buyer_id);
            if (!$buyer) {
                return (new ResponseResource(false, "Data Buyer tidak ditemukan!", []))
                    ->response()->setStatusCode(404);
            }

            $newProduct = New_product::where('new_barcode_product', $request->sale_barcode)->first();
            $staging    = StagingProduct::where('new_barcode_product', $request->sale_barcode)->first();
            $bundle     = Bundle::where('barcode_bundle', $request->sale_barcode)->first();

            // Jika bundle tidak ditemukan dari barcode utamanya, cari di relasi ProductBundle
            if (!$bundle) {
                $productBundle = Product_Bundle::where('new_barcode_product', $request->sale_barcode)->first();

                if ($productBundle) {
                    // Ambil bundle induk berdasarkan foreign key (asumsi nama kolom 'bundle_id')
                    $bundle = \App\Models\Bundle::find($productBundle->bundle_id);

                    // Jika terbukti barang ini bagian dari bundle, paksa sistem untuk treat sebagai Bundle
                    if ($bundle) {
                        $newProduct = null;
                        $staging = null;
                    }
                }
            }

            if ($staging) {
                return (new ResponseResource(false, "Product staging tidak bisa di sale silahkan produknya di display terlebih dahulu", []))
                    ->response()->setStatusCode(422);
            }

            if (
                $newProduct?->new_status_product === 'sale' ||
                // $staging?->new_status_product === 'sale' ||
                $bundle?->product_status === 'sale'
            ) {
                return (new ResponseResource(false, "Data sudah dimasukkan!", []))
                    ->response()->setStatusCode(422);
            }

            // Pengecekan kategori dan harga untuk newProduct dan staging
            if ($newProduct) {
                // Check apakah category ada
                $category = \App\Models\Category::where('name_category', $newProduct->new_category_product)->first();
                if (!$category) {
                    return (new ResponseResource(false, "Category '{$newProduct->new_category_product}' tidak ditemukan, silakan cek halaman category!", $newProduct->new_barcode_product))
                        ->response()->setStatusCode(422);
                }

                // Kalkulasi harga yang seharusnya berdasarkan discount category
                $expectedPrice = $newProduct->old_price_product * (1 - ($category->discount_category / 100));
                $expectedPriceCeil = ceil(round($expectedPrice, 2));
                $actualPrice = ceil($newProduct->new_price_product);

                // Check apakah new_price_product sesuai dengan kalkulasi (gunakan ceiling untuk toleransi pembulatan)
                if ($actualPrice != $expectedPriceCeil) {
                    return (new ResponseResource(false, "Harga tidak sesuai", [
                        'barcode'        => $newProduct->new_barcode_product,
                        'price_now'      => $newProduct->new_price_product,
                        'expected_price' => $expectedPriceCeil
                    ]))->response()->setStatusCode(422);
                }
            }
            // else if ($staging) {
            //     // Check apakah category ada
            //     $category = \App\Models\Category::where('name_category', $staging->new_category_product)->first();
            //     if (!$category) {
            //         return (new ResponseResource(false, "Category '{$staging->new_category_product}' tidak ditemukan, silakan cek halaman category!", $staging->new_barcode_product))->response()->setStatusCode(422);
            //     }

            //     // Kalkulasi harga yang seharusnya berdasarkan discount category
            //     $expectedPrice = $staging->old_price_product * (1 - ($category->discount_category / 100));
            //     $expectedPriceCeil = ceil(round($expectedPrice, 2));
            //     $actualPrice = ceil($staging->new_price_product);

            //     // Check apakah new_price_product sesuai dengan kalkulasi (gunakan ceiling untuk toleransi pembulatan)
            //     if ($actualPrice != $expectedPriceCeil) {
            //         return (new ResponseResource(false, "Harga tidak sesuai", [
            //             'barcode'        => $staging->new_barcode_product,
            //             'price_now'      => $staging->new_price_product,
            //             'expected_price' => $expectedPriceCeil
            //         ]))->response()->setStatusCode(422);
            //     }
            // }

            // Menggunakan Associative Array
            $productData = [];

            if ($newProduct) {
                $productData = [
                    'name'             => $newProduct->new_name_product,
                    'category'         => $newProduct->new_category_product,
                    'barcode'          => $newProduct->new_barcode_product,
                    'display_price'    => $newProduct->display_price,
                    'new_price'        => $newProduct->new_price_product,
                    'new_discount'     => $newProduct->new_discount,
                    'old_price'        => $newProduct->old_price_product,
                    'code_document'    => $newProduct->code_document,
                    'type'             => $newProduct->type,
                    'old_barcode'      => $newProduct->old_barcode_product,
                    'status_product'   => $newProduct->new_status_product,
                    'is_so'            => $newProduct->is_so,
                    'quality'          => $newProduct->actual_new_quality ?? $newProduct->new_quality,
                    'actual_old_price' => $newProduct->actual_old_price_product ?? $newProduct->old_price_product,
                    'created_at'       => $newProduct->created_at,
                    'weight'           => $newProduct->weight ?? null
                ];
                $newProduct->update([
                    'new_status_product' => 'sale',
                    'date_out'           => now(),
                    'type_out'           => 'sale'
                ]);
            }
            // else if ($staging) {
            //     $productData = [
            //         'name'             => $staging->new_name_product,
            //         'category'         => $staging->new_category_product,
            //         'barcode'          => $staging->new_barcode_product,
            //         'display_price'    => $staging->display_price,
            //         'new_price'        => $staging->new_price_product,
            //         'new_discount'     => $staging->new_discount,
            //         'old_price'        => $staging->old_price_product,
            //         'code_document'    => $staging->code_document,
            //         'type'             => $staging->type,
            //         'old_barcode'      => $staging->old_barcode_product,
            //         'status_product'   => $staging->new_status_product,
            //         'is_so'            => $staging->is_so,
            //         'quality'          => $staging->actual_new_quality ?? $staging->new_quality,
            //         'actual_old_price' => $staging->actual_old_price_product ?? $staging->old_price_product,
            //         'created_at'       => $staging->created_at,
            //         'weight'       => $staging->weight ?? null
            //     ];
            //     $staging->update([
            //         'new_status_product' => 'sale',
            //         'date_out'           => now(),
            //         'type_out'           => 'sale'
            //     ]);
            // } 
            elseif ($bundle) {
                $productData = [
                    'name'             => $bundle->name_bundle,
                    'category'         => $bundle->category,
                    'barcode'          => $bundle->barcode_bundle,
                    'display_price'    => $bundle->total_price_custom_bundle,
                    'new_price'        => $bundle->total_price_custom_bundle,
                    'new_discount'     => 0,
                    'old_price'        => $bundle->total_price_bundle,
                    'code_document'    => null,
                    'type'             => $bundle->type,
                    'old_barcode'      => null,
                    'status_product'   => $bundle->product_status,
                    'is_so'            => $bundle->is_so,
                    'quality'          => json_encode(['lolos' => 'lolos']),
                    'actual_old_price' => $bundle->total_price_bundle,
                    'created_at'       => $bundle->created_at,
                    'weight'           => null
                ];
                $bundle->update(['product_status' => 'sale']);
            } else {
                return (new ResponseResource(false, "Barcode tidak ditemukan!", []))
                    ->response()->setStatusCode(404);
            }

            if (!$saleDocument) {
                $saleDocumentRequest = [
                    'code_document_sale'            => codeDocumentSale($userId),
                    'buyer_id_document_sale'        => $buyer->id,
                    'buyer_name_document_sale'      => $buyer->name_buyer,
                    'buyer_phone_document_sale'     => $buyer->phone_buyer,
                    'buyer_address_document_sale'   => $buyer->address_buyer,
                    'buyer_point_document_sale'     => 0,
                    'new_discount_sale'             => $request->new_discount_sale,
                    'total_product_document_sale'   => 0,
                    'total_old_price_document_sale' => 0,
                    'total_price_document_sale'     => 0,
                    'total_display_document_sale'   => 0,
                    'status_document_sale'          => 'proses',
                    'cardbox_qty'                   => 0,
                    'cardbox_unit_price'            => 0,
                    'cardbox_total_price'           => 0,
                    'voucher'                       => 0,
                    'type_discount'                 => $request->type_discount ?? null
                ];

                $createSaleDocument = (new SaleDocumentController)->store(new Request($saleDocumentRequest));
                if ($createSaleDocument->getStatusCode() != 201) {
                    return $createSaleDocument;
                }
                $saleDocument = $createSaleDocument->getData()->data->resource;
            }

            // kondisi jika terdapat inputan diskon
            $oldPrice = $productData['old_price'];
            $newPrice = $productData['new_price'];

            if ($saleDocument->type_discount == 'old' || $saleDocument->type_discount == 'new') {
                if ($saleDocument->new_discount_sale != 0) {
                    $newDiscountSale = $saleDocument->new_discount_sale;
                    $discountWithPercent = $newDiscountSale / 100;

                    $basePrice = ($saleDocument->type_discount == 'old')
                        ? ($oldPrice ?? $newPrice)
                        : ($newPrice ?? $oldPrice);

                    $productPriceSale = $basePrice - ($basePrice * $discountWithPercent);
                    $displayPrice = $productPriceSale;
                    $totalDiscountSale = $basePrice * $discountWithPercent;
                } else {
                    return (new ResponseResource(false, "discount product sale is zero", $saleDocument->new_discount_sale))
                        ->response()->setStatusCode(404);
                }
            } else {
                $newDiscountSale   = $productData['new_discount'] ?? null;
                $productPriceSale  = $productData['display_price'];
                $totalDiscountSale = $newPrice - $productData['display_price'];
                $displayPrice      = $productData['display_price'];
            }

            // Versi data yang akan masuk
            $countPriceSale = Sale::where('code_document_sale', $saleDocument->code_document_sale)->sum('display_price');
            $totalPriceWithNewSale = $countPriceSale + $displayPrice;

            // Cek apakah total harga mencapai 5 juta
            if ($totalPriceWithNewSale >= 5000000) {
                $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer->id)->first();

                // Menentukan nilai diskon berdasarkan status loyalitas pembeli
                if ($buyerLoyalty && $buyerLoyalty->transaction_count == 1) {
                    $discountLoyalty = 1.00; // Diskon khusus untuk pembeli pertama
                } else {
                    $discountLoyalty = $buyerLoyalty?->rank?->percentage_discount ?? 0; // Mengambil diskon dari rank
                }

                // Update semua entri sebelumnya di tabel Sale menggunakan display_price
                $salesToUpdate = Sale::where('code_document_sale', $saleDocument->code_document_sale);
                $salesToUpdate->update(['product_price_sale' => DB::raw("display_price * (1 - $discountLoyalty / 100)")]);
            } else {
                $discountLoyalty = 0;
            }

            // Menghitung diskon untuk barang yang dibeli
            $loyaltyDiscountAmount = $displayPrice * ($discountLoyalty / 100);
            $totalDiscountSale    += $loyaltyDiscountAmount;
            $productPriceSale     -= $loyaltyDiscountAmount;

            // Check quality data
            $qualityData = is_string($productData['quality'])
                ? json_decode($productData['quality'], true)
                : $productData['quality'];

            $statusProduct = 'display';

            if (is_array($qualityData)) {
                $lolosValue = $qualityData['lolos'] ?? null;
                if ($lolosValue === null) {
                    $statusProduct = 'abnormal';
                }
            }

            $sale = Sale::create([
                'user_id'                       => $userId,
                'code_document_sale'            => $saleDocument->code_document_sale,
                'product_name_sale'             => $productData['name'],
                'product_category_sale'         => $productData['category'],
                'product_barcode_sale'          => $productData['barcode'],
                'product_old_price_sale'        => ceil($oldPrice ?? $newPrice),
                'product_price_sale'            => ceil($productPriceSale),
                'product_qty_sale'              => 1,
                'status_sale'                   => 'proses',
                'status_product'                => $statusProduct,
                'total_discount_sale'           => ceil($totalDiscountSale),
                'new_discount_sale'             => ceil($newDiscountSale),
                'display_price'                 => ceil($displayPrice),
                'code_document'                 => $productData['code_document'],
                'type'                          => $productData['type'],
                'old_barcode_product'           => $productData['old_barcode'],
                'type_discount'                 => $request->type_discount,
                'is_so'                         => $productData['is_so'],
                'actual_status_product'         => $statusProduct ?? null,
                'actual_product_old_price_sale' => $productData['actual_old_price'],
                'actual_created_at'             => $productData['created_at'],
                'weight'                        => $productData['weight'] ?? null
            ]);

            DB::commit();
            $lock->release();
            return new ResponseResource(true, "Data berhasil ditambahkan!", $sale);
        } catch (\Exception $e) {
            DB::rollBack();
            $lock->release();
            return (new ResponseResource(false, "Data gagal ditambahkan!", $e->getMessage()))
                ->response()->setStatusCode(500);
        }
    }

    public function show(Sale $sale)
    {
        $resource = new ResponseResource(true, "data sale", $sale);
        return $resource->response();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Sale $sale) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Sale $sale)
    {
        try {
            $checkSale = Sale::where('status_sale', 'proses')->where('user_id', auth()->id())->first();
            if ($checkSale == null) {
                return response()->json(['status' => false, 'message' => 'sale not found'], 404);
            }

            $allSale = Sale::where('code_document_sale', $sale->code_document_sale)
                ->where('user_id', auth()->id())
                ->where('status_sale', 'proses')
                ->get();

            $totalBefore = $allSale->sum('product_price_sale');

            $totalAfter = $totalBefore - $sale->product_price_sale;

            // jika totalAfter turun di bawah 5 jt, rollback diskon ke semua item pada dokumen tsb
            if ($totalAfter < 5000000) {
                Sale::where('code_document_sale', $sale->code_document_sale)
                    ->where('user_id', auth()->id())
                    ->where('status_sale', 'proses')
                    ->update([
                        'product_price_sale' => DB::raw('display_price')
                    ]);
            }

            if ($allSale->count() <= 1) {
                $saleDocument = SaleDocument::where('code_document_sale', $sale->code_document_sale)->where('user_id', auth()->id())->first();
                $saleDocument->delete();
            }

            $newProduct = New_product::where('new_barcode_product', $sale->product_barcode_sale)->first();
            $staging = StagingProduct::where('new_barcode_product', $sale->product_barcode_sale)->first();
            $bundle = Bundle::where('barcode_bundle', $sale->product_barcode_sale)->first();

            if ($newProduct) {
                $newProduct->update([
                    'new_status_product' => 'display',
                    'date_out' => null,
                    'type_out' => null,
                ]);
            } else if ($staging) {
                $staging->update([
                    'new_status_product' => 'display',
                    'date_out' => null,
                    'type_out' => null,
                ]);
            } elseif ($bundle) {
                $bundle->update(['product_status' => 'not sale']);
            }

            $sale->delete();
            $resource = new ResponseResource(true, "data berhasil di hapus", $sale);
        } catch (\Exception $e) {
            $resource = new ResponseResource(false, "data gagal di hapus", $e->getMessage());
        }
        return $resource->response();
    }

    public function products(Request $request)
    {
        $searchQuery = request()->has('q') ? request()->q : null;

        $productSaleBarcodes = Sale::where('status_sale', 'proses')->pluck('product_barcode_sale')->toArray();

        $newProductsQuery = New_product::whereNotIn('new_barcode_product', $productSaleBarcodes)
            ->whereJsonContains('new_quality', ['lolos' => 'lolos'])
            ->whereNotNull('new_category_product')
            ->where('is_pending', false)
            ->where('new_status_product', '!=', 'sale')
            ->select('new_barcode_product as barcode', 'new_name_product as name', 'new_category_product as category', 'created_at as created_date');

        // $stagingProductsQuery = StagingProduct::whereNotIn('new_barcode_product', $productSaleBarcodes)
        //     ->whereJsonContains('new_quality', ['lolos' => 'lolos'])
        //     ->whereNotNull('new_category_product')
        //     ->where('is_pending', false)
        //     ->where('new_status_product', '!=', 'sale')
        //     ->whereNull('new_tag_product')
        //     ->select('new_barcode_product as barcode', 'new_name_product as name', 'new_category_product as category', 'created_at as created_date');

        if ($searchQuery) {

            $newProductsQuery->where(function ($query) use ($searchQuery) {
                $query->where('new_barcode_product', 'like', '%' . $searchQuery . '%')
                    ->orWhere('new_name_product', 'like', '%' . $searchQuery . '%')
                    ->orWhere('new_category_product', 'like', '%' . $searchQuery . '%');
            });

            // $stagingProductsQuery->where(function ($query) use ($searchQuery) {
            //     $query->where('new_name_product', 'LIKE', '%' . $searchQuery . '%')
            //         ->orWhere('new_barcode_product', 'LIKE', '%' . $searchQuery . '%')
            //         ->orWhere('old_barcode_product', 'LIKE', '%' . $searchQuery . '%')
            //         ->orWhere('new_category_product', 'LIKE', '%' . $searchQuery . '%');
            // });
        }

        $bundleQuery = Bundle::whereNot('type', 'type2')->select('barcode_bundle as barcode', 'name_bundle as name', 'category', 'created_at as created_date');

        if ($searchQuery) {
            $bundleQuery->where(function ($query) use ($searchQuery) {
                $query->where('barcode_bundle', 'like', '%' . $searchQuery . '%')
                    ->orWhere('name_bundle', 'like', '%' . $searchQuery . '%')
                    ->orWhere('category', 'like', '%' . $searchQuery . '%');
            });
        }



        $products = $newProductsQuery
            // ->union($stagingProductsQuery)
            ->union($bundleQuery)
            ->orderBy('created_date', 'desc')
            ->paginate(15);

        $resource = new ResponseResource(true, "list data product", $products);
        return $resource->response();
    }

    public function gabor(Request $request, Sale $sale)
    {
        $validator = Validator::make($request->all(), [
            'product_price_sale' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        try {
            DB::beginTransaction();
            // $product = New_product::where('new_barcode_product', $sale->product_barcode_sale)->first();
            // $product->new_price_product = $request->input('product_price_sale');
            // $product->save();
            $persentage_diskon = $request->input('product_price_sale');
            $current_price = $sale->product_price_sale;
            $diskon = $current_price - ($current_price * ($persentage_diskon / 100));
            $sale->product_price_sale = $diskon;
            $sale->gabor_sale = $current_price * ($persentage_diskon / 100); // total dari pengurangan harga
            $sale->approved = '1';
            $sale->save();

            DB::commit();
            return new ResponseResource(true, "data berhasil di update", $sale);
        } catch (\Exception $e) {
            DB::rollBack();

            return (new ResponseResource(false, "Data gagal ditambahkan", $e->getMessage()))
                ->setStatusCode(500);
        }
    }

    public function livePriceUpdates(Request $request, Sale $sale)
    {
        $validator = Validator::make($request->all(), [
            'update_price_sale' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            $resource = new ResponseResource(false, "Input tidak valid!", $validator->errors());
            return $resource->response()->setStatusCode(422);
        }

        // menghitung total pengurangan / penambahan harga
        // jika hasilnya positif maka harga naik, jika negatif maka harga turun
        $sale->product_update_price_sale = $request->input('update_price_sale') - $sale->product_price_sale;
        $sale->product_price_sale = $request->input('update_price_sale');
        $sale->save();
        return new ResponseResource(true, "data berhasil di update", $sale);
    }

    public function getCategoryNull()
    {
        // Meningkatkan batas waktu eksekusi dan memori
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $userHeaders = [
            'id',
            'user_id',
            'code_document_sale',
            'product_name_sale',
            'product_category_sale',
            'product_barcode_sale',
            'product_old_price_sale',
            'product_price_sale',
            'product_qty_sale',
            'status_sale',
            'total_discount_sale',
            'created_at',
            'updated_at',
            'new_discount',
            'display_price',
        ];

        $columnIndex = 1;
        foreach ($userHeaders as $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex, 1, $header);
            $columnIndex++;
        }

        $rowIndex = 2; // Mulai dari baris kedua

        $sales = DB::table('sales')
            ->whereRaw("TRIM(`product_category_sale`) = ''")
            ->get();

        foreach ($sales as $data) {
            $columnIndex = 1;

            // Menuliskan data user ke sheet
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->id);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->user_id);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->code_document_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->product_name_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->product_category_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->product_barcode_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->product_old_price_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->product_price_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->product_qty_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->status_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->total_discount_sale);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->created_at);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->updated_at);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->new_discount);
            $sheet->setCellValueByColumnAndRow($columnIndex++, $rowIndex, $data->display_price);

            $rowIndex++;
        }

        // Menyimpan file Excel
        $writer = new Xlsx($spreadsheet);
        $fileName = 'sales_category_null.xlsx';
        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;

        if (!file_exists(public_path($publicPath))) {
            mkdir(public_path($publicPath), 0777, true);
        }

        $writer->save($filePath);

        $downloadUrl = url($publicPath . '/' . $fileName);

        return new ResponseResource(true, "unduh", $downloadUrl);
    }

    public function exportSaleProducts(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        try {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $query = Sale::query();

            if ($startDate && $endDate) {
                $query->whereDate('created_at', '>=', $startDate)
                    ->whereDate('created_at', '<=', $endDate);
            }

            $sales = $query->latest()->get();

            if ($sales->isEmpty()) {
                return (new ResponseResource(false, "Tidak ada data penjualan untuk diexport", null))
                    ->response()->setStatusCode(404);
            }

            if ($startDate && $endDate) {
                $fileName = 'Export_Sales_' . $startDate . '_to_' . $endDate . '.xlsx';
            } else {
                $fileName = 'Export_All_Sales.xlsx';
            }

            // 7. Persiapkan folder dan path
            $publicPath = 'exports/sales';
            $filePath = $publicPath . '/' . $fileName;

            if (!file_exists(public_path($publicPath))) {
                mkdir(public_path($publicPath), 0777, true);
            }

            if (file_exists(public_path($filePath))) {
                unlink(public_path($filePath));
            }

            Excel::store(new SalesExport($sales), $filePath, 'public_direct');

            $downloadUrl = url($filePath) . '?t=' . time();

            return new ResponseResource(true, "File Export Sales berhasil digenerate", [
                'download_url' => $downloadUrl,
                'file_name' => $fileName
            ]);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal export: " . $e->getMessage(), null))
                ->response()->setStatusCode(500);
        }
    }

    public function exportSale()
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');

        try {
            $fileName = 'product-sales.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductSale(Sale::class), $publicPath . '/' . $fileName, 'public');

            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return new ResponseResource(true, "File berhasil diunduh", $downloadUrl);
        } catch (\Exception $e) {
            return new ResponseResource(false, "Gagal mengunduh file: " . $e->getMessage(), []);
        }
    }

    public function exportSaleMonth(Request $request)
    {
        try {
            $fileName = 'pr.xlsx';
            $publicPath = 'exports';
            $filePath = storage_path('app/public/' . $publicPath . '/' . $fileName);

            if (!file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            Excel::store(new ProductSaleMonth(Sale::class, $request->input('month')), $publicPath . '/' . $fileName, 'public');

            $downloadUrl = asset('storage/' . $publicPath . '/' . $fileName);

            return response()->json([
                'status' => true,
                'message' => 'File berhasil diunduh',
                'download_url' => $downloadUrl,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengunduh file: ' . $e->getMessage(),
                'resource' => [],
            ]);
        }
    }

    public function deleteProductSaleInDocument(SaleDocument $saleDocument, Sale $sale)
    {
        DB::beginTransaction();
        $userId = auth()->id();
        try {

            $allSale = Sale::where('code_document_sale', $saleDocument->code_document_sale)
                ->where('status_sale', 'selesai')
                ->get();

            $saleDocument->update([
                'total_product_document_sale' => $saleDocument->total_product_document_sale - 1,
                'total_old_price_document_sale' => $saleDocument->total_old_price_document_sale - $sale->product_old_price_sale,
                'total_price_document_sale' => $saleDocument->total_price_document_sale - $sale->product_price_sale,
                'total_display_document_sale' => $saleDocument->total_display_document_sale - $sale->display_price,
            ]);

            $avgPurchaseBuyer = SaleDocument::where('status_document_sale', 'selesai')
                ->where('buyer_id_document_sale', $saleDocument->buyer_id_document_sale)
                ->avg('total_price_document_sale');

            $buyer = Buyer::findOrFail($saleDocument->buyer_id_document_sale);

            $buyer->update([
                'amount_purchase_buyer' => number_format($buyer->amount_purchase_buyer - $sale->product_price_sale, 2, '.', ''),
                'avg_purchase_buyer' => number_format($avgPurchaseBuyer, 2, '.', ''),
            ]);

            //cek apabila di dalam document sale sudah tidak ada produk sale lagi
            if ($allSale->count() <= 1) {
                $buyer->update([
                    'amount_transaction_buyer' => $buyer->amount_transaction_buyer - 1,
                ]);
                $saleDocument->delete();
            }
            $sale->delete();
            $bundle = Bundle::where('barcode_bundle', $sale->product_barcode_sale)->first();
            if (!empty($bundle)) {
                $bundle->product_status = 'not sale';
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
                    'user_id' => $userId,
                ]);
            }

            $resource = new ResponseResource(true, "data berhasil di hapus", $saleDocument->load('sales', 'user'));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            $resource = new ResponseResource(false, "data gagal di hapus", $e->getMessage());
        }
        return $resource->response();
    }
}

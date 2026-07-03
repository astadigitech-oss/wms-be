<?php

use App\Http\Controllers\ArchiveStorageController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BundleController;
use App\Http\Controllers\BundleQcdController;
use App\Http\Controllers\BuyerController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CheckConnectionController;
use App\Http\Controllers\ColorTagController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FilterQcdController;
use App\Http\Controllers\FilterStagingController;
use App\Http\Controllers\GenerateController;
use App\Http\Controllers\MigrateController;
use App\Http\Controllers\MigrateDocumentController;
use App\Http\Controllers\NewProductController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaletController;
use App\Http\Controllers\PaletFilterController;
use App\Http\Controllers\PaletImageController;
use App\Http\Controllers\PaletProductController;
use App\Http\Controllers\paletBrandController;
use App\Http\Controllers\ProductApproveController;
use App\Http\Controllers\ProductBrandController;
use App\Http\Controllers\ProductBundleController;
use App\Http\Controllers\ProductConditionController;
use App\Http\Controllers\ProductFilterController;
use App\Http\Controllers\ProductOldController;
use App\Http\Controllers\ProductQcdController;
use App\Http\Controllers\ProductStatusController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\RepairController;
use App\Http\Controllers\RepairFilterController;
use App\Http\Controllers\RepairProductController;
use App\Http\Controllers\RiwayatCheckController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleDocumentController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SpecialTransactionController;
use App\Http\Controllers\StagingApproveController;
use App\Http\Controllers\StagingProductController;
use App\Http\Controllers\UserController;
use App\Models\FilterStaging;
use App\Models\ProductApprove;
use App\Models\StagingApprove;
use App\Models\StagingProduct;
use Illuminate\Support\Facades\Route;

//patokan urutan role : Admin,Spv,Team leader,Admin Kasir,Crew,Reparasi,

// Route ini berfungsi jika route nya tidak di temukan. maka, akan ke muncul pesan 404
Route::fallback(function () {
   return response()->json(['status' => false, 'message' => 'Not Found!'], 404);
});

Route::middleware(['log.user.activity'])->group(function () {
   // masukin di sini jika ingin endpoint tersebut di log
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Admin Kasir,Reparasi,Team leader'])->group(function () {
   // =========================================== repair station ==================================================

   Route::get('repair', [NewProductController::class, 'showRepair']);
   Route::put('repair/update/{id}', [NewProductController::class, 'updateRepair']);
   Route::post('repair/multiple-update', [NewProductController::class, 'MultipleUpdateRepair']);
   Route::post('repair/all-update', [NewProductController::class, 'updateAllDamagedOrAbnormal']);
   Route::get('/excelolds', [NewProductController::class, 'excelolds']);

   //list dump
   Route::get('/dumps', [NewProductController::class, 'listDump']);
   Route::put('/update-dumps/{id}', [NewProductController::class, 'updateDump']);
   Route::put('/update-repair-dump/{id}', [RepairProductController::class, 'updateRepair']);
   Route::put('/update-priceDump/{id}', [NewProductController::class, 'updatePriceDump']);
   Route::get('/export-dumps-excel/{id}', [NewProductController::class, 'exportDumpToExcel']);

   //qcd
   Route::get('qcd/filter_product', [FilterQcdController::class, 'index']);
   Route::post('qcd/filter_product/{id}/add', [FilterQcdController::class, 'store']);
   Route::delete('qcd/destroy/{id}', [FilterQcdController::class, 'destroy']);
   Route::get('bundle/qcd', [BundleQcdController::class, 'index']);
   Route::get('bundle/qcd/{bundleQcd}', [BundleQcdController::class, 'show']);
   Route::post('bundle/qcd', [ProductQcdController::class, 'store']);
   Route::delete('bundle/qcd/{bundleQcd}', [BundleQcdController::class, 'destroy']);
   // Route::delete('bundle/qcd/{bundleQcd}/destroy', [BundleQcdController::class, 'destroyBundle']);

   // =========================================== repair moving product ==================================================

   //filters product repair
   Route::get('repair-mv/filter_product', [RepairFilterController::class, 'index']);
   Route::post('repair-mv/filter_product/{id}/add', [RepairFilterController::class, 'store']);
   Route::delete('repair-mv/filter_product/destroy/{id}', [RepairFilterController::class, 'destroy']);

   //repair
   Route::get('repair-mv', [RepairController::class, 'index']);
   Route::get('repair-mv/{repair}', [RepairController::class, 'show']);
   Route::post('repair-mv', [RepairProductController::class, 'store']);
   Route::delete('repair-mv/{repair}', [RepairController::class, 'destroy']);
   Route::get('getByNameColor', [ColorTagController::class, 'getByNameColor']);

   Route::get('repair-mv/product', [RepairProductController::class, 'index']);
   Route::get('repair-product-mv/{repairProduct}', [RepairProductController::class, 'show']);
   Route::delete('repair-mv/destroy/{id}', [RepairProductController::class, 'destroy']);

   Route::put('product-repair/{repairProduct}', [RepairProductController::class, 'update']);
   Route::delete('product-repair/{repairProduct}', [RepairProductController::class, 'destroy']);

   Route::get('new_products/{new_product}', [NewProductController::class, 'show']);
   Route::get('new_products', [NewProductController::class, 'index']);

   Route::get('getProductRepair', [RepairController::class, 'getProductRepair']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Admin Kasir'])->group(function () {
   //=========================================== outbound ==========================================================

   //migrate
   Route::resource('migrates', MigrateController::class)->except(['destroy']);
   Route::get('displayMigrate', [MigrateController::class, 'displayMigrate']);
   Route::post('migrate-finish', [MigrateDocumentController::class, 'MigrateDocumentFinish']);
   Route::resource('migrate-documents', MigrateDocumentController::class)->except(['destroy']);

   //sale
   Route::resource('sales', SaleController::class);
   Route::put('/sales/{sale}', [SaleController::class, 'updatePriceSale']);
   Route::put('/update_price_sales/{sale}', [SaleController::class, 'livePriceUpdates']);
   Route::resource('sale-documents', SaleDocumentController::class)->except(['destroy']);
   Route::post('sale-finish', [SaleDocumentController::class, 'saleFinish']);
   Route::get('sale-report', [SaleDocumentController::class, 'combinedReport']);
   Route::get('sale-report-by-product', [SaleDocumentController::class, 'combinedReport']);
   Route::get('sale-products', [SaleController::class, 'products']);

   Route::apiResource('buyers', BuyerController::class)->except(['destroy']);

   Route::get('new_products/{new_product}', [NewProductController::class, 'show']);
   Route::get('new_products', [NewProductController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader'])->group(function () {

   //=========================================== inbound ==========================================================
   //generates file excel -> input data ekspedisi 
   Route::post('/generate', [GenerateController::class, 'processExcelFiles']);
   Route::post('/generate/merge-headers', [GenerateController::class, 'mapAndMergeHeaders']);

   //bulking
   Route::post('/excelOld', [StagingProductController::class, 'processExcelFilesCategoryStaging']);
   Route::post('/bulkingInventory', [NewProductController::class, 'processExcelFilesCategory']);
   // Route::post('/excelOld/merge', [NewProductController::class, 'mapAndMergeHeadersCategory']);
   Route::post('/bulking_tag_warna', [NewProductController::class, 'processExcelFilesTagColor']);


   //=========================================== storage ==========================================================

   //slow moving products 
   //filters product bundle
   Route::get('bundle/filter_product', [ProductFilterController::class, 'index']);
   Route::post('bundle/filter_product/{id}/add', [ProductFilterController::class, 'store']);
   Route::delete('bundle/filter_product/destroy/{id}', [ProductFilterController::class, 'destroy']);

   //bundle
   Route::get('bundle', [BundleController::class, 'index']);
   Route::get('bundle/{bundle}', [BundleController::class, 'show']);
   Route::post('bundle', [ProductBundleController::class, 'store']);
   Route::delete('bundle/{bundle}', [BundleController::class, 'destroy']);

   Route::get('bundle/product', [ProductBundleController::class, 'index']);
   Route::get('product-bundle/{new_product}/{bundle}/add', [ProductBundleController::class, 'addProductBundle']);
   Route::delete('product-bundle/{productBundle}', [ProductBundleController::class, 'destroy']);

   //promo
   Route::get('promo', [PromoController::class, 'index']);
   Route::get('promo/{id}', [PromoController::class, 'show']);
   Route::post('promo', [PromoController::class, 'store']);
   Route::put('promo/{promo}', [PromoController::class, 'update']);
   Route::delete('promo/destroy/{promoId}/{productId}', [PromoController::class, 'destroy']);

   //palet filter
   Route::get('palet/filter_product', [PaletFilterController::class, 'index']);
   Route::post('palet/filter_product/{id}/add', [PaletFilterController::class, 'store']);
   // Route::delete('palet/filter_product/destroy/{id}', [PaletFilterController::class, 'destroy']);

   //palet
   Route::get('palet/display', [PaletController::class, 'display']);
   Route::get('palet', [PaletController::class, 'index']);
   Route::get('palet/{palet}', [PaletController::class, 'show']);
   Route::post('palet', [PaletProductController::class, 'store']);
   Route::delete('palet/{palet}', [PaletController::class, 'destroy']);
   Route::put('palet/{palet}', [PaletController::class, 'update']);

   Route::get('product-palet/{new_product}/{palet}/add', [PaletProductController::class, 'addProductPalet']);
   Route::delete('product-palet/{paletProduct}', [PaletProductController::class, 'destroy']);

   //categories discount
   Route::resource('categories', CategoryController::class)->except(['destroy']);

   //colortags diskon
   Route::resource('color_tags', ColorTagController::class)->except(['destroy']);

   //product
   Route::post('new_products', [NewProductController::class, 'store']);

   Route::put('new_products/{new_product}', [NewProductController::class, 'update']);
   Route::get('new_products/{new_product}', [NewProductController::class, 'show']);
   // Route::delete('new_products/{new_product}', [NewProductController::class, 'destroy']);

   //migrate
   Route::resource('migrates', MigrateController::class)->except(['destroy']);
   Route::put('migrate-add/{new_product}', [MigrateController::class, 'addMigrate']);
   Route::post('migrate-finish', [MigrateDocumentController::class, 'MigrateDocumentFinish']);
   Route::resource('migrate-documents', MigrateDocumentController::class)->except(['destroy']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Crew,Admin Kasir'])->group(function () {

   // =========================================== Dashboard ==================================================
   Route::get('dashboard', [DashboardController::class, 'index']);
   Route::get('dashboard2', [DashboardController::class, 'index2']);
   Route::get('dashboard/summary-transaction', [DashboardController::class, 'summaryTransaction']);
   Route::get('dashboard/summary-sales', [DashboardController::class, 'summarySales']);
   Route::get('dashboard/storage-report', [DashboardController::class, 'storageReport']);
   Route::get('dashboard/monthly-analytic-sales', [DashboardController::class, 'monthlyAnalyticSales']);
   Route::get('dashboard/yearly-analytic-sales', [DashboardController::class, 'yearlyAnalyticSales']);
   Route::get('dashboard/general-sales', [DashboardController::class, 'generalSale']);
   Route::get('generateExcel_StorageReport', [DashboardController::class, 'generateExcel_StorageReport']);
   Route::get('dashboard/analytic-slow-moving', [DashboardController::class, 'analyticSlowMoving']);
   Route::get('export/product-expired', [DashboardController::class, 'productExpiredExport']);


   // =========================================== Category ==================================================
   Route::get('list-category', [CategoryController::class, 'index']);

   //=========================================== inbound ==========================================================

   //product old
   Route::resource('product_olds', ProductOldController::class)->except(['destroy']);
   // Route::delete('delete-all-products-old', [ProductOldController::class, 'deleteAll']);
   Route::get('product_olds-search', [ProductOldController::class, 'searchByDocument']);
   Route::get('search_barcode_product', [ProductOldController::class, 'searchByBarcode']);

   //product approve
   Route::resource('product-approves', ProductApproveController::class);
   Route::get('productApprovesByDoc', [ProductApproveController::class, 'searchByDocument']);
   // Route::delete('delete_all_by_codeDocument', [ProductApproveController::class, 'delete_all_by_codeDocument']);


   //new product (hasil scan)
   Route::get('new_products', [NewProductController::class, 'index']);
   Route::get('get-latestPrice', [NewProductController::class, 'getLatestPrice']); //baru
   Route::post('new_products', [NewProductController::class, 'store']);
   Route::post('changeBarcodeDocument', [DocumentController::class, 'changeBarcodeDocument']);
   // Route::delete('deleteCustomBarcode', [DocumentController::class, 'deleteCustomBarcode']);

   Route::get('countColor', [NewProductController::class, 'totalPerColor']); //baru

   //slow moving products 
   //filters product bundle
   Route::get('bundle/filter_product', [ProductFilterController::class, 'index']);
   Route::post('bundle/filter_product/{id}/add', [ProductFilterController::class, 'store']);
   Route::delete('bundle/filter_product/destroy/{id}', [ProductFilterController::class, 'destroy']);

   //bundle
   Route::get('bundle', [BundleController::class, 'index']);
   Route::get('bundle/{bundle}', [BundleController::class, 'show']);
   Route::post('bundle', [ProductBundleController::class, 'store']);
   // Route::delete('bundle/{bundle}', [BundleController::class, 'destroy']);

   Route::get('bundle/product', [ProductBundleController::class, 'index']);
   // Route::delete('bundle/destroy/{id}', [ProductBundleController::class, 'destroy']);

   //palet filter
   Route::get('palet/filter_product', [PaletFilterController::class, 'index']);
   Route::post('palet/filter_product/{id}/add', [PaletFilterController::class, 'store']);
   // Route::delete('palet/filter_product/destroy/{id}', [PaletFilterController::class, 'destroy']);

   //palet
   Route::get('palet/display', [PaletController::class, 'display']);
   Route::get('palet', [PaletController::class, 'index']);
   Route::get('palet/{palet}', [PaletController::class, 'show']);
   Route::post('palet', [PaletProductController::class, 'store']);
   // Route::delete('palet/{palet}', [PaletController::class, 'destroy']);

   // Route::delete('/delete-all-new-products', [NewProductController::class, 'deleteAll']);
   Route::get('new_product/cronjob/expired', [NewProductController::class, 'expireProducts']);
   Route::get('new_product/expired', [NewProductController::class, 'listProductExp']);
   Route::get('new_product/display-expired', [NewProductController::class, 'listProductExpDisplay']);
   Route::post('new_product/excelImport', [NewProductController::class, 'excelImport']);
   Route::get('/new_product/document', [NewProductController::class, 'byDocument']);

   //document
   Route::resource('/documents', DocumentController::class)->except(['destroy']);
   // Route::delete('/delete-all-documents', [DocumentController::class, 'deleteAll']);
   Route::get('/documentDone', [DocumentController::class, 'documentDone']);
   Route::get('/documentInProgress', [DocumentController::class, 'documentInProgress']);

   //categories discount
   Route::get('categories', [CategoryController::class, 'index']);

   //colortags diskon
   Route::get('color_tags', [ColorTagController::class, 'index']);
   Route::get('product_byColor', [NewProductController::class, 'getTagColor']);
   Route::get('product_byCategory', [NewProductController::class, 'getByCategory']);

   //riwayat
   Route::resource('historys', RiwayatCheckController::class)->except(['destroy']);
   Route::get('riwayat-document/code_document', [RiwayatCheckController::class, 'getByDocument']);
   Route::post('history/exportToExcel', [RiwayatCheckController::class, 'exportToExcel']);

   Route::resource('notifications', NotificationController::class)->except(['destroy']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Admin Kasir'])->group(function () {
   Route::post('addStagingToSpv', [StagingProductController::class, 'addStagingToSpv']);
   Route::get('documentsApproveStaging', [StagingProductController::class, 'documentsApproveStaging']);
   Route::get('productStagingByDoc/{code_document}', [StagingProductController::class, 'productStagingByDoc'])
      ->where('code_document', '.*');
   Route::get('documentStagings', [StagingProductController::class, 'documentStagings']);

   //untuk spv me approve staging ke inventory 
   Route::resource('staging_approves', StagingApproveController::class);

   //store nya untuk mindah ke approve staging
   Route::resource('staging_products', StagingProductController::class);
   Route::get('staging/filter_product', [FilterStagingController::class, 'index']);
   Route::post('staging/filter_product/{id}/add', [FilterStagingController::class, 'store']);
   Route::delete('staging/filter_product/destroy/{id}', [FilterStagingController::class, 'destroy']);
});


Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader'])->group(function () {
   Route::post('add_product', [NewProductController::class, 'addProductByAdmin']);
   Route::post('/check-price', [NewProductController::class, 'checkPrice']);
   Route::resource('destinations', DestinationController::class)->except(['destroy']);
   Route::get('/spv/approve/{notificationId}', [NotificationController::class, 'approveTransaction'])->name('admin.approve');

   //export data by menu
   Route::post('export_product_byCategory', [NewProductController::class, 'export_product_byCategory']);
   Route::post('exportCategory', [CategoryController::class, 'exportCategory']);
   Route::post('exportBundlesDetail/{id}', [BundleController::class, 'exportBundlesDetail']);
   Route::post('exportProductExpired', [NewProductController::class, 'export_product_expired']);
   Route::post('exportpaletsDetail/{id}', [PaletController::class, 'exportpaletsDetail']);
   Route::post('exportRepairDetail/{id}', [RepairController::class, 'exportRepairDetail']);
   Route::post('exportMigrateDetail/{id}', [MigrateDocumentController::class, 'exportMigrateDetail']);
   Route::post('exportBuyers', [BuyerController::class, 'exportBuyers']);
   Route::post('exportUsers', [UserController::class, 'exportUsers']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Crew,Reparasi,Team leader'])->group(function () {
   Route::get('notificationByRole', [NotificationController::class, 'getNotificationByRole']);
   Route::get('documents-approve', [ProductApproveController::class, 'documentsApprove']);
   Route::get('product-approveByDoc/{code_document}', [ProductApproveController::class, 'productsApproveByDoc'])
      ->where('code_document', '.*');
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv'])->group(function () {
   //spv approve staging
   Route::resource('staging_approves', StagingApproveController::class);
   Route::get('stagingTransactionApprove', [StagingApproveController::class, 'stagingTransaction']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin'])->group(function () {

   Route::post('register', [AuthController::class, 'register']);
   Route::resource('users', UserController::class)->except(['store']);
   Route::resource('roles', RoleController::class);
   Route::post('sale-document/add-product', [SaleDocumentController::class, 'addProductSaleInDocument']);
   Route::delete('sale-document/{sale_document}/{sale}/delete-product', [SaleDocumentController::class, 'deleteProductSaleInDocument']);
   Route::get('generateApikey/{userId}', [UserController::class, 'generateApiKey']);

   // Tombol delete 
   Route::delete('migrates/{migrate}', [MigrateController::class, 'destroy']);
   Route::delete('migrate-documents/{migrateDocument}', [MigrateDocumentController::class, 'destroy']);
   Route::delete('sale-documents/{saleDocument}', [SaleDocumentController::class, 'destroy']);
   Route::delete('buyers/{buyer}', [BuyerController::class, 'destroy']);
   Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
   Route::delete('color_tags/{color_tag}', [ColorTagController::class, 'destroy']);
   Route::delete('product_olds/{product_old}', [ProductOldController::class, 'destroy']);
   Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
   Route::delete('historys/{history}', [RiwayatCheckController::class, 'destroy']);
   Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);
   Route::delete('destinations/{destination}', [DestinationController::class, 'destroy']);
   Route::delete('bundle/qcd/{bundleQcd}/destroy', [BundleQcdController::class, 'destroyBundle']);
   Route::delete('new_products/{new_product}', [NewProductController::class, 'destroy']);
   Route::delete('delete-all-products-old', [ProductOldController::class, 'deleteAll']);
   Route::delete('delete_all_by_codeDocument', [ProductApproveController::class, 'delete_all_by_codeDocument']);
   Route::delete('deleteCustomBarcode', [DocumentController::class, 'deleteCustomBarcode']);
   Route::delete('delete-all-new-products', [NewProductController::class, 'deleteAll']);
   Route::delete('delete-all-documents', [DocumentController::class, 'deleteAll']);
});


//login
Route::post('login', [AuthController::class, 'login']);

//export urgent
Route::post('exportBundles', [BundleController::class, 'exportBundles']);
Route::post('exportStaging', [StagingApproveController::class, 'export_product_staging']);
Route::post('exportNp', [NewProductController::class, 'exportNewProducts']);

Route::delete('cleargenerate', [GenerateController::class, 'deleteAll']);

Route::delete('deleteAll', [GenerateController::class, 'deleteAllData']);

Route::get('countBast', [StagingApproveController::class, 'countBast']);
Route::get('checkDuplicates', [ProductApproveController::class, 'checkDuplicates']);

// route untuk cek koneksi
Route::get('cek-ping-with-image', [CheckConnectionController::class, 'checkPingWithImage']);


Route::post('generateExcel_injectDisplay', [GenerateController::class, 'uploadExcel']);
Route::post('filter-cleanExcel-injectDisplay', [GenerateController::class, 'filterAndCleanExcelOld']);
Route::post('injectDisplay', [GenerateController::class, 'insertCleanedData']);

//download template
Route::post('downloadTemplate', [GenerateController::class, 'exportTemplaye']);
Route::get('getCategoryNull', [SaleController::class, 'getCategoryNull']);


//collab mtc
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Crew,Developer'])->group(function () {
   //=========================================== Api For Bulky ==========================================================
   Route::resource('product-brands', ProductBrandController::class);
   Route::resource('product-conditions', ProductConditionController::class);
   Route::resource('product-statuses', ProductStatusController::class);
   Route::resource('palet-brands', paletBrandController::class)->except(['update']);
   Route::put('palet-brands/{palet_id}', [paletBrandController::class, 'update'])->name('palet-brands.update');
   Route::resource('palet-images', PaletImageController::class)->except(['update', 'show']);
   Route::put('palet-images/{palet_id}', [PaletImageController::class, 'update'])->name('palet-images.update');
   Route::get('palet-images/{palet_id}', [PaletImageController::class, 'show'])->name('palet-images.show');
   Route::get('palets', [PaletController::class, 'index']);
   Route::get('palets-detail/{palet}', [PaletController::class, 'show']);
   Route::put('palets/{palet}', [PaletController::class, 'update']);
   Route::post('addPalet', [PaletController::class, 'store']);
   Route::delete('palets/{palet}', [PaletController::class, 'destroy']);

   //================================================product-collab======================================================

   //inbound-collab
   Route::post('addProduct', [NewProductController::class, 'addProductThirdParty']);
   Route::post('addProductById/{id}', [NewProductController::class, 'addProductById']);

   //get
   Route::get('productBycategory', [NewProductController::class, 'getByCategory']);
   Route::get('list-categories', [CategoryController::class, 'index']);
});

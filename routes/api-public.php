<?php

use App\Http\Controllers\Outbound\Lusi\CargoNewController;
use App\Http\Controllers\Outbound\Lusi\PublicCategoryController;
use App\Http\Controllers\Outbound\Lusi\PublicColorTagController;
use App\Http\Controllers\Outbound\Lusi\MigrasiController;
use App\Http\Controllers\Outbound\Lusi\PublicTaxController;
use App\Http\Controllers\Outbound\Lusi\PublicUserController;
use App\Http\Controllers\Outbound\Lusi\PublicVoucherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
|
| Route di file ini didaftarkan sebelum routes/api.php supaya endpoint
| public yang spesifik tidak ketimpa route generik yang memakai auth.
|
*/

Route::get('bulky-documents/not-sale', [CargoNewController::class, 'getPaletBelumDikasihHarga']);
Route::get('bulky-documents/ready-sale', [CargoNewController::class, 'getPaletSudahDikasihHarga']);
Route::post('sync-b2b/{idCargo}', [CargoNewController::class, 'updateSyncCargo']);
Route::post('sale-b2b/{idCargo}/sold', [CargoNewController::class, 'updateSoldCargo']);

// Public API tanpa auth - list data penting
Route::get('public/users', [PublicUserController::class, 'index']);
Route::get('public/categories', [PublicCategoryController::class, 'index']);
Route::get('public/color-tags', [PublicColorTagController::class, 'index']);
Route::get('public/taxes', [PublicTaxController::class, 'index']);
Route::get('public/vouchers', [PublicVoucherController::class, 'index']);

// Public API rank (class) & buyer
Route::get('public/ranks', [MigrasiController::class, 'ranks']);
Route::get('public/buyers', [MigrasiController::class, 'buyers']);
Route::get('public/suppliers', [MigrasiController::class, 'suppliers']);
Route::get('public/buyer-vouchers', [MigrasiController::class, 'buyerVouchers']);
Route::get('public/staging-products', [MigrasiController::class, 'stagingProducts']);
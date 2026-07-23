<?php

use App\Http\Controllers\Outbound\Lusi\CargoNewController;
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
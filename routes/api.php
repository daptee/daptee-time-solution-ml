<?php

use App\Http\Controllers\MercadoLibreController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/notification', [MercadoLibreController::class, 'handleWebhook']);
Route::post('/publication/update/price', [MercadoLibreController::class, 'update_publication_price']);
Route::post('/publication/update/status', [MercadoLibreController::class, 'update_publication_status']);
Route::post('/publication/update/stock', [MercadoLibreController::class, 'update_publication_stock']);
Route::post('/publication/upload/invoice', [MercadoLibreController::class, 'upload_publication_invoice']);
Route::get('/test_api', [MercadoLibreController::class, 'test_api']);
Route::get('/test_get_users', [MercadoLibreController::class, 'test_get_users']);

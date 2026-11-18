<?php

use App\Domains\Contacts\Http\Controllers\Company\AresLookupController;
use App\Domains\Contacts\Http\Controllers\Company\CustomersController;
use App\Domains\Contacts\Http\Controllers\Company\CustomerStatsController;
use Illuminate\Support\Facades\Route;

// Collection helpers precede the resource, so literal segments cannot be read as customer keys.
Route::prefix('customers')->group(function () {
    Route::post('delete', [CustomersController::class, 'delete']);
    Route::get('ares/{ico}', AresLookupController::class)->middleware('throttle:30,1');
    Route::get('{customer}/stats', CustomerStatsController::class);
});

// Contact CRUD, as a full resource rather than an API one — the create and
// edit routes stay in the table even though the SPA never calls them.
Route::resources([
    'customers' => CustomersController::class,
]);

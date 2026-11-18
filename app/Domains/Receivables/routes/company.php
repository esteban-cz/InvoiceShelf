<?php

use App\Domains\Receivables\Http\Controllers\Company\CreditAllocationsController;
use App\Domains\Receivables\Http\Controllers\Company\FioConnectionsController;
use App\Domains\Receivables\Http\Controllers\Company\FioTransactionsController;
use App\Domains\Receivables\Http\Controllers\Company\PaymentMethodsController;
use App\Domains\Receivables\Http\Controllers\Company\PaymentsController;
use Illuminate\Support\Facades\Route;

Route::prefix('fio')->where(['connection' => '[0-9]+', 'transaction' => '[0-9]+'])->group(function (): void {
    Route::get('connections', [FioConnectionsController::class, 'index']);
    Route::post('connections', [FioConnectionsController::class, 'store']);
    Route::patch('connections/{connection}', [FioConnectionsController::class, 'update']);
    Route::delete('connections/{connection}', [FioConnectionsController::class, 'destroy']);
    Route::post('connections/{connection}/sync', [FioConnectionsController::class, 'sync'])->middleware('throttle:10,1');
    Route::get('transactions', [FioTransactionsController::class, 'index']);
    Route::get('transactions/{transaction}/candidates', [FioTransactionsController::class, 'candidates']);
    Route::patch('transactions/{transaction}', [FioTransactionsController::class, 'update']);
});

Route::post('customers/{customer}/credit-allocations', [CreditAllocationsController::class, 'store']);
Route::get('/payments/{payment}/send/preview', [PaymentsController::class, 'sendPreview']);
Route::post('/payments/{payment}/send', [PaymentsController::class, 'send']);
Route::put('/payments/{payment}/allocations', [PaymentsController::class, 'replaceAllocations']);
Route::post('payments/delete', [PaymentsController::class, 'delete']);

Route::apiResources([
    'payments' => PaymentsController::class,
    'payment-methods' => PaymentMethodsController::class,
]);

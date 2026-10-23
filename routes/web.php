<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventories\InventoryController;
use App\Http\Controllers\Inventories\WarehouseController;
use App\Http\Controllers\Inventories\WarehouseLocationController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('inventories', [InventoryController::class, 'index'])->name('inventories.index');
        Route::post('inventories', [InventoryController::class, 'store'])->name('inventories.store');
        Route::patch('inventories/{inventoryItem}', [InventoryController::class, 'update'])->name('inventories.update');
        Route::delete('inventories/{inventoryItem}', [InventoryController::class, 'destroy'])->name('inventories.destroy');
        Route::post('inventories/{inventoryItem}/stock', [InventoryController::class, 'stock'])->name('inventories.stock');
        Route::get('inventories/{inventoryItem}/movements', [InventoryController::class, 'movements'])->name('inventories.movements');
        Route::post('inventories/{inventoryItem}/images', [InventoryController::class, 'updateImages'])->name('inventories.images.update');

        Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::patch('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');
        Route::post('warehouses/{warehouse}/locations', [WarehouseLocationController::class, 'store'])->name('warehouses.locations.store');
        Route::patch('warehouses/{warehouse}/locations/{location}', [WarehouseLocationController::class, 'update'])->name('warehouses.locations.update');
        Route::delete('warehouses/{warehouse}/locations/{location}', [WarehouseLocationController::class, 'destroy'])->name('warehouses.locations.destroy');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';

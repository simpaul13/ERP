<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventories\InventoryController;
use App\Http\Controllers\Inventories\InventoryScanController;
use App\Http\Controllers\Inventories\WarehouseController;
use App\Http\Controllers\Inventories\WarehouseLocationController;
use App\Http\Controllers\Purchases\PurchaseOrderController;
use App\Http\Controllers\Sales\SalesOrderController;
use App\Http\Controllers\Suppliers\SupplierController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
        Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->name('purchase-orders.submit');
        Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
        Route::post('purchase-orders/{purchaseOrder}/return', [PurchaseOrderController::class, 'returnGoods'])->name('purchase-orders.return');
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
        Route::get('inventories', [InventoryController::class, 'index'])->name('inventories.index');
        Route::get('sales-orders', [SalesOrderController::class, 'index'])->name('sales-orders.index');
        Route::post('sales-orders', [SalesOrderController::class, 'store'])->name('sales-orders.store');
        Route::get('sales-orders/{salesOrder}', [SalesOrderController::class, 'show'])->name('sales-orders.show');
        Route::post('sales-orders/{salesOrder}/reserve', [SalesOrderController::class, 'reserve'])->name('sales-orders.reserve');
        Route::post('sales-orders/{salesOrder}/fulfill', [SalesOrderController::class, 'fulfill'])->name('sales-orders.fulfill');
        Route::post('sales-orders/{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])->name('sales-orders.cancel');
        Route::post('sales-orders/{salesOrder}/returns', [SalesOrderController::class, 'returnGoods'])->name('sales-orders.returns.store');
        Route::post('sales-orders/{salesOrder}/refunds', [SalesOrderController::class, 'refund'])->name('sales-orders.refunds.store');
        Route::patch('sales-orders/{salesOrder}/refunds/{salesRefund}', [SalesOrderController::class, 'updateRefund'])->name('sales-orders.refunds.update');
        Route::get('inventory-scans', [InventoryScanController::class, 'index'])->name('inventory-scans.index');
        Route::post('inventory-scans', [InventoryScanController::class, 'store'])->name('inventory-scans.store');
        Route::get('inventories/{inventoryItem}', [InventoryController::class, 'show'])->name('inventories.show');
        Route::post('inventories', [InventoryController::class, 'store'])->name('inventories.store');
        Route::patch('inventories/{inventoryItem}', [InventoryController::class, 'update'])->name('inventories.update');
        Route::delete('inventories/{inventoryItem}', [InventoryController::class, 'destroy'])->name('inventories.destroy');
        Route::post('inventories/{inventoryItem}/stock', [InventoryController::class, 'stock'])->name('inventories.stock');
        Route::get('inventories/{inventoryItem}/movements', [InventoryController::class, 'movements'])->name('inventories.movements');
        Route::post('inventories/{inventoryItem}/images', [InventoryController::class, 'updateImages'])->name('inventories.images.update');

        Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->name('warehouses.show');
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

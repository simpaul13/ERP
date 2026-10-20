<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventories\InventoryActivityController;
use App\Http\Controllers\Inventories\InventoryController;
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
        Route::post('inventories/{inventoryItem}/images', [InventoryController::class, 'updateImages'])->name('inventories.images.update');
        Route::post('inventories/activity', InventoryActivityController::class)->name('inventories.activity');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';

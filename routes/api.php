<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashDrawerController;
use App\Http\Controllers\Api\CashMovementController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\FloorController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\KitchenController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\ModifierGroupController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\RestaurantTableController;
use App\Http\Controllers\Api\PublicOrderingController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\ShiftScheduleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;


Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');


Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');


Route::prefix('public')->group(function () {
    Route::middleware('throttle:public-read')->group(function () {
        Route::get('/info', [PublicOrderingController::class, 'info']);
        Route::get('/menu', [PublicOrderingController::class, 'menu']);
        Route::get('/tables/{token}', [PublicOrderingController::class, 'table']);
        Route::get('/orders/{token}', [PublicOrderingController::class, 'track']);
    });
    Route::post('/orders', [PublicOrderingController::class, 'store'])->middleware('throttle:public-orders');
});


Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
});


Route::middleware(['auth:sanctum', 'active', 'password.changed'])->group(function () {

 
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::get('/menu-items', [MenuItemController::class, 'index']);
    Route::get('/menu-items/{menuItem}', [MenuItemController::class, 'show']);
    Route::middleware('permission:menu.manage')->group(function () {
        Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
        Route::apiResource('menu-items', MenuItemController::class)->except(['index', 'show']);
        Route::post('/menu-items/{menuItem}/image', [MenuItemController::class, 'uploadImage']);
    });

    Route::apiResource('tables', RestaurantTableController::class)
        ->parameters(['tables' => 'restaurantTable'])
        ->only(['index', 'show']);
    Route::middleware('permission:tables.manage')->group(function () {
        Route::apiResource('tables', RestaurantTableController::class)
            ->parameters(['tables' => 'restaurantTable'])
            ->except(['index', 'show']);
  
        Route::get('/tables/{restaurantTable}/qr', [RestaurantTableController::class, 'qr']);
        Route::post('/tables/{restaurantTable}/qr/rotate', [RestaurantTableController::class, 'rotateQr']);
    });


    Route::get('/floors', [FloorController::class, 'index']);
    Route::get('/floors/{floor}', [FloorController::class, 'show']);
    Route::middleware('permission:tables.manage')->group(function () {
        Route::post('/floors', [FloorController::class, 'store']);
        Route::put('/floors/{floor}', [FloorController::class, 'update']);
        Route::delete('/floors/{floor}', [FloorController::class, 'destroy']);
        Route::put('/tables/{restaurantTable}/position', [FloorController::class, 'updatePosition']);
        Route::post('/tables/positions/bulk', [FloorController::class, 'bulkUpdatePositions']);
    });


    Route::get('/currencies', [CurrencyController::class, 'index']);


    Route::middleware('permission:orders.view')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
    });

    Route::middleware('permission:orders.manage')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::get('/coupons', [CouponController::class, 'index']);
        Route::get('/coupons/{coupon}', [CouponController::class, 'show']);
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::get('/payments/{payment}', [PaymentController::class, 'show']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
        Route::post('/coupons/validate', [CouponController::class, 'validateCoupon']);
        Route::post('/orders', [OrderController::class, 'store'])->middleware('idempotent');
        Route::put('/orders/{order}', [OrderController::class, 'update']);
        Route::patch('/orders/{order}', [OrderController::class, 'update']);
        Route::post('/payments', [PaymentController::class, 'store'])->middleware('idempotent');
        Route::put('/payments/{payment}', [PaymentController::class, 'update']);
        Route::patch('/payments/{payment}', [PaymentController::class, 'update']);
    });

    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.manage');
    Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->middleware('permission:orders.delete');
    Route::delete('/payments/{payment}', [PaymentController::class, 'destroy'])->middleware('permission:payments.manage');

    Route::middleware('permission:coupons.manage')->group(function () {
        Route::post('/coupons', [CouponController::class, 'store']);
        Route::put('/coupons/{coupon}', [CouponController::class, 'update']);
        Route::patch('/coupons/{coupon}', [CouponController::class, 'update']);
        Route::delete('/coupons/{coupon}', [CouponController::class, 'destroy']);
    });

   
    Route::middleware('permission:reservations.manage')->group(function () {
        Route::apiResource('reservations', ReservationController::class);
        Route::post('/reservations/{reservation}/confirm', [ReservationController::class, 'confirm']);
        Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
        Route::post('/reservations/{reservation}/complete', [ReservationController::class, 'complete']);
        Route::post('/reservations/{reservation}/no-show', [ReservationController::class, 'noShow']);
    });

   
    Route::middleware('permission:orders.manage,reviews.approve')->group(function () {
        Route::get('/reviews', [ReviewController::class, 'index']);
        Route::get('/reviews/{review}', [ReviewController::class, 'show']);
        Route::post('/reviews', [ReviewController::class, 'store']);
    });
   
    Route::get('/menu-items/{menuItem}/rating-summary', [ReviewController::class, 'ratingSummary'])
        ->middleware('permission:orders.manage,reviews.approve,menu.manage');
    Route::middleware('permission:reviews.approve')->group(function () {
        Route::put('/reviews/{review}', [ReviewController::class, 'update']);
        Route::patch('/reviews/{review}', [ReviewController::class, 'update']);
        Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);
        Route::post('/reviews/{review}/approve', [ReviewController::class, 'approve']);
    });


    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{notification}/mark-read', [NotificationController::class, 'markAsRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

 
    Route::middleware('permission:kitchen.manage')->group(function () {
        Route::get('/kitchen/queue', [KitchenController::class, 'queue']);
        Route::post('/kitchen/orders/{order}/start', [KitchenController::class, 'start']);
        Route::post('/kitchen/orders/{order}/ready', [KitchenController::class, 'ready']);
        Route::post('/kitchen/orders/{order}/serve', [KitchenController::class, 'serve']);
    });

  
    Route::middleware('permission:deliveries.manage')->group(function () {
        Route::get('/deliveries', [DeliveryController::class, 'index']);
        Route::post('/orders/{order}/delivery-status', [DeliveryController::class, 'updateStatus']);
        Route::post('/orders/{order}/assign-driver', [DeliveryController::class, 'assignDriver'])
            ->middleware('permission:orders.manage');
    });

  
    Route::middleware('permission:menu.manage')->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock']);
        Route::post('/inventory/{menuItem}/restock', [InventoryController::class, 'restock']);
        Route::put('/inventory/{menuItem}/settings', [InventoryController::class, 'updateSettings']);
    });

   
    Route::get('/customers/{customer}/loyalty', [LoyaltyController::class, 'show'])->middleware('permission:orders.manage');
    
    Route::post('/customers/{customer}/loyalty/adjust', [LoyaltyController::class, 'adjust'])->middleware('permission:customers.manage');

   
    Route::get('/menu-items/{menuItem}/modifier-groups', [ModifierGroupController::class, 'index']);
    Route::middleware('permission:menu.manage')->group(function () {
        Route::post('/menu-items/{menuItem}/modifier-groups', [ModifierGroupController::class, 'store']);
        Route::put('/menu-items/{menuItem}/modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'update']);
        Route::delete('/menu-items/{menuItem}/modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'destroy']);
        Route::post('/menu-items/{menuItem}/modifier-groups/{modifierGroup}/modifiers', [ModifierGroupController::class, 'storeModifier']);
        Route::put('/menu-items/{menuItem}/modifier-groups/{modifierGroup}/modifiers/{modifier}', [ModifierGroupController::class, 'updateModifier']);
        Route::delete('/menu-items/{menuItem}/modifier-groups/{modifierGroup}/modifiers/{modifier}', [ModifierGroupController::class, 'destroyModifier']);
    });

  
    Route::middleware('permission:drawer.manage_own,drawer.manage_all')->group(function () {
        Route::get('/cash-drawers', [CashDrawerController::class, 'index']);
        Route::get('/cash-drawers/current', [CashDrawerController::class, 'current']); 
        Route::get('/cash-drawers/{drawer}', [CashDrawerController::class, 'show']);
        Route::post('/cash-drawers/open', [CashDrawerController::class, 'open'])->middleware('idempotent');
        Route::post('/cash-drawers/{drawer}/close', [CashDrawerController::class, 'close'])->middleware('idempotent');
       
        Route::get('/cash-drawers/{drawer}/movements', [CashMovementController::class, 'index']);
        Route::post('/cash-drawers/{drawer}/movements', [CashMovementController::class, 'store'])->middleware('idempotent');
        Route::delete('/cash-drawers/{drawer}/movements/{movement}', [CashMovementController::class, 'destroy'])->middleware('permission:drawer.manage_all');
    });

  
    Route::get('/refunds', [RefundController::class, 'index'])->middleware('permission:orders.manage,refunds.approve');
    Route::post('/refunds', [RefundController::class, 'store'])->middleware('permission:orders.manage');
    Route::get('/refunds/{refund}', [RefundController::class, 'show'])->middleware('permission:orders.manage,refunds.approve');
    Route::middleware('permission:refunds.approve')->group(function () {
        Route::post('/refunds/{refund}/approve', [RefundController::class, 'approve']);
        Route::post('/refunds/{refund}/reject', [RefundController::class, 'reject']);
    });

  
    Route::middleware('permission:orders.manage')->group(function () {
        Route::get('/customers/{customer}/favorites', [FavoriteController::class, 'index']);
        Route::post('/customers/{customer}/favorites', [FavoriteController::class, 'store']);
        Route::delete('/customers/{customer}/favorites/{menuItem}', [FavoriteController::class, 'destroy']);
    });

   
    Route::get('/shifts', [ShiftController::class, 'index']);
    Route::get('/shifts/{shift}', [ShiftController::class, 'show']);
    Route::post('/shifts/clock-in', [ShiftController::class, 'clockIn']);
    Route::post('/shifts/clock-out', [ShiftController::class, 'clockOut']);
    
    Route::match(['put', 'patch'], '/shifts/{shift}', [ShiftController::class, 'update'])->middleware('permission:shifts.manage_all');

   
    Route::apiResource('shift-schedules', ShiftScheduleController::class);


    Route::get('/payroll/export', [PayrollController::class, 'export'])->middleware('permission:payroll.manage');
    Route::get('/payroll', [PayrollController::class, 'index']);
    Route::get('/payroll/{payroll}', [PayrollController::class, 'show']);
 
    Route::get('/payroll/{payroll}/payslip', [PayrollController::class, 'payslip']);
    Route::middleware('permission:payroll.manage')->group(function () {
        Route::post('/payroll/generate', [PayrollController::class, 'generate']);
        Route::put('/payroll/{payroll}', [PayrollController::class, 'update']);
        Route::post('/payroll/{payroll}/approve', [PayrollController::class, 'approve']);
        Route::post('/payroll/{payroll}/mark-paid', [PayrollController::class, 'markPaid']);
        Route::delete('/payroll/{payroll}', [PayrollController::class, 'destroy']);
    });

    Route::get('/orders/{order}/invoice', [InvoiceController::class, 'show'])->middleware('permission:invoices.print');


    Route::middleware('permission:reports.export')->group(function () {
        Route::get('/reports/export', [ReportController::class, 'export']);
        Route::get('/reports/z-report/pdf', [ReportController::class, 'zReportPdf']);
    });

    Route::middleware('permission:audit.view')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-logs/filters', [AuditLogController::class, 'filters']); 
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);
    });

   
    Route::middleware('permission:reports.view')->group(function () {
        Route::get('/reports/sales', [ReportController::class, 'sales']);
        Route::get('/reports/z-report', [ReportController::class, 'zReport']);
        Route::get('/reports/top-items', [ReportController::class, 'topItems']);
        Route::get('/reports/revenue-by-category', [ReportController::class, 'revenueByCategory']);
        Route::get('/reports/profit', [ReportController::class, 'profit']);
        Route::get('/reports/staff-performance', [ReportController::class, 'staffPerformance']);
    });

    Route::get('/settings', [SettingController::class, 'index']);

 
    Route::middleware('permission:settings.manage')->group(function () {
        Route::put('/settings', [SettingController::class, 'update']);

        Route::post('/currencies', [CurrencyController::class, 'store']);
        Route::put('/currencies/{currency}', [CurrencyController::class, 'update']);
        Route::delete('/currencies/{currency}', [CurrencyController::class, 'destroy']);
        Route::post('/currencies/{currency}/set-default', [CurrencyController::class, 'setDefault']);
    });

  
    Route::middleware('permission:expenses.view')->group(function () {
        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show']);
    });
    Route::middleware('permission:expenses.manage')->group(function () {
        Route::post('/expenses', [ExpenseController::class, 'store']);
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update']);
        Route::patch('/expenses/{expense}', [ExpenseController::class, 'update']);
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);
    });


    Route::get('/staff-directory', [UserController::class, 'directory'])
        ->middleware('permission:orders.manage,payroll.manage,schedules.manage,shifts.manage_all');

    Route::middleware('role:admin')->group(function () {

    
        Route::apiResource('users', UserController::class);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

   
        Route::get('/permissions', [UserController::class, 'availablePermissions']);
        Route::get('/users/{user}/permissions', [UserController::class, 'showPermissions']);
        Route::put('/users/{user}/permissions', [UserController::class, 'updatePermissions']);

    });

    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

});
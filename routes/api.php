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

// تسجيل الدخول - متاح للجميع بدون حماية، بس محمي بـ throttle حتى نمنع
// محاولات تخمين كلمة السر المتكررة (brute force): أقصى 6 محاولات بالدقيقة لكل IP
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

// استعادة كلمة السر - متاحة بدون تسجيل دخول، نفس منطق الحماية من brute force
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');

// ---------- الواجهة العامة للزبون (بدون تسجيل دخول) ----------
// قائمة الطعام + الطلب من الطاولة (QR) + الطلب الأونلاين + تتبّع الطلب.
// مقفولة افتراضياً (qr_ordering_enabled / online_ordering_enabled بالإعدادات) وكلها بـ throttle.
Route::prefix('public')->group(function () {
    Route::middleware('throttle:public-read')->group(function () {
        Route::get('/info', [PublicOrderingController::class, 'info']);
        Route::get('/menu', [PublicOrderingController::class, 'menu']);
        Route::get('/tables/{token}', [PublicOrderingController::class, 'table']);
        Route::get('/orders/{token}', [PublicOrderingController::class, 'track']);
    });
    Route::post('/orders', [PublicOrderingController::class, 'store'])->middleware('throttle:public-orders');
});

// كل الطرق تحت هاد الجزء محمية - لازم تسجل دخول أول
// هدول التلاتة متاحين حتى لو الموظف لسا لازم يغيّر كلمة السر (must_change_password)،
// لأنو بدونن ما بيقدر يغيّرها ولا يطلع من الحساب.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
});

// باقي الطرق: ممنوعة على أي موظف لسا ما غيّر كلمة السر المؤقتة
Route::middleware(['auth:sanctum', 'active', 'password.changed'])->group(function () {

    // ---------- المنيو والتصنيفات ----------
    // القراءة لأي مستخدم مسجل دخول (الكاشير/الويتر بيحتاجوها)، والكتابة
    // (إضافة/تعديل/حذف/رفع صورة) محصورة بصلاحية menu.manage
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::get('/menu-items', [MenuItemController::class, 'index']);
    Route::get('/menu-items/{menuItem}', [MenuItemController::class, 'show']);
    Route::middleware('permission:menu.manage')->group(function () {
        Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
        Route::apiResource('menu-items', MenuItemController::class)->except(['index', 'show']);
        Route::post('/menu-items/{menuItem}/image', [MenuItemController::class, 'uploadImage']);
    });
    // منحدد اسم الـ route parameter صراحة (restaurantTable) حتى يتطابق
    // مع اسم المتغير فـ RestaurantTableController، وإلا Laravel ما غاديش
    // يقدر يربط الموديل تلقائيًا (implicit route model binding) وغادي
    // توصل دالة show/update/destroy بموديل فارغ بدل الطاولة الحقيقية
    Route::apiResource('tables', RestaurantTableController::class)
        ->parameters(['tables' => 'restaurantTable'])
        ->only(['index', 'show']);
    Route::middleware('permission:tables.manage')->group(function () {
        Route::apiResource('tables', RestaurantTableController::class)
            ->parameters(['tables' => 'restaurantTable'])
            ->except(['index', 'show']);
        // رمز الـ QR تبع الطاولة (الطلب العام): عرضه/توليده، وتغييره لو انكشف
        Route::get('/tables/{restaurantTable}/qr', [RestaurantTableController::class, 'qr']);
        Route::post('/tables/{restaurantTable}/qr/rotate', [RestaurantTableController::class, 'rotateQr']);
    });

    // ---------- خطة الطاولات المرئية (Floor Plan) ----------
    // أي مستخدم مسجل دخول يقدر يشوف الخطة (index/show)، بس التعديل
    // (إضافة/حذف طابق أو تحريك طاولات) محصور بصلاحية tables.manage
    Route::get('/floors', [FloorController::class, 'index']);
    Route::get('/floors/{floor}', [FloorController::class, 'show']);
    Route::middleware('permission:tables.manage')->group(function () {
        Route::post('/floors', [FloorController::class, 'store']);
        Route::put('/floors/{floor}', [FloorController::class, 'update']);
        Route::delete('/floors/{floor}', [FloorController::class, 'destroy']);
        Route::put('/tables/{restaurantTable}/position', [FloorController::class, 'updatePosition']);
        Route::post('/tables/positions/bulk', [FloorController::class, 'bulkUpdatePositions']);
    });

    // ---------- العملات (تعدد العملات بعرض المنيو) ----------
    // أي مستخدم مسجل دخول يشوف قائمة العملات المتاحة (عشان قائمة اختيار
    // بالفرونت)، بس إدارتها محصورة بالأدمن (شوف مجموعة role:admin تحت)
    Route::get('/currencies', [CurrencyController::class, 'index']);

    // ---------- الزبائن / الكوبونات / الطلبات / الدفعات ----------
    // القراءة: orders.view. الإنشاء والتعديل التشغيلي: orders.manage.
    // العمليات الحساسة (حذف زبون، إدارة كوبونات، حذف طلب/دفعة) صلاحيات منفصلة.
    // الطلبات بس لـ orders.view (الشيف والسائق بيحتاجوها). الزبائن والكوبونات والدفعات
    // بيانات حساسة (أرقام هواتف، أكواد خصم، مبالغ) فبتحتاج orders.manage.
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

    // ---------- الحجوزات ----------
    // محصورة بصلاحية reservations.manage (مدير/كاشير/ويتر افتراضيًا): الشيف والسائق ما بيدخلوا
    Route::middleware('permission:reservations.manage')->group(function () {
        Route::apiResource('reservations', ReservationController::class);
        Route::post('/reservations/{reservation}/confirm', [ReservationController::class, 'confirm']);
        Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
        Route::post('/reservations/{reservation}/complete', [ReservationController::class, 'complete']);
        Route::post('/reservations/{reservation}/no-show', [ReservationController::class, 'noShow']);
    });

    // ---------- التقييمات ----------
    // القراءة والإضافة: لمن بيشتغل بالطلبات (orders.manage) أو بيراجع التقييمات.
    // التعديل والحذف والموافقة (moderation) = reviews.approve بس. قبل كان أي موظف (حتى السائق)
    // يقدر يعدّل is_approved أو يحذف أي تقييم.
    Route::middleware('permission:orders.manage,reviews.approve')->group(function () {
        Route::get('/reviews', [ReviewController::class, 'index']);
        Route::get('/reviews/{review}', [ReviewController::class, 'show']);
        Route::post('/reviews', [ReviewController::class, 'store']);
    });
    // ملخص التقييمات بيظهر كمان بتبويب المنيو (menu.manage)
    Route::get('/menu-items/{menuItem}/rating-summary', [ReviewController::class, 'ratingSummary'])
        ->middleware('permission:orders.manage,reviews.approve,menu.manage');
    Route::middleware('permission:reviews.approve')->group(function () {
        Route::put('/reviews/{review}', [ReviewController::class, 'update']);
        Route::patch('/reviews/{review}', [ReviewController::class, 'update']);
        Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);
        Route::post('/reviews/{review}/approve', [ReviewController::class, 'approve']);
    });

    // ---------- الإشعارات ----------
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{notification}/mark-read', [NotificationController::class, 'markAsRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    // ---------- نظام المطبخ (Kitchen Display) ----------
    // محصورة بصلاحية kitchen.manage (شيف/ويتر/كاشير/مدير افتراضيًا، وبتقدر
    // تعطيها أو تسحبها من أي موظف من /users/{user}/permissions)
    Route::middleware('permission:kitchen.manage')->group(function () {
        Route::get('/kitchen/queue', [KitchenController::class, 'queue']);
        Route::post('/kitchen/orders/{order}/start', [KitchenController::class, 'start']);
        Route::post('/kitchen/orders/{order}/ready', [KitchenController::class, 'ready']);
        Route::post('/kitchen/orders/{order}/serve', [KitchenController::class, 'serve']);
    });

    // ---------- التوصيل والسائقين ----------
    // deliveries.manage: عرض طلبات التوصيل وتحديث حالة التسليم (السائق بيشوف ويحدّث
    // طلباته هو بس - شوف DeliveryController). تعيين سائق يحتاج كمان orders.manage.
    Route::middleware('permission:deliveries.manage')->group(function () {
        Route::get('/deliveries', [DeliveryController::class, 'index']);
        Route::post('/orders/{order}/delivery-status', [DeliveryController::class, 'updateStatus']);
        Route::post('/orders/{order}/assign-driver', [DeliveryController::class, 'assignDriver'])
            ->middleware('permission:orders.manage');
    });

    // ---------- إدارة المخزون ----------
    Route::middleware('permission:menu.manage')->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock']);
        Route::post('/inventory/{menuItem}/restock', [InventoryController::class, 'restock']);
        Route::put('/inventory/{menuItem}/settings', [InventoryController::class, 'updateSettings']);
    });

    // ---------- نقاط الولاء ----------
    Route::get('/customers/{customer}/loyalty', [LoyaltyController::class, 'show'])->middleware('permission:orders.manage');
    // تعديل نقاط الولاء يدويًا = منح/سحب رصيد، فمحصور بـ customers.manage
    Route::post('/customers/{customer}/loyalty/adjust', [LoyaltyController::class, 'adjust'])->middleware('permission:customers.manage');

    // ---------- الخيارات الإضافية للأصناف (Modifiers / Add-ons) ----------
    Route::get('/menu-items/{menuItem}/modifier-groups', [ModifierGroupController::class, 'index']);
    Route::middleware('permission:menu.manage')->group(function () {
        Route::post('/menu-items/{menuItem}/modifier-groups', [ModifierGroupController::class, 'store']);
        Route::put('/menu-items/{menuItem}/modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'update']);
        Route::delete('/menu-items/{menuItem}/modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'destroy']);
        Route::post('/menu-items/{menuItem}/modifier-groups/{modifierGroup}/modifiers', [ModifierGroupController::class, 'storeModifier']);
        Route::put('/menu-items/{menuItem}/modifier-groups/{modifierGroup}/modifiers/{modifier}', [ModifierGroupController::class, 'updateModifier']);
        Route::delete('/menu-items/{menuItem}/modifier-groups/{modifierGroup}/modifiers/{modifier}', [ModifierGroupController::class, 'destroyModifier']);
    });

    // ---------- صندوق الكاشير (Cash Drawer) ----------
    // drawer.manage_own = صندوقي، drawer.manage_all = كل الصناديق (الفلترة جوّا الـ controller)
    Route::middleware('permission:drawer.manage_own,drawer.manage_all')->group(function () {
        Route::get('/cash-drawers', [CashDrawerController::class, 'index']);
        Route::get('/cash-drawers/current', [CashDrawerController::class, 'current']); // قبل {drawer}
        Route::get('/cash-drawers/{drawer}', [CashDrawerController::class, 'show']);
        Route::post('/cash-drawers/open', [CashDrawerController::class, 'open'])->middleware('idempotent');
        Route::post('/cash-drawers/{drawer}/close', [CashDrawerController::class, 'close'])->middleware('idempotent');
        // حركات نقدية على الصندوق: مصروف / سحب / إيداع. الحذف (تصحيح غلطة) للمدير بس
        Route::get('/cash-drawers/{drawer}/movements', [CashMovementController::class, 'index']);
        Route::post('/cash-drawers/{drawer}/movements', [CashMovementController::class, 'store'])->middleware('idempotent');
        Route::delete('/cash-drawers/{drawer}/movements/{movement}', [CashMovementController::class, 'destroy'])->middleware('permission:drawer.manage_all');
    });

    // ---------- الاسترجاعات (Refunds) ----------
    // أي مستخدم مسجل دخول فيه يطلب استرجاع (index/store/show)، بس
    // الموافقة/الرفض محصورة بصلاحية refunds.approve (أدمن/مدير افتراضيًا)
    // القراءة لمن بيطلب الاسترجاع (orders.manage) أو بيوافق عليه (refunds.approve)
    Route::get('/refunds', [RefundController::class, 'index'])->middleware('permission:orders.manage,refunds.approve');
    Route::post('/refunds', [RefundController::class, 'store'])->middleware('permission:orders.manage');
    Route::get('/refunds/{refund}', [RefundController::class, 'show'])->middleware('permission:orders.manage,refunds.approve');
    Route::middleware('permission:refunds.approve')->group(function () {
        Route::post('/refunds/{refund}/approve', [RefundController::class, 'approve']);
        Route::post('/refunds/{refund}/reject', [RefundController::class, 'reject']);
    });

    // ---------- المفضّلة عند الزبون (Wishlist) ----------
    Route::middleware('permission:orders.manage')->group(function () {
        Route::get('/customers/{customer}/favorites', [FavoriteController::class, 'index']);
        Route::post('/customers/{customer}/favorites', [FavoriteController::class, 'store']);
        Route::delete('/customers/{customer}/favorites/{menuItem}', [FavoriteController::class, 'destroy']);
    });

    // ---------- الحضور والانصراف (Shifts / Attendance) ----------
    // أي موظف مسجل دخول فيه يسجل حضوره/انصرافه ويشوف ورديات حاله
    Route::get('/shifts', [ShiftController::class, 'index']);
    Route::get('/shifts/{shift}', [ShiftController::class, 'show']);
    Route::post('/shifts/clock-in', [ShiftController::class, 'clockIn']);
    Route::post('/shifts/clock-out', [ShiftController::class, 'clockOut']);
    // تصحيح أوقات وردية (مثلاً وردية انسكرت تلقائياً): للمدير بس
    Route::match(['put', 'patch'], '/shifts/{shift}', [ShiftController::class, 'update'])->middleware('permission:shifts.manage_all');

    // ---------- جدولة الورديات المسبقة (Shift Scheduling) ----------
    // مختلفة عن /shifts فوق: هاي جدولة مسبقة ("وليد شغال بكرا من 9-5")
    // مو تسجيل حضور فعلي. الموظف العادي بيشوف/يأكد جدولته هو بس،
    // صاحب صلاحية schedules.manage (أدمن/مدير افتراضيًا) بيدير جدولة الكل.
    Route::apiResource('shift-schedules', ShiftScheduleController::class);

    // ---------- الرواتب (Payroll) ----------
    // الموظف العادي بيشوف كشوفات راتبه هو بس (index/show بيتحقق من هاد
    // الأمر داخليًا بالكونترولر). توليد/اعتماد/تعديل الكشوفات محصور
    // بصلاحية payroll.manage.
    // export قبل {payroll} وإلا بيتفسّر "export" كـ id
    Route::get('/payroll/export', [PayrollController::class, 'export'])->middleware('permission:payroll.manage');
    Route::get('/payroll', [PayrollController::class, 'index']);
    Route::get('/payroll/{payroll}', [PayrollController::class, 'show']);
    // الموظف لكشفه هو، والمدير للكل (التحقق جوّا الـ controller)
    Route::get('/payroll/{payroll}/payslip', [PayrollController::class, 'payslip']);
    Route::middleware('permission:payroll.manage')->group(function () {
        Route::post('/payroll/generate', [PayrollController::class, 'generate']);
        Route::put('/payroll/{payroll}', [PayrollController::class, 'update']);
        Route::post('/payroll/{payroll}/approve', [PayrollController::class, 'approve']);
        Route::post('/payroll/{payroll}/mark-paid', [PayrollController::class, 'markPaid']);
        Route::delete('/payroll/{payroll}', [PayrollController::class, 'destroy']);
    });

    // ---------- طباعة الفواتير (Printable Invoices) ----------
    // بيرجع PDF جاهز للطباعة لفاتورة الطلب
    Route::get('/orders/{order}/invoice', [InvoiceController::class, 'show'])->middleware('permission:invoices.print');

    // ---------- تصدير التقارير PDF/CSV ----------
    // محمي بصلاحية reports.export (مش لازم تكون role:admin بالضبط،
    // ممكن تمنح صلاحية reports.export لأي مستخدم عن طريق extra_permissions)
    Route::middleware('permission:reports.export')->group(function () {
        Route::get('/reports/export', [ReportController::class, 'export']);
        Route::get('/reports/z-report/pdf', [ReportController::class, 'zReportPdf']);
    });

    // ---------- سجل التدقيق (Audit Log) ----------
    // مين عدل شو وإيمتى - محمي بصلاحية audit.view
    Route::middleware('permission:audit.view')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-logs/filters', [AuditLogController::class, 'filters']); // قبل {auditLog}
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);
    });

    // ---------- التقارير والتحليلات (Reports) ----------
    // نقلناها لصلاحية reports.view بدل ما تكون محصورة بـ role:admin بالضبط
    // (مثال: تقدر تعطي كاشير صلاحية reports.view بس بدون reports.export
    // فيشوف التقارير بس ما يقدر يصدرها - شوف config/permissions.php)
    Route::middleware('permission:reports.view')->group(function () {
        Route::get('/reports/sales', [ReportController::class, 'sales']);
        Route::get('/reports/z-report', [ReportController::class, 'zReport']);
        Route::get('/reports/top-items', [ReportController::class, 'topItems']);
        Route::get('/reports/revenue-by-category', [ReportController::class, 'revenueByCategory']);
        Route::get('/reports/profit', [ReportController::class, 'profit']);
        Route::get('/reports/staff-performance', [ReportController::class, 'staffPerformance']);
    });

    // GET متاحة لأي مستخدم مسجل دخول (بيحتاجها كل التطبيق: اسم المطعم، العملة...)
    // بس التعديل (PUT) يضل محصور بـ admin فقط
    Route::get('/settings', [SettingController::class, 'index']);

    // الإعدادات وإدارة العملات: صلاحية settings.manage (الأدمن دايمًا، وبتقدر تعطيها لغيره)
    Route::middleware('permission:settings.manage')->group(function () {
        Route::put('/settings', [SettingController::class, 'update']);

        Route::post('/currencies', [CurrencyController::class, 'store']);
        Route::put('/currencies/{currency}', [CurrencyController::class, 'update']);
        Route::delete('/currencies/{currency}', [CurrencyController::class, 'destroy']);
        Route::post('/currencies/{currency}/set-default', [CurrencyController::class, 'setDefault']);
    });

    // ---------- المصاريف (Expenses) ----------
    // قبل: role:admin بالضبط، فمدير عنده expenses.manage (افتراضيًا حسب config/permissions.php) كان ياخد 403
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

    // ---------- دليل الموظفين المختصر (للقوائم المنسدلة) ----------
    // id/name/role/phone/is_active بس. لمن بيعيّن سائق أو بيدير رواتب/جداول/حضور
    Route::get('/staff-directory', [UserController::class, 'directory'])
        ->middleware('permission:orders.manage,payroll.manage,schedules.manage,shifts.manage_all');

    // هاي المسارات محمية بـ role:admin بالتحديد (منع تصعيد الصلاحيات: ما حدا غير الأدمن
    // بينشئ موظفين أو بيغيّر الأدوار والصلاحيات)
    Route::middleware('role:admin')->group(function () {

        // ---------- إدارة الموظفين (Staff Management) ----------
        Route::apiResource('users', UserController::class);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

        // ---------- صلاحيات دقيقة لكل موظف (فوق الـ role الأساسي) ----------
        Route::get('/permissions', [UserController::class, 'availablePermissions']);
        Route::get('/users/{user}/permissions', [UserController::class, 'showPermissions']);
        Route::put('/users/{user}/permissions', [UserController::class, 'updatePermissions']);

    });

    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

});
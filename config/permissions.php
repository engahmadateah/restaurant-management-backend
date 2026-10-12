<?php

// نظام صلاحيات دقيق (Fine-grained Permissions) يشتغل فوق الـ role الأساسي.
// role تبع المستخدم (admin/manager/cashier/waiter/chef/driver) بيحدد
// صلاحياته الافتراضية من هون تحت. بعدين ممكن تضيف/تسحب صلاحيات محددة
// لكل مستخدم لحاله من عمودي extra_permissions / revoked_permissions
// بجدول users (شوف App\Models\User::hasPermission()).
return [

    // كل الصلاحيات المتاحة بالنظام (للعرض بالواجهة مثلاً)
    'all' => [
        'reports.view',
        'reports.export',
        'orders.view',
        'orders.manage',
        'invoices.print',
        'expenses.view',
        'expenses.manage',
        'settings.manage',
        'tables.manage',
        'reservations.manage',
        'menu.manage',
        'refunds.approve',
        'reviews.approve',
        'shifts.manage_own',
        'shifts.manage_all',
        'schedules.view_own',
        'schedules.manage',
        'audit.view',
        'payroll.view',
        'payroll.manage',
        // صلاحيات حساسة (حذف/تعديل مالي) - مدير/أدمن فقط افتراضيًا
        'orders.delete',
        'payments.manage',
        'customers.manage',
        'coupons.manage',
        // المطبخ (بدء/جاهز/تسليم) والتوصيل (تعيين سائق/تحديث حالة التسليم)
        'kitchen.manage',
        'deliveries.manage',
        // الصندوق: فتح/إغلاق صندوق الكاشير الشخصي، أو رؤية/إغلاق صناديق الكل
        'drawer.manage_own',
        'drawer.manage_all',
    ],

    // إدارة الموظفين وصلاحياتهم (/users و/permissions) محصورة بدور admin عن قصد،
    // وما بتتعطى كصلاحية: مدير عنده users.manage كان يقدر ينشئ أدمن أو يرفع صلاحيات نفسه.
    // (حسب App\Http\Middleware\CheckRole بـ routes/api.php)

    // الصلاحيات الافتراضية لكل دور. '*' يعني كل الصلاحيات (الأدمن دايمًا
    // عنده كل شي بغض النظر عن هاد الإعداد، شوف User::hasPermission()).
    'defaults' => [
        'admin' => ['*'],

        'manager' => [
            'reports.view', 'reports.export', 'orders.view', 'orders.manage',
            'invoices.print', 'expenses.view', 'expenses.manage', 'tables.manage',
            'menu.manage', 'refunds.approve', 'reviews.approve', 'reservations.manage',
            'shifts.manage_own', 'shifts.manage_all',
            'schedules.view_own', 'schedules.manage', 'audit.view',
            'payroll.view', 'payroll.manage',
            'orders.delete', 'payments.manage', 'customers.manage', 'coupons.manage',
            'kitchen.manage', 'deliveries.manage',
            'drawer.manage_own', 'drawer.manage_all',
        ],

        'cashier' => [
            'orders.view', 'orders.manage', 'invoices.print', 'reservations.manage',
            'shifts.manage_own', 'schedules.view_own',
            'kitchen.manage', 'deliveries.manage',
            'drawer.manage_own',
        ],

        'waiter' => [
            'orders.view', 'orders.manage', 'tables.manage', 'kitchen.manage', 'reservations.manage',
            'shifts.manage_own', 'schedules.view_own',
        ],

        'chef' => [
            'orders.view', 'kitchen.manage',
            'shifts.manage_own', 'schedules.view_own',
        ],

        'driver' => [
            'orders.view', 'deliveries.manage',
            'shifts.manage_own', 'schedules.view_own',
        ],
    ],
];

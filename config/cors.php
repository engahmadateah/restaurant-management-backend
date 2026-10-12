<?php

// قبل: ما كان فيه config/cors.php فكان الافتراضي بيسمح لأي موقع بالعالم يستدعي الـ API.
// هلأ الأصول المسموحة بتجي من CORS_ALLOWED_ORIGINS (مفصولة بفاصلة).
// مثال إنتاج: CORS_ALLOWED_ORIGINS=https://pos.myrestaurant.com
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://localhost:5173'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // الفرونت بيقرأ هالهيدر ليعرف إنو الرد معاد (Idempotency) مش عملية جديدة
    'exposed_headers' => ['Idempotent-Replayed'],

    'max_age' => 600,

    // لازم تكون false لو استخدمت '*' كأصل. فعّلها بس لو الفرونت بيعتمد على cookies (SPA بدون Bearer).
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];

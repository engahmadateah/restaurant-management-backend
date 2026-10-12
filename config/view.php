<?php

return [

    'paths' => [
        resource_path('views'),
    ],

    // realpath() بترجع false إذا المجلد مش موجود، فمنرجع للمسار العادي
    // حتى ما يطلع خطأ "Please provide a valid cache path"
    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views')) ?: storage_path('framework/views')
    ),

];

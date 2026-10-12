<?php

use Illuminate\Http\Request;

// بنمنع طباعة تحذيرات PHP Deprecated بجسم الرد مباشرة (متل تحذير
// PDO::MYSQL_ATTR_SSL_CA اللي بيطلع مع PHP 8.5 بملف config/database.php
// حتى لو مستخدمين SQLite). لو ظلت هاي التحذيرات تنطبع، بتنكسر أي رد JSON
// من الـ API لأنو بتنضاف كنص HTML قبل الـ JSON نفسه.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());

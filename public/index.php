<?php

use Illuminate\Http\Request;


error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

define('LARAVEL_START', microtime(true));


if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}


require __DIR__.'/../vendor/autoload.php';


(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());

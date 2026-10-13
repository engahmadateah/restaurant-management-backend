<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

 
    protected const MAX_PER_PAGE = 100;

   
    protected const ALL_ROWS_CAP = 1000;

  
    protected function perPage(Request $request, int $default = 20): int
    {
        $value = (int) $request->get('per_page', $default);

        return max(1, min($value > 0 ? $value : $default, static::MAX_PER_PAGE));
    }

   
    protected function wantsAll(Request $request): bool
    {
        return $request->get('per_page') === 'all';
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /** أقصى عدد صفوف بصفحة وحدة (per_page=N) */
    protected const MAX_PER_PAGE = 100;

    /** سقف أمان لـ per_page=all: بيرجّع القائمة كاملة بس بحد أقصى هالعدد من الصفوف */
    protected const ALL_ROWS_CAP = 1000;

    /**
     * عدد الصفوف بالصفحة: بين 1 و MAX_PER_PAGE (قبل كان per_page=100000 أو 0 أو سالب
     * يمر كما هو ويسحب الجدول كله أو يرمي خطأ).
     */
    protected function perPage(Request $request, int $default = 20): int
    {
        $value = (int) $request->get('per_page', $default);

        return max(1, min($value > 0 ? $value : $default, static::MAX_PER_PAGE));
    }

    /** هل الطلب بدو القائمة كلها (per_page=all)؟ */
    protected function wantsAll(Request $request): bool
    {
        return $request->get('per_page') === 'all';
    }
}

<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * خطأ قاعدة عمل متوقع (مثلاً: "الكشف معتمد أصلاً"). بيرجع للعميل {message} بكود 422
 * وما بينكتب بسجل الأخطاء لأنو مش عطل بالسيرفر.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }

    /** void (مش false) = اعتبره "تم الإبلاغ عنه" فلا يتسجّل بـ laravel.log */
    public function report(): void
    {
    }
}

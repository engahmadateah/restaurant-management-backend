<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * هاد الميدل وير هو "الحارس" يلي بيتحقق من دور المستخدم
     * قبل ما يسمحله يدخل على أي route محمي فيه.
     *
     * مثال استخدام بالـ routes:
     * Route::middleware('role:admin')->group(...)
     *
     * $roles هي الأدوار المسموحة، ممكن نمرر أكتر من دور:
     * Route::middleware('role:admin,cashier')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // لو ما في مستخدم مسجل دخول أصلاً (نظرياً ما لازم يوصل لهون
        // لأنو auth:sanctum بيتحقق قبله، بس منتحقق للأمان الإضافي)
        if (!$user) {
            return response()->json([
                'message' => 'غير مصرح - لازم تسجل دخول',
            ], 401);
        }

        // لو دور المستخدم مش موجود ضمن الأدوار المسموحة لهاد الـ route
        if (!in_array($user->role, $roles)) {
            return response()->json([
                'message' => 'ما عندك صلاحية توصل لهاد الجزء',
            ], 403);
        }

        // كل شي تمام، خلي الطلب يكمل لل Controller
        return $next($request);
    }
}

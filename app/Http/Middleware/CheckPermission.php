<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * حارس صلاحيات دقيق (Fine-grained permissions)، أدق من CheckRole
     * (يلي بيتحقق بس من الـ role الثابت).
     *
     * استخدام بالـ routes:
     *   Route::middleware('permission:reports.view')->group(...)
     *
     * لو بدك تسمح لأكتر من صلاحية بديلة (أي وحدة فيها تكفي):
     *   Route::middleware('permission:reports.view,reports.export')
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'غير مصرح - لازم تسجل دخول'], 401);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'ما عندك صلاحية توصل لهاد الجزء'], 403);
    }
}

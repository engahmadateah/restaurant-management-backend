<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حساب معطّل (is_active = false) ما بيكمل حتى لو معه توكن لسا ساري.
 * login بيفحص هاد الشي مرة وحدة وقت إصدار التوكن بس، فبدون هاد الميدل وير
 * التوكنات القديمة كانت تضل شغالة بعد التعطيل.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            // منسحب التوكن الحالي كمان حتى ما يضل صالح لو انفعّل الحساب لاحقًا بدون قصد
            $token = $user->currentAccessToken();
            if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
                $token->delete();
            }

            return response()->json(['message' => 'هذا الحساب غير مفعّل'], 403);
        }

        return $next($request);
    }
}

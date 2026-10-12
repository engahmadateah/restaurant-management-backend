<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * موظف انعملّه حساب (أو انصفّرت كلمة سره) من الأدمن لازم يغيّر كلمة السر أول شي.
 * هاد الميدل وير بيقفل كل الـ API قدامه (403 + code) لحد ما يغيّرها، وبالباك اند
 * مش بس بالواجهة، فما حدا يقدر يتجاوزها بطلبات مباشرة بالتوكن.
 * المسارات المستثناة (logout / me / change-password) معرّفة برا هاد الميدل وير بـ routes/api.php.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            return response()->json([
                'message' => 'لازم تغيّر كلمة السر قبل ما تكمّل',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}

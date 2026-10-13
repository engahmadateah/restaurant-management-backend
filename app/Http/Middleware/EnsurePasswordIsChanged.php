<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;


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

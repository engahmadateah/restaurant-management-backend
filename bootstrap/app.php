<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        // بنظام API ما فيه صفحة login لنعمل redirect إلها. الافتراضي كان يستدعي
        // route('login') (مش موجود) فيطلع 500 بدل 401 لما العميل ما يبعت Accept: application/json.
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : '/');

        // بيفعّل throttle:api عالمجموعة الافتراضية لكل مسارات api.php - بيربطها
        // بالحد المسجّل بـ AppServiceProvider::boot() (RateLimiter::for('api', ...))
        $middleware->throttleApi();

        // منسجل الحارس تحت اسم مستعار "role" حتى نقدر نستخدمه
        // بالـ routes متل: ->middleware('role:admin')
        $middleware->alias([
            'role' => CheckRole::class,
            'permission' => CheckPermission::class,
            'active' => EnsureUserIsActive::class,
            'password.changed' => EnsurePasswordIsChanged::class,
            'idempotent' => EnforceIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // كل ردود /api/* بتكون JSON حتى لو العميل ما بعت Accept: application/json.
        // بدون هاد، طلب بدون الهيدر بيحاول يعمل redirect لـ route('login') الغير موجود
        // فبيطلع 500 بدل 401.
        $exceptions->shouldRenderJsonWhen(
            fn ($request, \Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // الـ Resources بترجع نفس شكل الردود القديم (بدون مفتاح data حول الكائن/القائمة)
        JsonResource::withoutWrapping();

        // حد عام لكل الـ API - 120 طلب بالدقيقة لكل مستخدم مسجّل دخول (مميّز
        // بالـ id تبعه)، أو لكل IP لو مو مسجّل دخول بعد (متل صفحة تسجيل الدخول
        // قبل ما ياخد توكن). هاد الحد منفصل عن throttle:6,1 الأشد يلي مطبّق
        // أصلاً على /login و/forgot-password و/reset-password بـ routes/api.php
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // الصفحات العامة للزبون (بدون تسجيل دخول): قراءة المنيو/تتبّع الطلب، وإرسال الطلب (أشد بكتير)
        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('public-orders', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(40)->by($request->ip()),
        ]);
    }
}

<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    
    public function register(): void
    {
        //
    }

 
    public function boot(): void
    {
 
        JsonResource::withoutWrapping();

    
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });


        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('public-orders', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(40)->by($request->ip()),
        ]);
    }
}

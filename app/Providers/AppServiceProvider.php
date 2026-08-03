<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        // Límite por sistema cliente ya autenticado (después de sistema.auth,
        // que deja el sistema resuelto en $request->attributes). Más alto
        // que el límite por IP porque ya sabemos quién es.
        RateLimiter::for('sistema', function (Request $request) {
            $sistema = $request->attributes->get('sistemaCliente');
            $key = $sistema ? 'sistema:' . $sistema->sisid : 'ip:' . $request->ip();

            return Limit::perMinute(120)->by($key);
        });
    }
}

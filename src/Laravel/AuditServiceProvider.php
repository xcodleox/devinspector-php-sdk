<?php

namespace DevInspector\Laravel;

use Illuminate\Support\ServiceProvider;
use DevInspector\AuditCore;

class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Lê as configurações do .env do projeto do usuário
        $apiKey = config('services.devinspector.api_key', env('DEVINSPECTOR_API_KEY'));
        $endpoint = config('services.devinspector.endpoint', env('DEVINSPECTOR_ENDPOINT'));
        $environment = config('services.devinspector.environment', env('APP_ENV', 'production'));

        if ($apiKey) {
            AuditCore::getInstance()->init(
                apiKey: $apiKey,
                endpoint: $endpoint,
                environment: $environment
            );
        }
    }

    public function register(): void
    {
        $this->app->singleton(AuditCore::class, function () {
            return AuditCore::getInstance();
        });
    }
}
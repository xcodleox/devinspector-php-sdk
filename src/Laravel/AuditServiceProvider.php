<?php

namespace DevInspector\Laravel;

use Illuminate\Support\ServiceProvider;
use DevInspector\AuditCore;

class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * Configurações do projeto Laravel.
         *
         * Pode utilizar:
         *
         * config/services.php
         *
         * ou diretamente as variáveis do .env.
         */
        $apiKey = config(
            'services.devinspector.api_key',
            env('DEVINSPECTOR_API_KEY')
        );

        $endpoint = config(
            'services.devinspector.endpoint',
            env('DEVINSPECTOR_ENDPOINT')
        );

        $environment = config(
            'services.devinspector.environment',
            env('APP_ENV', 'production')
        );

        $release = config(
            'services.devinspector.release',
            env('DEVINSPECTOR_RELEASE')
        );

        $slowThresholdMs = config(
            'services.devinspector.slow_threshold_ms',
            env('DEVINSPECTOR_SLOW_THRESHOLD_MS')
        );

        /*
         * Converte o threshold para float quando
         * fornecido através do .env/config.
         */
        if (
            $slowThresholdMs !== null &&
            $slowThresholdMs !== ''
        ) {
            $slowThresholdMs = (float) $slowThresholdMs;
        } else {
            $slowThresholdMs = null;
        }

        if (
            is_string($apiKey) &&
            trim($apiKey) !== ''
        ) {
            AuditCore::getInstance()->initAdvanced(
                $apiKey,
                $endpoint,
                $environment,
                $release,
                $slowThresholdMs
            );
        }
    }

    public function register(): void
    {
        $this->app->singleton(
            AuditCore::class,
            function () {
                return AuditCore::getInstance();
            }
        );
    }
}
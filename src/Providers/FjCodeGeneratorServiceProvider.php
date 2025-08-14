<?php

namespace FjCodeGenerator\Providers;

use FjCodeGenerator\Commands\MakeApiResource;
use FjCodeGenerator\Commands\MakeFilamentResource;
use Illuminate\Support\ServiceProvider;

class FjCodeGeneratorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        // Publicar configuración
        $this->publishes([
            __DIR__ . '/../../config/fj-code-generator.php' => config_path('fj-code-generator.php'),
        ], 'fj-config');

        // Publicar stubs
        $this->publishes([
            __DIR__ . '/../Stubs' => base_path('stubs/fj'),
        ], 'fj-stubs');

        // Registrar comandos
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeApiResource::class,
                MakeFilamentResource::class,
            ]);
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/fj-code-generator.php',
            'fj-code-generator'
        );
    }
}

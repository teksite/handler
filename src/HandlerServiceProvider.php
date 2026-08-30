<?php

namespace Teksite\Handler;

use Illuminate\Support\ServiceProvider;
use Teksite\Handler\Services\Builder\ResponderServices;

class HandlerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerConfigFiles();
        $this->registerBindings();
    }

    public function boot(): void
    {
        $this->bootPublishFiles();
    }

    private function registerConfigFiles(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/handler.php', 'handler');
    }

    private function registerBindings(): void
    {
        $this->app->bind(ResponderServices::class, fn () => new ResponderServices());
    }

    private function bootPublishFiles(): void
    {
        $this->publishes([
            __DIR__ . '/config/handler.php' => config_path('handler.php'),
        ], ['handler', 'handler-config']);
    }

}

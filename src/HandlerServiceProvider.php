<?php

namespace Teksite\Handler;

use Illuminate\Support\ServiceProvider;
use Teksite\Handler\Services\Builder\ResponderServices;

class HandlerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerConfigFiles();
        $this->registerFacade();
    }


    public function boot(): void
    {
        $this->bootPublishFiles();
    }

    private function registerConfigFiles(): void
    {
        $configPath = config_path('handler.php');

        $this->mergeConfigFrom(
            file_exists($configPath) ? $configPath : __DIR__ . '/config/handler.php', 'handler');

    }

    private function registerFacade(): void
    {
        $this->app->singleton('Responder', function () {
            return new ResponderServices();
        });
    }

    private function bootPublishFiles(): void
    {
        $this->publishes([
            __DIR__ . '/config/handler.php' => config_path('handler.php')
        ], ['handler','handler-config']);
    }

}

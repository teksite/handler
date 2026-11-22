<?php

namespace Teksite\Handler;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Teksite\Handler\Contracts\FetchDataContract;
use Teksite\Handler\Services\Builder\ResponderServices;
use Teksite\Handler\Services\FetchDataService;

class HandlerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerConfigFiles();
        $this->registerBindings();
    }

    public function boot(): void
    {
        $this->loadTranslateFiles();
        $this->bootPublishFiles();
        $this->bootMigrationCacheFlush();
    }


    private function loadTranslateFiles(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/lang', 'handler');

    }


    private function registerConfigFiles(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/handler.php', 'handler');
    }

    private function registerBindings(): void
    {
        $this->app->bind(FetchDataContract::class, FetchDataService::class);

    }

    private function bootPublishFiles(): void
    {
        if (!$this->app->runningInConsole()) return;

        $this->publishes([
            __DIR__.'/config/handler.php' => config_path('handler.php'),
        ], ['handler', 'handler-config']);

        $this->publishes([
            __DIR__ . '/lang' => $this->app->langPath('vendor/handler'),
        ], ['handler', 'handler-lang']);
    }


    private function bootMigrationCacheFlush(): void
    {
        Event::listen(MigrationsEnded::class, function () {
            $this->app->make(FetchDataContract::class)->forgetAllColumnsCache();
        });
    }

}

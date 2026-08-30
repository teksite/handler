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
        $this->bootPublishFiles();
        $this->bootMigrationCacheFlush();

    }


    private function bootMigrationCacheFlush(): void
    {
        Event::listen(MigrationsEnded::class, function () {
            FetchDataService::forgetAllColumnsCache();
        });
    }

    private function registerConfigFiles(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/handler.php', 'handler');
    }

    private function registerBindings(): void
    {
        $this->app->bind(ResponderServices::class, fn () => new ResponderServices());
        $this->app->bind(FetchDataContract::class, FetchDataService::class);

    }

    private function bootPublishFiles(): void
    {
        $this->publishes([
            __DIR__ . '/config/handler.php' => config_path('handler.php'),
        ], ['handler', 'handler-config']);
    }

}

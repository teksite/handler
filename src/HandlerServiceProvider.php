<?php

namespace Teksite\Handler;

use Illuminate\Support\ServiceProvider;

class HandlerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configPath = config_path('handler-settings.php'); // Path to the published file

        $this->mergeConfigFrom(
            file_exists($configPath) ? $configPath : __DIR__ . '/config/handler-settings.php', 'handler-settings');
    }


    public function boot(): void
    {
        $this->publishes([
                __DIR__ . '/config/handler-settings.php' => config_path('handler-settings.php')
            ],'handler-settings');
    }


}

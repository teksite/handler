<?php

namespace Teksite\Handler;

use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configPath = config_path('cms-settings.php'); // Path to the published file

        $this->mergeConfigFrom(
            file_exists($configPath) ? $configPath : __DIR__ . '/config/cms-settings.php', 'cms-settings');
    }


    public function boot(): void
    {
        $this->publishes([
                __DIR__ . '/config/cms-settings.php' => config_path('cms-settings.php')
            ],'config');
    }


}

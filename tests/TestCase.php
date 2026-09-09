<?php

namespace Teksite\Handler\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Teksite\Handler\Facade\FetchData;
use Teksite\Handler\Facade\Responder;
use Teksite\Handler\HandlerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    /**
     * Register the package service provider.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            HandlerServiceProvider::class,
        ];
    }

    /**
     * Register the package facades.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'FetchData' => FetchData::class,
            'Responder' => Responder::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Keep handler defaults small & predictable for assertions.
        $app['config']->set('handler.pagination', 10);
        $app['config']->set('handler.limit-pagination', 50);
    }

    /**
     * Build a minimal schema shared by the Feature test-suite:
     * users (1) -> posts (*) so relation / search-through-relation
     * behaviour can be exercised.
     */
    protected function setUpDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('title');
            $table->string('category')->nullable();
            $table->string('status')->default('draft');
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }
}

<?php

namespace Teksite\Handler\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Teksite\Handler\Services\ServiceWrapper;
use Teksite\Handler\Tests\Fixtures\Models\User;
use Teksite\Handler\Tests\TestCase;

class ServiceWrapperTransactionHelpersTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Always use the default connection and keep the log quiet for these tests.
        $app['config']->set('handler.connection', null);
        $app['config']->set('handler.log', false);

        $app['config']->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    #[Test]
    public function with_db_transaction_runs_the_action_inside_a_transaction(): void
    {
        $level = null;

        ServiceWrapper::withDbTransaction()
            ->do(function () use (&$level) {
                $level = DB::transactionLevel();
            })
            ->run();

        $this->assertSame(1, $level);
    }

    #[Test]
    public function without_db_transaction_runs_the_action_outside_a_transaction(): void
    {
        $level = null;

        ServiceWrapper::withoutDbTransaction()
            ->do(function () use (&$level) {
                $level = DB::transactionLevel();
            })
            ->run();

        $this->assertSame(0, $level);
    }

    #[Test]
    public function the_helpers_ignore_the_global_transaction_setting(): void
    {
        $withLevel = null;
        $withoutLevel = null;

        config()->set('handler.transaction', false);

        ServiceWrapper::withDbTransaction()
            ->do(function () use (&$withLevel) {
                $withLevel = DB::transactionLevel();
            })
            ->run();

        config()->set('handler.transaction', true);

        ServiceWrapper::withoutDbTransaction()
            ->do(function () use (&$withoutLevel) {
                $withoutLevel = DB::transactionLevel();
            })
            ->run();

        $this->assertSame(1, $withLevel);
        $this->assertSame(0, $withoutLevel);
    }

    #[Test]
    public function with_db_transaction_rolls_back_the_changes_of_a_failed_action(): void
    {
        $result = ServiceWrapper::withDbTransaction()
            ->do(function () {
                User::create(['name' => 'Ada']);

                throw new RuntimeException('boom');
            })
            ->run();

        $this->assertTrue($result->isFailed());
        $this->assertSame(0, User::count());
    }

    #[Test]
    public function without_db_transaction_keeps_the_changes_made_before_a_failure(): void
    {
        $result = ServiceWrapper::withoutDbTransaction()
            ->do(function () {
                User::create(['name' => 'Ada']);

                throw new RuntimeException('boom');
            })
            ->run();

        $this->assertTrue($result->isFailed());
        $this->assertSame(1, User::count());
    }

    #[Test]
    public function the_helpers_forward_the_service_result_option(): void
    {
        $with = ServiceWrapper::withDbTransaction(wrapServiceResult: false)->do(fn () => 'raw')->run();
        $without = ServiceWrapper::withoutDbTransaction(wrapServiceResult: false)->do(fn () => 'raw')->run();

        $this->assertSame('raw', $with);
        $this->assertSame('raw', $without);
    }

    #[Test]
    public function with_db_transaction_forwards_the_handler_option(): void
    {
        $this->expectException(RuntimeException::class);

        ServiceWrapper::withDbTransaction(withHandler: false)
            ->do(fn () => throw new RuntimeException('boom'))
            ->run();
    }

    #[Test]
    public function without_db_transaction_forwards_the_handler_option(): void
    {
        $this->expectException(RuntimeException::class);

        ServiceWrapper::withoutDbTransaction(withHandler: false)
            ->do(fn () => throw new RuntimeException('boom'))
            ->run();
    }

    #[Test]
    public function with_db_transaction_forwards_the_connection_option(): void
    {
        $secondaryLevel = null;
        $defaultLevel = null;

        ServiceWrapper::withDbTransaction(connection: 'secondary')
            ->do(function () use (&$secondaryLevel, &$defaultLevel) {
                $secondaryLevel = DB::connection('secondary')->transactionLevel();
                $defaultLevel = DB::connection()->transactionLevel();
            })
            ->run();

        $this->assertSame(1, $secondaryLevel);
        $this->assertSame(0, $defaultLevel);
    }
}

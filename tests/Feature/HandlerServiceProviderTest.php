<?php

namespace Teksite\Handler\Tests\Feature;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Teksite\Handler\Contracts\FetchDataContract;
use Teksite\Handler\Services\Builder\ResponderServices;
use Teksite\Handler\Services\FetchDataService;
use Teksite\Handler\Tests\TestCase;

class HandlerServiceProviderTest extends TestCase
{
    #[Test]
    public function it_merges_the_package_config_with_the_application_config(): void
    {
        $this->assertNotNull(config('handler.default_order_by'));
        $this->assertSame('created_at', config('handler.default_order_by'));
        $this->assertSame('desc', config('handler.default_sort_direction'));
        $this->assertSame('s', config('handler.search_input_field'));
    }

    #[Test]
    public function environment_overrides_defined_in_define_environment_take_effect(): void
    {
        $this->assertSame(10, config('handler.pagination'));
        $this->assertSame(50, config('handler.limit-pagination'));
    }

    #[Test]
    public function fetch_data_contract_resolves_to_fetch_data_service(): void
    {
        $this->assertInstanceOf(FetchDataService::class, app(FetchDataContract::class));
    }

    #[Test]
    public function responder_services_builder_is_resolvable_from_the_container(): void
    {
        $this->assertInstanceOf(ResponderServices::class, app(ResponderServices::class));
    }

    #[Test]
    public function bindings_are_not_shared_a_new_instance_is_resolved_every_time(): void
    {
        $this->assertNotSame(app(FetchDataContract::class), app(FetchDataContract::class));
        $this->assertNotSame(app(ResponderServices::class), app(ResponderServices::class));
    }

    #[Test]
    public function the_column_cache_is_flushed_when_a_migration_batch_finishes(): void
    {
        Cache::put('fetch-data-columns:default:posts', ['id', 'title'], now()->addDay());
        Cache::put('fetch-data-columns:__registry', ['fetch-data-columns:default:posts'], now()->addDay());

        $this->assertTrue(Cache::has('fetch-data-columns:default:posts'));

        Event::dispatch(new MigrationsEnded('up'));

        $this->assertFalse(Cache::has('fetch-data-columns:default:posts'));
    }
}

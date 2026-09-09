<?php

namespace Teksite\Handler\Tests\Unit\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Teksite\Handler\Services\FetchDataService;
use Teksite\Handler\Tests\TestCase;

class FetchServiceCacheTest extends TestCase
{
    private function service(): FetchDataService
    {
        return new FetchDataService(new Request());
    }

    private function callGetTableColumns(FetchDataService $service, string $table, ?string $connection = null): array
    {
        $method = new \ReflectionMethod($service, 'getTableColumns');
        $method->setAccessible(true);

        return $method->invoke($service, $table, $connection);
    }

    #[Test]
    public function it_reads_and_caches_the_real_column_listing_for_a_table(): void
    {
        $columns = $this->callGetTableColumns($this->service(), 'posts');

        $this->assertContains('id', $columns);
        $this->assertContains('title', $columns);
        $this->assertContains('user_id', $columns);

        $this->assertTrue(Cache::has('fetch-data-columns:default:posts'));
    }

    #[Test]
    public function it_returns_an_empty_array_for_a_table_that_does_not_exist_instead_of_throwing(): void
    {
        $columns = $this->callGetTableColumns($this->service(), 'this_table_does_not_exist');

        $this->assertSame([], $columns);
    }

    #[Test]
    public function forget_all_columns_cache_clears_every_registered_key(): void
    {
        $service = $this->service();

        $this->callGetTableColumns($service, 'posts');
        $this->callGetTableColumns($service, 'users');

        $this->assertTrue(Cache::has('fetch-data-columns:default:posts'));
        $this->assertTrue(Cache::has('fetch-data-columns:default:users'));

        $service->forgetAllColumnsCache();

        $this->assertFalse(Cache::has('fetch-data-columns:default:posts'));
        $this->assertFalse(Cache::has('fetch-data-columns:default:users'));
    }
}

<?php

namespace Teksite\Handler\Tests\Unit\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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

        return $method->invoke($service, $table, $connection);
    }

    private function cacheKey(string $table, string $connection = 'default'): string
    {
        return sprintf(
            'fetch-data-columns:%s:%s:%s',
            Cache::get('fetch-data-columns:__version', '0'),
            $connection,
            $table,
        );
    }

    #[Test]
    public function it_reads_and_caches_the_real_column_listing_for_a_table(): void
    {
        $columns = $this->callGetTableColumns($this->service(), 'posts');

        $this->assertContains('id', $columns);
        $this->assertContains('title', $columns);
        $this->assertContains('user_id', $columns);

        $this->assertTrue(Cache::has($this->cacheKey('posts')));
    }

    #[Test]
    public function a_cached_listing_is_served_without_touching_the_schema_again(): void
    {
        Cache::put($this->cacheKey('posts'), ['cached_column'], 60);

        $this->assertSame(['cached_column'], $this->callGetTableColumns($this->service(), 'posts'));
    }

    #[Test]
    public function it_returns_an_empty_array_for_a_table_that_does_not_exist_and_does_not_cache_it(): void
    {
        $columns = $this->callGetTableColumns($this->service(), 'this_table_does_not_exist');

        $this->assertSame([], $columns);
        $this->assertFalse(Cache::has($this->cacheKey('this_table_does_not_exist')));
    }

    #[Test]
    public function a_failing_schema_lookup_returns_an_empty_array_logs_a_warning_and_is_not_cached(): void
    {
        Log::spy();

        $columns = $this->callGetTableColumns($this->service(), 'posts', 'connection_that_is_not_configured');

        $this->assertSame([], $columns);
        $this->assertFalse(Cache::has($this->cacheKey('posts', 'connection_that_is_not_configured')));
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function forget_all_columns_cache_makes_every_cached_listing_unreachable(): void
    {
        $service = $this->service();

        $this->callGetTableColumns($service, 'posts');
        $this->callGetTableColumns($service, 'users');

        $this->assertTrue(Cache::has($this->cacheKey('posts')));
        $this->assertTrue(Cache::has($this->cacheKey('users')));

        $service->forgetAllColumnsCache();

        $this->assertFalse(Cache::has($this->cacheKey('posts')));
        $this->assertFalse(Cache::has($this->cacheKey('users')));

        // The listing is rebuilt on demand under the new version.
        $this->callGetTableColumns($service, 'posts');

        $this->assertTrue(Cache::has($this->cacheKey('posts')));
    }

    #[Test]
    public function forget_columns_cache_only_clears_the_given_model(): void
    {
        $service = $this->service();

        $this->callGetTableColumns($service, 'posts', 'testing');
        $this->callGetTableColumns($service, 'users', 'testing');

        $service->forgetColumnsCache(\Teksite\Handler\Tests\Fixtures\Models\Post::class);

        $this->assertFalse(Cache::has($this->cacheKey('posts', 'testing')));
        $this->assertTrue(Cache::has($this->cacheKey('users', 'testing')));
    }

    #[Test]
    public function forget_columns_cache_rejects_a_class_that_is_not_an_eloquent_model(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->forgetColumnsCache(\stdClass::class);
    }
}

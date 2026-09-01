<?php

namespace Teksite\Handler\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

trait FetchServiceCache
{

    private const int COLUMN_CACHE_TTL = 86400;

    private const int MAX_SEARCH_KEYWORD_LENGTH = 255;

    private const string COLUMNS_REGISTRY_KEY = 'fetch-data-columns:__registry';

    private function getTableColumns(string $table, ?string $connection = null): array
    {
        $key = $this->columnsCacheKey($table, $connection);

        $this->registerCacheKey($key);

        return Cache::remember($key, self::COLUMN_CACHE_TTL, function () use ($table, $connection): array {
            try {
                return $connection
                    ? Schema::connection($connection)->getColumnListing($table)
                    : Schema::getColumnListing($table);
            } catch (\Throwable $e) {
                Log::warning(
                    "Failed getting columns for table {$table}",
                    [
                        'connection' => $connection,
                        'error'      => $e->getMessage(),
                    ]
                );

                return [];
            }
        });
    }

    private function registerCacheKey(string $key): void
    {
        $registry = Cache::get(self::COLUMNS_REGISTRY_KEY, []);

        if (!is_array($registry)) $registry = [];

        if (!in_array($key, $registry, true)) {
            $registry[] = $key;

            Cache::forever(self::COLUMNS_REGISTRY_KEY, $registry);
        }
    }

    public function forgetAllColumnsCache(): void
    {
        $registry = Cache::get(self::COLUMNS_REGISTRY_KEY, []);

        if (!is_array($registry)) $registry = [];

        foreach ($registry as $key) {
            if (is_string($key)) Cache::forget($key);
        }

        Cache::forget(self::COLUMNS_REGISTRY_KEY);
    }

    private static function columnsCacheKey(string  $table, ?string $connection): string
    {
        return sprintf('fetch-data-columns:%s:%s', $connection ?: 'default', $table);
    }
}

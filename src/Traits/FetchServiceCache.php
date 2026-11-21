<?php

namespace Teksite\Handler\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Caches the column listing of tables so ordering input can be validated
 * against real columns without querying the schema on every request.
 *
 * Invalidation uses a version token embedded in every cache key: bumping the
 * token makes all cached listings unreachable in a single atomic write.
 */
trait FetchServiceCache
{
    private const int COLUMN_CACHE_TTL = 86400;

    private const string COLUMNS_VERSION_KEY = 'fetch-data-columns:__version';

    private function getTableColumns(string $table, ?string $connection = null): array
    {
        $key = $this->columnsCacheKey($table, $connection);
        $cached = Cache::get($key);

        if (is_array($cached)) return $cached;

        try {
            $columns = $connection
                ? Schema::connection($connection)->getColumnListing($table)
                : Schema::getColumnListing($table);
        } catch (Throwable $e) {
            Log::warning("Failed getting columns for table {$table}", [
                'connection' => $connection,
                'error'      => $e->getMessage(),
            ]);
            return [];
        }
        if ($columns !== []) Cache::put($key, $columns, self::COLUMN_CACHE_TTL);

        return $columns;
    }

    public function forgetAllColumnsCache(): void
    {
        Cache::forever(self::COLUMNS_VERSION_KEY, Str::random(12));
    }


    public function forgetColumnsCache(string|Model $model): void
    {
        if (is_string($model)) {
            if (!is_a($model, Model::class, true)) {
                throw new InvalidArgumentException(sprintf('The given class [%s] must extend [%s].', $model, Model::class),);
            }
            $model = new $model();
        }
        Cache::forget($this->columnsCacheKey($model->getTable(), $model->getConnection()->getName()));
    }

    private function columnsCacheKey(string $table, ?string $connection): string
    {
        return sprintf(
            'fetch-data-columns:%s:%s:%s',
            Cache::get(self::COLUMNS_VERSION_KEY, '0'),
            $connection ?: 'default',
            $table,
        );
    }
}

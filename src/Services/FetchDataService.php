<?php

namespace Teksite\Handler\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

class FetchDataService
{
    /**
     * Allowed SQL operators for searching.
     */
    private const array ALLOWED_OPERATORS = ['=', 'LIKE', 'ILIKE', '!=', '<>', '>', '<', '<=', '>='];

    /**
     * Cache lifetime for table columns (seconds).
     */
    private const int COLUMN_CACHE_TTL = 86400; // 24 hours

    /**
     * Main entry point for fetching data.
     *
     * @param string|Closure|Builder|Relation $model
     * @param array|string|null $searchColumns
     * @param array $only
     * @param int|false|null $perPage
     * @param int|false|null $limitPagination
     * @param array $with
     * @param array $withCount
     * @return mixed
     * @throws \Exception
     */
    public static function get(
        string|Closure|Builder|Relation $model,
        string|array|null               $searchColumns = null,
        array|string                    $only = ['*'],
        null|int|false                  $perPage = null,
        null|false|int                  $limitPagination = null,
        array                           $with = [],
        array                           $withCount = []
    ): mixed
    {
        // Handle closure
        if ($model instanceof Closure) {
            return self::executeClosure($model);
        }

        // Build query
        $query = self::getQueryBuilder($model);

        // Apply eager loading
        if (!empty($with)) {
            $query->with($with);
        }

        // Apply withCount
        if (!empty($withCount)) {
            $query->withCount($withCount);
        }

        // Apply selection
        $query = self::applySelection($query, (array)$only);

        // Apply search (supports relations)
        if ($searchColumns) {
            $query = self::applySearch($query, (array)$searchColumns);
        }

        // Apply ordering
        $query = self::applyOrdering($query);

        // Apply pagination or limit
        return self::applyPagination(
            $query,
            self::resolvePerPage($perPage),
            self::resolveLimitPagination($limitPagination)
        );
    }

    /**
     * Execute closure and return result.
     */
    private static function executeClosure(Closure $closure): mixed
    {
        return $closure();
    }

    /**
     * Resolve per-page value from request or config.
     */
    private static function resolvePerPage(null|int|false $perPage): int|false
    {
        $fromRequest = request()->has('per_page') ? max(1, request()->integer('per_page')) : null;
        $configValue = config('handler-settings.pagination', 25);

        return $fromRequest ?? ($perPage ?? $configValue);
    }

    /**
     * Resolve limit pagination value.
     */
    private static function resolveLimitPagination(null|false|int $limitPagination): int|false
    {
        return $limitPagination ?? config('handler-settings.limit-pagination', 250);
    }

    /**
     * Convert model/relation/builder into Builder.
     */
    private static function getQueryBuilder(string|Model|Builder|Relation $model): Builder
    {
        return match (true) {
            $model instanceof Builder  => $model,
            $model instanceof Relation => $model->getQuery(),
            $model instanceof Model    => $model->newQuery(),
            is_string($model)          => (new $model())->newQuery(),
            default                    => throw new InvalidArgumentException('Invalid model, builder, or relation provided.'),
        };
    }

    /**
     * Apply select columns (always include primary key if not '*')
     */
    private static function applySelection(Builder $query, array $only): Builder
    {
        if (in_array('*', $only)) {
            return $query;
        }

        $primaryKey = $query->getModel()->getKeyName();
        if (!in_array($primaryKey, $only, true)) {
            $only[] = $primaryKey;
        }

        return $query->select($only);
    }

    /**
     * Apply search with relation support (e.g., 'user.name', 'category.title')
     */
    private static function applySearch(Builder $query, array $searchColumns): Builder
    {
        $searchInput = config('handler-settings.search_input_field', 's');
        $keyword = trim(request()->input($searchInput, ''));

        if ($keyword === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($searchColumns, $keyword) {
            $hasCondition = false;

            foreach ($searchColumns as $columnDefinition) {
                $condition = self::parseSearchCondition($columnDefinition, $keyword);

                if (!$condition) {
                    continue;
                }

                ['column' => $column, 'operator' => $operator, 'value' => $value] = $condition;

                if (str_contains($column, '.')) {
                    // Relation search (e.g., user.name)
                    $q->orWhereHas(
                        self::extractRelationName($column),
                        function (Builder $relationQuery) use ($column, $operator, $value, &$hasCondition) {
                            $field = self::extractColumnName($column);
                            if ($hasCondition) {
                                $relationQuery->where($field, $operator, $value);
                            } else {
                                $relationQuery->where($field, $operator, $value);
                            }
                        }
                    );
                    $hasCondition = true;
                } else {
                    // Direct column search
                    if (!$hasCondition) {
                        $q->where($column, $operator, $value);
                        $hasCondition = true;
                    } else {
                        $q->orWhere($column, $operator, $value);
                    }
                }
            }

            // Prevent empty search returning all rows
            if (!$hasCondition) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /**
     * Parse search condition from definition.
     *
     * @param string|array $definition
     * @param string $keyword
     * @return array|null
     */
    private static function parseSearchCondition(string|array $definition, string $keyword): ?array
    {
        if (is_array($definition)) {
            $column = $definition['column'] ?? $definition[0] ?? null;
            $operator = strtoupper($definition['operation'] ?? $definition[1] ?? 'LIKE');
            $value = $definition['value'] ?? $keyword;
        } else {
            $column = $definition;
            $operator = 'LIKE';
            $value = "%{$keyword}%";
        }

        // Validate operator
        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            $operator = 'LIKE';
        }

        // Format value for LIKE/ILIKE
        if (in_array($operator, ['LIKE', 'ILIKE'], true) && !str_contains($value, '%')) {
            $value = "%{$value}%";
        }

        // Basic column validation (for direct columns, relations validated later)
        if (!is_string($column) || empty($column)) {
            return null;
        }

        return [
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
        ];
    }

    /**
     * Extract relation name from dot notation (e.g., 'user.profile.name' -> 'user')
     */
    private static function extractRelationName(string $path): string
    {
        $parts = explode('.', $path);
        return $parts[0];
    }

    /**
     * Extract column name from dot notation (e.g., 'user.profile.name' -> 'name')
     */
    private static function extractColumnName(string $path): string
    {
        $parts = explode('.', $path);
        return end($parts);
    }

    /**
     * Apply ordering with column validation and smart defaults.
     */
    private static function applyOrdering(Builder $query): Builder
    {
        $defaultOrderBy = config('handler-settings.default_order_by', 'created_at');
        $defaultSort = config('handler-settings.default_sort_direction', 'desc');

        // Get from request, fallback to defaults
        $orderBy = request()->input('order', $defaultOrderBy);
        $sort = strtolower(request()->input('sort', $defaultSort));

        // Validate sort direction
        $sort = in_array($sort, ['asc', 'desc'], true) ? $sort : $defaultSort;

        // Check if ordering by relation (contains dot)
        if (str_contains($orderBy, '.')) {
            $allowRelationOrdering = config('handler-settings.allow_relation_ordering', false);

            if (!$allowRelationOrdering) {
                // Fallback to default if relation ordering not allowed
                $orderBy = $defaultOrderBy;
                $sort = $defaultSort;
            } else {
                // Optional: Implement relation ordering with join
                // For now, just apply as is (will work if relation is joined)
                $query->orderBy($orderBy, $sort);
                return $query;
            }
        }

        // Validate column exists in main table
        $validColumns = self::getTableColumns($query->getModel()->getTable());

        // If column not valid, fallback to primary key or created_at
        if (!in_array($orderBy, $validColumns, true)) {
            $orderBy = in_array('created_at', $validColumns) ? 'created_at' : $query->getModel()->getKeyName();
            $sort = $defaultSort;

            // Log warning for debugging
            Log::warning("Invalid order column requested", [
                'requested' => request()->input('order'),
                'fallback' => $orderBy
            ]);
        }

        $query->orderBy($orderBy, $sort);
        return $query;
    }

    /**
     * Get cached table columns.
     */
    private static function getTableColumns(string $table): array
    {
        $cacheKey = "fetch-data-columns:{$table}";
        return Cache::remember($cacheKey, self::COLUMN_CACHE_TTL, function () use ($table) {
            try {
                return Schema::getColumnListing($table);
            } catch (\Throwable $e) {
                Log::warning("Failed to get columns for table: {$table}", ['error' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Apply pagination or simple collection.
     */
    private static function applyPagination(Builder $query, int|false $perPage, int|false $limitPagination): LengthAwarePaginator|Collection
    {
        // No pagination
        if ($perPage === false) {
            return $limitPagination === false
                ? $query->get()
                : $query->limit($limitPagination)->get();
        }

        // Prevent abuse
        $limit = $limitPagination ? min($perPage, $limitPagination) : $perPage;

        return $query->paginate($limit)->withQueryString();
    }
}

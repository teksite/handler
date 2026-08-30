<?php

namespace Teksite\Handler\Services;

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

class FetchDataService
{
    /**
     * Allowed SQL operators.
     */
    private const array ALLOWED_OPERATORS = [
        '=',
        '!=',
        '<>',
        '>',
        '<',
        '<=',
        '>=',
        'LIKE',
        'ILIKE',
    ];

    /**
     * Cache lifetime for table columns.
     */
    private const int COLUMN_CACHE_TTL = 860400;

    /**
     * Hard cap on the raw search keyword length. Prevents pathological
     * LIKE patterns (and unnecessary payload size) from user input.
     */
    private const int MAX_SEARCH_KEYWORD_LENGTH = 255;


    /**
     * Main fetch method.
     */
    public static function get(
        string|Model|Builder|Relation $model,
        string|array|null             $searchColumns = null,
        array|string                  $only = ['*'],
        int|false|null                $perPage = null,
        int|false|null                $maxPerPage = null,
        array                         $with = [],
        array                         $withCount = []
    ): mixed
    {
        $query = self::resolveQuery($model);

        self::applyEagerLoading($query, $with);

        self::applySelection($query, self::normalizeColumns($only));

        if (!empty($withCount)) $query->withCount($withCount);

        if ($searchColumns) $query = self::applySearch($query, self::normalizeColumns($searchColumns));

        $query = self::applyOrdering($query);

        return self::applyPagination(
            $query,
            self::resolvePerPage($perPage),
            self::resolveLimitPagination($maxPerPage)
        );
    }

    /**
     * Clear cached columns for a model.
     *
     * @param class-string<Model>|Model $model
     */
    public static function forgetColumnsCache(string|Model $model): void
    {
        $instance = is_string($model) ? new $model() : $model;
        Cache::forget(self::columnsCacheKey($instance->getTable(), $instance->getConnectionName()));
    }

    /**
     * Convert model/builder/relation into Builder.
     */
    private static function resolveQuery(string|Model|Builder|Relation $model): Builder
    {
        return match (true) {
            $model instanceof Builder  => $model,
            $model instanceof Relation => $model->getQuery(),
            $model instanceof Model    => $model->newQuery(),
            is_string($model)          => self::newModelQuery($model),
            default                    => throw new InvalidArgumentException(sprintf('Expected a Model class, Model instance, Builder or Relation; %s given.', get_debug_type($model))),
        };
    }

    private static function newModelQuery(string $modelClass): Builder
    {
        if (!is_a($modelClass, Model::class, true)) {
            throw new InvalidArgumentException(sprintf('The given class [%s] must extend [%s].', $modelClass, Model::class),);
        }
        return (new $modelClass)->newQuery();
    }

    private static function applyEagerLoading(Builder $query, array $with): void
    {
        if ($with !== []) $query->with($with);
    }

    /**
     * Normalize a column list that may be given as an array
     * or as a comma-separated string (e.g. "title,slug").
     *
     * @return array<int, string|array>
     */
    private static function normalizeColumns(array|string $columns): array
    {
        if (is_string($columns)) $columns = explode(',', $columns);

        $result = [];

        foreach ($columns as $column) {
            if (!is_string($column)) continue;
            $column = trim($column);
            if ($column !== '') $result[] = $column;
        }

        return array_values(array_unique($result));
    }

    private static function stringInput(string $key, string $default): string
    {
        $value = request()->input($key, $default);
        return is_string($value) ? $value : $default;
    }


    /**
     * Resolve per page.
     */
    private static function resolvePerPage(null|int|false $perPage): int|false
    {
        $requestPerPage = request()->integer('per_page');

        if ($requestPerPage > 0) return $requestPerPage;

        return $perPage ?? config('handler.pagination', 25);
    }

    /**
     * Resolve max pagination limit.
     */
    private static function resolveLimitPagination(null|false|int $limitPagination): int|false
    {
        return $limitPagination ?? config('handler.limit-pagination', 250);
    }

    /**
     * Apply select.
     */
    private static function applySelection(Builder $query, array $only, array $with = []): void
    {
        if ($only === [] || in_array('*', $only, true)) return;

        $model = $query->getModel();

        $primaryKey = $model->getKeyName();

        if (!in_array($primaryKey, $only, true)) $only[] = $primaryKey;

        foreach ($with as $key => $value) {
            $relationName = is_string($key) ? $key : $value;

            if (!is_string($relationName)) continue;

            $relationName = explode(':', $relationName)[0];

            if (!method_exists($model, $relationName)) continue;

            try {
                $relation = $model->{$relationName}();

                if (!$relation instanceof Relation) continue;

                if (method_exists($relation, 'getForeignKeyName')) $only[] = $relation->getForeignKeyName();


                if (method_exists($relation, 'getLocalKeyName')) $only[] = $relation->getLocalKeyName();

            } catch (\Throwable $e) {
                Log::debug("FetchDataService: could not resolve relation '{$relationName}' for column selection.", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $query->select(array_values(array_unique($only)));
    }

    /**
     * Apply search.
     */
    private static function applySearch(Builder $query, array $searchColumns): Builder
    {
        $searchInput = config('handler.search_input_field', 's');

        $keyword = trim(self::stringInput($searchInput, ''));

        if ($keyword === '') return $query;

        $keyword = mb_substr($keyword, 0, self::MAX_SEARCH_KEYWORD_LENGTH);

        return $query->where(function (Builder $q) use ($searchColumns, $keyword) {

            $hasCondition = false;

            foreach ($searchColumns as $definition) {
                $condition = self::parseSearchCondition($definition, $keyword);

                if (!$condition) continue;

                ['column' => $column, 'operator' => $operator, 'value' => $value] = $condition;

                /**
                 * Relation search
                 */
                if (str_contains($column, '.')) {

                    $relation = self::extractRelationName($column);
                    $field = self::extractColumnName($column);
                    $method = $hasCondition ? 'orWhereHas' : 'whereHas';

                    $q->{$method}($relation, fn(Builder $rq) => $rq->where($field, $operator, $value));
                    $hasCondition = true;

                    continue;
                }

                /**
                 * Direct column
                 */
                $method = $hasCondition ? 'orWhere' : 'where';
                $q->{$method}($column, $operator, $value);
                $hasCondition = true;
            }

            if (!$hasCondition) $q->whereRaw('1 = 0');

        });
    }

    /**
     * Parse search condition.
     */
    private static function parseSearchCondition(string|array $definition, string $keyword): ?array
    {
        if (is_array($definition)) {

            $column = $definition['column'] ?? $definition[0] ?? null;

            $operator = strtoupper((string)($definition['operation'] ?? $definition[1] ?? 'LIKE'));

            $value = $definition['value'] ?? $definition[2] ?? $keyword;

        } else {
            $column = $definition;
            $operator = 'LIKE';
            $value = $keyword;
        }

        if (!$column || !is_string($column)) return null;


        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            $operator = 'LIKE';
        }

        // PostgreSQL only
        if ($operator === 'ILIKE' && DB::getDriverName() !== 'pgsql') {
            $operator = 'LIKE';
        }


        if (in_array($operator, ['LIKE', 'ILIKE'], true)) {
            $isUserSuppliedKeyword = $value === $keyword;
            $value = (string)$value;

            if ($isUserSuppliedKeyword)  $value = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

            if (!str_contains($value, '%'))  $value = "%{$value}%";

        }

        return [
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
        ];
    }

    /**
     * Extract relation path.
     *
     * user.profile.name
     * => user.profile
     */
    private static function extractRelationName(string $path): string
    {
        $parts = explode('.', $path);

        array_pop($parts);

        return implode('.', $parts);
    }

    /**
     * Extract field name.
     *
     * user.profile.name
     * => name
     */
    private static function extractColumnName(string $path): string
    {
        return last(explode('.', $path));
    }

    /**
     * Apply ordering.
     */
    private static function applyOrdering(Builder $query): Builder
    {
        $defaultOrderBy = config('handler.default_order_by', 'created_at');

        $defaultSort = strtolower((string)config('handler.default_sort_direction', 'desc'));

        $orderBy = self::stringInput('order', $defaultOrderBy);

        $sort = strtolower(self::stringInput('sort', $defaultSort));

        $sort = in_array($sort, ['asc', 'desc'], true) ? $sort : $defaultSort;

        if (str_contains($orderBy, '.'))  $orderBy = $defaultOrderBy;

        $columns = self::getTableColumns($query->getModel()->getTable());

        if (!in_array($orderBy, $columns, true)) {
            $orderBy = in_array('created_at', $columns, true)
                ? 'created_at'
                : $query->getModel()->getKeyName();
        }
        return $query->orderBy($orderBy, $sort);
    }

    /**
     * Get cached table columns.
     */
    private static function getTableColumns(string $table): array
    {
        return Cache::remember("fetch-data-columns:{$table}", self::COLUMN_CACHE_TTL, function () use ($table) {
                try {
                    return Schema::getColumnListing($table);
                } catch (\Throwable $e) {
                    Log::warning(
                        "Failed getting columns for table {$table}",
                        [
                            'error' => $e->getMessage(),
                        ]
                    );
                    return [];
                }
            }
        );
    }

    /**
     * Apply pagination.
     */
    private static function applyPagination(Builder $query, int|false $perPage, int|false $limitPagination): LengthAwarePaginator|Collection
    {
        /**
         * no pagination
         */
        if ($perPage === false) {
            if ($limitPagination !== false)  $query->limit($limitPagination);

            return $query->get();
        }


        if ($limitPagination !== false) {
            $perPage = min($perPage, $limitPagination);
        }
        return $query->paginate($perPage)->withQueryString();
    }

    private static function columnsCacheKey(?string $connection, string $table): string
    {
        return sprintf('fetch-data:columns:%s:%s', $connection ?: 'default', $table);
    }
}


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
     * Allowed SQL operators.
     */
    private const array ALLOWED_OPERATORS = ['=', 'LIKE', 'ILIKE', '!=', '<>', '>', '<', '<=', '>='];

    /**
     * Cache lifetime for table columns.
     */
    private const int COLUMN_CACHE_TTL = 86400;

    /**
     * Main fetch method.
     */
    public static function get(
        string|Model|Closure|Builder|Relation $model,
        string|array|null                     $searchColumns = null,
        array|string                          $only = ['*'],
        null|int|false                        $perPage = null,
        null|false|int                        $limitPagination = null,
        array                                 $with = [],
        array                                 $withCount = []
    ): mixed
    {
        if ($model instanceof Closure) {
            return $model();
        }

        $query = self::getQueryBuilder($model);

        // eager load
        if (!empty($with)) {
            $query->with($with);
        }

        // with count
        if (!empty($withCount)) {
            $query->withCount($withCount);
        }

        // select
        $query = self::applySelection($query, (array)$only, $with);

        // search
        if ($searchColumns) {
            $query = self::applySearch($query, (array)$searchColumns);
        }

        // ordering
        $query = self::applyOrdering($query);

        // pagination
        return self::applyPagination($query, self::resolvePerPage($perPage), self::resolveLimitPagination($limitPagination)
        );
    }

    /**
     * Convert model/builder/relation into Builder.
     */
    private static function getQueryBuilder(string|Model|Builder|Relation $model): Builder
    {
        return match (true) {
            $model instanceof Builder  => $model,
            $model instanceof Relation => $model->getQuery(),
            $model instanceof Model    => $model->newQuery(),
            is_string($model)          => (new $model())->newQuery(),
            default                    => throw new InvalidArgumentException('Invalid model, builder, or relation.'),
        };
    }

    /**
     * Resolve per page.
     */
    private static function resolvePerPage(null|int|false $perPage): int|false
    {
        $requestPerPage = request()->integer('per_page');

        if ($requestPerPage > 0) {
            return $requestPerPage;
        }

        return $perPage ?? config('handler-settings.pagination', 25);
    }

    /**
     * Resolve max pagination limit.
     */
    private static function resolveLimitPagination(null|false|int $limitPagination): int|false
    {
        return $limitPagination ?? config('handler-settings.limit-pagination', 250);
    }

    /**
     * Apply select.
     */
    private static function applySelection(Builder $query, array $only, array $with = []): Builder
    {
        if (in_array('*', $only, true)) {
            return $query;
        }

        $model = $query->getModel();

        // always include primary key
        $primaryKey = $model->getKeyName();

        if (!in_array($primaryKey, $only, true)) {
            $only[] = $primaryKey;
        }

        /**
         * Include foreign keys for eager loading.
         */
        foreach ($with as $relationName) {
            $relationName = explode(':', $relationName)[0];

            if (!method_exists($model, $relationName)) {
                continue;
            }

            try {
                $relation = $model->{$relationName}();

                if (method_exists($relation, 'getForeignKeyName')) {
                    $foreignKey = $relation->getForeignKeyName();

                    if (!in_array($foreignKey, $only, true)) {
                        $only[] = $foreignKey;
                    }
                }

                if (method_exists($relation, 'getLocalKeyName')) {
                    $localKey = $relation->getLocalKeyName();

                    if (!in_array($localKey, $only, true)) {
                        $only[] = $localKey;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $query->select(array_unique($only));
    }

    /**
     * Apply search.
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

            foreach ($searchColumns as $definition) {
                $condition = self::parseSearchCondition($definition, $keyword);
                if (!$condition) {
                    continue;
                }

                ['column' => $column, 'operator' => $operator, 'value' => $value,] = $condition;

                /**
                 * Relation search
                 */
                if (str_contains($column, '.')) {

                    $relation = self::extractRelationName($column);
                    $field = self::extractColumnName($column);

                    if (!$hasCondition) {
                        $q->whereHas($relation, fn(Builder $rq) => $rq->where($field, $operator, $value));
                        $hasCondition = true;
                    } else {
                        $q->orWhereHas($relation, fn(Builder $rq) => $rq->where($field, $operator, $value));
                    }
                    continue;
                }

                /**
                 * Direct column
                 */
                if (!$hasCondition) {
                    $q->where($column, $operator, $value);
                    $hasCondition = true;
                } else {

                    $q->orWhere($column, $operator, $value);
                }
            }

            if (!$hasCondition) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /**
     * Parse search condition.
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
            $value = $keyword;
        }

        if (!$column || !is_string($column)) {
            return null;
        }

        // validate operator
        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            $operator = 'LIKE';
        }

        // PostgreSQL only
        if ($operator === 'ILIKE' && DB::getDriverName() !== 'pgsql') {
            $operator = 'LIKE';
        }

        // add %
        if (in_array($operator, ['LIKE', 'ILIKE'], true) && !str_contains($value, '%')) {
            $value = "%{$value}%";
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
        $defaultOrderBy = config('handler-settings.default_order_by', 'created_at');

        $defaultSort = strtolower(config('handler-settings.default_sort_direction', 'desc'));

        $orderBy = request()->input('order', $defaultOrderBy);

        $sort = strtolower(request()->input('sort', $defaultSort));

        $sort = in_array($sort, ['asc', 'desc'], true) ? $sort : $defaultSort;

        /**
         * Prevent relation ordering.
         */
        if (str_contains($orderBy, '.')) {
            throw new RuntimeException(
                'Relation ordering requires joins.'
            );
        }

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
        return Cache::remember(
            "fetch-data-columns:{$table}",
            self::COLUMN_CACHE_TTL,
            function () use ($table) {

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
            if ($limitPagination !== false) {
                $query->limit($limitPagination);
            }
            return $query->get();
        }

        /**
         * limit max per page
         */
        if ($limitPagination !== false) {
            $perPage = min($perPage, $limitPagination);
        }

        return $query->paginate($perPage)->withQueryString();
    }
}

<?php

namespace Teksite\Handler\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class FetchDataService
{

    /**
     * Allowed SQL operators for searching.
     */
    private const array ALLOWED_OPERATORS = [
        '=',
        'LIKE',
        'ILIKE',
        '!=',
        '<>',
    ];

    /**
     * Main entry point for fetching data.
     *
     * @throws \Exception
     */
    public static function get(
        string|Closure|Builder|Relation $model,
        string|array|null               $searchColumns = null,
        array                           $only = ['*'],
        null|int|false                  $perPage = null,
        null|false|int                  $limitPagination = null
    ): mixed
    {

        if ($model instanceof Closure) {
            return self::executeClosure($model);
        }


        $perPageFromReq = request()->has('per_page') ? max(1, request()->integer('per_page')) : null;
        $configPerPage = config('handler-settings.pagination', 25);

        $perPage = $perPageFromReq ?? $perPage ?? $configPerPage ?? 25;

        $limitPagination = is_null($limitPagination) ? config('handler-settings.limit-pagination', 250) : $limitPagination;

        $query = self::getQueryBuilder($model);

        if ($searchColumns) {
            $query = self::applySearch($query, (array)$searchColumns);
        }

        $query = self::applyingSelection($query, $only);

        $query = self::applyOrdering($query);

        return self::applyPagination($query, $perPage, $limitPagination);
    }


    /**
     * Fetch data from closure.
     */
    private static function executeClosure(Closure $closure): mixed
    {
        return $closure();
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
     * Apply select columns.
     */
    private static function applyingSelection(Builder $query, string|array $only = ['*']): Builder
    {

        $only = is_array($only) ? $only : [$only];

        if (!in_array('*', $only)) {

            $model = $query->getModel();

            $primaryKey = $model->getKeyName();

            if (!in_array($primaryKey, $only, true)) {
                $only[] = $primaryKey;
            }

            $query->select($only);
        }

        return $query;
    }


    /**
     * Apply search conditions safely.
     */
    private static function applySearch(Builder $query, array $searchColumns): Builder
    {

        $searchInputField = config('handler-settings.search_input_field', 's');

        $keyword = trim((string)request()->input($searchInputField));

        if ($keyword === '') {
            return $query;
        }

        $model = $query->getModel();

        $table = $model->getTable();

        /**
         * Cache table columns for performance.
         */
        $validColumns = Cache::remember(
            "fetch-data-columns:{$table}",
            now()->addHours(24),
            fn() => Schema::getColumnListing($table)
        );

        $query->where(function (Builder $q) use ($searchColumns, $keyword, $validColumns) {

            $hasValidCondition = false;

            foreach ($searchColumns as $columnDefinition) {

                $column = null;

                $operator = 'LIKE';

                $value = "%{$keyword}%";

                /**
                 * Array definition.
                 */
                if (is_array($columnDefinition)) {
                    $column = $columnDefinition['column'] ?? $columnDefinition[0] ?? null;
                    $operator = strtoupper($columnDefinition['operation'] ?? $columnDefinition[1] ?? 'LIKE');
                    /**
                     * Validate operator.
                     */
                    if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
                        $operator = 'LIKE';
                    }
                    $value = $operator === 'LIKE' || $operator === 'ILIKE' ? "%{$keyword}%" : ($columnDefinition['value'] ?? $keyword);
                } else {
                    $column = $columnDefinition;
                }

                /**
                 * Security validation.
                 */
                if (
                    !is_string($column)
                    || !preg_match('/^[a-zA-Z0-9_]+$/', $column)
                    || !in_array($column, $validColumns, true)
                ) {
                    continue;
                }

                if (!$hasValidCondition) {
                    $q->where($column, $operator, $value);
                    $hasValidCondition = true;
                } else {
                    $q->orWhere($column, $operator, $value);
                }
            }

            /**
             * Prevent returning all rows
             * if no valid searchable column exists.
             */
            if (!$hasValidCondition) {
                $q->whereRaw('1 = 0');
            }
        });

        return $query;
    }

    /**
     * Applies ordering to the query builder based on request parameters.
     */
    private static function applyOrdering(Builder $query): Builder
    {
        $orderBy = request()->input('order', 'created_at');

        $sort = strtolower( request()->input('sort', 'desc'));

        $sort = in_array($sort, ['asc', 'desc'], true) ? $sort : 'desc';

        $model = $query->getModel();

        $table = $model->getTable();

        /**
         * Cache table columns.
         */
        $validColumns = Cache::remember(
            "fetch-data-columns:{$table}",
            now()->addHours(12),
            fn () => Schema::getColumnListing($table)
        );

        /**
         * Validate orderBy column.
         */
        if (!in_array($orderBy, $validColumns, true)) {
            $orderBy = $model->getKeyName();
        }

        $query->orderBy($orderBy, $sort);

        return $query;
    }


    /**
     * Applies pagination to the query builder.
     */
    private static function applyPagination(Builder $query, int|false $perPage, int|false $limitPagination): LengthAwarePaginator|Collection {

        /**
         * No pagination.
         */
        if ($perPage === false) {
            return $limitPagination === false ? $query->get() : $query->limit($limitPagination)->get();
        }

        /**
         * Prevent abuse.
         */
        $limit = $limitPagination ? min($perPage, $limitPagination) : $perPage;

        return $query ->paginate($limit)->withQueryString();
    }


}

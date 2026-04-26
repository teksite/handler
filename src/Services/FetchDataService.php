<?php

namespace Teksite\Handler\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class FetchDataService
{

    /**
     * Main entry point for fetching data.
     */
    public static function get(
        string|Closure|Builder|Relation $model,
        string|array|Closure|null       $searchColumns = ['title'],
        array                           $only = ['*'],
        null|int|false                  $perPage = null,
        null|false|int                  $limitPagination = null
    ): mixed
    {
        if ($model instanceof Closure) return self::executeClosure($model);

        $perPage =request()->integer('per_page')
            ?? (is_null($perPage)  ? config('handler-settings.pagination', 25) : $perPage);

        $limitPagination = is_null($limitPagination)
            ? config('handler-settings.limit-pagination', 250)
            : $limitPagination;

        $query = self::getQueryBuilder($model);

        if ($searchColumns) $query = self::applySearch($query, (array)$searchColumns);

        $query = self::applyingSelection($query, $only);

        $query = self::applyOrdering($query);

        return self::applyPagination($query, $perPage , $limitPagination);
    }


    /**
     * Fetch data from closure.
     */
    private static function executeClosure(Closure $closure): mixed
    {
        return $closure();
    }


    /**
     * Converts various model types into an Eloquent Query Builder instance.
     *
     * Handles Eloquent models, relations, and query builders directly.
     *
     * @param string|Model|Builder|Relation $model The Eloquent model, relation, or builder.
     *
     * @return Builder Returns an Eloquent Query Builder instance.
     *
     * @throws InvalidArgumentException If the provided model type is not supported.
     */
    private static function getQueryBuilder(string|Model|Builder|Relation $model): Builder
    {

        return match (true) {
            $model instanceof Builder  => $model,
            $model instanceof Relation => $model->getQuery(),
            $model instanceof Model    => $model->newQuery(),
            is_string($model)          => (new $model())->newQuery(),
            default                    => new InvalidArgumentException('Invalid model, builder, or relation provided.')
        };

    }

    /**
     * @param Builder $query
     * @param string|array $only
     * @return Builder
     */
    private static function applyingSelection(Builder $query, string|array $only = ['*']): Builder
    {
        $only = is_array($only) ? $only : [$only];
        $query->select($only);

        return $query;
    }


    /**
     * Applies search filters to the query builder based on request parameters.
     *
     * Searches across specified columns using a LIKE clause.
     *
     * @param Builder $query The Eloquent query builder instance.
     * @param array $searchColumns An array of column names or column definitions to search within.
     *
     * @return Builder The query builder with search conditions applied.
     */
    private static function applySearch(Builder $query, array $searchColumns): Builder
    {
        $searchInputField = config('handler-settings.search_input_field', 's');
        $keyword = request()->input($searchInputField);

        if (!$keyword) return $query;

        $query->where(function (Builder $q) use ($searchColumns, $keyword) {
            foreach ($searchColumns as $index => $columnDefinition) {


                if (is_array($columnDefinition)) {
                    $column = $columnDefinition['column'] ?? $columnDefinition[0];
                    $operator = $columnDefinition['operation'] ?? $columnDefinition[1] ?? 'LIKE';
                    $value = ($operator === 'LIKE') ? "%{$keyword}%" : ($columnDefinition['value'] ?? $keyword);
                } else {
                    $column = $columnDefinition;
                    $operator = 'LIKE';
                    $value = "%{$keyword}%";
                }

                if ($index === 0) {
                    $q->where($column, $operator, $value);
                } else {
                    $q->orWhere($column, $operator, $value);
                }
            }
        });

        return $query;
    }


    /**
     * Applies ordering to the query builder based on request parameters.
     *
     * Defaults to ordering by 'created_at' descending. Allows specifying 'order' and 'sort' parameters.
     * Validates that the order column exists in the table.
     *
     * @param Builder $query The Eloquent query builder instance.
     *
     * @return Builder The query builder with ordering conditions applied.
     * @throws \Exception
     */
    private static function applyOrdering(Builder $query): Builder
    {
        $orderBy = request()->input('order', 'created_at');
        $sort = strtolower(request()->input('sort', 'desc'));

        if ($orderBy === 'created_at') {
            $sort = $sort === 'asc' ? 'asc' : 'desc'; // default desc
        } else {
            $sort = $sort === 'desc' ? 'desc' : 'asc'; // default asc
        }

        // Check if the column exists in the table before applying orderBy to prevent SQL errors
        $model = $query->getModel();
        if (Schema::hasColumn($model->getTable(), $orderBy)) {
            $query->orderBy($orderBy, $sort);
        } else {
            Log::warning("Attempted to order by non-existent column: {$orderBy} on table {$model->getTable()}");
            throw new \Exception('Attempted to order by non-existent column: ' . $orderBy);
        }

        return $query;
    }


    /**
     * Applies pagination to the query builder.
     *
     * Determines the number of items per page based on the $pagination parameter,
     * request parameter, or class defaults. Optionally limits pagination to a maximum.
     * If $pagination is 0, all results are returned without pagination.
     *
     * @param Builder $query The Eloquent query builder instance.
     * @param int|false $perPage
     * @param int|false $limitPagination
     * @return LengthAwarePaginator|Collection Returns a paginator instance or a collection of results.
     */
    private static function applyPagination(Builder $query, int|false $perPage, int|false $limitPagination): LengthAwarePaginator|Collection
    {

        if ($perPage === false) {
            return $limitPagination === false ? $query->get() : $query->limit($limitPagination)->get();
        }

        $limit = $limitPagination ? min($perPage, $limitPagination) : $perPage;

        return $limit ? $query->paginate($limit) : $query->get();
    }


}

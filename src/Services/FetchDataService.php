<?php
//namespace Teksite\Handler\Services;
//
//use Closure;
//use Illuminate\Database\Eloquent\Builder;
//use Illuminate\Database\Eloquent\Model;
//use Illuminate\Database\Eloquent\Relations\Relation;
//use Illuminate\Pagination\LengthAwarePaginator;
//use Illuminate\Support\Collection;
//use InvalidArgumentException;
//
//
//class FetchDataService
//{
//    protected int $perPage;
//    protected bool $limitPagination;
//
//    public function __construct()
//    {
//        $this->perPage = config('cms-settings.pagination', 20);
//        $this->limitPagination = config('cms-settings.limit-pagination', true);
//    }
//
//    /**
//     * Main entry point for fetching data either by model or closure.
//     *
//     * @param string|Closure $model The model or closure to use for fetching.
//     * @param string|array|Closure|null $searchColumns The columns to search in.
//     * @param array $only Columns to select.
//     * @param int|null $pagination Pagination limit.
//     * @return mixed
//     */
//    public function __invoke(string|Closure $model, string|array|Closure|null $searchColumns = ['title'], array $only = ['*'], ?int $pagination = null): mixed
//    {
//        if (is_string($model)) {
//            return $this->getFromModel($model, $searchColumns, $only, $pagination);
//        }
//
//        if ($model instanceof Closure) {
//            return $this->getFromClosure($model);
//        }
//
//        // Handle unexpected cases.
//        throw new InvalidArgumentException('Invalid model or closure provided.');
//    }
//
//    /**
//     * Fetch data from closure.
//     *
//     * @param Closure $model The closure that returns the data.
//     * @return mixed
//     */
//    private function getFromClosure(Closure $model)
//    {
//        return $model(); // Execute closure to fetch data.
//    }
//
//    /**
//     * Fetch data from a model with optional search and pagination.
//     *
//     * @param string|Model $model The model or its name.
//     * @param array $searchColumns Columns to search by.
//     * @param array $only Columns to select.
//     * @param int|null $pagination Pagination limit.
//     * @return mixed
//     */
//    private function getFromModel(string|Model $model, array $searchColumns = [], array $only = ['*'], ?int $pagination = null)
//    {
//        $query = $this->only($model, $only); // Apply column selection.
//        $query = $this->applySearch($query, $searchColumns); // Apply search filters.
//
//        return $this->applyPagination($query, $pagination); // Apply pagination.
//    }
//
//    /**
//     * Apply search filters to the query.
//     *
//     * @param Builder $query The query builder instance.
//     * @param array $searchColumns Columns to search by.
//     * @return Builder
//     */
//    private function applySearch(Builder $query, array $searchColumns = []): Builder
//    {
//        // Retrieve search keyword from request if present.
//        $keyword = request('s');
//
//        if ($keyword) {
//            foreach ($searchColumns as $index => $column) {
//                // Handle both string and array-based column search conditions.
//                if (is_string($column)) {
//                    $query = $this->applySearchForColumn($query, $column, $keyword, $index);
//                } elseif (is_array($column)) {
//                    $query = $this->applyAdvancedSearchForColumn($query, $column, $keyword, $index);
//                }
//            }
//        }
//
//        return $query;
//    }
//
//    /**
//     * Apply basic search condition to a column.
//     *
//     * @param Builder $query The query builder instance.
//     * @param string $column The column name.
//     * @param string $keyword The search keyword.
//     * @param int $index The index of the column to determine if `orWhere` is needed.
//     * @return Builder
//     */
//    private function applySearchForColumn(Builder $query, string $column, string $keyword, int $index): Builder
//    {
//        $operator = 'LIKE';
//        $value = "%$keyword%";
//
//        return $index === 0 ? $query->where($column, $operator, $value) : $query->orWhere($column, $operator, $value);
//    }
//
//    /**
//     * Apply advanced search condition to a column.
//     *
//     * @param Builder $query The query builder instance.
//     * @param array $column The column and operation.
//     * @param string $keyword The search keyword.
//     * @param int $index The index of the column.
//     * @return Builder
//     */
//    private function applyAdvancedSearchForColumn(Builder $query, array $column, string $keyword, int $index): Builder
//    {
//        // Use specified column, operation, and apply LIKE or exact match.
//        $columnName = $column['column'] ?? $column[0];
//        $operator = $column['operation'] ?? $column[1] ?? '=';
//        $value = $column['operation'] ?? $column[1] === 'LIKE' ? "%$keyword%" : $keyword;
//
//        return $index === 0 ? $query->where($columnName, $operator, $value) : $query->orWhere($columnName, $operator, $value);
//    }
//
//    /**
//     * Select specific columns for the query.
//     *
//     * @param string|Model $model The model to query.
//     * @param array $only Columns to select.
//     * @return Builder
//     */
//    private function only(string|Model $model, array $only = ['*']): Builder
//    {
//        return $model instanceof Model ? $model->select($only) : (new $model)->select($only);
//    }
//
//    /**
//     * Apply pagination logic to the query.
//     *
//     * @param Builder $query The query builder instance.
//     * @param int|null $pagination The requested pagination size.
//     * @return LengthAwarePaginator|Collection
//     */
//    private function applyPagination(Builder $query, ?int $pagination = null)
//    {
//        if ($pagination) {
//            return $pagination >= 0 ? $query->paginate($pagination) : $query->get();
//        }
//        $requestedPagination = $pagination ?? request()->get('pagination', $this->perPage);
//
//
//        // Determine pagination limit based on config.
//        $paginatingBy = $this->limitPagination ? min($requestedPagination, 250) : $requestedPagination;
//        // Return paginated results if pagination is valid, otherwise return all results.
//        return $paginatingBy ? $query->paginate($paginatingBy) : $query->get();
//
//
//
//    }
//
//    /**
//     * Cache the query result for performance optimization.
//     *
//     * @param Builder $query The query builder instance.
//     * @param string $cacheKey The cache key.
//     * @param int $ttl Cache time-to-live in minutes.
//     * @return mixed
//     */
//    private function cacheQuery(Builder $query, string $cacheKey, int $ttl = 60)
//    {
//        return cache()->remember($cacheKey, $ttl, fn() => $query->get());
//    }
//
//
//    private function applyFilters(Builder $query)
//    {
//        // Example: Filtering by date range.
//        if ($startDate = request('start_date')) {
//            $query->where('created_at', '>=', $startDate);
//        }
//        if ($endDate = request('end_date')) {
//            $query->where('created_at', '<=', $endDate);
//        }
//
//        return $query;
//    }
//}


namespace Teksite\Handler\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class FetchDataService
{
    protected int $perPage;
    protected bool $limitPagination;

    public function __construct()
    {
        $this->perPage = config('cms-settings.pagination', 20);
        $this->limitPagination = config('cms-settings.limit-pagination', true);
    }

    /**
     * Main entry point for fetching data.
     */
    public function __invoke(
        string|Closure|Builder|Relation $model,
        string|array|Closure|null       $searchColumns = ['title'],
        array                           $only = ['*'],
        ?int                            $pagination = null
    ): mixed
    {
        if ($model instanceof Closure) {
            return $this->getFromClosure($model);
        }

        if ($model instanceof Builder || $model instanceof Relation || is_string($model) || $model instanceof Model) {
            return $this->getFromModel($model, $searchColumns, $only, $pagination);
        }

        throw new InvalidArgumentException('Invalid model, builder, relation or closure provided.');
    }

    /**
     * Fetch data from closure.
     */
    private function getFromClosure(Closure $closure): mixed
    {
        return $closure();
    }

    /**
     * Fetch data from model / relation / builder.
     */
    private function getFromModel(
        string|Model|Builder|Relation $model,
        string|array|Closure|null     $searchColumns,
        array                         $only,
        ?int                          $pagination
    ): mixed
    {
        // Convert input into a Builder
        if ($model instanceof Relation) {
            $query = $model->getQuery();
        } elseif ($model instanceof Builder) {
            $query = $model;
        } elseif ($model instanceof Model) {
            $query = $model->newQuery();
        } else {
            $query = (new $model)->newQuery();
        }

        $query->select($only);

        // Apply search
        if ($searchColumns) {
            $query = $this->applySearch($query, (array)$searchColumns);
        }

        return $this->applyPagination($query, $pagination);
    }

    /**
     * Apply search filters.
     */
    private function applySearch(Builder $query, array $searchColumns): Builder
    {
        $keyword = request('s');

        if (!$keyword) {
            return $query;
        }

        $query->where(function ($q) use ($searchColumns, $keyword) {
            foreach ($searchColumns as $index => $column) {
                if (is_string($column)) {
                    $index === 0
                        ? $q->where($column, 'LIKE', "%$keyword%")
                        : $q->orWhere($column, 'LIKE', "%$keyword%");
                } elseif (is_array($column)) {
                    $col = $column['column'] ?? $column[0];
                    $op = $column['operation'] ?? $column[1] ?? '=';
                    $val = ($op === 'LIKE') ? "%$keyword%" : $keyword;

                    $index === 0
                        ? $q->where($col, $op, $val)
                        : $q->orWhere($col, $op, $val);
                }
            }
        });

        return $query;
    }

    /**
     * Apply pagination.
     */
    private function applyPagination(Builder $query, ?int $pagination): LengthAwarePaginator|Collection
    {
        if ($pagination === 0) {
            return $query->get();
        }

        $requested = $pagination ?? request()->integer('pagination', $this->perPage);
        $limit = $this->limitPagination ? min($requested, 250) : $requested;

        return $limit > 0 ? $query->paginate($limit) : $query->get();
    }
}

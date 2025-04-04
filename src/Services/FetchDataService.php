<?php
namespace Teksite\Handler\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class FetchDataService
{
    protected int $perPage;
    protected bool $limitPagination;
    protected string $defaultOrderColumn = 'created_at';
    protected string $defaultSortDirection = 'desc';

    public function __construct()
    {
        $this->perPage = config('cms-settings.pagination', 20);
        $this->limitPagination = config('cms-settings.limit-pagination', true);
    }

    /**
     * Main entry point for fetching data either by model, closure, or query builder.
     *
     * @param string|Closure|Builder $model The model, closure, or query builder to use for fetching.
     * @param string|array|Closure|null $searchColumns The columns to search in.
     * @param array $only Columns to select.
     * @param int|null $pagination Pagination limit.
     * @return mixed
     */
    public function __invoke(string|Closure|Builder $model, string|array|Closure|null $searchColumns = ['title'], array $only = ['*'], ?int $pagination = null): mixed
    {
        if (is_string($model)) {
            return $this->getFromModel($model, $searchColumns, $only, $pagination);
        }

        if ($model instanceof Closure) {
            return $this->getFromClosure($model);
        }

        if ($model instanceof Builder) {
            return $this->getFromQueryBuilder($model, $searchColumns, $only, $pagination);
        }

        throw new InvalidArgumentException('Invalid model, closure, or query builder provided.');
    }

    /**
     * Fetch data from closure.
     *
     * @param Closure $model The closure that returns the data.
     * @return mixed
     */
    private function getFromClosure(Closure $model)
    {
        return $model();
    }

    /**
     * Fetch data from a model with optional search, sorting, and pagination.
     *
     * @param string|Model $model The model or its name.
     * @param array $searchColumns Columns to search by.
     * @param array $only Columns to select.
     * @param int|null $pagination Pagination limit.
     * @return mixed
     */
    private function getFromModel(string|Model $model, array $searchColumns = [], array $only = ['*'], ?int $pagination = null)
    {
        $query = $this->only($model, $only);
        $query = $this->applySearch($query, $searchColumns);
        $query = $this->applySorting($query);
        $query = $this->applyFilters($query);

        return $this->applyPagination($query, $pagination);
    }

    /**
     * Fetch data from a query builder with optional search, sorting, and pagination.
     *
     * @param Builder $query The query builder instance.
     * @param array $searchColumns Columns to search by.
     * @param array $only Columns to select.
     * @param int|null $pagination Pagination limit.
     * @return mixed
     */
    private function getFromQueryBuilder(Builder $query, array $searchColumns = [], array $only = ['*'], ?int $pagination = null)
    {
        $query = $this->applyColumnsToBuilder($query, $only);
        $query = $this->applySearch($query, $searchColumns);
        $query = $this->applySorting($query);
        $query = $this->applyFilters($query);

        return $this->applyPagination($query, $pagination);
    }

    /**
     * Apply column selection to an existing query builder.
     *
     * @param Builder $query The query builder instance.
     * @param array $only Columns to select.
     * @return Builder
     */
    private function applyColumnsToBuilder(Builder $query, array $only = ['*']): Builder
    {
        if ($only !== ['*']) {
            $query->select($only);
        }
        return $query;
    }

    /**
     * Apply search filters to the query.
     *
     * @param Builder $query The query builder instance.
     * @param array $searchColumns Columns to search by.
     * @return Builder
     */
    private function applySearch(Builder $query, array $searchColumns = []): Builder
    {
        $keyword = request('s');

        if ($keyword) {
            foreach ($searchColumns as $index => $column) {
                if (is_string($column)) {
                    $query = $this->applySearchForColumn($query, $column, $keyword, $index);
                } elseif (is_array($column)) {
                    $query = $this->applyAdvancedSearchForColumn($query, $column, $keyword, $index);
                }
            }
        }

        return $query;
    }

    /**
     * Apply basic search condition to a column.
     *
     * @param Builder $query The query builder instance.
     * @param string $column The column name.
     * @param string $keyword The search keyword.
     * @param int $index The index of the column to determine if `orWhere` is needed.
     * @return Builder
     */
    private function applySearchForColumn(Builder $query, string $column, string $keyword, int $index): Builder
    {
        $operator = 'LIKE';
        $value = "%$keyword%";

        return $index === 0 ? $query->where($column, $operator, $value) : $query->orWhere($column, $operator, $value);
    }

    /**
     * Apply advanced search condition to a column.
     *
     * @param Builder $query The query builder instance.
     * @param array $column The column and operation.
     * @param string $keyword The search keyword.
     * @param int $index The index of the column.
     * @return Builder
     */
    private function applyAdvancedSearchForColumn(Builder $query, array $column, string $keyword, int $index): Builder
    {
        $columnName = $column['column'] ?? $column[0];
        $operator = $column['operation'] ?? $column[1] ?? '=';
        $value = ($operator === 'LIKE') ? "%$keyword%" : $keyword;

        return $index === 0 ? $query->where($columnName, $operator, $value) : $query->orWhere($columnName, $operator, $value);
    }

    /**
     * Select specific columns for the query.
     *
     * @param string|Model $model The model to query.
     * @param array $only Columns to select.
     * @return Builder
     */
    private function only(string|Model $model, array $only = ['*']): Builder
    {
        return $model instanceof Model ? $model->select($only) : (new $model)->select($only);
    }

    /**
     * Apply sorting to the query based on request parameters.
     *
     * @param Builder $query The query builder instance.
     * @return Builder
     */
    private function applySorting(Builder $query): Builder
    {
        $orderColumn = request('order', $this->defaultOrderColumn);
        $sortDirection = request('sort', $this->defaultSortDirection);
        $sortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($orderColumn, $sortDirection);
    }

    /**
     * Apply pagination logic to the query.
     *
     * @param Builder $query The query builder instance.
     * @param int|null $pagination The requested pagination size.
     * @return LengthAwarePaginator|Collection
     */
    private function applyPagination(Builder $query, ?int $pagination = null)
    {
        $requestedPagination = $pagination ?? request()->get('pagination', $this->perPage);
        $paginatingBy = $this->limitPagination ? min($requestedPagination, 250) : $requestedPagination;

        return $paginatingBy ? $query->paginate($paginatingBy) : $query->get();
    }

    /**
     * Apply additional filters to the query.
     *
     * @param Builder $query The query builder instance.
     * @return Builder
     */
    private function applyFilters(Builder $query): Builder
    {
        if ($startDate = request('start_date')) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate = request('end_date')) {
            $query->where('created_at', '<=', $endDate);
        }

        return $query;
    }
}

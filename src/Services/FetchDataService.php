<?php

namespace Teksite\Handler\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
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
        $query = $this->applyOrdering($query);

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



    private function applyOrdering(Builder $query): Builder
    {
        $orderBy = request()->get('order' ,'created_at');
        $sort = request()->get('sort' ,'desc');

        if (!$orderBy) {
            return $query;
        }

        // Default sort rules
        if ($orderBy === 'created_at') {
            $sort = $sort === 'asc' ? 'asc' : 'desc'; // default desc
        } else {
            $sort = $sort === 'desc' ? 'desc' : 'asc'; // default asc
        }

        // Validate column exists to avoid SQL errors
        $model = $query->getModel();
        if (Schema::hasColumn($model->getTable(), $orderBy)) {
            $query->orderBy($orderBy, $sort);
        }

        return $query;
    }
}

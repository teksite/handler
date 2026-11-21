<?php

namespace Teksite\Handler\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;


interface FetchDataContract
{
    public function get(
        string|Model|Builder|Relation|Closure $model,
        string|array|null                     $searchColumns = null,
        array|string                          $only = null,
        int|false|null                        $perPage = null,
        int|false|null                        $limitPagination = null,
        array                                 $with = [],
        array                                 $withCount = []
    ): Collection|LengthAwarePaginator;


    public function only(array|string|Closure $columns): static;

    public function with(array|string|Closure $relations): static;

    public function withCount(array|string|Closure $relations): static;

    public function search(array|string|Closure $columns): static;

    public function orderBy(string|Closure $column): static;

    public function sort(string|Closure $direction): static;

    public function perPage(int|false|Closure $perPage): static;

    public function limitPagination(int|false|Closure $limit): static;

    public function resetOnly(): static;

    public function resetWith(): static;

    public function resetWithCount(): static;

    public function resetSearch(): static;

    public function resetOrdering(): static;

    public function resetPagination(): static;

    public function reset(): static;

    public function forgetColumnsCache(string|Model $model): void;

    public function forgetAllColumnsCache(): void;


}

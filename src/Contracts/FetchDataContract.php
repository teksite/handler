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
}

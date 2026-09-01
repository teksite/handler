<?php

namespace Teksite\Handler\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;


interface FetchDataContract {
    public function get(
        string|Model|Builder|Relation $model,
        string|array|null             $searchColumns = null,
        array|string                  $only = ['*'],
        int|false|null                $perPage = null,
        int|false|null                $limitPagination = null,
        array                         $with = [],
        array                         $withCount = []
    ): static;
}

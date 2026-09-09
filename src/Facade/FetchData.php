<?php

namespace Teksite\Handler\Facade;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Teksite\Handler\Contracts\FetchDataContract;

/**
 * @method static FetchDataContract only(array|string $columns,)
 * @method static FetchDataContract with(array|string $relations,)
 * @method static FetchDataContract withCount(array|string $relations,)
 * @method static FetchDataContract search(array|string $columns,)
 * @method static FetchDataContract orderBy(string $column,)
 * @method static FetchDataContract sort(string $direction,)
 * @method static FetchDataContract perPage(int|false $perPage,)
 * @method static FetchDataContract limitPagination(int|false $limit,)
 * @method static FetchDataContract resetOnly()
 * @method static FetchDataContract resetWith()
 * @method static FetchDataContract resetWithCount()
 * @method static FetchDataContract resetSearch()
 * @method static FetchDataContract resetOrdering()
 * @method static FetchDataContract resetPagination()
 * @method static FetchDataContract reset()
 *
 * @method static Collection|LengthAwarePaginator get(string|Model|Builder|Relation|Closure $model, string|array|null $searchColumns = null, array|string|null $only = null, int|false|null $perPage = null, int|false|null $limitPagination = null, array $with = [], array $withCount = [],)
 *
 * @method static void forgetColumnsCache(string|Model $model,)
 * @method static void forgetAllColumnsCache()
 *
 * @see \Teksite\Handler\Services\FetchDataService
 */
class FetchData extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FetchDataContract::class;
    }
}

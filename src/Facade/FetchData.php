<?php

namespace Teksite\Handler\Facade;

use Illuminate\Support\Facades\Facade;
use Teksite\Handler\Contracts\FetchDataContract;

/**
 * @method static \Teksite\Handler\Contracts\FetchDataContract only(array|string $columns)
 * @method static \Teksite\Handler\Contracts\FetchDataContract with(array|string $relations)
 * @method static \Teksite\Handler\Contracts\FetchDataContract withCount(array|string $relations)
 * @method static \Teksite\Handler\Contracts\FetchDataContract search(array|string $columns)
 * @method static \Teksite\Handler\Contracts\FetchDataContract orderBy(string $column)
 * @method static \Teksite\Handler\Contracts\FetchDataContract sort(string $direction)
 * @method static \Teksite\Handler\Contracts\FetchDataContract perPage(int|false $perPage)
 * @method static \Teksite\Handler\Contracts\FetchDataContract limitPagination(int|false $limit)
 * @method static \Teksite\Handler\Contracts\FetchDataContract resetOnly()
 * @method static \Teksite\Handler\Contracts\FetchDataContract resetWith()
 * @method static \Teksite\Handler\Contracts\FetchDataContract resetWithCount()
 * @method static \Teksite\Handler\Contracts\FetchDataContract resetSearch()
 * @method static \Teksite\Handler\Contracts\FetchDataContract resetOrdering()
 * @method static \Teksite\Handler\Contracts\FetchDataContract resetPagination()
 * @method static \Teksite\Handler\Contracts\FetchDataContract reset()
 *
 * @method static \Illuminate\Support\Collection|\Illuminate\Pagination\LengthAwarePaginator get( string|\Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation|\Closure $model, string|array|null $searchColumns = null, array|string|null $only = null, int|false|null $perPage = null, int|false|null $limitPagination = null, array $with = [], array $withCount = [])
 *
 * @method static void forgetColumnsCache(string|\Illuminate\Database\Eloquent\Model $model)
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

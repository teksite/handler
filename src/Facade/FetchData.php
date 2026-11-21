<?php

namespace Teksite\Handler\Facade;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Teksite\Handler\Contracts\FetchDataContract;

/**
 * Every static call resolves a brand-new service instance, so fluent settings
 * never leak between calls, requests or jobs. Chain the calls to keep them on
 * one instance: FetchData::only(['id'])->with(['user'])->get(Post::class).
 *
 * @method static FetchDataContract only(array|string|Closure $columns)
 * @method static FetchDataContract with(array|string|Closure $relations)
 * @method static FetchDataContract withCount(array|string|Closure $relations)
 * @method static FetchDataContract search(array|string|Closure $columns)
 * @method static FetchDataContract orderBy(string|Closure $column)
 * @method static FetchDataContract sort(string|Closure $direction)
 * @method static FetchDataContract perPage(int|false|Closure $perPage)
 * @method static FetchDataContract limitPagination(int|false|Closure $limit)
 * @method static FetchDataContract resetOnly()
 * @method static FetchDataContract resetWith()
 * @method static FetchDataContract resetWithCount()
 * @method static FetchDataContract resetSearch()
 * @method static FetchDataContract resetOrdering()
 * @method static FetchDataContract resetPagination()
 * @method static FetchDataContract reset()
 *
 * @method static Collection|LengthAwarePaginator get(string|Model|Builder|Relation|Closure $model, string|array|null $searchColumns = null, array|string|null $only = null, int|false|null $perPage = null, int|false|null $limitPagination = null, array $with = [], array $withCount = [])
 *
 * @method static void forgetColumnsCache(string|Model $model)
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

    /**
     * Always resolve a fresh instance and bypass Laravel's facade instance caching:
     * the service is stateful and holds the current request.
     *
     * @throws BindingResolutionException
     */
    protected static function resolveFacadeInstance($name)
    {
        return static::$app->make($name);
    }
}

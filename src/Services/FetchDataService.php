<?php

namespace Teksite\Handler\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Teksite\Handler\Traits\FetchServiceCache;
use Throwable;

//use Teksite\Handler\Contracts\FetchDataContract;

class FetchDataService
{
    use FetchServiceCache;

    private Builder|null $query = null;

    private array $withRelations = [];
    private array $withCountRelations = [];
    private array $only = [];
    private array $searchColumns = [];


    private int|false|null $perPage = null;
    private int|false|null $maxPagination = null;


    private const array ALLOWED_OPERATORS = ['=', '!=', '<>', '>', '<', '<=', '>=', 'LIKE', 'ILIKE',];

    private const array ALLOWED_SORT_DIRECTIONS = ['asc', 'desc',];


    public function __construct(private readonly Request $request)
    {
//        $this->perPage = config('handler.pagination', 25);
//        $this->maxPagination = config('handler.limit-pagination', 250);
//        $this->searchField = config('handler.search_input_field');
//        $this->orderBy = config('handler.default_order_by', 'created_at');
//        $this->sortDirection = config('handler.default_sort_direction', 'desc');
    }


    public function get(
        string|Model|Builder|Relation|Closure $model,
        string|array|null                     $searchColumns = null,
        array|string|null                     $only = null,
        int|false|null                        $perPage = null,
        int|false|null                        $limitPagination = null,
        array                                 $with = [],
        array                                 $withCount = []
    ): FetchDataService
    {
        $this->resolveQuery($model);
        $this->withRelations = self::mergeRelations($this->withRelations, $this->normalizeRelations($with));
        $this->withCountRelations = self::mergeRelations($this->withCountRelations, $this->normalizeRelations($withCount));
        $this->only = $this->mergeColumns($this->only, $this->normalizeColumns($only));
        $this->searchColumns = self::uniqueColumns([...$this->searchColumns, ...$this->normalizeColumns($searchColumns ?? [])]);
        $this->perPage = $perPage;
        $this->maxPagination = $limitPagination;
        return $this;
    }


    private function resolveQuery(string|Model|Builder|Relation|Closure $model): void
    {
        $query = match (true) {

            $model instanceof Closure  => $model(),

            $model instanceof Builder  => $model,

            $model instanceof Relation => $model->getQuery(),

            $model instanceof Model    => $model->newQuery(),

            is_string($model)          => self::newModelQuery($model),

            default                    => throw new InvalidArgumentException(
                sprintf(
                    'Expected a Model class, Model instance, Builder or Relation; %s given.',
                    get_debug_type($model)
                )
            ),
        };
        $this->query = $query;
    }


    public function with(array|string|Closure $relations)
    {
        $relations = self::normalizeRelations($relations);

        if ($relations === []) return $this;

        $this->withRelations = self::mergeRelations($this->withRelations, $relations);

        return $this;
    }

    public function withCount(array|string|Closure $relations)
    {
        $relations = $this->normalizeRelations($relations);

        if ($relations === []) return $this;

        $this->withCountRelations = $this->mergeRelations($this->withCountRelations, $relations);

        return $this;
    }

    private function normalizeRelations(array|string|null $relations): array
    {
        if ($relations === null) return [];

        if (is_string($relations)) $relations = explode(',', $relations);

        $result = [];

        foreach ($relations as $key => $relation) {

            if (is_int($key)) {
                if (!is_string($relation)) continue;

                $relation = trim($relation);

                if ($relation !== '') $result[] = $relation;

                continue;
            }

            /*
             * Constrained relation:
             *
             * [
             *     'posts' => fn (Builder $query) => ...
             * ]
             *
             * Keep it exactly as Laravel expects.
             */
            if (!is_string($key) || trim($key) === '') continue;

            $result[trim($key)] = $relation;
        }

        return $result;
    }

    private function mergeRelations(array $first, array $second): array
    {
        $result = [];

        /*
         * First pass: numeric relations.
         */
        foreach ([$first, $second] as $relations) {
            foreach ($relations as $key => $value) {

                if (is_int($key)) {
                    if (!is_string($value)) continue;
                    $value = trim($value);
                    if ($value === '') continue;
                    $result[$value] = $value;
                }

                if (is_string($key)) {
                    $key = trim($key);
                    if ($key === '') continue;
                    $result[$key] = $value;
                }
            }
        }


        return array_values(
                array_filter($result, fn($value) => is_string($value) || is_array($value) || $value instanceof Closure))
            + array_filter($result, fn($value, $key) => is_string($key) && !is_int($key), ARRAY_FILTER_USE_BOTH);
    }


    public function only(array|string $only): void
    {
        $this->only = $this->mergeColumns($this->only, $this->normalizeColumns($only));

    }

    private function normalizeColumns(array|string|null $columns): array
    {
        if ($columns === null) return [];

        if (is_string($columns)) $columns = explode(',', $columns);

        $result = [];
        foreach ($columns as $column) {
            if (is_array($column)) {
                $result[] = $column;
                continue;
            }

            if (!is_string($column)) continue;

            $column = trim($column);

            if ($column !== '') $result[] = $column;
        }
        return $result;
    }

    private function mergeColumns(array $fluentColumns, array $getColumns): array
    {
        if (in_array('*', $fluentColumns, true) || in_array('*', $getColumns, true)) return ['*'];

        if ($fluentColumns === [] && $getColumns === []) return ['*'];

        return self::uniqueColumns([...$fluentColumns, ...$getColumns,]);
    }

    private function uniqueColumns(array $columns): array
    {
        $result = [];

        foreach ($columns as $column) {
            if (is_array($column)) {
                $key = serialize($column);
                if (!isset($result[$key])) $result[$key] = $column;
                continue;
            }

            if (!is_string($column)) continue;

            $column = trim($column);

            if ($column !== '') $result[$column] = $column;
        }

        return array_values($result);
    }


    private function resolveClosure(mixed $value): mixed
    {
        return $value instanceof Closure ? $value($this->query) : $value;
    }


    private function stringInput(string $key, string $default): string
    {
        $value = $this->request->input($key, $default);
        return is_string($value) ? $value : $default;
    }


    private function applyEagerLoading(): void
    {
        $relations = $this->withRelations
        if ($relations !== []) $this->query->with($relations);
    }

    private function applyWithCount(): void
    {
        $withCountRelations = $this->withCountRelations;
        if ($withCountRelations !== []) $this->query->with($withCountRelations);
    }

    private function applySelection(): void
    {
        $only = $this->only;
        $with = $this->withRelations;

        if ($only === [] || in_array('*', $only, true)) return;

        $model = $this->query->getModel();

        $primaryKey = $model->getKeyName();

        if (!in_array($primaryKey, $only, true)) {
            $only[] = $primaryKey;
        }

        $this->addRelationKeys($model, $only, $with);

        $this->query->select(self::uniqueColumns($only));
    }

    private function addRelationKeys(Model $model, array &$columns, array $relations): void
    {
        foreach ($relations as $relationDefinition) {
            if (!is_string($relationDefinition)) continue;

            $relationName = explode(':', $relationDefinition, 2)[0];

            if ($relationName === '') continue;

            $segments = explode('.', $relationName);

            $currentModel = $model;

            foreach ($segments as $segment) {
                if (!method_exists($currentModel, $segment)) break;

                try {
                    $relation = $currentModel->{$segment}();

                    if (!$relation instanceof Relation) break;


                    if (method_exists($relation, 'getForeignKeyName')) {
                        $columns[] = $relation->getForeignKeyName();
                    }

                    if (method_exists($relation, 'getLocalKeyName')) {
                        $columns[] = $relation->getLocalKeyName();
                    }

                    $currentModel = $relation->getRelated();
                } catch (Throwable $e) {
                    Log::debug(
                        'FetchDataService: could not resolve relation for column selection.',
                        [
                            'relation' => $relationName,
                            'error'    => $e->getMessage(),
                        ]
                    );

                    break;
                }
            }
        }
    }

    private function resolvePerPage(null|int|false $perPage): int|false
    {

        if ($perPage !== null) return $perPage;

        $perPageQuery = config('handler.per_page_query', 'per_page');

        $requestPerPage = $this->request->input($perPageQuery);

        if ($requestPerPage > 0) return $requestPerPage;

        return (int)config('handler.pagination', 25);
    }

    private function resolveLimitPagination(null|false|int $limitPagination): int|false
    {
        if ($limitPagination !== null) return $limitPagination;

        return (int)config('handler.limit-pagination', 250);
    }

    private function applyPagination(): void
    {
        $perPage = $this->resolvePerPage($this->perPage);
        $limitPagination = $this->resolveLimitPagination($this->maxPagination)

        if ($perPage === false) {
            if ($limitPagination !== false) $this->query->limit($limitPagination);
            $this->query->get();
            return;
        }

        if ($limitPagination !== false) $perPage = min($perPage, $limitPagination);

        $this->query->paginate($perPage)->withQueryString();
    }


    private function applyOrdering(): void
    {
        $defaultOrderBy = config('handler.default_order_by', 'created_at');

        $defaultSort = strtolower((string)config('handler.default_sort_direction', 'desc'));

        if (!in_array($defaultSort, self::ALLOWED_SORT_DIRECTIONS, true)) $defaultSort = 'desc';

        $orderBy = $this->stringInput('order', $defaultOrderBy);

        $sort = strtolower($this->stringInput('sort', $defaultSort));

        if (!in_array($sort, self::ALLOWED_SORT_DIRECTIONS, true)) $sort = $defaultSort;

        if (str_contains($orderBy, '.')) $orderBy = $defaultOrderBy;


        $model = $this->query->getModel();

        $columns = self::getTableColumns($model->getTable(), $model->getConnectionName());

        /*
         * Validate order column against real DB columns.
         */
        if (!in_array($orderBy, $columns, true)) {
            $orderBy = in_array('created_at', $columns, true)
                ? 'created_at'
                : $model->getKeyName();
        }
        $this->query->orderBy($orderBy, $sort);
    }


    public function __destruct()
    {
        $this->applyEagerLoading();
        $this->applySelection();
        $this->applyWithCount();

        $this->applySearch();
        $this->applyOrdering();
        $this->applyPagination();

        return $this->query;
    }


}

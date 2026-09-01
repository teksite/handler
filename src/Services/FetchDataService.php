<?php

namespace Teksite\Handler\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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


    public function __construct(private readonly Request $request) {}


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
        $this->withRelations = $this->mergeRelations($this->withRelations, $this->normalizeRelations($with));
        $this->withCountRelations = $this->mergeRelations($this->withCountRelations, $this->normalizeRelations($withCount));
        $this->only = $this->mergeColumns($this->only, $this->normalizeColumns($only));
        $this->searchColumns = $this->uniqueColumns([...$this->searchColumns, ...$this->normalizeColumns($searchColumns ?? [])]);
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

            is_string($model)          => $this->newModelQuery($model),

            default                    => throw new InvalidArgumentException(
                sprintf(
                    'Expected a Model class, Model instance, Builder or Relation; %s given.',
                    get_debug_type($model)
                )
            ),
        };
        $this->query = $query;
    }

    private function newModelQuery(string $modelClass): Builder
    {
        if (!is_a($modelClass, Model::class, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'The given class [%s] must extend [%s].',
                    $modelClass,
                    Model::class
                )
            );
        }
        return (new $modelClass)->newQuery();
    }

    public function with(array|string|Closure $relations): static
    {
        $relations = $this->normalizeRelations($relations);

        if ($relations === []) return $this;

        $this->withRelations = $this->mergeRelations($this->withRelations, $relations);

        return $this;
    }

    public function withCount(array|string|Closure $relations): static
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

        return $this->uniqueColumns([...$fluentColumns, ...$getColumns,]);
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
        $relations = $this->withRelations;
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

        $this->query->select($this->uniqueColumns($only));
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

        $limitPagination = $this->resolveLimitPagination($this->maxPagination);

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

        $columns = $this->getTableColumns($model->getTable(), $model->getConnectionName());

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


    private function applySearch(): void
    {
        $searchColumns= $this->searchColumns;

        $searchInput = config('handler.search_input_field', 's');

        $keyword = trim($this->stringInput($searchInput, ''));

        if ($keyword === '') return;


        $keyword = mb_substr($keyword, 0, self::MAX_SEARCH_KEYWORD_LENGTH);

        $this->query->where(function (Builder $q) use ($searchColumns, $keyword) {

            $hasCondition = false;

            foreach ($searchColumns as $definition) {

                if (!is_string($definition) && !is_array($definition)) continue;

                $condition = $this->parseSearchCondition($definition, $keyword);

                if ($condition === null) continue;

                ['column' => $column, 'operator' => $operator, 'value' => $value,] = $condition;

                /*
                 * Relation search:
                 *
                 * user.name
                 */
                if (str_contains($column, '.')) {
                    $relation = $this->extractRelationName($column);

                    $field = $this->extractColumnName($column);

                    $method = $hasCondition ? 'orWhereHas' : 'whereHas';

                    $q->{$method}($relation, fn(Builder $rq) => $rq->where($field, $operator, $value));

                    $hasCondition = true;

                    continue;
                }

                $method = $hasCondition ? 'orWhere' : 'where';

                $q->{$method}($column, $operator, $value);

                $hasCondition = true;
            }

            /*
             * Invalid/empty search definition:
             * return no records rather than all records.
             */
            if (!$hasCondition) $q->whereRaw('1 = 0');

        });
    }

    private function parseSearchCondition(string|array $definition, string $keyword): ?array
    {
        $valueProvided = false;

        if (is_array($definition)) {
            $column = $definition['column'] ?? $definition[0] ?? null;

            $operator = strtoupper((string)($definition['operation'] ?? $definition['operator'] ?? $definition[1] ?? 'LIKE'));

            if (array_key_exists('value', $definition)) {
                $value = $definition['value'];
                $valueProvided = true;
            } elseif (array_key_exists(2, $definition)) {
                $value = $definition[2];
                $valueProvided = true;
            } else {
                $value = $keyword;
            }
        } else {
            $column = $definition;
            $operator = 'LIKE';
            $value = $keyword;
        }

        if (!is_string($column) || trim($column) === '') return null;


        $column = trim($column);

        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) $operator = 'LIKE';


        /*
         * ILIKE only exists on PostgreSQL.
         */
        if ($operator === 'ILIKE' && DB::getDriverName() !== 'pgsql') $operator = 'LIKE';


        if (in_array($operator, ['LIKE', 'ILIKE'], true)) {
            $value = (string)$value;

            /*
             * Escape LIKE wildcards only when the value
             * comes from the user's search keyword.
             *
             * Explicit values are considered intentional.
             */
            if (!$valueProvided) $value = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

            if (!str_contains($value, '%')) $value = "%{$value}%";

        }

        return [
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
        ];
    }

    private function extractRelationName(string $path): string
    {
        $parts = explode('.', $path);
        array_pop($parts);
        return implode('.', $parts);
    }

    private function extractColumnName(string $path): string
    {
        return (string)last(explode('.', $path));
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

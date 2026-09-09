<?php

namespace Teksite\Handler\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Teksite\Handler\Contracts\FetchDataContract;
use Teksite\Handler\Traits\FetchServiceCache;
use Throwable;

class FetchDataService implements FetchDataContract
{
    use FetchServiceCache;

    private const array ALLOWED_OPERATORS = ['=', '!=', '<>', '>', '<', '<=', '>=', 'LIKE', 'ILIKE',];

    private const array ALLOWED_SORT_DIRECTIONS = ['asc', 'desc',];

    /**
     * Columns selected through only().
     *
     * @var array<int, string>
     */
    private array $onlyColumns = [];

    /**
     * Eager loaded relationships.
     *
     * Supports:
     *
     * [
     *     'profile',
     *     'posts' => fn (Builder $query) => ...,
     * ]
     *
     * @var array<int|string, mixed>
     */
    private array $withRelations = [];

    /**
     * Relationships used with withCount().
     *
     * @var array<int|string, mixed>
     */
    private array $withCountRelations = [];

    /**
     * Search columns.
     *
     * @var array<int, string|array>
     */
    private array $searchColumns = [];

    /**
     * Fluent order column.
     */
    private ?string $orderColumn = null;

    /**
     * Fluent sort direction.
     */
    private ?string $sortDirection = null;

    /**
     * Fluent per page.
     *
     * null means use the get() argument / request / config.
     * false means don't paginate.
     */
    private int|false|null $perPageValue = null;

    /**
     * Fluent pagination limit.
     *
     * null means use the get() argument / config.
     * false means unlimited.
     */
    private int|false|null $paginationLimit = null;

    public function __construct(private readonly Request $request,) {}

    /*
    |--------------------------------------------------------------------------
    | Fluent API
    |--------------------------------------------------------------------------
    */

    /**
     * Add columns to the select list.
     *
     * Supports:
     *
     * ->only('id')
     * ->only(['id', 'name'])
     * ->only(fn () => ['id', 'name'])
     */
    public function only(array|string|Closure $columns,): static
    {
        $columns = $this->resolveClosure($columns);

        $columns = $this->normalizeColumns($columns);

        if ($columns === []) return $this;

        if (in_array('*', $columns, true)) {
            $this->onlyColumns = ['*'];
            return $this;
        }

        if (in_array('*', $this->onlyColumns, true)) return $this;

        $this->onlyColumns = $this->uniqueColumns([...$this->onlyColumns, ...$columns,]);

        return $this;
    }

    /**
     * Add eager loaded relationships.
     *
     * Supports:
     *
     * ->with('profile')
     * ->with(['profile', 'roles'])
     * ->with(fn () => ['profile', 'roles'])
     *
     * Laravel constrained eager loading is also supported:
     *
     * ->with([
     *     'posts' => fn (Builder $query) =>
     *         $query->latest(),
     * ])
     */
    public function with(array|string|Closure $relations,): static
    {
        $relations = $this->resolveClosure($relations);

        $relations = $this->normalizeRelations($relations);

        if ($relations === []) return $this;

        $this->withRelations = $this->mergeRelations($this->withRelations, $relations);

        return $this;
    }

    /**
     * Add relationships for withCount().
     *
     * Supports:
     *
     * ->withCount('posts')
     * ->withCount(['posts', 'comments'])
     * ->withCount(fn () => ['posts', 'comments'])
     *
     * Laravel constrained withCount() is also supported:
     *
     * ->withCount([
     *     'posts' => fn (Builder $query) =>
     *         $query->where('published', true),
     * ])
     */
    public function withCount(array|string|Closure $relations,): static
    {
        $relations = $this->resolveClosure($relations);

        $relations = $this->normalizeRelations($relations);

        if ($relations === []) return $this;

        $this->withCountRelations = $this->mergeRelations($this->withCountRelations, $relations);

        return $this;
    }

    /**
     * Add search columns.
     *
     * Supports:
     *
     * ->search('name,email')
     * ->search(['name', 'email'])
     * ->search(fn () => ['name', 'email'])
     *
     * Advanced:
     *
     * ->search([
     *     'name',
     *     ['email', 'LIKE'],
     * ])
     */
    public function search(array|string|Closure $columns,): static
    {
        $columns = $this->resolveClosure($columns);

        $columns = $this->normalizeColumns($columns);

        if ($columns === []) return $this;

        $this->searchColumns = $this->uniqueColumns([...$this->searchColumns, ...$columns,]);

        return $this;
    }

    /**
     * Set ordering column.
     *
     * Supports:
     *
     * ->orderBy('created_at')
     * ->orderBy(fn () => 'created_at')
     */
    public function orderBy(string|Closure $column,): static
    {
        $column = $this->resolveClosure($column);

        if (!is_string($column)) {
            throw new InvalidArgumentException('The orderBy value must resolve to a string.');
        }

        $column = trim($column);

        if ($column !== '') $this->orderColumn = $column;

        return $this;
    }

    /**
     * Set sorting direction.
     *
     * Supports:
     *
     * ->sort('asc')
     * ->sort(fn () => 'desc')
     */
    public function sort(string|Closure $direction,): static
    {
        $direction = $this->resolveClosure($direction);

        if (!is_string($direction)) throw new InvalidArgumentException('The sort value must resolve to a string.');

        $direction = strtolower(trim($direction));

        if (in_array($direction, self::ALLOWED_SORT_DIRECTIONS, true)) $this->sortDirection = $direction;

        return $this;
    }

    /**
     * Set items per page.
     *
     * Supports:
     *
     * ->perPage(20)
     * ->perPage(false)
     * ->perPage(fn () => 20)
     */
    public function perPage(int|false|Closure $perPage,): static
    {
        $perPage = $this->resolveClosure($perPage);

        if ($perPage !== false && !is_int($perPage)) throw new InvalidArgumentException('The perPage value must resolve to an integer or false.');

        if ($perPage !== false && $perPage < 1) throw new InvalidArgumentException('The perPage value must be greater than zero or false.');

        $this->perPageValue = $perPage;

        return $this;
    }

    /**
     * Set maximum pagination limit.
     *
     * Supports:
     *
     * ->limitPagination(100)
     * ->limitPagination(false)
     * ->limitPagination(fn () => 100)
     */
    public function limitPagination(int|false|Closure $limit,): static
    {
        $limit = $this->resolveClosure($limit);

        if ($limit !== false && !is_int($limit)) throw new InvalidArgumentException('The pagination limit must resolve to an integer or false.');

        if ($limit !== false && $limit < 1) throw new InvalidArgumentException('The pagination limit must be greater than zero or false.');

        $this->paginationLimit = $limit;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Reset API
    |--------------------------------------------------------------------------
    */

    public function resetOnly(): static
    {
        $this->onlyColumns = [];

        return $this;
    }

    public function resetWith(): static
    {
        $this->withRelations = [];

        return $this;
    }

    public function resetWithCount(): static
    {
        $this->withCountRelations = [];

        return $this;
    }

    public function resetSearch(): static
    {
        $this->searchColumns = [];

        return $this;
    }

    public function resetOrdering(): static
    {
        $this->orderColumn = null;
        $this->sortDirection = null;

        return $this;
    }

    public function resetPagination(): static
    {
        $this->perPageValue = null;
        $this->paginationLimit = null;

        return $this;
    }

    /**
     * Reset all fluent settings.
     */
    public function reset(): static
    {
        $this->onlyColumns = [];
        $this->withRelations = [];
        $this->withCountRelations = [];
        $this->searchColumns = [];
        $this->orderColumn = null;
        $this->sortDirection = null;
        $this->perPageValue = null;
        $this->paginationLimit = null;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Main Query
    |--------------------------------------------------------------------------
    */

    /**
     * Build and run the query, combining any fluent configuration with the
     * arguments given here, and return the fetched records.
     */
    public function get(
        string|Model|Builder|Relation|Closure $model,
        string|array|null                     $searchColumns = null,
        array|string|null                     $only = null,
        int|false|null                        $perPage = null,
        int|false|null                        $limitPagination = null,
        array                                 $with = [],
        array                                 $withCount = [],
    ): Collection|LengthAwarePaginator
    {
        $query = $this->resolveQuery($model);

        $relations = $this->mergeRelations($this->withRelations, $this->normalizeRelations($with));

        $counts = $this->mergeRelations($this->withCountRelations, $this->normalizeRelations($withCount));

        $selectedColumns = $this->mergeColumns($this->onlyColumns, $this->normalizeColumns($only));

        $searchColumns = $this->uniqueColumns([...$this->searchColumns, ...$this->normalizeColumns($searchColumns ?? [])]);

        $this->applyEagerLoading($query, $relations);

        $this->applySelection($query, $selectedColumns, $relations);

        if ($counts !== []) $query->withCount($counts);

        if ($searchColumns !== []) $query = $this->applySearch($query, $searchColumns);

        $query = $this->applyOrdering($query);

        return $this->applyPagination(
            $query,
            $this->resolvePerPage($perPage),
            $this->resolveLimitPagination($limitPagination),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Closure Resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve fluent configuration Closures, e.g. ->only(fn () => ['id']).
     * The service instance is passed in, so ->only(fn ($fetch) => [...]) also works.
     */
    private function resolveClosure(mixed $value,): mixed
    {
        return $value instanceof Closure ? $value($this) : $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Query Resolution
    |--------------------------------------------------------------------------
    */

    private function resolveQuery(string|Model|Builder|Relation|Closure $model,): Builder
    {
        return match (true) {
            $model instanceof Closure  => $model(),

            $model instanceof Builder  => $model,

            $model instanceof Relation => $model->getQuery(),

            $model instanceof Model    => $model->newQuery(),

            is_string($model)          => $this->newModelQuery($model),

            default                    => throw new InvalidArgumentException(
                sprintf(
                    'Expected a Model class, Model instance, Builder, Relation or Closure; %s given.',
                    get_debug_type($model),
                ),
            ),
        };
    }

    private function newModelQuery(string $modelClass,): Builder
    {
        if (!is_a($modelClass, Model::class, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'The given class [%s] must extend [%s].',
                    $modelClass,
                    Model::class,
                ),
            );
        }

        return (new $modelClass)->newQuery();
    }

    /*
    |--------------------------------------------------------------------------
    | Eager Loading
    |--------------------------------------------------------------------------
    */

    private function applyEagerLoading(Builder $query, array $relations,): void
    {
        if ($relations !== []) $query->with($relations);
    }

    /*
    |--------------------------------------------------------------------------
    | Selection
    |--------------------------------------------------------------------------
    */

    private function applySelection(Builder $query, array $only, array $with = [],): void
    {
        if ($only === [] || in_array('*', $only, true)) return;

        $model = $query->getModel();

        $primaryKey = $model->getKeyName();

        if (!in_array($primaryKey, $only, true)) {
            $only[] = $primaryKey;
        }

        $this->addRelationKeys($model, $only, $with);

        $query->select($this->uniqueColumns($only));
    }

    /**
     * Add foreign/local keys required by eager loaded relations.
     *
     * This is intentionally conservative. If a relation cannot safely
     * be resolved, no additional column is injected.
     */
    private function addRelationKeys(Model $model, array &$columns, array $relations,): void
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
                        ],
                    );

                    break;
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    private function applySearch(Builder $query, array $searchColumns,): Builder
    {
        $searchInput = config('handler.search_input_field', 's');

        $keyword = trim($this->stringInput($searchInput, ''));

        if ($keyword === '') return $query;

        $keyword = mb_substr($keyword, 0, self::MAX_SEARCH_KEYWORD_LENGTH);

        return $query->where(function (Builder $q,) use ($searchColumns, $keyword) {
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

                    $q->{$method}($relation, fn(Builder $rq,) => $rq->where($field, $operator, $value));

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

    private function parseSearchCondition(string|array $definition, string $keyword,): ?array
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

    private function extractRelationName(string $path,): string
    {
        $parts = explode('.', $path);

        array_pop($parts);

        return implode('.', $parts);
    }

    private function extractColumnName(string $path,): string
    {
        return (string)last(explode('.', $path));
    }

    /*
    |--------------------------------------------------------------------------
    | Ordering
    |--------------------------------------------------------------------------
    */

    private function applyOrdering(Builder $query,): Builder
    {
        $defaultOrderBy = config('handler.default_order_by', 'created_at');

        $defaultSort = strtolower((string)config('handler.default_sort_direction', 'desc'));

        if (!in_array($defaultSort, self::ALLOWED_SORT_DIRECTIONS, true)) $defaultSort = 'desc';

        /*
         * Fluent API has priority over request.
         */
        $orderBy = $this->orderColumn ?? $this->stringInput('order', $defaultOrderBy);

        $sort = $this->sortDirection ?? strtolower($this->stringInput('sort', $defaultSort));

        if (!in_array($sort, self::ALLOWED_SORT_DIRECTIONS, true)) $sort = $defaultSort;

        /*
         * Don't allow relation/raw ordering through request.
         */
        if (str_contains($orderBy, '.')) $orderBy = $defaultOrderBy;

        $model = $query->getModel();

        $columns = $this->getTableColumns($model->getTable(), $model->getConnectionName());

        /*
         * Validate order column against real DB columns.
         */
        if (!in_array($orderBy, $columns, true)) {
            $orderBy = in_array('created_at', $columns, true)
                ? 'created_at'
                : $model->getKeyName();
        }

        return $query->orderBy($orderBy, $sort);
    }

    /*
    |--------------------------------------------------------------------------
    | Request / Pagination
    |--------------------------------------------------------------------------
    */

    private function stringInput(string $key, string $default,): string
    {
        $value = $this->request->input($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function resolvePerPage(null|int|false $perPage,): int|false
    {
        /*
         * Fluent value has priority.
         */
        if ($this->perPageValue !== null) return $this->perPageValue;

        /*
         * get() argument.
         */
        if ($perPage !== null) return $perPage;

        /*
         * Request.
         */
        $perPageQuery = config('handler.per_page_query', 'per_page');

        $requestPerPage = $this->request->integer($perPageQuery);

        if ($requestPerPage > 0) return $requestPerPage;

        /*
         * Config.
         */
        return (int)config('handler.pagination', 25);
    }

    private function resolveLimitPagination(null|false|int $limitPagination,): int|false
    {
        /*
         * Fluent value has priority.
         */
        if ($this->paginationLimit !== null) return $this->paginationLimit;

        /*
         * get() argument.
         */
        if ($limitPagination !== null) return $limitPagination;

        return (int)config('handler.limit-pagination', 250);
    }

    private function applyPagination(Builder $query, int|false $perPage, int|false $limitPagination,): LengthAwarePaginator|Collection
    {
        /*
         * Pagination disabled.
         */
        if ($perPage === false) {
            if ($limitPagination !== false) $query->limit($limitPagination);

            return $query->get();
        }

        /*
         * Don't allow perPage to exceed the global limit.
         */
        if ($limitPagination !== false) $perPage = min($perPage, $limitPagination);

        return $query->paginate($perPage)->withQueryString();
    }

    /*
    |--------------------------------------------------------------------------
    | Normalization
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize column definitions.
     *
     * @return array<int, string|array>
     */
    private function normalizeColumns(array|string|null $columns,): array
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

    /**
     * Normalize relationship definitions while preserving
     * Laravel's associative constrained-relation syntax.
     *
     * Examples:
     *
     * [
     *     'profile',
     *     'posts' => fn (Builder $query) => ...,
     * ]
     */
    private function normalizeRelations(array|string|null $relations,): array
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

    /**
     * Merge relation definitions.
     *
     * Numeric relations are deduplicated.
     * Associative constrained relations override previous
     * definitions for the same relation.
     */
    private function mergeRelations(array $first, array $second,): array
    {
        $result = [];

        /*
         * First pass: numeric relations.
         */
        foreach ([$first, $second] as $relations) {
            foreach ($relations as $key => $value) {
                if (!is_int($key)) continue;

                if (!is_string($value)) continue;

                $value = trim($value);

                if ($value === '') continue;

                $result[$value] = $value;
            }
        }

        /*
         * Second pass: associative constrained relations.
         */
        foreach ([$first, $second] as $relations) {
            foreach ($relations as $key => $value) {
                if (!is_string($key)) continue;

                $key = trim($key);

                if ($key === '') continue;

                $result[$key] = $value;
            }
        }

        return array_values(
                array_filter($result, static fn($value,) => is_string($value) || is_array($value) || $value instanceof Closure))
            + array_filter($result, static fn($value, $key,) => is_string($key) && !is_int($key), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Make unique columns while preserving search definitions.
     */
    private function uniqueColumns(array $columns,): array
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

    /**
     * Merge select columns.
     */
    private function mergeColumns(array $fluentColumns, array $getColumns,): array
    {
        if (in_array('*', $fluentColumns, true) || in_array('*', $getColumns, true)) return ['*'];

        if ($fluentColumns === [] && $getColumns === []) return ['*'];

        return $this->uniqueColumns([...$fluentColumns, ...$getColumns,]);
    }
}

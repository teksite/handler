<?php

namespace Teksite\Handler\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Teksite\Handler\Contracts\FetchDataContract;
use Teksite\Handler\Traits\FetchServiceCache;
use Throwable;

class FetchDataService implements FetchDataContract
{
    use FetchServiceCache;

    private const array ALLOWED_OPERATORS = ['=', '!=', '<>', '>', '<', '<=', '>=', 'LIKE', 'ILIKE'];

    private const array ALLOWED_SORT_DIRECTIONS = ['asc', 'desc'];


    private const int MAX_SEARCH_KEYWORD_LENGTH = 255;

    /**
     * Portable LIKE escape character: SQLite and SQL Server have no default one,
     * and a backslash would need extra escaping on MySQL.
     */
    private const string LIKE_ESCAPE_CHARACTER = '!';


    /**
     * @var array<int, string>
     * Columns selected through only().
     */
    private array $onlyColumns = [];

    /**
     * @var array<int|string, mixed>
     *
     * Eager loaded relations, constrained ones keyed by name.
     * Supports:
     *  [
     *      'profile',
     *      'posts' => fn (Builder $query) => ...,
     *  ]
     */
    private array $withRelations = [];

    /**
     * @var array<int|string, mixed>
     *
     * Relations used with withCount().
     */
    private array $withCountRelations = [];

    /**
     * Search columns.
     *
     * @var array<string|array<string, mixed>>
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
     * null: use get() argument / request / config.
     *
     * false: do not paginate
     */
    private int|false|null $perPageValue = null;

    /**
     * Fluent pagination limit.
     *
     * null: use get() argument / config.
     * false: unlimited.
     */
    private int|false|null $paginationLimit = null;

    public function __construct(private readonly Request $request) {}

    /*
    |--------------------------------------------------------------------------
    | Fluent API
    |--------------------------------------------------------------------------
    */


    /**
     * Add columns to the select list: ->only('id'), ->only(['id', 'name']), ->only(fn () => [...]).
     * The primary key and the keys needed by eager loaded relations are added automatically.
     */
    public function only(array|string|Closure $columns): static
    {

        $columns = $this->normalizeColumns($this->resolveClosure($columns));

        if ($columns === []) return $this;

        if (in_array('*', $columns, true)) {
            $this->onlyColumns = ['*'];
            return $this;
        }

        if (in_array('*', $this->onlyColumns, true)) return $this;

        $this->onlyColumns = $this->uniqueColumns([...$this->onlyColumns, ...$columns]);

        return $this;
    }

    /**
     * Add eager loaded relations: ->with('profile'), ->with(['profile', 'roles']),
     *
     * or Laravel's constrained syntax ->with(['posts' => fn (Builder $query) => $query->latest()]).
     */
    public function with(array|string|Closure $relations): static
    {
        $relations = $this->normalizeRelations($this->resolveClosure($relations));

        if ($relations !== []) $this->withRelations = $this->mergeRelations($this->withRelations, $relations);


        $this->withRelations = $this->mergeRelations($this->withRelations, $relations);

        return $this;
    }

    /**
     * Add relations for withCount(); the constrained syntax of ->with() is supported too.
     */
    public function withCount(array|string|Closure $relations): static
    {
        $relations = $this->normalizeRelations($this->resolveClosure($relations));

        if ($relations !== []) $this->withCountRelations = $this->mergeRelations($this->withCountRelations, $relations);

        return $this;
    }

    /**
     * Add search columns: ->search('name,email'), ->search(['name', 'user.name']),
     * ->search([['email', 'LIKE'], ['column' => 'status', 'operator' => '=']]).
     * A dotted column ("user.name") searches through the relation.
     */
    public function search(array|string|Closure $columns): static
    {
        $columns = $this->normalizeColumns($this->resolveClosure($columns));

        if ($columns !== []) $this->searchColumns = $this->uniqueColumns([...$this->searchColumns, ...$columns]);

        return $this;
    }

    /**
     * Set ordering column.
     *
     * Supports: ->orderBy('created_at'), ->orderBy(fn () => 'created_at')
     */
    public function orderBy(string|Closure $column): static
    {
        $column = $this->resolveClosure($column);

        if (!is_string($column)) throw new InvalidArgumentException('The orderBy value must resolve to a string.');

        $column = trim($column);

        if ($column !== '') $this->orderColumn = $column;

        return $this;
    }

    /**
     * Set sorting direction.
     *
     * Supports: >sort('asc'), ->sort(fn () => 'desc')
     */
    public function sort(string|Closure $direction): static
    {
        $direction = $this->resolveClosure($direction);

        if (!is_string($direction)) throw new InvalidArgumentException('The sort value must resolve to a string.');

        $direction = strtolower(trim($direction));

        if (in_array($direction, self::ALLOWED_SORT_DIRECTIONS, true)) $this->sortDirection = $direction;

        return $this;
    }

    /**
     * Set items per page: ->perPage(20), ->perPage(false) to skip pagination.
     */
    public function perPage(int|false|Closure $perPage): static
    {

        $perPage = $this->resolveClosure($perPage);

        $this->perPageValue = $this->assertPositiveIntOrFalse($perPage, 'perPage');

        return $this;
    }

    /**
     * Set the maximum page size: ->limitPagination(100), ->limitPagination(false) for no cap.
     */
    public function limitPagination(int|false|Closure $limit): static
    {
        $limit = $this->resolveClosure($limit);

        $this->paginationLimit = $this->assertPositiveIntOrFalse($limit, 'limitPagination');

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
        return $this->resetOnly()
                    ->resetWith()
                    ->resetWithCount()
                    ->resetSearch()
                    ->resetOrdering()
                    ->resetPagination();
    }

    /*
    |--------------------------------------------------------------------------
    | Main Query
    |--------------------------------------------------------------------------
    */

    /**
     * Build and run the query, combining the fluent configuration with the
     * arguments given here (fluent values win), and return the records.
     *
     * @throws InvalidArgumentException
     */
    public function get(
        string|Model|Builder|Relation|Closure $model,
        string|array|null $searchColumns = null,
        array|string|null $only = null,
        int|false|null $perPage = null,
        int|false|null $limitPagination = null,
        array $with = [],
        array $withCount = [],
    ): Collection|LengthAwarePaginator
    {
        $query = $this->resolveQuery($model);

        $relations = $this->mergeRelations($this->withRelations, $this->normalizeRelations($with));

        $counts = $this->mergeRelations($this->withCountRelations, $this->normalizeRelations($withCount));

        $selectedColumns = $this->mergeColumns($this->onlyColumns, $this->normalizeColumns($only));

        $searchColumns = $this->uniqueColumns([...$this->searchColumns, ...$this->normalizeColumns($searchColumns ?? [])]);

        if ($relations !== []) $query->with($relations);

        $this->applySelection($query, $selectedColumns, $relations);

        if ($counts !== []) $query->withCount($counts);

        if ($searchColumns !== []) $query = $this->applySearch($query, $searchColumns);

        return $this->applyPagination(
            $this->applyOrdering($query),
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
     * Resolve fluent Closures such as ->only(fn () => ['id']); the service itself is passed in.
     */
    private function resolveClosure(mixed $value): mixed
    {
        return $value instanceof Closure ? $value($this) : $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Query Resolution
    |--------------------------------------------------------------------------
    */

    private function resolveQuery(string|Model|Builder|Relation|Closure $model): Builder
    {
        if ($model instanceof Closure) {
            $query = $model();

            if (!$query instanceof Builder) {
                throw new InvalidArgumentException(sprintf(
                    'The query Closure must return an  \Illuminate\Database\Eloquent\Builder; %s returned.',
                    get_debug_type($query),
                ));
            }

            return $query;
        }


        return match (true) {
            $model instanceof Builder  => $model,
            $model instanceof Relation => $model->getQuery(),
            $model instanceof Model    => $model->newQuery(),
            default                    => $this->newModelQuery($model),
        };
    }

    private function newModelQuery(string $modelClass): Builder
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
    | Selection
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<int, string|array<int|string, mixed>> $only
     * @param array<int|string, mixed>                    $relations
     */
    private function applySelection(Builder $query, array $only, array $relations): void
    {
        if ($only === [] || in_array('*', $only, true)) return;

        $model = $query->getModel();

        array_push($only, ...$this->relationKeyColumns($model, $relations));

        $query->select($this->uniqueColumns($only));

    }

    /**
     * Columns of the parent table that eager loading needs to match related rows.
     * Only the first segment of a nested relation ("user.posts") matters here:
     * deeper levels are loaded from the related models, which keep all their columns.
     *
     * @param array<int|string, mixed> $relations
     * @return array<int, string>
     */
    private function relationKeyColumns(Model $model, array $relations): array
    {
        $columns = [];

        foreach ($relations as $key => $value) {
            $definition = is_int($key) ? $value : $key;

            if (!is_string($definition)) continue;

            $name = explode('.', explode(':', $definition, 2)[0], 2)[0];

            // Skipping the base Model methods keeps names such as "delete" from ever being called.
            if ($name === '' || method_exists(Model::class, $name) || !method_exists($model, $name)) {
                continue;
            }

            try {
                $relation = Relation::noConstraints(static fn() => $model->{$name}());
            } catch (Throwable) {
                continue;
            }

            if ($relation instanceof Relation) {
                array_push($columns, ...$this->parentKeyColumns($relation));
            }
        }

        return $columns;
    }

    /**
     * @return array<int, string>
     */
    private function parentKeyColumns(Relation $relation): array
    {
        return match (true) {
            $relation instanceof MorphTo                                                => [$relation->getForeignKeyName(), $relation->getMorphType()],
            $relation instanceof BelongsTo                                              => [$relation->getForeignKeyName()],
            $relation instanceof BelongsToMany                                          => [$relation->getParentKeyName()],
            $relation instanceof HasOneOrMany, $relation instanceof HasOneOrManyThrough => [$relation->getLocalKeyName()],
            default                                                                     => [],
        };
    }


    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    private function applySearch(Builder $query, array $searchColumns): Builder
    {

        $searchInput = (string)config('handler.search_input_field', 's');
        $keyword = trim($this->stringInput($searchInput, ''));

        if ($keyword === '') return $query;

        $driver = $query->getQuery()->getConnection()->getDriverName();

        $keyword = mb_substr($keyword, 0, self::MAX_SEARCH_KEYWORD_LENGTH);

        $local = [];
        $related = [];

        foreach ($searchColumns as $definition) {
            if (!is_string($definition) && !is_array($definition)) continue;

            $condition = $this->parseSearchCondition($definition, $keyword, $driver);

            if ($condition === null) continue;


            // "user.name" searches through the relation; conditions are grouped per relation.
            if (str_contains($condition['column'], '.')) {
                $path = $this->extractRelationName($condition['column']);
                $condition['column'] = $this->extractColumnName($condition['column']);
                $related[$path][] = $condition;

                continue;
            }

            $local[] = $condition;

        }

        return $query->where(function (Builder $q) use ($local, $related): void {
            if ($local === [] && $related === []) {
                $q->whereRaw('1 = 0');
                return;
            }

            foreach ($local as $condition) {
                $this->orWhereCondition($q, $condition);
            }

            foreach ($related as $relation => $conditions) {
                $q->orWhereHas((string)$relation, function (Builder $relationQuery) use ($conditions): void {
                    $relationQuery->where(function (Builder $group) use ($conditions): void {
                        foreach ($conditions as $condition) {
                            $this->orWhereCondition($group, $condition);
                        }
                    });
                });
            }
        });
    }

    /**
     * @param string|array<int|string, mixed> $definition
     * @return array{column: string, operator: string, value: mixed, escaped: bool}|null
     */
    private function parseSearchCondition(string|array $definition, string $keyword, string $driver): ?array
    {
        $valueProvided = false;
        $value = $keyword;

        if (is_array($definition)) {
            $column = $definition['column'] ?? $definition[0] ?? null;

            $operator = strtoupper((string)($definition['operation'] ?? $definition['operator'] ?? $definition[1] ?? 'LIKE'));

            if (array_key_exists('value', $definition)) {
                $value = $definition['value'];
                $valueProvided = true;
            } elseif (array_key_exists(2, $definition)) {
                $value = $definition[2];
                $valueProvided = true;
            }
        } else {
            $column = $definition;
            $operator = 'LIKE';
        }

        if (!is_string($column) || trim($column) === '') return null;

        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            $operator = 'LIKE';
        }

        // ILIKE only exists on PostgreSQL.
        if ($operator === 'ILIKE' && $driver !== 'pgsql') {
            $operator = 'LIKE';
        }

        $escaped = false;

        if (in_array($operator, ['LIKE', 'ILIKE'], true)) {
            $value = (string)$value;

            if ($valueProvided) {
                // Explicit values are intentional, wildcards included.
                if (!str_contains($value, '%')) {
                    $value = "%{$value}%";
                }
            } else {
                // The user's keyword is always matched literally, as a "contains" search.
                $value = '%'.$this->escapeLike($value).'%';
                $escaped = true;
            }
        }

        return [
            'column'   => trim($column),
            'operator' => $operator,
            'value'    => $value,
            'escaped'  => $escaped,
        ];
    }

    /**
     * @param array{column: string, operator: string, value: mixed, escaped: bool} $condition
     */
    private function orWhereCondition(Builder $query, array $condition): void
    {
        if (!$condition['escaped']) {
            $query->orWhere($condition['column'], $condition['operator'], $condition['value']);

            return;
        }

        // Operator comes from a whitelist and the column is quoted by the grammar.
        $column = $query->getQuery()->getGrammar()->wrap($condition['column']);

        $query->orWhereRaw(
            sprintf("%s %s ? escape '%s'", $column, $condition['operator'], self::LIKE_ESCAPE_CHARACTER),
            [$condition['value']],
        );
    }

    private function escapeLike(string $value): string
    {
        $escape = self::LIKE_ESCAPE_CHARACTER;

        return strtr($value, [
            $escape => $escape.$escape,
            '%'     => $escape.'%',
            '_'     => $escape.'_',
        ]);
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


    /*
    |--------------------------------------------------------------------------
    | Ordering
    |--------------------------------------------------------------------------
    */

    private function applyOrdering(Builder $query): Builder
    {
        $defaultOrderBy = (string)config('handler.default_order_by', 'created_at');

        $defaultSort = $this->normalizeDirection((string)config('handler.default_sort_direction', 'desc'), 'desc');

        // The fluent API has priority over the request.
        $orderBy = $this->orderColumn ?? $this->stringInput('order', $defaultOrderBy);

        $sort = $this->normalizeDirection($this->sortDirection ?? $this->stringInput('sort', $defaultSort), $defaultSort);

        // Relation paths are never accepted for ordering.
        if (str_contains($orderBy, '.')) {
            $orderBy = $defaultOrderBy;
        }

        $model = $query->getModel();
        $keyName = $model->getKeyName();

        $columns = $this->getTableColumns($model->getTable(), $query->getQuery()->getConnection()->getName());

        // The order column must be a real column of the table.
        if (!in_array($orderBy, $columns, true)) {
            $orderBy = in_array('created_at', $columns, true) ? 'created_at' : $keyName;
        }

        $query->orderBy($orderBy, $sort);

        // Deterministic tie-breaker so rows sharing a value keep a stable order across pages.
        if ($orderBy !== $keyName && in_array($keyName, $columns, true)) {
            $query->orderBy($keyName, $sort);
        }

        return $query;
    }

    private function normalizeDirection(string $direction, string $fallback = 'desc'): string
    {
        $direction = strtolower(trim($direction));

        return in_array($direction, self::ALLOWED_SORT_DIRECTIONS, true) ? $direction : $fallback;
    }


    /*
    |--------------------------------------------------------------------------
    | Request / Pagination
    |--------------------------------------------------------------------------
    */

    private function stringInput(string $key, string $default): string
    {
        $value = $this->request->input($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function resolvePerPage(int|false|null $perPage): int|false
    {
        // Priority: fluent value, get() argument, request, config.
        if ($this->perPageValue !== null) return $this->perPageValue;

        if ($perPage !== null) return $this->assertPositiveIntOrFalse($perPage, 'perPage');

        $requestPerPage = $this->request->integer((string)config('handler.per_page_query', 'per_page'));

        if ($requestPerPage > 0) return $requestPerPage;

        return max(1, (int)config('handler.pagination', 50));
    }

    private function resolveLimitPagination(int|false|null $limitPagination): int|false
    {
        // Priority: fluent value, get() argument, config.
        if ($this->paginationLimit !== null) return $this->paginationLimit;

        if ($limitPagination !== null) return $this->assertPositiveIntOrFalse($limitPagination, 'limitPagination');

        $configured = config('handler.limit-pagination', 250);

        if ($configured === false) return false;

        return is_numeric($configured) ? max(1, (int)$configured) : 250;
    }

    private function applyPagination(Builder $query, int|false $perPage, int|false $limitPagination): LengthAwarePaginator|Collection
    {
        if ($perPage === false) {
            if ($limitPagination !== false) {
                $query->limit($limitPagination);
            }
            return $query->get();
        }

        // The page size can never exceed the global limit.
        if ($limitPagination !== false) $perPage = min($perPage, $limitPagination);

        return $query->paginate($perPage)->withQueryString();
    }

    private function assertPositiveIntOrFalse(mixed $value, string $name): int|false
    {

        if ($value === false) return false;

        if (!is_int($value) || $value < 1) throw new InvalidArgumentException("The {$name} value must be a positive integer or false.");

        return $value;
    }


    /*
    |--------------------------------------------------------------------------
    | Normalization
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize column definitions; array definitions (search conditions) are kept as they are.
     *
     * @return array<int, string|array<int|string, mixed>>
     */
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

    /**
     * Normalize relation definitions while preserving Laravel's associative
     * constrained-relation syntax: ['profile', 'posts' => fn (Builder $query) => ...].
     *
     * @return array<int|string, mixed>
     */
    private function normalizeRelations(array|string|null $relations): array
    {
        if ($relations === null) return [];

        if (is_string($relations)) $relations = explode(',', $relations);

        $result = [];

        foreach ($relations as $key => $relation) {
            if (is_int($key)) {
                if (!is_string($relation)) continue;

                $relation = trim($relation);

                if ($relation !== '') {
                    $result[] = $relation;
                }
                continue;
            }

            if (trim($key) === '') continue;

            $result[trim($key)] = $relation;
        }

        return $result;
    }


    /**
     * Merge relation definitions: plain relations are deduplicated and a
     * constrained relation overrides earlier definitions of the same name.
     *
     * @param array<int|string, mixed> $first
     * @param array<int|string, mixed> $second
     * @return array<int|string, mixed>
     */
    private function mergeRelations(array $first, array $second): array
    {
        $merged = [];

        foreach ([$first, $second] as $relations) {
            foreach ($relations as $key => $value) {
                if (is_int($key)) {
                    if (is_string($value) && trim($value) !== '') {
                        $merged[trim($value)] = true;
                    }
                    continue;
                }

                if (trim($key) !== '') {
                    $merged[trim($key)] = $value;
                }
            }
        }

        $result = [];

        foreach ($merged as $name => $value) {
            if ($value === true) {
                $result[] = $name;
                continue;
            }
            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * Deduplicate columns while keeping search definitions (arrays) intact.
     *
     * @param array<int, mixed> $columns
     * @return array<int, string|array<int|string, mixed>>
     * @throws JsonException
     */
    private function uniqueColumns(array $columns): array
    {
        $result = [];

        foreach ($columns as $column) {
            if (is_array($column)) {
                $key = 'array:'.(json_encode($column, JSON_THROW_ON_ERROR) ?: count($result));
                $result[$key] ??= $column;
                continue;
            }

            if (!is_string($column)) continue;

            $column = trim($column);

            if ($column !== '') {
                $result[$column] = $column;
            }
        }

        return array_values($result);
    }

    /**
     * @param array<int, string|array<int|string, mixed>> $fluentColumns
     * @param array<int, string|array<int|string, mixed>> $getColumns
     * @return array<int, string|array<int|string, mixed>>
     * @throws JsonException
     */
    private function mergeColumns(array $fluentColumns, array $getColumns): array
    {
        if (in_array('*', $fluentColumns, true) || in_array('*', $getColumns, true)) return ['*'];

        if ($fluentColumns === [] && $getColumns === []) return ['*'];

        return $this->uniqueColumns([...$fluentColumns, ...$getColumns]);
    }
}

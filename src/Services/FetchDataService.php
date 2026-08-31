<?php

namespace Teksite\Handler\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Teksite\Handler\Contracts\FetchDataContract;
use Throwable;

class FetchDataService implements FetchDataContract
{
    private const array ALLOWED_OPERATORS = ['=', '!=', '<>', '>', '<', '<=', '>=', 'LIKE', 'ILIKE',];

    private const array ALLOWED_SORT_DIRECTIONS = ['asc', 'desc',];

    private const int COLUMN_CACHE_TTL = 86400;

    private const int MAX_SEARCH_KEYWORD_LENGTH = 255;

    private const string COLUMNS_REGISTRY_KEY = 'fetch-data-columns:__registry';

    /**
     * Columns selected through only().
     */
    private array $onlyColumns = [];

    /**
     * Eager loaded relationships.
     */
    private array $withRelations = [];

    /**
     * Relationships used with withCount().
     */
    private array $withCountRelations = [];

    /**
     * Search columns.
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
     * null means use request/config.
     * false means don't paginate.
     */
    private int|false|null $perPageValue = null;

    /**
     * Fluent pagination limit.
     *
     * null means use config.
     * false means unlimited.
     */
    private int|false|null $paginationLimit = null;

    public function __construct(private readonly Request $request) {}

    /*
    |--------------------------------------------------------------------------
    | Fluent API
    |--------------------------------------------------------------------------
    */

    /**
     * Add columns to the select list.
     *
     * Examples:
     *
     * ->only('id')
     * ->only(['name', 'email'])
     * ->only('id,name,email')
     */
    public function only(array|string $columns): static
    {
        $columns = self::normalizeColumns($columns);

        if ($columns === []) return $this;

        if (in_array('*', $columns, true)) {
            $this->onlyColumns = ['*'];
            return $this;
        }

        if (in_array('*', $this->onlyColumns, true)) {
            return $this;
        }
        $this->onlyColumns = self::uniqueColumns([
            ...$this->onlyColumns,
            ...$columns,
        ]);
        return $this;
    }

    /**
     * Add eager loaded relationships.
     *
     * Examples:
     *
     * ->with('profile')
     * ->with(['profile', 'roles'])
     * ->with('profile:id,user_id,avatar')
     */
    public function with(array|string $relations): static
    {
        $relations = self::normalizeRelations($relations);

        $this->withRelations = self::uniqueValues([
            ...$this->withRelations,
            ...$relations,
        ]);

        return $this;
    }

    /**
     * Add relationships for withCount().
     *
     * Examples:
     *
     * ->withCount('posts')
     * ->withCount(['posts', 'comments'])
     */
    public function withCount(array|string $relations): static
    {
        $relations = self::normalizeRelations($relations);
        $this->withCountRelations = self::uniqueValues([
            ...$this->withCountRelations,
            ...$relations,
        ]);
        return $this;
    }

    /**
     * Add search columns.
     *
     * Examples:
     *
     * ->search(['name', 'email'])
     * ->search('name,email')
     *
     * Advanced:
     *
     * ->search([
     *     'name',
     *     ['email', 'LIKE'],
     * ])
     */
    public function search(array|string $columns): static
    {
        $columns = self::normalizeColumns($columns);
        $this->searchColumns = [
            ...$this->searchColumns,
            ...$columns,
        ];
        return $this;
    }

    /**
     * Set ordering column.
     *
     * Example:
     *
     * ->orderBy('created_at')
     */
    public function orderBy(string $column): static
    {
        $column = trim($column);
        if ($column !== '') $this->orderColumn = $column;
        return $this;
    }

    /**
     * Set sorting direction.
     *
     * Example:
     *
     * ->sort('asc')
     */
    public function sort(string $direction): static
    {
        $direction = strtolower(trim($direction));

        if (in_array($direction, self::ALLOWED_SORT_DIRECTIONS, true)) {
            $this->sortDirection = $direction;
        }

        return $this;
    }

    /**
     * Set items per page.
     *
     * Example:
     *
     * ->perPage(20)
     *
     * Disable pagination:
     *
     * ->perPage(false)
     */
    public function perPage(int|false $perPage): static
    {
        if ($perPage !== false && $perPage < 1) throw new InvalidArgumentException('The perPage value must be greater than zero or false.');
        $this->perPageValue = $perPage;
        return $this;
    }

    /**
     * Set maximum pagination limit.
     *
     * Example:
     *
     * ->limitPagination(100)
     *
     * Disable limit:
     *
     * ->limitPagination(false)
     */
    public function limitPagination(int|false $limit): static
    {
        if ($limit !== false && $limit < 1) {
            throw new InvalidArgumentException('The pagination limit must be greater than zero or false.');
        }
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
     * Main fetch method.
     */
    public function get(
        string|Model|Builder|Relation $model,
        string|array|null             $searchColumns = null,
        array|string                  $only = null,
        int|false|null                $perPage = null,
        int|false|null                $limitPagination = null,
        array                         $with = [],
        array                         $withCount = []
    ): Collection|LengthAwarePaginator
    {
        $query = self::resolveQuery($model);

        $relations = self::uniqueValues([
            ...$this->withRelations,
            ...self::normalizeRelations($with),
        ]);

        $counts = self::uniqueValues([
            ...$this->withCountRelations,
            ...self::normalizeRelations($withCount),
        ]);

        $selectedColumns = self::mergeColumns(
            $this->onlyColumns,
            self::normalizeColumns($only)
        );

        $searchColumns = [
            ...$this->searchColumns,
            ...self::normalizeColumns($searchColumns ?? []),
        ];

        /*
         * Eager loading.
         */
        self::applyEagerLoading($query, $relations);

        /*
         * Select columns.
         */
        self::applySelection($query, $selectedColumns, $relations);

        /*
         * withCount().
         */
        if ($counts !== []) $query->withCount($counts);


        /*
         * Search.
         */
        if ($searchColumns !== []) $query = $this->applySearch($query, $searchColumns);

        /*
         * Ordering.
         */
        $query = $this->applyOrdering($query);

        /*
         * Pagination.
         */
        return self::applyPagination(
            $query,
            $this->resolvePerPage($perPage),
            $this->resolveLimitPagination($limitPagination)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query Resolution
    |--------------------------------------------------------------------------
    */

    private static function resolveQuery(string|Model|Builder|Relation $model): Builder
    {
        return match (true) {
            $model instanceof Builder  => $model,
            $model instanceof Relation => $model->getQuery(),
            $model instanceof Model    => $model->newQuery(),
            is_string($model)          => self::newModelQuery($model),
            default                    => throw new InvalidArgumentException(sprintf('Expected a Model class, Model instance, Builder or Relation; %s given.', get_debug_type($model))),
        };
    }

    private static function newModelQuery(string $modelClass): Builder
    {
        if (!is_a($modelClass, Model::class, true)) {
            throw new InvalidArgumentException(sprintf('The given class [%s] must extend [%s].', $modelClass, Model::class));
        }
        return (new $modelClass)->newQuery();
    }

    /*
    |--------------------------------------------------------------------------
    | Eager Loading
    |--------------------------------------------------------------------------
    */

    private static function applyEagerLoading(Builder $query, array $relations): void
    {
        if ($relations !== []) $query->with($relations);

    }

    /*
    |--------------------------------------------------------------------------
    | Selection
    |--------------------------------------------------------------------------
    */

    private static function applySelection(Builder $query, array $only, array $with = []): void
    {

        if ($only === [] || in_array('*', $only, true)) return;
        $model = $query->getModel();

        $primaryKey = $model->getKeyName();

        if (!in_array($primaryKey, $only, true)) $only[] = $primaryKey;

        self::addRelationKeys($model, $only, $with);

        $query->select(self::uniqueColumns($only));
    }

    /**
     * Add foreign/local keys required by eager loaded relations.
     */
    private static function addRelationKeys(Model $model, array &$columns, array $relations): void
    {
        foreach ($relations as $relationDefinition) {

            if (!is_string($relationDefinition)) continue;

            /*
             * Example:
             *
             * profile:id,user_id,avatar
             *
             * We only need "profile" here.
             */
            $relationName = explode(':', $relationDefinition)[0];

            /*
             * Nested relation:
             *
             * profile.avatar
             *
             * Resolve each segment independently.
             */
            $segments = explode('.', $relationName);

            $currentModel = $model;

            foreach ($segments as $segment) {
                if (!method_exists($currentModel, $segment)) break;

                try {
                    $relation = $currentModel->{$segment}();

                    if (!$relation instanceof Relation) break;

                    if (method_exists($relation, 'getForeignKeyName')) $columns[] = $relation->getForeignKeyName();

                    if (method_exists($relation, 'getLocalKeyName')) $columns[] = $relation->getLocalKeyName();

                    $currentModel = $relation->getRelated();
                } catch (Throwable $e) {
                    Log::debug('FetchDataService: could not resolve relation for column selection.', ['relation' => $relationName, 'error' => $e->getMessage(),]);
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

    private function applySearch(Builder $query, array $searchColumns): Builder
    {
        $searchInput = config('handler.search_input_field', 's');

        $keyword = trim($this->stringInput($searchInput, ''));

        if ($keyword === '') return $query;

        $keyword = mb_substr($keyword, 0, self::MAX_SEARCH_KEYWORD_LENGTH);

        return $query->where(function (Builder $q) use ($searchColumns, $keyword) {
            $hasCondition = false;

            foreach ($searchColumns as $definition) {
                $condition = self::parseSearchCondition($definition, $keyword);

                if (!$condition) continue;

                ['column' => $column, 'operator' => $operator, 'value' => $value,] = $condition;

                /*
                 * Relation search:
                 *
                 * user.name
                 */
                if (str_contains($column, '.')) {
                    $relation = self::extractRelationName($column);

                    $field = self::extractColumnName($column);

                    $method = $hasCondition ? 'orWhereHas' : 'whereHas';

                    $q->{$method}($relation, fn(Builder $rq) => $rq->where($field, $operator, $value));

                    $hasCondition = true;
                    continue;
                }

                $method = $hasCondition
                    ? 'orWhere'
                    : 'where';

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

    private static function parseSearchCondition(string|array $definition, string $keyword): ?array
    {
        if (is_array($definition)) {

            $column = $definition['column']
                ?? $definition[0]
                ?? null;

            $operator = strtoupper((string)(
                $definition['operation']
                ?? $definition['operator']
                ?? $definition[1]
                ?? 'LIKE'
            ));

            $value = $definition['value']
                ?? $definition[2]
                ?? $keyword;
        } else {
            $column = $definition;
            $operator = 'LIKE';
            $value = $keyword;
        }

        if (!$column || !is_string($column)) return null;


        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            $operator = 'LIKE';
        }

        /*
         * ILIKE only exists on PostgreSQL.
         */
        if ($operator === 'ILIKE' && DB::getDriverName() !== 'pgsql') $operator = 'LIKE';


        if (in_array($operator, ['LIKE', 'ILIKE'], true)) {
            $isUserSuppliedKeyword = $value === $keyword;

            $value = (string)$value;

            /*
             * Escape LIKE wildcards for user input.
             */
            if ($isUserSuppliedKeyword) {
                $value = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
            }

            if (!str_contains($value, '%')) $value = "%{$value}%";

        }

        return [
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
        ];
    }

    private static function extractRelationName(string $path): string
    {
        $parts = explode('.', $path);

        array_pop($parts);

        return implode('.', $parts);
    }

    private static function extractColumnName(string $path): string
    {
        return last(explode('.', $path));
    }

    /*
    |--------------------------------------------------------------------------
    | Ordering
    |--------------------------------------------------------------------------
    */

    private function applyOrdering(Builder $query): Builder
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

        $columns = self::getTableColumns($model->getTable(), $model->getConnectionName());

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

    private function stringInput(string $key, string $default): string
    {
        $value = $this->request->input($key, $default);
        return is_string($value) ? $value : $default;
    }

    private function resolvePerPage(null|int|false $perPage): int|false
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
        $requestPerPage = $this->request->integer('per_page');

        if ($requestPerPage > 0) return $requestPerPage;

        /*
         * Config.
         */
        return config('handler.pagination', 25);
    }

    private function resolveLimitPagination(null|false|int $limitPagination): int|false
    {
        /*
         * Fluent value has priority.
         */
        if ($this->paginationLimit !== null) return $this->paginationLimit;


        /*
         * get() argument.
         */
        if ($limitPagination !== null) return $limitPagination;


        return config('handler.limit-pagination', 250);
    }

    private static function applyPagination(Builder $query, int|false $perPage, int|false $limitPagination): LengthAwarePaginator|Collection
    {
        /*
         * Pagination disabled.
         */
        if ($perPage === false) {
            if ($limitPagination !== false) $query->limit($limitPagination);

            return $query->get();
        }

        /*
         * Don't allow perPage to exceed global limit.
         */
        if ($limitPagination !== false) $perPage = min($perPage, $limitPagination);


        return $query->paginate($perPage)->withQueryString();
    }

    /*
    |--------------------------------------------------------------------------
    | Column Cache
    |--------------------------------------------------------------------------
    */

    public static function forgetColumnsCache(string|Model $model): void
    {
        $instance = is_string($model) ? new $model() : $model;

        Cache::forget(self::columnsCacheKey($instance->getTable(), $instance->getConnectionName()));
    }

    private static function getTableColumns(string $table, ?string $connection = null): array
    {
        $key = self::columnsCacheKey($table, $connection);

        self::registerCacheKey($key);

        return Cache::remember($key, self::COLUMN_CACHE_TTL, function () use ($table, $connection) {
            try {
                return $connection
                    ? Schema::connection($connection)->getColumnListing($table)
                    : Schema::getColumnListing($table);
            } catch (Throwable $e) {
                Log::warning("Failed getting columns for table {$table}",
                    [
                        'connection' => $connection,
                        'error'      => $e->getMessage(),
                    ]);

                return [];
            }
        }
        );
    }

    private static function registerCacheKey(string $key): void
    {
        $registry = Cache::get(self::COLUMNS_REGISTRY_KEY, []);

        if (!in_array($key, $registry, true)) {
            $registry[] = $key;
            Cache::forever(self::COLUMNS_REGISTRY_KEY, $registry);
        }
    }

    public static function forgetAllColumnsCache(): void
    {
        $registry = Cache::get(self::COLUMNS_REGISTRY_KEY, []);

        foreach ($registry as $key) {
            Cache::forget($key);
        }

        Cache::forget(self::COLUMNS_REGISTRY_KEY);
    }

    private static function columnsCacheKey(string $table, ?string $connection): string
    {
        return sprintf('fetch-data-columns:%s:%s', $connection ?: 'default', $table);
    }

    /*
    |--------------------------------------------------------------------------
    | Normalization / Helpers
    |--------------------------------------------------------------------------
    */

    private static function normalizeColumns(array|string|null $columns): array
    {
        if ($columns === null) return [];


        if (is_string($columns)) $columns = explode(',', $columns);

        $result = [];

        foreach ($columns as $column) {
            /*
             * Search definitions such as:
             *
             * ['email', 'LIKE']
             *
             * must remain intact.
             */
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

    private static function normalizeRelations(array|string|null $relations): array
    {
        if ($relations === null) return [];


        if (is_string($relations)) $relations = explode(',', $relations);


        $result = [];

        foreach ($relations as $relation) {
            if (!is_string($relation)) continue;

            $relation = trim($relation);

            if ($relation !== '') $result[] = $relation;
        }

        return $result;
    }

    private static function uniqueColumns(array $columns): array
    {
        $result = [];

        foreach ($columns as $column) {
            if (is_array($column)) {
                $key = serialize($column);
                if (!isset($result[$key])) $result[$key] = $column;
                continue;
            }

            if (!is_string($column)) continue;

            $result[$column] = $column;
        }
        return array_values($result);
    }

    private static function uniqueValues(array $values): array
    {
        return array_values(array_unique(array_filter($values,
                static fn($value) => is_string($value) && trim($value) !== '')
        ));
    }

    private static function mergeColumns(array $fluentColumns, array $getColumns): array
    {

        if (in_array('*', $fluentColumns, true) || in_array('*', $getColumns, true)) return ['*'];
        if ($fluentColumns === [] && $getColumns === [])  return ['*'];
        return self::uniqueColumns([...$fluentColumns, ...$getColumns,]);
    }
}

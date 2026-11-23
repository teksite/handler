# Handler Package

A lightweight Laravel package that removes boilerplate from three everyday jobs:

- **ServiceWrapper** - run an action with try/catch, an optional database transaction, a unified result object, logging and success/failure events.
- **FetchData** - build an Eloquent query from the request (search, ordering, pagination) in one call.
- **Responder** - build JSON or redirect responses with a consistent payload.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [ServiceWrapper](#servicewrapper)
- [ServiceResult](#serviceresult)
- [Events](#events)
- [FetchData](#fetchdata)
- [Responder](#responder)
- [Configuration](#configuration)
- [Translations](#translations)
- [Upgrade Notes](#upgrade-notes)
- [Testing](#testing)
- [Author and Support](#author-and-support)
- [License](#license)

## Requirements

- PHP ^8.3
- Laravel ^12 or ^13

## Installation

```bash
composer require teksite/handler
```

The service provider is registered automatically through package auto-discovery.

Publish the files you want to customize:

```bash
# config/handler.php
php artisan vendor:publish --tag=handler-config

# lang/vendor/handler
php artisan vendor:publish --tag=handler-lang

# both
php artisan vendor:publish --tag=handler
```

## ServiceWrapper

`Teksite\Handler\Services\ServiceWrapper`

### Basic usage

```php
use Teksite\Handler\Services\ServiceWrapper;

$result = ServiceWrapper::make()
    ->do(fn () => User::create($request->validated()))
    ->ifFailed(fn () => ['error' => 'User creation failed'])
    ->run();
```

- `do()` is required. The closure runs inside a database transaction (enabled by default).
- `ifFailed()` is optional. It runs only when `do()` throws an exception that is caught, and its return value becomes the result of the failed run.
- Caught exceptions are logged automatically (see `handler.log`).
- `run()` returns a [`ServiceResult`](#serviceresult) unless wrapping is disabled.
- `run()` throws a `LogicException` when `do()` was never called.

### Options

```php
ServiceWrapper::make(
    hasTransaction: true,      // run inside a DB transaction (default: handler.transaction)
    wrapServiceResult: true,   // wrap the outcome in a ServiceResult (default: handler.service_result)
    withHandler: true,         // catch exceptions (default: handler.wrapper)
    connection: null,          // DB connection of the transaction (default: handler.connection)
);

ServiceWrapper::make()->onConnection('tenant');
ServiceWrapper::make()->withEventData(['actor' => 'system']);
```

Any argument left `null` falls back to the matching `handler.*` config value.

### Forcing the transaction on or off

Two shortcuts ignore `handler.transaction`. They accept the other options (`wrapServiceResult`, `withHandler`, `connection`):

```php
// always inside a transaction
ServiceWrapper::withDbTransaction()->do(fn () => $this->import())->run();

// never inside a transaction
ServiceWrapper::withoutDbTransaction(connection: 'reports')->do(fn () => $this->report())->run();
```

### Exceptions that are never swallowed

Laravel uses some exceptions for control flow. They are always rethrown, after the transaction has been rolled back, and they never trigger logging, `ifFailed()` or the failure event:

- `Illuminate\Validation\ValidationException`
- `Illuminate\Auth\Access\AuthorizationException`
- `Illuminate\Auth\AuthenticationException`
- any `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface` (this includes `abort()`)
- `Illuminate\Http\Exceptions\HttpResponseException`

Every other `Throwable` is caught.

### Disabling the handler (development and debugging)

Setting `HANDLER_WRAPPER=false` (or `withHandler: false`) stops exceptions from being caught, so they bubble up with their full trace.
Only the catching is disabled: the transaction, the `ServiceResult` wrapping and the success event keep following their own settings.
`ifFailed()`, logging and the failure event do not run because nothing is caught.

## ServiceResult

`Teksite\Handler\Data\ServiceResult` is an immutable value object:

```php
$result = ServiceWrapper::make()->do(fn () => Post::find(1))->run();

if ($result->isSuccess()) {
    $post = $result->getResult();
} else {
    $errors = $result->getErrors();
}
```

| Accessor             | Property        | Meaning                                              |
|----------------------|-----------------|------------------------------------------------------|
| `isSuccess()`        | `success`       | `true` only when `success === true`                  |
| `isFailed()`         | -               | `true` only when `success === false`                 |
| `isUnknown()`        | -               | `true` when `success` is `null` (outcome not known)  |
| `getResult()`        | `result`        | the returned value (`ifFailed()` value on failure)   |
| `getErrors()`        | `errors`        | `string`, `array` or `null`                          |
| `getSuccessStatus()` | `successStatus` | HTTP status hint for a success response              |
| `getFailedStatus()`  | `failedStatus`  | HTTP status hint for a failed response               |

`ServiceWrapper` fills only `success` (`true` or `false`) and `result`. When you need errors or status hints, for example before calling `Responder::fromResult()`, create the object yourself:

```php
use Teksite\Handler\Data\ServiceResult;

$result = new ServiceResult(false, null, errors: ['email' => 'taken'], failedStatus: 422);
```

Disable wrapping to get the raw value instead:

```php
$post = ServiceWrapper::make(wrapServiceResult: false)->do(fn () => Post::find(1))->run();
```

To use your own result class, implement `Teksite\Handler\Contracts\ServiceResultContract` and set it in `handler.service_result_class`.
`ServiceWrapper` creates it as `new YourResult($success, $result)`.

## Events

`run()` can dispatch an event after the action:

```php
ServiceWrapper::make()
    ->withEventData(['actor' => 'system'])
    ->do(fn () => User::create($data))
    ->run(
        dispatchSuccessEvent: true,   // default: false
        dispatchFailureEvent: true,   // default: false
        additionalEventData: ['request_id' => 'abc-123'],
    );
```

The default events are `Teksite\Handler\Events\OnSuccessEvent` (`$result`, `$data`) and `Teksite\Handler\Events\OnFailureEvent` (`$exception`, `$data`).
The success event is dispatched after the transaction has been committed.

### Custom events

A custom event class must implement the matching contract. The wrapper creates it positionally, so the parameter names are up to you:

```php
namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Teksite\Handler\Contracts\SuccessEventContract;

class UserCreated implements SuccessEventContract
{
    use Dispatchable;

    public function __construct(public mixed $result, public array $data = []) {}
}
```

```php
// config/handler.php
'success_event_class' => \App\Events\UserCreated::class,
'failure_event_class' => \App\Events\UserCreationFailed::class, // implements FailureEventContract
```

Notes:

- A class that does not exist or does not implement the contract is skipped and a warning is logged.
- Set a value to `null` to disable that event.
- A problem while dispatching an event never changes the result of the action.
- Events are created with `new`, so container injection into their constructors is not supported.

## FetchData

`Teksite\Handler\Facade\FetchData` (backed by `Teksite\Handler\Services\FetchDataService`)

The facade returns a **new instance for every static call**, so settings never leak between calls, requests or jobs.
Chain the calls to keep them on one instance:

```php
use Teksite\Handler\Facade\FetchData;

// Everything from the request: ?s=laravel&order=title&sort=asc&per_page=20&page=2
$posts = FetchData::search(['title', 'user.name'])->get(Post::class);

$posts = FetchData::only(['id', 'title'])
    ->with(['user'])
    ->search(['title', 'category'])
    ->orderBy('title')
    ->sort('asc')
    ->perPage(20)
    ->limitPagination(100)
    ->get(Post::class);
```

You can also inject `Teksite\Handler\Contracts\FetchDataContract`. An injected or resolved instance is stateful: call `reset()` before reusing it for a different query.

### Method parameters

```php
FetchData::get(
    string|Model|Builder|Relation|Closure $model,   // class name, model, query builder, relation, or a Closure returning any of these
    string|array|null $searchColumns = null,        // search columns, see below
    array|string|null $only = null,                 // columns to select (default: all)
    int|false|null $perPage = null,                 // items per page, false disables pagination
    int|false|null $limitPagination = null,         // maximum items per page, false removes the cap
    array $with = [],                               // relations to eager load
    array $withCount = [],                          // relations to count
): \Illuminate\Support\Collection|\Illuminate\Pagination\LengthAwarePaginator;
```

Fluent settings and `get()` arguments are combined. For `perPage` and `limitPagination` the priority is: fluent value, `get()` argument, request / config.
It returns a paginator, or a plain collection when pagination is disabled with `perPage(false)`.

### Search

The search runs only when the request contains a keyword (`?s=...`, see `handler.search_input_field`).
The keyword is always matched literally (`%` and `_` have no special meaning) as a "contains" search, and the columns are combined with OR.
Only the first `handler.search_input_field_length` characters of the keyword are used (at most 1000).

```php
FetchData::search([
    'title',                                        // LIKE
    ['category', '='],                              // custom operator
    ['column' => 'status', 'operator' => 'LIKE'],   // named keys
    'user.name',                                    // through a relation
])->get(Post::class);

FetchData::search('title,body');                    // comma separated string
```

- Allowed operators: `=`, `!=`, `<>`, `>`, `<`, `<=`, `>=`, `LIKE`, `ILIKE` (anything else becomes `LIKE`; `ILIKE` falls back to `LIKE` unless the connection is PostgreSQL).
- A column with a dot searches through the relation. Several columns of the same relation share one `EXISTS` subquery.
- An explicit `value` (`['column' => 'title', 'value' => 'Lara%']`) replaces the keyword for that column. For `LIKE` it is used as given, wildcards included, and wrapped in `%...%` when it contains no `%`.
- Search definitions without any valid column match nothing.

### Ordering

Ordering comes from `orderBy()` / `sort()`, or from the request (`?order=title&sort=asc`):

- the column must be a real column of the table (the column list is cached), otherwise the default is used;
- relation paths are never accepted;
- the default is `handler.default_order_by` (then `created_at`, then the primary key) and `handler.default_sort_direction`;
- the primary key is added as a tie-breaker so pages stay stable.

### Pagination

The page size comes from `perPage()`, the `get()` argument, the request (`?per_page=20`) or `handler.pagination`, and is capped by `handler.limit-pagination`.
A page size smaller than 1 throws an `InvalidArgumentException`.

### Selecting columns and relations

`only()` always adds the primary key and the columns the eager loaded relations need (foreign keys of `belongsTo`, local keys of `hasMany`, the morph type of `morphTo`, ...), so relations keep loading.
Laravel's constrained syntax is supported:

```php
FetchData::only(['title'])
    ->with(['user' => fn ($query) => $query->select('id', 'name')])
    ->withCount('comments')
    ->get(Post::class);
```

Do not pass relation names that come from user input to `with()`: Laravel calls the matching model method.

### Column cache

Column lists are cached for 24 hours and flushed automatically after migrations run.
You can flush them yourself:

```php
FetchData::forgetColumnsCache(Post::class);
FetchData::forgetAllColumnsCache();
```

### Example with ServiceWrapper

```php
public function index(): ServiceResult
{
    return ServiceWrapper::make(hasTransaction: false)
        ->do(fn () => FetchData::search(['title'])->get(Post::class))
        ->run(dispatchSuccessEvent: true);
}
```

## Responder

`Teksite\Handler\Facade\Responder` creates a new builder for every static call.

```php
use Teksite\Handler\Facade\Responder;

// JSON
return Responder::success('Well done!', ['post' => $post], 201)->reply();
return Responder::failed('Something went wrong', ['auth' => 'forbidden'], 403)->reply();

// Redirect (back, or to a URL / route) with the reply flashed to the session
return Responder::success('Saved')->go();
return Responder::success('Saved')->route('posts.index')->go();
return Responder::failed('Not saved')->go('/posts/create');
```

Builder methods: `title()`, `message()`, `type()`, `error()`, `statusCode()`, `data()`, `route()`, `safeSetRoute()`, `url()`, `success()`, `failed()`, `fromResult()`, `reply()`, `go()`.
Messages and errors accumulate across calls, and the status code must be between 100 and 599.

`route()` fails like Laravel's `route()` helper when the route does not exist. `safeSetRoute()` sets the URL only when the route exists, otherwise it leaves the builder untouched:

```php
Responder::success('Saved')->safeSetRoute('posts.index')->go();   // goes back when "posts.index" is not defined
```

JSON payload:

```json
{
    "message": ["Well done!"],
    "type": "success",
    "statusCode": 201,
    "data": {"post": {}}
}
```

Empty parts are left out. `type` is one of `success`, `failed`, `error`, `warning`, `info` (`Teksite\Handler\Enums\ResponseType`).
When no message is given, `success()` and `failed()` use the translated defaults (see [Translations](#translations)).

### Redirects

`go()` flashes the payload to the session under the `reply` key **without `data`**, so large or sensitive data never ends up in the session:

```blade
@if (session('reply'))
    <div class="alert alert-{{ session('reply.type') }}">
        {{ implode(' ', session('reply.message', [])) }}
    </div>
@endif
```

The redirect URL is not restricted: if you build it from user input, validate it yourself.

### From a ServiceResult

```php
$result = ServiceWrapper::make()->do(fn () => Post::create($data))->run();

return Responder::fromResult(
    $result,
    success_message: 'Post created',
    failed_message: 'Post could not be created',
    success_url: route('posts.index'),
    autoReply: true,                 // redirect when a URL is set, JSON otherwise
);
```

Any result that is not a success (including an unknown one) is answered as a failure, with the result's errors and status (default 500). Without errors, `['server' => <translated failed message>]` is used.
`fromResult()` accepts any `ServiceResultContract` implementation.

## Configuration

Published to `config/handler.php`. Environment variables take priority over the defaults.

| Key                         | Environment variable                           | Default                  |
|-----------------------------|------------------------------------------------|--------------------------|
| `pagination`                | `HANDLER_PAGINATION`                           | `50`                     |
| `limit-pagination`          | `HANDLER_LIMIT_PAGINATION`                     | `250` (`false` = no cap) |
| `search_input_field`        | -                                              | `s`                      |
| `search_input_field_length` | -                                              | `250` (1 to 1000)        |
| `per_page_query`            | -                                              | `per_page`               |
| `default_order_by`          | -                                              | `created_at`             |
| `default_sort_direction`    | -                                              | `desc`                   |
| `wrapper`                   | `HANDLER_WRAPPER`                              | `true`                   |
| `transaction`               | `HANDLER_TRANSACTION`                          | `true`                   |
| `service_result`            | `HANDLER_USE_RESULT_SERVICE`                   | `true`                   |
| `service_result_class`      | -                                              | `ServiceResult::class`   |
| `connection`                | `HANDLER_DB_CONNECTION` (then `DB_CONNECTION`) | app default              |
| `log`                       | `HANDLER_LOG`                                  | `true`                   |
| `success_event_class`       | -                                              | `OnSuccessEvent::class`  |
| `failure_event_class`       | -                                              | `OnFailureEvent::class`  |

The ordering (`order`, `sort`) request parameters are fixed.

## Translations

The default response messages are translatable and live in the `handler` namespace:

| Key                         | English default       |
|-----------------------------|-----------------------|
| `handler::messages.success` | Successfully done     |
| `handler::messages.failed`  | Something went wrong  |

Publish them with `php artisan vendor:publish --tag=handler-lang`, then edit `lang/vendor/handler/en/messages.php` or add a folder for another locale.

## Upgrade Notes

Changes in 4.0 that can affect existing code (compared with 3.x):

- **Requirements:** PHP ^8.3 and Laravel ^12 or ^13.
- **Events:**
  - custom `success_event_class` / `failure_event_class` must implement `SuccessEventContract` / `FailureEventContract`;
  - they are created positionally (`new Event($result, $data)`, `new Event($exception, $data)`), so container injection into their constructors is no longer supported;
  - the failure event now receives the event data.
- **ServiceWrapper:**
  - validation, authorization, authentication and HTTP exceptions are rethrown instead of being swallowed;
  - `HANDLER_WRAPPER=false` (or `withHandler: false`) now only stops exceptions from being caught: the transaction and the result wrapping stay on;
  - the success event is dispatched after the commit, and a wrapping or event problem no longer triggers the failure path;
  - `handler.log` defaults to `true`.
- **ServiceResult:**
  - `ServiceResultContract` requires the accessor methods listed above, and its constructor is `__construct(bool|null $success, mixed $result)`;
  - `Responder::fromResult()` is typed on the contract instead of the `ServiceResult` class.
- **Responder:**
  - `ResponseType::Error` was renamed to `ResponseType::ERROR`; the old name no longer exists;
  - `data` is no longer flashed by `go()`, and `go()` returns a `RedirectResponse`;
  - the default messages changed (`Successfully done`, `Something went wrong`) and are translatable;
  - a status code outside 100-599 throws an `InvalidArgumentException`.
- **FetchData:**
  - the facade returns a new instance per call;
  - `FetchDataContract` declares the fluent, reset and cache methods;
  - the column cache keys changed (old entries simply expire);
  - a `perPage` smaller than 1 throws an `InvalidArgumentException`, and `limit-pagination = false` now really removes the cap;
  - the keyword of a search is matched literally and cut to `search_input_field_length` characters;
  - ordering accepts only real columns of the table, and the primary key is appended to `ORDER BY`; an index on `(created_at, id)` helps large tables.
- **Config:**
  - the environment variables are prefixed with `HANDLER_`; the old `PAGINATE_PER_PAGE` and `PAGINATE_LIMITATION` are no longer read;
  - the unused `client-pagination` and `allow_relation_ordering` keys were removed;
  - new key: `search_input_field_length`.
- **New:** `ServiceWrapper::withDbTransaction()`, `ServiceWrapper::withoutDbTransaction()`, `Responder::safeSetRoute()`, publishable translations (`handler-lang`).

## Testing

```bash
composer test
composer test-coverage
```

The GitHub Actions workflow runs the suite on PHP 8.3 and 8.4 with Laravel 12 and 13.

## Author and Support

Developed by Sina Zangiband.

- Website: teksite.net | laratek.net | laratek.ir
- Email: sina.zangiband@gmail.com
- Support: support@teksite.net
- GitHub Issues: [teksite/handler](https://github.com/teksite/handler/issues)

Contributions are welcome: open an issue or submit a pull request.

## License

The MIT License (MIT). See [LICENSE](LICENSE).

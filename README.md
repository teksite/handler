# Handler Package

## About

A lightweight Laravel package designed to simplify common PHP and Laravel operations, including try-catch handling,
database querying, and other utility functions.

## Table of Contents

- [About](#about)
- [Author](#author)
- [Contact](#contact)
- [Installation](#installation)
- [Features](#features)
- [Event System](#event-system)
- [Configuration](#configuration)
- [Support](#support)

## About

The Handler Package streamlines development by providing helper functions for managing try-catch blocks, fetching
data from databases, and other common tasks in PHP and Laravel applications. It aims to reduce boilerplate code and
improve code readability.

## Author

Developed by Sina Zangiband.

## Contact

- Website:
    - teksite.net
    - laratek.net
    - laratek.ir
- Email: sina.zangiband@gmail.com

---

## Installation

### Step 1: Install via Composer

Run the following command in your terminal:

bash
composer require teksite/handler


### Step 2: Register the Service Provider

#### For Laravel > 9

Add the service provider to the bootstrap/providers.php file:

```php
'providers' => [
// Other Service Providers
Teksite\Handler\ServiceProvider::class,
],
```

Note: Laravel 5.5 and above supports auto-discovery, so this step is not required for newer versions.

## Features

### Simplify exception handling with ServiceWrapper

`Teksite\Handler\Actions\ServiceWrapper`

#### Basic Usage

```php
return ServiceWrapper::make()->do(function () {
// your code
})->ifFailed(function () {
// in case your code failed
})->run();
```

- do() is required - contains your main code to be processed in try-catch
- ifFailed() is optional - runs if your code fails, errors are automatically logged to laravel.log

#### Advanced Configuration

```php
// Disable error handling wrapper
ServiceWrapper::make(withHandler: false)->do(fn() => $this->someMethod())->run();

// Disable database transaction (enabled by default)
ServiceWrapper::make(hasTransaction: false)->do(fn() => $this->someMethod())->run();

// Disable ServiceResult wrapping (enabled by default)
ServiceWrapper::make(wrapServiceResult: false)->do(fn() => $this->someMethod())->run();
```

#### Event Dispatching

The `run()` method accepts two optional parameters to dispatch custom events:

```php
public function run(bool $dispatchSuccessEvent = false, bool $dispatchFailureEvent = true): mixed
```

Example:
```php
return ServiceWrapper::make()
->do(function () {
// Your business logic
return User::create($request->validated());
})
->ifFailed(function () {
// Fallback logic
return ['error' => 'User creation failed'];
})
->run(
dispatchSuccessEvent: true,  // Dispatches success event on completion
dispatchFailureEvent: false  // Disables failure event dispatch
);

```
## Event Classes Configuration:

Define your event classes in config/handler-settings.php:

```php
return [
// ... other configurations

    'success_event_class' => "Teksite\\Handler\\Events\\OnSuccessEvent",
    'failure_event_class' => "Teksite\\Handler\\Events\\OnFailureEvent",
];
```

Note: Events are resolved using Laravel's service container, so dependency injection is fully supported in your event constructors.

#### ServiceResult Wrapper

By default, the output of both do() and ifFailed() is wrapped in a ServiceResult instance for consistent return values:

```php
$result = ServiceWrapper::make()->do(fn() => Post::find(1))->run();

if ($result->isSuccess()) {
$post = $result->getData();
} else {
$error = $result->getData(); // Error information
}

```
Disable wrapping:

```php
$rawResult = ServiceWrapper::make(wrapServiceResult: false)->do(fn() => Post::find(1))->run();
```

### Streamlined Database Query Methods

```Teksite\Handler\Services\FetchDataService```

#### Example with ServiceWrapper

```php
public function get(mixed $fetchData = [])
{
return ServiceWrapper::make()
->do(function () use ($fetchData) {
return FetchDataService::get(Post::class, ['title'], ...$fetchData);
})
->ifFailed(function () {
// Handle failure
})
->run(dispatchSuccessEvent: true);
}
```

#### Standalone Usage

```php
// Without ServiceWrapper
$posts = FetchDataService::get(Post::class, ['title']);
```

#### Method Parameters

```php
FetchDataService::get(
string|Closure|Builder|Relation $model,  // Model class, query builder, or relation
string|array|Closure|null $searchColumns, // Search columns with operators
array $only = ['*'],                       // Columns to select
null|int|false $perPage = null,            // Items per page (pagination)
null|false|int $limitPagination = null     // Maximum items per page limit
);

```
Search with Operators:

```php
$searchColumns = [
['column' => 'title', 'operator' => 'LIKE'],
['column' => 'category', 'operator' => '='],
'status' // Simple column search (default '=')
];
```

### HTTP Response Helper

```php
use Teksite\Handler\Services\ResponderServices;

// Success response
$response = ResponderServices::success('Well done!', ['post' => $post], 201);

// Failure response
$response = ResponderServices::failed('Something went wrong', ['auth' => 'forbidden'], 500);

// Redirect as HTTP response
$response->go();

// Return as JSON response (for APIs and AJAX)
$response->reply();

```
## Event System

### How It Works

The ServiceWrapper automatically dispatches events when configured:

1. Success Events - Triggered when the do() closure executes without errors
2. Failure Events - Triggered when an exception is caught

### Custom Event Example

Create your event class:

```php
namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class UserCreationSucceeded
{
use Dispatchable;

    public function __construct(public $user)
    {
        //
    }
}

```
Configure in config/handler-settings.php:

```php
'success_event_class' => \App\Events\UserCreationSucceeded::class,
```

Use in your codes:

```php
ServiceWrapper::make()
->do(fn() => User::create($data))
->run(dispatchSuccessEvent: true);
```

### Advanced Event Dispatching

Control event dispatch per method call:

```php
// Dispatch only success event
$wrapper->run(dispatchSuccessEvent: true, dispatchFailureEvent: false);

// Dispatch only failure event (default behavior)
$wrapper->run(dispatchSuccessEvent: false, dispatchFailureEvent: true);

// Dispatch both
$wrapper->run(dispatchSuccessEvent: true, dispatchFailureEvent: true);

// Dispatch none
$wrapper->run(dispatchSuccessEvent: false, dispatchFailureEvent: false);

```
## Configuration

### Publish Configuration File

```bash
php artisan vendor:publish --provider="Teksite\Handler\ServiceProvider"
```

## Support

For questions, issues, or feature requests, please reach out via:

- Website: teksite.net | laratek.net
- Email: support@teksite.net
- GitHub Issues: teksite/handler

Contributions are welcome! Feel free to submit a pull request or open an issue on GitHub.

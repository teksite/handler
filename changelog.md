# Changelog

All notable changes to `teksite/handler` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows [Semantic Versioning](https://semver.org/).
Entries for versions before 4.0.0 are reconstructed from the GitHub release notes and the commit history.

## [4.0.0]

A security, performance and readability overhaul. It contains breaking changes, so read the **Changed** and **Removed** sections before upgrading.

### Added

- `SuccessEventContract` and `FailureEventContract`. Custom event classes set in `handler.success_event_class` / `handler.failure_event_class` must implement them.
- `ServiceResultContract` accessors: `isSuccess()`, `isFailed()`, `getResult()`, `getErrors()`, `getSuccessStatus()` and `getFailedStatus()`.
- `ServiceResult::isUnknown()`. The `success` flag can now be `null` (outcome not known).
- `ServiceWrapper::withDbTransaction()` and `ServiceWrapper::withoutDbTransaction()`: shortcuts that force the transaction on or off, whatever `handler.transaction` says.
- `Responder::safeSetRoute()`: sets the redirect URL only when the route exists.
- Translatable default response messages (`handler::messages.success`, `handler::messages.failed`) and the publish tags `handler-lang`, `handler-config` and `handler`.
- Config key `search_input_field_length`: maximum number of characters of the search keyword (1 to 1000, default 250).
- `HANDLER_*` environment variables: `HANDLER_PAGINATION`, `HANDLER_LIMIT_PAGINATION`, `HANDLER_WRAPPER`, `HANDLER_TRANSACTION`, `HANDLER_USE_RESULT_SERVICE`, `HANDLER_DB_CONNECTION` and `HANDLER_LOG`.
- A much larger test suite and a GitHub Actions workflow that runs it on PHP 8.3 and 8.4 with Laravel 12 and 13.
- `LICENSE` file (MIT).
### Changed

- **BREAKING:** events are created positionally (`new Event($result, $data)` and `new Event($exception, $data)`). Container injection into event constructors is no longer supported, and the failure event now receives the event data.
- **BREAKING:** `ServiceWrapper` no longer swallows `ValidationException`, `AuthorizationException`, `AuthenticationException`, `HttpExceptionInterface` exceptions (including `abort()`) and `HttpResponseException`. They are rethrown after the rollback.
- **BREAKING:** `HANDLER_WRAPPER=false` (or `withHandler: false`) now only stops exceptions from being caught. The transaction and the result wrapping stay on.
- **BREAKING:** `ServiceResultContract` requires the new accessors, and its constructor is `__construct(bool|null $success, mixed $result)`. `Responder::fromResult()` is typed on the contract instead of the `ServiceResult` class.
- **BREAKING:** `Responder` redirects (`go()`) no longer flash `data` to the session and always return a `RedirectResponse`.
- **BREAKING:** `success()` and `failed()` use translated default messages (`Successfully done`, `Something went wrong`). A status code outside 100-599 throws an `InvalidArgumentException`.
- **BREAKING:** `FetchData` and `Responder` facades return a new instance for every static call. Chain the calls to keep the settings on one instance.
- **BREAKING:** a `perPage` smaller than 1 throws an `InvalidArgumentException`, and a pagination config value below 1 is raised to 1.
- **BREAKING:** the column cache keys changed. Old entries simply expire.
- `FetchDataContract` declares the fluent, reset and column cache methods.
- The success event is dispatched after the transaction has been committed. A problem while wrapping the result or dispatching an event no longer triggers the failure path.
- `handler.log` defaults to `true`.
- The package supports Laravel ^12 in addition to ^13 (PHP ^8.3 is still required).
### Removed

- **BREAKING:** `ResponseType::Error` was renamed to `ResponseType::ERROR`, and the old name no longer exists.
- **BREAKING:** the `PAGINATE_PER_PAGE` and `PAGINATE_LIMITATION` environment variables are no longer read. Use `HANDLER_PAGINATION` and `HANDLER_LIMIT_PAGINATION`.
- The unused `client-pagination` and `allow_relation_ordering` config keys.
- `composer.lock` and the `minimum-stability` setting of `composer.json`.
### Fixed

- A search whose definitions contain no valid column matched every row. It now matches nothing.
- `limit-pagination = false` now really removes the page size cap.
- `only()` combined with `with()` now selects the keys the relations need (foreign keys of `belongsTo`, local keys of `hasMany`, the morph type of `morphTo`, ...) and never calls base Eloquent model methods while resolving them.
### Security

- The search keyword is matched literally: `%` and `_` are escaped and the `LIKE` expression uses an explicit escape character. The keyword is cut to `search_input_field_length` characters (never more than 1000).
- Ordering accepts only real columns of the table. Relation paths and unknown columns fall back to the default. The primary key is added as a tie-breaker.
- `Responder::go()` no longer stores `data` in the session.
- Authorization and authentication exceptions can no longer be hidden by `ServiceWrapper`.
- The GitHub Actions workflow pins third-party actions to commit SHAs and does not persist the checkout credentials.
### Performance

- The table column list used to validate ordering is cached with a version token, so one write invalidates every entry. Empty or failed listings are never cached, and the cache is flushed after migrations run (or manually with `forgetColumnsCache()` / `forgetAllColumnsCache()`).
- Several search columns of the same relation share one `EXISTS` subquery.
- Facades keep no state between calls, requests or jobs, so the package is safe under Octane.
## [3.0.3]

### Fixed

- `FetchData::get()` with a Closure as the model. The result of the Closure is resolved recursively: a model class, `Model`, `Builder`, `Relation` or another Closure.
## [3.0.2]

### Fixed

- Bug in `FetchDataService::mergeRelations()`.
## [3.0.1]

### Changed

- **BREAKING:** the `ServiceResult` contract was renamed to `ServiceResultContract`.
## [3.0.0]

### Added

- Tests.
- `FetchDataContract` (with Closure support) and `forgetColumnsCache()` in the column cache trait.
### Changed

- **BREAKING:** the codebase was refactored: `ServiceWrapper` was moved to another directory, `FetchDataService` and its facade were rewritten, the column cache trait was moved and renamed (`FetchServiceCache`), and the config file name and content changed.
- `ServiceResult` received more parameters.
### Fixed

- Bugs in the Responder service and its builder.
- `ServiceWrapper` failure handling.
## [2.1.10]

### Changed

- **BREAKING for positional callers:** the arguments of `Responder::fromResult()` were reordered.
## [2.1.9]

### Fixed

- Bug fix (the release notes give no details).
## [2.1.8]

### Fixed

- Bug in the `with` handling of `FetchDataService`.
## [2.1.7]

### Added

- The `go()` method of the Responder builder accepts a URL.
## [2.1.6]

### Added

- Parameter to set a route in the Responder service.
## [2.1.5]

### Changed

- The Responder service was refactored.
### Fixed

- Bug fixes.
## [2.1.4]

### Added

- New features in `FetchDataService`.
### Fixed

- Bug fixes.
## [2.1.3]

### Security

- Additional hardening of the `FetchDataService` search method.
### Fixed

- Error in `FetchDataService`.
## [2.1.2]

### Security

- Fixed security bugs in the search.
## [2.1.1]

### Added

- `withEventData()` to pass data to the success and failure events.
### Fixed

- Conflict between the config and `make()` in `ServiceWrapper`.
### Changed

- README updated.
## [2.1.0]

This version is a tag only; no GitHub release was published for it.

### Added

- Events for successful and failed operations, with matching config keys.
## [2.0.0]

Version 1 is deprecated. Several methods and classes changed to be more efficient.

### Added

- Support for Laravel ^13.
- A contract for `ServiceResult`.
- `error` in failed responses.
- Environment variable support in the config file.

### Changed

- **BREAKING:** requires PHP ^8.3 and Laravel ^13.
- **BREAKING:** `ServiceWrapper::__invoke()` was removed; use `ServiceWrapper::make()`.
- **BREAKING:** `FetchDataService::__invoke()` was removed; use `get()`, which is called statically.
- The code was refactored and improved.
### Fixed

- Issues in the Responder service and its builder.
## [1.1.1]

### Fixed

- `null` or `false` values in `ResponderServices`.
## [1.1.0]

### Added

- Responder service: web (redirect) and JSON responses in a single class.
## [1.0.3]

### Added

- Sorting and ordering in `FetchDataService`.
## [1.0.2]

### Changed

- `FetchDataService` was refactored.
## [1.0.1]

### Fixed

- Bug fixes.
## [1.0.0]

First stable version.

### Added

- Support for Laravel 12.
- `wrapper` config key to switch the handler on or off globally.
### Changed

- Performance improvements and a refactor of `FetchDataService`.
## [0.0.2]

Pre-release.

### Added

- Try-catch wrapper (`ServiceWrapper`).
- Fetching data through a single unified class (`FetchDataService`).
### Changed

- `ModuleServiceProvider` was renamed to `HandlerServiceProvider`, and the config file was renamed.
- Fixed the provider namespace in `composer.json` and removed its `version` field.
## [0.0.1]

Pre-release: a beta version under development with the first `FetchDataService` and `ServiceWrapper`.

[4.0.0]: https://github.com/teksite/handler/compare/3.0.3...4.0.0
[3.0.3]: https://github.com/teksite/handler/compare/3.0.2...3.0.3
[3.0.2]: https://github.com/teksite/handler/compare/3.0.1...3.0.2
[3.0.1]: https://github.com/teksite/handler/compare/3.0.0...3.0.1
[3.0.0]: https://github.com/teksite/handler/compare/2.1.10...3.0.0
[2.1.10]: https://github.com/teksite/handler/compare/2.1.9...2.1.10
[2.1.9]: https://github.com/teksite/handler/compare/2.1.8...2.1.9
[2.1.8]: https://github.com/teksite/handler/compare/2.1.7...2.1.8
[2.1.7]: https://github.com/teksite/handler/compare/2.1.6...2.1.7
[2.1.6]: https://github.com/teksite/handler/compare/2.1.5...2.1.6
[2.1.5]: https://github.com/teksite/handler/compare/2.1.4...2.1.5
[2.1.4]: https://github.com/teksite/handler/compare/2.1.3...2.1.4
[2.1.3]: https://github.com/teksite/handler/compare/2.1.2...2.1.3
[2.1.2]: https://github.com/teksite/handler/compare/2.1.1...2.1.2
[2.1.1]: https://github.com/teksite/handler/compare/2.1.0...2.1.1
[2.1.0]: https://github.com/teksite/handler/compare/2.0.0...2.1.0
[2.0.0]: https://github.com/teksite/handler/compare/1.1.1...2.0.0
[1.1.1]: https://github.com/teksite/handler/compare/1.1.0...1.1.1
[1.1.0]: https://github.com/teksite/handler/compare/1.0.3...1.1.0
[1.0.3]: https://github.com/teksite/handler/compare/1.0.2...1.0.3
[1.0.2]: https://github.com/teksite/handler/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/teksite/handler/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/teksite/handler/compare/v0.0.2...1.0.0
[0.0.2]: https://github.com/teksite/handler/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/teksite/handler/releases/tag/v0.0.1
 

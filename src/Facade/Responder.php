<?php

namespace Teksite\Handler\Facade;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Facade;
use Teksite\Handler\Contracts\ServiceResultContract;
use Teksite\Handler\Enums\ResponseType;
use Teksite\Handler\Services\Builder\ResponderServices;

/**
 * @method static ResponderServices title(?string $title = null)
 * @method static ResponderServices message(string|array|null $message)
 * @method static ResponderServices type(ResponseType $type)
 * @method static ResponderServices error(string|array|null $error = null)
 * @method static ResponderServices statusCode(?int $statusCode = null)
 * @method static ResponderServices data(mixed $data)
 * @method static ResponderServices route(?string $route, mixed $parameters = [])
 * @method static ResponderServices url(?string $url)
 * @method static ResponderServices success(string|array|null $message = null, mixed $data = null, int $status = 200)
 * @method static ResponderServices failed(string|array|null $message = null, string|array $errors = [], int $status = 403, mixed $data = [])
 * @method static ResponderServices|JsonResponse|RedirectResponse fromResult(ServiceResultContract $result, string|array|null $success_message = null, string|array|null $failed_message = null, ?string $success_url = null, ?string $failed_url = null, bool $autoReply = false)
 * @method static JsonResponse reply()
 * @method static RedirectResponse go(?string $url = null)
 *
 * @see ResponderServices
 */
class Responder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ResponderServices::class;
    }

    /**
     * Always resolve a brand-new instance and bypass Laravel's default
     * facade instance caching: the builder is stateful and must never be
     * shared across calls, requests, or jobs.
     *
     * @throws BindingResolutionException
     */
    protected static function resolveFacadeInstance($name)
    {
        return static::$app->make($name);
    }
}

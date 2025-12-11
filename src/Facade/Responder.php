<?php

namespace Teksite\Handler\Facade;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Facade;
use Teksite\Handler\Services\Builder\ResponderServices;

/**
 * @method static ResponderServices message(string|array|null $message)
 * @method static ResponderServices error(string|array|null $error)
 * @method static ResponderServices title(string|null $title)
 * @method static ResponderServices type(\Teksite\Lareon\Enums\ResponseType $type)
 * @method static ResponderServices data(mixed $data)
 * @method static ResponderServices route(string|null $route)
 * @method static ResponderServices statusCode(int|string|null $status)
 * @method static ResponderServices success(string|array $message = 'success', mixed $data = [], int $status = 200)
 * @method static ResponderServices failed(string|array $message = 'failed', mixed $data = [], int $status = 500)
 * @method static ResponderServices|JsonResponse|RedirectResponse|Redirector fromResult(\Teksite\Handler\Actions\ServiceResult $result, string|array|null $success_message = null, string|array|null $failed_message = null, string|null $success_route = null, string|null $failed_route = null, bool $autoReply = false)
 * @method static JsonResponse reply()
 * @method static RedirectResponse|Redirector go()
 *
 * @see \Teksite\Handler\Services\Builder\Responder
 */
class Responder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'Responder';
    }


}

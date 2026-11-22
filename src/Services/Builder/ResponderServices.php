<?php

namespace Teksite\Handler\Services\Builder;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Route;
use Teksite\Handler\Contracts\ServiceResultContract;
use Teksite\Handler\Data\ServiceResult;
use Teksite\Handler\Services\ResponderServices as ResponsePayload;
use Teksite\Handler\Enums\ResponseType;

/**
 * Fluent builder of JSON / redirect responses.
 *
 * It is stateful: the Responder facade resolves a fresh instance for every call.
 */
class ResponderServices
{
    private ResponsePayload $responder;


    public function __construct()
    {
        $this->responder = new ResponsePayload();
    }

    public function title(?string $title = null): static
    {
        $this->responder->setTitle($title);

        return $this;
    }

    public function message(null|string|array $message): static
    {
        $this->responder->setMessage($message);

        return $this;
    }

    public function type(ResponseType $type): static
    {
        $this->responder->setType($type);

        return $this;
    }

    public function error(null|string|array $error = null): static
    {
        $this->responder->setError($error);

        return $this;
    }

    public function statusCode(?int $statusCode = null): static
    {
        $this->responder->setStatusCode($statusCode);

        return $this;
    }

    public function data(mixed $data): static
    {
        $this->responder->setData($data);

        return $this;
    }

    public function route(?string $route, mixed $parameters = []): static
    {
        if ($route) $this->responder->setUrl(route($route, $parameters));

        return $this;
    }

    public function url(?string $url): static
    {
        if ($url) $this->responder->setUrl($url);

        return $this;
    }

    public function safeSetRoute(?string $route, mixed $parameters = []): static
    {
        if ($route && Route::has($route)) $this->responder->setUrl(route($route, $parameters));

        return $this;
    }


    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    */

    public function go(?string $url = null): RedirectResponse
    {
        if ($url) $this->responder->setUrl($url);

        return $this->responder->redirecting();
    }


    public function reply(): JsonResponse
    {
        return $this->responder->replying();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<int|string, mixed>|string|null $message Defaults to the translated "success" message.
     */
    public function success(string|array|null $message = null, mixed $data = null, int $status = 200): static
    {
        return $this->buildResponse(
            ResponseType::SUCCESS,
            $message ?? __('handler::messages.success'),
            $data,
            $status,
        );

    }

    public function failed(string|array|null $message = null, string|array $errors = [], int $status = 403, mixed $data = []): static
    {
        return $this->buildResponse(
            ResponseType::FAILED,
            $message ?? __('handler::messages.failed'),
            $data,
            $status,
            $errors);
    }


    private function buildResponse(ResponseType $type, string|array $message, mixed $data = null, int $status = 200, string|array $error = []): static
    {
        return $this->type($type)
                    ->statusCode($status)
                    ->message($message)
                    ->data($data)
                    ->error($error);
    }

    /*
    |--------------------------------------------------------------------------
    | ServiceResult integration
    |--------------------------------------------------------------------------
    */

    /**
     * Build a response from the outcome of a ServiceWrapper run.
     *
     * @param array<int|string, mixed>|string|null $success_message
     * @param array<int|string, mixed>|string|null $failed_message
     */
    public function fromResult(ServiceResultContract $result,
        null|string|array $success_message = null,
        null|string|array $failed_message = null,
        ?string $success_url = null,
        ?string $failed_url = null,
        bool $autoReply = false): static|JsonResponse|RedirectResponse
    {

        if ($result->isSuccess()) {
            $this->success($success_message, $result->getResult(), $result->getSuccessStatus() ?? 200)
                 ->url($success_url);
        } else {
            $this->failed(
                $failed_message,
                $result->getErrors() ?? ['server' => __('handler::messages.failed')],
                $result->getFailedStatus() ?? 500,
            )->url($failed_url);
        }

        if ($autoReply) return $this->responder->getUrl() ? $this->go() : $this->reply();

        return $this;
    }


}

<?php

namespace Teksite\Handler\Services\Builder;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Teksite\Handler\Data\ServiceResult;
use Teksite\Handler\Services\ResponderServices as ResponsePayload;
use Teksite\Handler\Enums\ResponseType;

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


    /** ===== Output Methods ===== */

    public function go(null|string $url = null): Redirector|RedirectResponse
    {
        if ($url) $this->responder->setUrl($url);
        return $this->responder->redirecting();
    }


    public function reply(): JsonResponse
    {
        return $this->responder->replying();
    }

    /** ===== Helpers ===== */

    public function success(string|array $message = 'success', mixed $data = null, int $status = 200): static
    {
        return $this->buildResponse(ResponseType::SUCCESS,
            $message,
            $data,
            $status
        );

    }

    public function failed(string|array $message = 'failed', string|array $errors = [], int $status = 403, mixed $data = []): static
    {
        return $this->buildResponse(ResponseType::FAILED,
            $message,
            $data,
            $status,
            $errors);
    }


    private function buildResponse(ResponseType $type, string|array $message, mixed $data = null, int $status = 200, array $error = []): static
    {
        return $this->type($type)
                    ->statusCode($status)
                    ->message($message)
                    ->data($data)
                    ->error($error);
    }

    /** ===== ServiceResult Integration ===== */

    public function fromResult(
        ServiceResult     $result,
        null|string|array $success_message = null,
        null|string|array $failed_message = null,
        ?string           $success_url = null,
        ?string           $failed_url = null,
        bool              $autoReply = false
    ): static|JsonResponse|Redirector|RedirectResponse
    {
        {
            if ($result->success) {
                $this->success(
                    $success_message ?? __('successfully done'),
                    $result->result,
                    $result->successStatus ?? 200);
                if ($success_url) $this->url($success_url);

            } else {
                $this->failed($failed_message ?? __('something went wrong'),
                    $result->errors ?? ['server' => __('something went wrong')],
                    $result->failedStatus ?? 500);
                if ($failed_url) $this->url($failed_url);
            }

            if ($autoReply) return $this->responder->getUrl() ? $this->go() : $this->reply();

            return $this;
        }
    }
}

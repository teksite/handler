<?php

namespace Teksite\Handler\Services\Builder;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Teksite\Handler\Actions\ServiceResult;
use Teksite\Handler\Services\ResponderServices as Service;
use Teksite\Lareon\Enums\ResponseType;

class ResponderServices
{
    private Service $responder;

    public function __construct()
    {
        $this->responder = new Service();
    }

    /** ===== Fluent Setters ===== */

    /**
     * @param string|array|null $message
     * @return $this
     */
    public function message(null|string|array $message): static
    {
        $this->responder->setMessage($message);
        return $this;
    }

    /**
     * @param string|array|null $error
     * @return $this
     */
    public function error(null|string|array $error): static
    {
        $this->responder->setError($error);
        return $this;
    }

    /**
     * @param string|null $title
     * @return $this
     */
    public function title(?string $title): static
    {
        $this->responder->setTitle($title);
        return $this;
    }

    /**
     * @param ResponseType $type
     * @return $this
     */
    public function type(ResponseType $type): static
    {
        $this->responder->setType($type);
        return $this;
    }


    public function statusCode(int|string|null $statusCode = null): static
    {
        $this->responder->setStatusCode($statusCode);
        return $this;
    }

    /**
     * @param mixed $data
     * @return $this
     */
    public function data(mixed $data): static
    {
        $this->responder->setData($data);
        return $this;
    }

    /**
     * @param string|null $route
     * @return $this
     */
    public function route(?string $route): static
    {
        $this->responder->setRoute($route);
        return $this;
    }

    /** ===== Output Methods ===== */

    /**
     * @return Redirector|RedirectResponse
     */
    public function go(): Redirector|RedirectResponse
    {
        return $this->responder->redirecting();
    }

    /**
     * @return JsonResponse
     */
    public function reply(): JsonResponse
    {
        return $this->responder->replying();
    }

    /**
     * @param string|array $message
     * @param mixed $data
     * @param int $status
     * @return $this
     */

    /** ===== Helpers ===== */

    public function success(string|array $message = 'success', mixed $data = [], int $status = 200): static
    {
        return $this->type(ResponseType::SUCCESS)->statusCode($status)->data($data)->message($message);
    }

    /**
     * @param string|array $message
     * @param mixed $data
     * @param int $status
     * @return $this
     */
    public function failed(string|array $message = 'failed', mixed $data = [], int $status = 403): static
    {
        return $this->type(ResponseType::FAILED)->statusCode($status)->data($data)->message($message);
    }


    /**
     * General method to set type, message, data, and status
     */
    private function setResponse(ResponseType $type, string|array $message, mixed $data = null, int $status = 200): static
    {
        return $this->type($type)
            ->message($message)
            ->data($data)
            ->statusCode($status);
    }

    /** ===== ServiceResult Integration ===== */

    /**
     * Handle a ServiceResult and optionally auto reply
     *
     * @param ServiceResult $result
     * @param string|array|null $success_message
     * @param string|array|null $failed_message
     * @param string|null $success_route
     * @param string|null $failed_route
     * @param bool $autoReply
     * @return static|JsonResponse|Redirector|RedirectResponse
     */
    public function fromResult(
        ServiceResult $result,
        null|string|array $success_message = null,
        null|string|array $failed_message = null,
        ?string $success_route = null,
        ?string $failed_route = null,
        bool $autoReply = false
    ): static|JsonResponse|Redirector|RedirectResponse {
        if ($result->success) {
            $this->success($success_message ?? __('successfully done'), $result->result, $result->successStatus ?? 200)
                ->route($success_route);
        } else {
            $this->failed($failed_message ?? __('something went wrong'), $result->result, $result->failedStatus ?? 500)
                ->route($failed_route);
        }
        if ($autoReply) {
            return $this->responder->getRoute() ? $this->go() : $this->reply();
        }

        return $this;
    }
}

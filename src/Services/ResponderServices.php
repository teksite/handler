<?php

namespace Teksite\Handler\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Teksite\Lareon\Enums\ResponseType;

class ResponderServices
{
    private ?string $title = null;
    private array $message = [];
    private array $error = [];
    private ?ResponseType $type = null;
    private null|int|string $statusCode  = 200;
    private mixed $data = null;
    private ?string $route = null;

    /**
     * Set title
     */
    public function setTitle(?string $title = null): static
    {
        $this->title = $title;
        return $this;
    }
    /**
     * Set status code
     */
    public function setStatusCode(int|string|null $statusCode=null): static
    {
        $this->statusCode = $statusCode;
        return $this;
    }
    /**
     * Add message
     */
    public function setMessage(null|array|string $message = null): static
    {
        if ($message !== null) {
            $this->message = array_merge($this->message, (array)$message);
        }
        return $this;
    }

    /**
     * Add error
     */
    public function setError(null|array|string $error = null): static
    {
        if ($error !== null) {
            $this->error = array_merge($this->error, (array)$error);
        }
        return $this;
    }

    /**
     * Set response type
     */
    public function setType(ResponseType $type): static
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Set data
     */
    public function setData(mixed $data = null): static
    {
        $this->data = $data;
        return $this;
    }


    /**
     * Set redirect route
     */
    public function setRoute(?string $route = null): static
    {
        $this->route = $route;
        return $this;
    }

    /**
     * Convert response to array
     */
    public function toArray(): array
    {
        return array_filter([
            'title'   => $this->title,
            'message' => $this->message ?: null,
            'error'   => $this->error ?: null,
            'type'    => $this->type?->value,
            'statusCode'    => $this->statusCode,
            'data'    => $this->data,
        ], fn($value) => $value !== null);
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }
    /**
     * Redirect with flash
     */
    public function redirecting(): Redirector|RedirectResponse
    {
        $redirect = $this->route ? redirect($this->route) : redirect()->back();
        return $redirect->with(['reply' => $this->toArray()]);
    }

    /**
     * Return JSON response
     */
    public function replying(int $statusCode = 200): JsonResponse
    {
        return response()->json($this->toArray(), $statusCode);
    }
}

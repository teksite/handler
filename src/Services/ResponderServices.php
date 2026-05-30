<?php

namespace Teksite\Handler\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Teksite\Handler\Enums\ResponseType;

class ResponderServices
{
    private ?string $title = null;
    private array $message = [];
    private array $error = [];
    private ?ResponseType $type = null;
    private int $statusCode = 200;
    private mixed $data = null;
    private ?string $url = null;

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function setMessage(null|array|string $message): void
    {
        if (!empty($message)) {
            $this->message = array_values(array_filter(
                array_merge($this->message, (array)$message)
            ));
        }
    }

    public function setType(ResponseType $type): void
    {
        $this->type = $type;
    }

    public function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    public function setError(null|array|string $error): void
    {
        if (!empty($error)) {
            $this->error = array_merge($this->error, (array)$error);
        }
    }

    /**
     * set data
     */
    public function setData(mixed $data): void
    {
        if ($data === null || $data === [] || $data === '') return;

        if ($this->data === null) {
            $this->data = $data;
        } elseif (is_array($this->data) && is_array($data)) {
            $this->data = array_merge($this->data, $data);
        } else {
            $this->data = $data;
        }
    }

    public function setUrl(?string $url): void
    {
        $this->url = $url;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function toArray(): array
    {
        return array_filter([
            'title'      => $this->title,
            'message'    => $this->message ?: null,
            'type'       => $this->type?->value,
            'error'      => $this->error ?: null,
            'statusCode' => $this->statusCode,
            'data'       => $this->data,
        ], fn($value) => $value !== null);
    }

    public function redirecting(): Redirector|RedirectResponse
    {
        $redirect = $this->url
            ? redirect()->to($this->url)
            : redirect()->back();

        return $redirect->with(['reply' => $this->toArray()]);
    }

    public function replying(): JsonResponse
    {
        return response()->json($this->toArray(), $this->statusCode);
    }
}

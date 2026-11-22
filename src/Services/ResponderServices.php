<?php

namespace Teksite\Handler\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use Teksite\Handler\Enums\ResponseType;

class ResponderServices
{
    private ?string       $title      = null;
    private array         $message    = [];
    private array         $error      = [];
    private ?ResponseType $type       = null;
    private int           $statusCode = 200;
    private mixed         $data       = null;
    private ?string       $url        = null;

    /**
     * title of response message
     *
     * @param string|null $title
     */
    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    /**
     * Messages accumulate across calls; empty values are ignored.
     *
     * @param array<int|string, mixed>|string|null $message
     */
    public function setMessage(null|array|string $message): void
    {
        if ($message === null || $message === '' || $message === []) return;

        $this->message = array_values(array_filter(
            array_merge($this->message, (array)$message),
            static fn(mixed $value): bool => $value !== null && $value !== '',
        ));
    }

    public function setType(ResponseType $type): void
    {
        $this->type = $type;
    }

    /**
     * @throws InvalidArgumentException When the status is outside the valid HTTP range.
     */
    public function setStatusCode(?int $statusCode): void
    {
        if ($statusCode === null) return;

        if ($statusCode < 100 || $statusCode > 599) throw new InvalidArgumentException("The HTTP status code [{$statusCode}] must be between 100 and 599.");

        $this->statusCode = $statusCode;
    }

    public function setError(null|array|string $error): void
    {
        if ($error === null || $error === '' || $error === []) return;
        $this->error = array_merge($this->error, (array)$error);
    }

    /**
     * Arrays are merged with earlier data; any other value replaces it.
     */
    public function setData(mixed $data): void
    {
        if ($data === null || $data === [] || $data === '') return;

        $this->data = is_array($this->data) && is_array($data)
            ? array_merge($this->data, $data)
            : $data;
    }

    public function setUrl(?string $url): void
    {
        $this->url = $url;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title'      => $this->title,
            'message'    => $this->message ?: null,
            'type'       => $this->type?->value,
            'error'      => $this->error ?: null,
            'statusCode' => $this->statusCode,
            'data'       => $this->data,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Redirect to the given URL (or back) and flash the payload as "reply".
     * The data is left out: it can be large or sensitive and must not live in the session.
     */
    public function redirecting(): RedirectResponse
    {
        $redirect = $this->url ? redirect()->to($this->url) : redirect()->back();

        $reply = $this->toArray();

        unset($reply['data']);

        return $redirect->with(['reply' => $reply]);
    }

    public function replying(): JsonResponse
    {
        return response()->json($this->toArray(), $this->statusCode);
    }
}

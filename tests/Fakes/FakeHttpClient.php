<?php

declare(strict_types=1);

namespace AiSdk\Anthropic\Tests\Fakes;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    /** @var array<int, RequestInterface> */
    public array $requests = [];

    /** @var array<int, string> */
    private array $queuedBodies = [];

    public function queue(string ...$bodies): void
    {
        $this->queuedBodies = [...$this->queuedBodies, ...$bodies];
    }

    public function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly string $contentType = 'application/json',
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;
        $this->requests[] = $request;

        return new Response($this->status, ['Content-Type' => $this->contentType], array_shift($this->queuedBodies) ?? $this->body);
    }

    /**
     * @return array<string, mixed>
     */
    public function sentBody(): array
    {
        $decoded = json_decode((string) $this->lastRequest?->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}

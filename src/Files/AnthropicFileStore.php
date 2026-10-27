<?php

declare(strict_types=1);

namespace AiSdk\Anthropic\Files;

use AiSdk\Contracts\FileStoreInterface;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\Files\FilePage;
use AiSdk\Files\FileReference;
use AiSdk\Files\FileUpload;
use AiSdk\Files\StoredFile;
use AiSdk\Support\Json;
use AiSdk\Support\Sdk;
use AiSdk\Utils\Http\HttpRunner;
use AiSdk\Utils\Http\MultipartUpload;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final readonly class AnthropicFileStore implements FileStoreInterface
{
    /** @param array<string, string> $headers */
    public function __construct(private Sdk $sdk, private string $baseUrl, private array $headers, private string $provider = 'anthropic') {}

    public function upload(FileUpload $file, array $options = []): StoredFile
    {
        $fields = $options;
        $request = MultipartUpload::request($this->sdk, $this->url(), $file, $fields, 512 * 1024 * 1024);

        return $this->parse(Json::decode((string) $this->send($request)->getBody()));
    }

    public function retrieve(string $id): StoredFile
    {
        return $this->parse($this->json('GET', $this->url($id)));
    }

    public function list(?string $cursor = null, int $limit = 20): FilePage
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('File page limit must be between 1 and 1000.');
        }
        $query = ['limit' => $limit];
        if ($cursor !== null) {
            $query['page'] = $cursor;
        }
        $data = $this->json('GET', $this->url() . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        if (! is_array($data['data'] ?? null)) {
            $this->invalid();
        }
        $files = [];
        foreach ($data['data'] as $item) {
            if (! is_array($item)) {
                $this->invalid();
            }
            $files[] = $this->parse($item);
        }
        if (isset($data['next_page']) && ! is_string($data['next_page'])) {
            $this->invalid();
        }
        $next = is_string($data['next_page'] ?? null) && $data['next_page'] !== '' ? $data['next_page'] : null;

        return new FilePage($files, $next);
    }

    public function delete(string $id): void
    {
        $this->send($this->sdk->requestFactory->createRequest('DELETE', $this->url($id)));
    }

    public function download(string $id): StreamInterface
    {
        return $this->send($this->sdk->requestFactory->createRequest('GET', $this->url($id) . '/content'))->getBody();
    }

    private function url(?string $id = null): string
    {
        if ($id !== null) {
            new FileReference($this->provider, $id);
        }

        return rtrim($this->baseUrl, '/') . '/files' . ($id === null ? '' : '/' . rawurlencode($id));
    }

    private function send(RequestInterface $request): ResponseInterface
    {
        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return HttpRunner::fromSdk($this->sdk)->sendRequest($request, $this->provider);
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $url): array
    {
        return Json::decode((string) $this->send($this->sdk->requestFactory->createRequest($method, $url))->getBody());
    }

    /** @param array<string, mixed> $data */
    private function parse(array $data): StoredFile
    {
        if (! is_string($data['id'] ?? null) || ! is_string($data['filename'] ?? null) || ! is_int($data['size_bytes'] ?? null) || $data['size_bytes'] < 0) {
            $this->invalid();
        }

        return new StoredFile(new FileReference($this->provider, $data['id']), $data['filename'], $data['size_bytes'], is_string($data['mime_type'] ?? null) ? $data['mime_type'] : null, is_bool($data['downloadable'] ?? null) ? $data['downloadable'] : null, $data);
    }

    private function invalid(): never
    {
        throw InvalidResponseException::forProvider($this->provider, 'Provider returned malformed file metadata.');
    }
}

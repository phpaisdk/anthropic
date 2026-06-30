<?php

declare(strict_types=1);

namespace AiSdk\Anthropic;

use AiSdk\Support\Sdk;
use AiSdk\Utils\Support\Env;
use AiSdk\Utils\Support\Url;

final class AnthropicOptions
{
    public const string DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';
    public const string DEFAULT_VERSION = '2023-06-01';
    public const string PROVIDER_NAME = 'anthropic';

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly string $version = self::DEFAULT_VERSION,
        public readonly array $headers = [],
        public readonly ?Sdk $sdk = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config = []): self
    {
        $apiKey = Env::loadApiKey(
            isset($config['apiKey']) ? (string) $config['apiKey'] : null,
            'ANTHROPIC_API_KEY',
            self::PROVIDER_NAME,
        );

        $baseUrl = Url::withoutTrailingSlash(
            Env::loadOptionalSetting(isset($config['baseUrl']) ? (string) $config['baseUrl'] : null, 'ANTHROPIC_BASE_URL')
                ?? self::DEFAULT_BASE_URL,
        );

        $version = Env::loadOptionalSetting(
            isset($config['version']) ? (string) $config['version'] : null,
            'ANTHROPIC_VERSION',
        ) ?? self::DEFAULT_VERSION;

        /** @var array<string, string> $headers */
        $headers = isset($config['headers']) && is_array($config['headers']) ? $config['headers'] : [];
        $sdk = $config['sdk'] ?? null;

        return new self(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            version: $version,
            headers: $headers,
            sdk: $sdk instanceof Sdk ? $sdk : null,
        );
    }

    /**
     * @return array<string, string>
     */
    public function authHeaders(): array
    {
        return array_merge([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->version,
        ], $this->headers);
    }
}

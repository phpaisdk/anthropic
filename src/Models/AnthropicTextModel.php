<?php

declare(strict_types=1);

namespace AiSdk\Anthropic\Models;

use AiSdk\Anthropic\AnthropicOptions;
use AiSdk\Anthropic\Support\AnthropicRequestBuilder;
use AiSdk\Anthropic\Support\AnthropicResponseParser;
use AiSdk\Anthropic\Support\AnthropicStreamParser;
use AiSdk\Capability;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Requests\TextModelRequest;
use AiSdk\Responses\TextModelResponse;
use AiSdk\Utils\Support\Url;
use Generator;

final class AnthropicTextModel extends BaseModel implements TextModelInterface
{
    private const array ADAPTER_CAPABILITIES = [
        Capability::TextGeneration,
        Capability::Streaming,
        Capability::ToolCalling,
        Capability::Reasoning,
        Capability::TextInput,
        Capability::ImageInput,
        Capability::FileInput,
    ];

    private const array ADAPTED_CAPABILITIES = [
        'structured_output' => 'forced tool use with schema validation',
    ];

    public function __construct(
        private readonly string $modelId,
        private readonly AnthropicOptions $options,
    ) {}

    public function provider(): string
    {
        return AnthropicOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function generate(TextModelRequest $request): TextModelResponse
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES, self::ADAPTED_CAPABILITIES);

        $body = AnthropicRequestBuilder::build($this->modelId, $request, stream: false);
        $url = Url::joinPath($this->options->baseUrl, '/messages');

        $payload = $this->runner($this->options->sdk)
            ->postJson($url, $body, $this->options->authHeaders(), $this->provider());

        return AnthropicResponseParser::parse($payload);
    }

    public function stream(TextModelRequest $request): Generator
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES, self::ADAPTED_CAPABILITIES, streaming: true);

        $body = AnthropicRequestBuilder::build($this->modelId, $request, stream: true);
        $url = Url::joinPath($this->options->baseUrl, '/messages');

        $events = $this->runner($this->options->sdk)
            ->postStream($url, $body, $this->options->authHeaders(), $this->provider());

        yield from AnthropicStreamParser::parse($events);
    }

}

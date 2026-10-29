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
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\Requests\TextModelRequest;
use AiSdk\Responses\TextModelResponse;
use AiSdk\Streaming\FinishPart;
use AiSdk\Support\Json;
use AiSdk\Support\Usage;
use AiSdk\Utils\Support\Url;
use Generator;

final class AnthropicTextModel extends BaseModel implements TextModelInterface
{
    private const int MAX_CONTINUATIONS = 5;

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

    protected function supportsStoredFiles(): bool
    {
        return true;
    }

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

        $parts = $content = [];
        $usage = Usage::empty();

        for ($continuations = 0; ; $continuations++) {
            $httpResponse = $this->runner($this->options->sdk)
                ->postRaw($url, $body, $this->options->authHeaders(), $this->provider());
            $json = (string) $httpResponse->getBody();
            $payload = Json::decode($json, $this->provider());
            $response = AnthropicResponseParser::parse($payload);
            $parts = [...$parts, ...$response->parts];
            // Keep nested JSON objects intact for server-tool replay, including empty objects.
            $raw = json_decode($json);
            foreach ($raw->content as $block) {
                $content[] = is_object($block) ? get_object_vars($block) : $block;
            }
            $usage = $usage->add($response->usage);

            if ($response->response->rawFinishReason !== 'pause_turn') {
                return new TextModelResponse(
                    parts: $parts,
                    finishReason: $response->finishReason,
                    usage: $usage,
                    rawResponse: $response->rawResponse,
                    providerMetadata: $response->providerMetadata,
                    response: $response->response,
                );
            }

            $body = self::continueBody($body, $content, $continuations);
        }
    }

    public function stream(TextModelRequest $request): Generator
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES, self::ADAPTED_CAPABILITIES, streaming: true);

        $body = AnthropicRequestBuilder::build($this->modelId, $request, stream: true);
        $url = Url::joinPath($this->options->baseUrl, '/messages');

        $content = [];
        $usage = Usage::empty();

        for ($continuations = 0; ; $continuations++) {
            $events = $this->runner($this->options->sdk)
                ->postStream($url, $body, $this->options->authHeaders(), $this->provider());
            $parser = AnthropicStreamParser::parse($events, count($content));
            $finish = null;

            foreach ($parser as $part) {
                if ($part instanceof FinishPart) {
                    $finish = $part;
                } else {
                    yield $part;
                }
            }

            if ($finish === null) {
                throw InvalidResponseException::forProvider('anthropic', 'Anthropic returned no stream finish.');
            }

            $content = [...$content, ...$parser->getReturn()];
            $usage = $usage->add($finish->usage);

            if ($finish->response->rawFinishReason !== 'pause_turn') {
                yield new FinishPart($finish->reason, $usage, $finish->response);

                return;
            }

            $body = self::continueBody($body, $content, $continuations);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int, mixed>  $content
     * @return array<string, mixed>
     */
    private static function continueBody(array $body, array $content, int $continuations): array
    {
        if ($continuations >= self::MAX_CONTINUATIONS) {
            throw InvalidResponseException::forProvider('anthropic', 'Anthropic pause_turn exceeded the limit of 5 continuations.');
        }

        // Replay the entire assistant turn, including opaque server-tool results and thinking signatures.
        // https://platform.claude.com/docs/en/build-with-claude/handling-stop-reasons#pause-turn
        if ($continuations > 0) {
            array_pop($body['messages']);
        }

        $body['messages'][] = ['role' => 'assistant', 'content' => $content];

        return $body;
    }
}

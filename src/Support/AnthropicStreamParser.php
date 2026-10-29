<?php

declare(strict_types=1);

namespace AiSdk\Anthropic\Support;

use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\FinishReason;
use AiSdk\Responses\ResponseMetadata;
use AiSdk\Streaming\ErrorPart;
use AiSdk\Streaming\FinishPart;
use AiSdk\Streaming\ProviderMetadataPart;
use AiSdk\Streaming\ReasoningDeltaPart;
use AiSdk\Streaming\StreamPart;
use AiSdk\Streaming\TextDeltaPart;
use AiSdk\Streaming\ToolCallDeltaPart;
use AiSdk\Streaming\ToolCallStartPart;
use AiSdk\Support\Usage;
use AiSdk\Utils\Errors\HttpErrorNormalizer;
use Generator;

final class AnthropicStreamParser
{
    /**
     * @param  iterable<int, array{event: ?string, data: string}>  $events
     * @return Generator<int, StreamPart, mixed, array<int, array<string, mixed>>>
     */
    public static function parse(iterable $events, int $indexOffset = 0): Generator
    {
        $content = $partialInputs = [];
        $stopped = false;
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheRead = null;
        $cacheWrite = null;
        $id = $model = $rawFinishReason = null;
        $finishReason = FinishReason::Stop;

        foreach ($events as $event) {
            $payload = json_decode($event['data'], true);
            if (! is_array($payload)) {
                continue;
            }

            $type = (string) ($payload['type'] ?? $event['event'] ?? '');

            if ($type === 'error') {
                yield new ErrorPart(HttpErrorNormalizer::fromStreamError('anthropic', $payload['error'] ?? null));

                continue;
            }

            if ($type === 'message_start') {
                $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
                $id = is_string($message['id'] ?? null) ? $message['id'] : null;
                $model = is_string($message['model'] ?? null) ? $message['model'] : null;
                if (isset($message['id'])) {
                    yield new ProviderMetadataPart('anthropic', ['id' => $message['id']]);
                }

                $usage = is_array($message['usage'] ?? null) ? $message['usage'] : [];
                $inputTokens = isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : $inputTokens;
                $outputTokens = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : $outputTokens;
                $cacheRead = isset($usage['cache_read_input_tokens']) ? (int) $usage['cache_read_input_tokens'] : $cacheRead;
                $cacheWrite = isset($usage['cache_creation_input_tokens']) ? (int) $usage['cache_creation_input_tokens'] : $cacheWrite;

                continue;
            }

            if ($type === 'content_block_start') {
                $block = is_array($payload['content_block'] ?? null) ? $payload['content_block'] : [];
                $index = (int) ($payload['index'] ?? 0);
                // Decode nested objects without converting empty JSON objects to arrays on replay.
                $raw = json_decode($event['data']);
                $content[$index] = isset($raw->content_block) && is_object($raw->content_block)
                    ? get_object_vars($raw->content_block) : $block;
                if (($block['type'] ?? null) === 'tool_use') {
                    yield new ToolCallStartPart(
                        index: $index + $indexOffset,
                        id: (string) ($block['id'] ?? ''),
                        name: (string) ($block['name'] ?? ''),
                    );
                }

                continue;
            }

            if ($type === 'content_block_delta') {
                $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];
                $index = (int) ($payload['index'] ?? 0);

                if (isset($content[$index])) {
                    $field = match ($delta['type'] ?? null) {
                        'text_delta' => 'text',
                        'thinking_delta' => 'thinking',
                        'signature_delta' => 'signature',
                        default => null,
                    };
                    if ($field !== null) {
                        $content[$index][$field] = ($content[$index][$field] ?? '') . ($delta[$field] ?? '');
                    }
                    if (($delta['type'] ?? null) === 'citations_delta' && isset($delta['citation'])) {
                        $content[$index]['citations'][] = $delta['citation'];
                    }
                    if (($delta['type'] ?? null) === 'input_json_delta') {
                        $partialInputs[$index] = ($partialInputs[$index] ?? '') . ($delta['partial_json'] ?? '');
                    }
                }

                match ($delta['type'] ?? null) {
                    'text_delta' => yield new TextDeltaPart((string) ($delta['text'] ?? '')),
                    'thinking_delta' => yield new ReasoningDeltaPart((string) ($delta['thinking'] ?? '')),
                    'input_json_delta' => ($content[$index]['type'] ?? null) === 'tool_use'
                        ? yield new ToolCallDeltaPart($index + $indexOffset, (string) ($delta['partial_json'] ?? '')) : null,
                    default => null,
                };

                continue;
            }

            if ($type === 'message_delta') {
                $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];
                $rawFinishReason = is_string($delta['stop_reason'] ?? null) ? $delta['stop_reason'] : null;
                $finishReason = AnthropicResponseParser::finishReason(isset($delta['stop_reason']) ? (string) $delta['stop_reason'] : null);
                $stopped = true;

                $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
                $outputTokens = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : $outputTokens;
            }
        }

        if (! $stopped) {
            throw InvalidResponseException::forProvider('anthropic', 'Anthropic ended its event stream before the message finished; the response may be incomplete.');
        }

        foreach ($partialInputs as $index => $json) {
            $input = json_decode($json);
            if (! is_object($input)) {
                throw InvalidResponseException::forProvider('anthropic', 'Anthropic returned invalid streamed tool input.');
            }
            $content[$index]['input'] = $input;
        }

        yield new FinishPart($finishReason, new Usage(
            inputTokens: $inputTokens + ($cacheRead ?? 0) + ($cacheWrite ?? 0),
            outputTokens: $outputTokens,
            cachedInputTokens: $cacheRead,
            cacheWriteInputTokens: $cacheWrite,
        ), new ResponseMetadata($id, $model, $rawFinishReason));

        return array_values($content);
    }
}

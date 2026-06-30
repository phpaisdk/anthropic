<?php

declare(strict_types=1);

namespace AiSdk\Anthropic\Support;

use AiSdk\FinishReason;
use AiSdk\Streaming\ErrorPart;
use AiSdk\Streaming\FinishPart;
use AiSdk\Streaming\ProviderMetadataPart;
use AiSdk\Streaming\ReasoningDeltaPart;
use AiSdk\Streaming\StreamPart;
use AiSdk\Streaming\TextDeltaPart;
use AiSdk\Streaming\ToolCallDeltaPart;
use AiSdk\Streaming\ToolCallStartPart;
use AiSdk\Support\Usage;
use Generator;

final class AnthropicStreamParser
{
    /**
     * @param  iterable<int, array{event: ?string, data: string}>  $events
     * @return Generator<int, StreamPart>
     */
    public static function parse(iterable $events): Generator
    {
        $inputTokens = 0;
        $outputTokens = 0;
        $finishReason = FinishReason::Stop;

        foreach ($events as $event) {
            $payload = json_decode($event['data'], true);
            if (! is_array($payload)) {
                continue;
            }

            $type = (string) ($payload['type'] ?? $event['event'] ?? '');

            if ($type === 'error') {
                yield new ErrorPart(new \RuntimeException((string) ($payload['error']['message'] ?? 'Anthropic stream error.')));
                continue;
            }

            if ($type === 'message_start') {
                $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
                if (isset($message['id'])) {
                    yield new ProviderMetadataPart('anthropic', ['id' => $message['id']]);
                }

                $usage = is_array($message['usage'] ?? null) ? $message['usage'] : [];
                $inputTokens = isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : $inputTokens;
                $outputTokens = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : $outputTokens;
                continue;
            }

            if ($type === 'content_block_start') {
                $block = is_array($payload['content_block'] ?? null) ? $payload['content_block'] : [];
                if (($block['type'] ?? null) === 'tool_use') {
                    yield new ToolCallStartPart(
                        index: (int) ($payload['index'] ?? 0),
                        id: (string) ($block['id'] ?? ''),
                        name: (string) ($block['name'] ?? ''),
                    );
                }

                continue;
            }

            if ($type === 'content_block_delta') {
                $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];
                $index = (int) ($payload['index'] ?? 0);

                match ($delta['type'] ?? null) {
                    'text_delta' => yield new TextDeltaPart((string) ($delta['text'] ?? '')),
                    'thinking_delta' => yield new ReasoningDeltaPart((string) ($delta['thinking'] ?? '')),
                    'input_json_delta' => yield new ToolCallDeltaPart($index, (string) ($delta['partial_json'] ?? '')),
                    default => null,
                };

                continue;
            }

            if ($type === 'message_delta') {
                $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];
                $finishReason = self::finishReason(isset($delta['stop_reason']) ? (string) $delta['stop_reason'] : null);

                $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
                $outputTokens = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : $outputTokens;
            }
        }

        yield new FinishPart($finishReason, new Usage($inputTokens, $outputTokens));
    }

    private static function finishReason(?string $reason): FinishReason
    {
        return match ($reason) {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'max_tokens' => FinishReason::Length,
            'tool_use' => FinishReason::ToolCalls,
            default => FinishReason::Unknown,
        };
    }
}

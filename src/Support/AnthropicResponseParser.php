<?php

declare(strict_types=1);

namespace AiSdk\Anthropic\Support;

use AiSdk\FinishReason;
use AiSdk\Responses\Parts\ReasoningPart;
use AiSdk\Responses\Parts\TextPart;
use AiSdk\Responses\Parts\ToolCallPart;
use AiSdk\Responses\TextModelResponse;
use AiSdk\Support\Json;
use AiSdk\Support\Usage;

final class AnthropicResponseParser
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function parse(array $payload): TextModelResponse
    {
        $parts = [];

        foreach (($payload['content'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? null;
            if ($type === 'text' && isset($item['text']) && is_string($item['text'])) {
                $parts[] = new TextPart($item['text']);
            }

            if ($type === 'thinking' && isset($item['thinking']) && is_string($item['thinking'])) {
                $parts[] = new ReasoningPart($item['thinking']);
            }

            if ($type === 'tool_use') {
                $input = $item['input'] ?? [];
                if (($item['name'] ?? null) === 'structured_output') {
                    $parts[] = new TextPart(Json::encode(is_array($input) ? $input : []));

                    continue;
                }

                $parts[] = new ToolCallPart(
                    id: (string) ($item['id'] ?? ''),
                    name: (string) ($item['name'] ?? ''),
                    arguments: is_array($input) ? $input : [],
                );
            }
        }

        return new TextModelResponse(
            parts: $parts,
            finishReason: self::finishReason(isset($payload['stop_reason']) ? (string) $payload['stop_reason'] : null),
            usage: self::usage($payload['usage'] ?? null),
            rawResponse: $payload,
            providerMetadata: ['anthropic' => ['id' => $payload['id'] ?? null]],
        );
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

    private static function usage(mixed $usage): Usage
    {
        if (! is_array($usage)) {
            return Usage::empty();
        }

        return new Usage(
            inputTokens: isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : 0,
            outputTokens: isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : 0,
        );
    }
}

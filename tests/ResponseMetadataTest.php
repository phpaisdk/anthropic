<?php

declare(strict_types=1);

use AiSdk\Anthropic\Support\AnthropicResponseParser;
use AiSdk\Anthropic\Support\AnthropicStreamParser;
use AiSdk\FinishReason;

it('preserves response identity and unknown stop reasons in both paths', function () {
    $response = AnthropicResponseParser::parse(['id' => 'r', 'model' => 'm', 'stop_reason' => 'new_reason', 'content' => []]);
    $events = [
        ['event' => null, 'data' => json_encode(['type' => 'message_start', 'message' => ['id' => 'r', 'model' => 'm']])],
        ['event' => null, 'data' => json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'new_reason']])],
    ];
    $parts = iterator_to_array(AnthropicStreamParser::parse($events));
    expect(end($parts)->response)->toEqual($response->response)
        ->and($response->response->rawFinishReason)->toBe('new_reason');
});

it('maps stop reasons consistently while preserving the raw reason', function (string $reason, FinishReason $expected) {
    $response = AnthropicResponseParser::parse(['stop_reason' => $reason, 'content' => []]);
    $parts = iterator_to_array(AnthropicStreamParser::parse([
        ['event' => null, 'data' => json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => $reason]])],
    ]));
    $finish = end($parts);

    expect($response->finishReason)->toBe($expected)
        ->and($finish->reason)->toBe($expected)
        ->and($response->response->rawFinishReason)->toBe($reason)
        ->and($finish->response->rawFinishReason)->toBe($reason);
})->with([
    ['refusal', FinishReason::ContentFilter],
    ['model_context_window_exceeded', FinishReason::Length],
    ['future_reason', FinishReason::Unknown],
]);

<?php

declare(strict_types=1);

use AiSdk\Anthropic\Support\AnthropicResponseParser;
use AiSdk\Anthropic\Support\AnthropicStreamParser;

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

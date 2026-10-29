<?php

declare(strict_types=1);

use AiSdk\Anthropic\Support\AnthropicResponseParser;
use AiSdk\Anthropic\Support\AnthropicStreamParser;

it('reports reasoning tokens as a subset of billed output tokens', function (?array $details, ?int $expected) {
    $usage = ['input_tokens' => 20, 'cache_read_input_tokens' => 800, 'cache_creation_input_tokens' => 180, 'output_tokens' => 12];
    if ($details !== null) {
        $usage['output_tokens_details'] = $details;
    }
    $response = AnthropicResponseParser::parse(['content' => [], 'usage' => $usage]);

    expect($response->usage->reasoningTokens)->toBe($expected)
        ->and($response->usage->inputTokens)->toBe(1000)
        ->and($response->usage->cachedInputTokens)->toBe(800)
        ->and($response->usage->cacheWriteInputTokens)->toBe(180)
        ->and($response->usage->outputTokens)->toBe(12)
        ->and($response->usage->totalTokens)->toBe(1012);
})->with([
    [['thinking_tokens' => 7], 7],
    [['thinking_tokens' => 0], 0],
    [null, null],
]);

it('replaces cumulative streaming counts and preserves omitted usage fields', function () {
    $events = [
        ['type' => 'message_start', 'message' => ['usage' => [
            'input_tokens' => 20, 'output_tokens' => 1, 'cache_read_input_tokens' => 800, 'cache_creation_input_tokens' => 180,
        ]]],
        ['type' => 'message_delta', 'delta' => [], 'usage' => [
            'input_tokens' => 25, 'output_tokens' => 9, 'cache_read_input_tokens' => 810, 'cache_creation_input_tokens' => 190,
        ]],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => [
            'output_tokens' => 12, 'output_tokens_details' => ['thinking_tokens' => 7],
        ]],
    ];
    $parts = iterator_to_array(AnthropicStreamParser::parse(array_map(
        fn(array $event): array => ['event' => null, 'data' => json_encode($event)],
        $events,
    )));
    $usage = end($parts)->usage;

    expect($usage->inputTokens)->toBe(1025)
        ->and($usage->cachedInputTokens)->toBe(810)
        ->and($usage->cacheWriteInputTokens)->toBe(190)
        ->and($usage->outputTokens)->toBe(12)
        ->and($usage->reasoningTokens)->toBe(7)
        ->and($usage->totalTokens)->toBe(1037);
});

it('keeps the final streamed cumulative output count without adding the starting count', function (?array $details, ?int $expected) {
    $usage = ['output_tokens' => 12];
    if ($details !== null) {
        $usage['output_tokens_details'] = $details;
    }
    $parts = iterator_to_array(AnthropicStreamParser::parse([
        ['event' => null, 'data' => json_encode(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 9, 'output_tokens' => 1]]])],
        ['event' => null, 'data' => json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => $usage])],
    ]));
    $usage = end($parts)->usage;

    expect($usage->outputTokens)->toBe(12)
        ->and($usage->inputTokens)->toBe(9)
        ->and($usage->cachedInputTokens)->toBeNull()
        ->and($usage->cacheWriteInputTokens)->toBeNull()
        ->and($usage->reasoningTokens)->toBe($expected);
})->with([
    [['thinking_tokens' => 7], 7],
    [['thinking_tokens' => 0], 0],
    [null, null],
]);

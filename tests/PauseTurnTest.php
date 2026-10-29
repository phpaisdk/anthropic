<?php

declare(strict_types=1);

use AiSdk\Anthropic;
use AiSdk\Anthropic\Tests\Fakes\FakeHttpClient;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\FinishReason;
use AiSdk\Generate;
use AiSdk\Streaming\FinishPart;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    Anthropic::reset();
});

function pauseClient(string $body, bool $streaming): FakeHttpClient
{
    $client = new FakeHttpClient(200, $body, $streaming ? 'text/event-stream' : 'application/json');
    $factory = new Psr17Factory();
    Generate::configure(new Sdk(httpClient: $client, requestFactory: $factory, streamFactory: $factory));
    Anthropic::create(['apiKey' => 'test']);

    return $client;
}

function pauseBlocks(): array
{
    return [
        ['type' => 'thinking', 'thinking' => 'Plan', 'signature' => 'opaque-signature'],
        ['type' => 'text', 'text' => 'Searching. ', 'citations' => [
            ['type' => 'web_search_result_location', 'url' => 'https://example.com', 'title' => 'SDK', 'encrypted_index' => 'opaque', 'cited_text' => 'SDK'],
        ]],
        ['type' => 'server_tool_use', 'id' => 'srv_1', 'name' => 'web_search', 'input' => ['query' => 'SDK']],
        ['type' => 'web_search_tool_result', 'tool_use_id' => 'srv_1', 'content' => [
            ['type' => 'web_search_result', 'url' => 'https://example.com', 'title' => 'SDK', 'encrypted_content' => 'opaque-result'],
        ]],
        ['type' => 'server_tool_use', 'id' => 'srv_2', 'name' => 'web_search', 'input' => ['query' => 'PHP']],
    ];
}

function pauseFixture(string $reason, array $content, bool $streaming): string
{
    $message = [
        'id' => 'message-' . $reason, 'model' => 'opaque-model', 'content' => $content, 'stop_reason' => $reason,
        'usage' => ['input_tokens' => 3, 'output_tokens' => 4, 'cache_read_input_tokens' => 10, 'cache_creation_input_tokens' => 2, 'output_tokens_details' => ['thinking_tokens' => 2]],
    ];
    if (! $streaming) {
        return json_encode($message);
    }
    $message['content'] = [];
    $message['usage']['output_tokens'] = 1;
    unset($message['usage']['output_tokens_details']);
    $events = [['type' => 'message_start', 'message' => $message]];
    foreach ($content as $index => $block) {
        $type = $block['type'];
        $deltas = [];
        if (in_array($type, ['text', 'thinking'], true)) {
            $field = $type === 'text' ? 'text' : 'thinking';
            $deltas[] = ['type' => $field . '_delta', $field => $block[$field]];
            $block[$field] = '';
            foreach ($block['citations'] ?? [] as $citation) {
                $deltas[] = ['type' => 'citations_delta', 'citation' => $citation];
            }
            unset($block['citations']);
            if (isset($block['signature'])) {
                $deltas[] = ['type' => 'signature_delta', 'signature' => $block['signature']];
                $block['signature'] = '';
            }
        } elseif ($type === 'server_tool_use') {
            foreach (str_split(json_encode($block['input']), 5) as $json) {
                $deltas[] = ['type' => 'input_json_delta', 'partial_json' => $json];
            }
            $block['input'] = new stdClass();
        }
        $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => $block];
        foreach ($deltas as $delta) {
            $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => $delta];
        }
        $events[] = ['type' => 'content_block_stop', 'index' => $index];
    }
    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $reason], 'usage' => ['output_tokens' => 4, 'output_tokens_details' => ['thinking_tokens' => 2]]];
    $events[] = ['type' => 'message_stop'];

    return implode("\n\n", array_map(fn(array $event): string => 'data: ' . json_encode($event), $events)) . "\n\n";
}

function runPaused(bool $streaming): array
{
    $pending = Generate::text('Search')->model(Anthropic::model('opaque-model'));
    if (! $streaming) {
        return [$pending->run(), 1];
    }
    $stream = $pending->stream();
    $finishes = 0;
    foreach ($stream as $part) {
        if ($part instanceof FinishPart) {
            $finishes++;
        }
    }

    return [$stream->result(), $finishes];
}

it('continues paused turns with verbatim server blocks and accumulates content and usage', function (bool $streaming) {
    $blocks = pauseBlocks();
    $client = pauseClient(pauseFixture('end_turn', [['type' => 'text', 'text' => 'Done.']], $streaming), $streaming);
    $client->queue(pauseFixture('pause_turn', $blocks, $streaming));

    [$result, $finishes] = runPaused($streaming);

    expect($result->text)->toBe('Searching. Done.')
        ->and($result->reasoning)->toBe('Plan')
        ->and($result->usage->inputTokens)->toBe(30)
        ->and($result->usage->outputTokens)->toBe(8)
        ->and($result->usage->reasoningTokens)->toBe(4)
        ->and($result->usage->cachedInputTokens)->toBe(20)
        ->and($result->usage->cacheWriteInputTokens)->toBe(4)
        ->and($result->finishReason)->toBe(FinishReason::Stop)
        ->and($finishes)->toBe(1)
        ->and($client->requests)->toHaveCount(2)
        ->and($client->sentBody()['messages'][1])->toBe(['role' => 'assistant', 'content' => $blocks]);
})->with([false, true]);

it('bounds paused continuations to five additional requests', function (bool $streaming) {
    $blocks = [['type' => 'text', 'text' => 'Again']];
    $client = pauseClient(pauseFixture('pause_turn', $blocks, $streaming), $streaming);

    expect(fn() => runPaused($streaming))->toThrow(InvalidResponseException::class, 'limit of 5 continuations');

    expect($client->requests)->toHaveCount(6)
        ->and($client->sentBody()['messages'])->toHaveCount(2)
        ->and($client->sentBody()['messages'][1]['content'])->toHaveCount(5);
})->with([false, true]);

it('allows completion on the fifth continuation', function (bool $streaming) {
    $blocks = [['type' => 'text', 'text' => 'x']];
    $client = pauseClient(pauseFixture('end_turn', $blocks, $streaming), $streaming);
    $client->queue(...array_fill(0, 5, pauseFixture('pause_turn', $blocks, $streaming)));

    [$result] = runPaused($streaming);

    expect($client->requests)->toHaveCount(6)
        ->and($result->text)->toBe('xxxxxx')
        ->and($result->usage->outputTokens)->toBe(24);
})->with([false, true]);

it('preserves empty JSON objects in replayed server blocks', function (bool $streaming) {
    $blocks = [['type' => 'server_tool_use', 'id' => 'srv_1', 'name' => 'code_execution', 'input' => new stdClass()]];
    $client = pauseClient(pauseFixture('end_turn', [['type' => 'text', 'text' => 'Done']], $streaming), $streaming);
    $client->queue(pauseFixture('pause_turn', $blocks, $streaming));

    runPaused($streaming);

    $body = json_decode((string) $client->lastRequest->getBody());
    expect($body->messages[1]->content[0]->input)->toBeInstanceOf(stdClass::class);
})->with([false, true]);

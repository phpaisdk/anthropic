<?php

declare(strict_types=1);

use AiSdk\Anthropic;
use AiSdk\Anthropic\Tests\Fakes\FakeHttpClient;
use AiSdk\Generate;
use AiSdk\Message;
use AiSdk\PromptCache;
use AiSdk\Schema;
use AiSdk\Support\Sdk;
use AiSdk\Tool;

afterEach(function () {
    Generate::reset();
    Anthropic::reset();
});

function cacheClient(array $usage = ['input_tokens' => 9, 'output_tokens' => 4]): FakeHttpClient
{
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'msg_1',
        'content' => [['type' => 'text', 'text' => 'ok']],
        'stop_reason' => 'end_turn',
        'usage' => $usage,
    ]));

    $factory = new Nyholm\Psr7\Factory\Psr17Factory();
    Generate::configure(new Sdk(httpClient: $client, requestFactory: $factory, streamFactory: $factory));
    Anthropic::create(['apiKey' => 'sk-ant-test']);

    return $client;
}

function lookupTool(): Tool
{
    return Tool::make('lookup', 'Look something up')
        ->input(Schema::object('lookup_input', properties: [Schema::string('q')->required()]))
        ->run(fn(array $input): string => 'x');
}

it('adds no cache_control unless caching is requested', function () {
    $client = cacheClient();

    Generate::text('Hi')->instructions('Guide')->tools(lookupTool())->model(Anthropic::model('claude-sonnet-4'))->run();

    expect(json_encode($client->sentBody()))->not->toContain('cache_control')
        ->and($client->sentBody()['system'])->toBe('Guide');
});

it('marks instructions, the last tool and the conversation prefix as cacheable', function () {
    $client = cacheClient();

    Generate::text()
        ->messages([Message::user('First question'), Message::assistant('First answer'), Message::user('Second question')])
        ->instructions('Long stable guide')
        ->tools(lookupTool())
        ->cache()
        ->model(Anthropic::model('claude-sonnet-4'))
        ->run();

    $body = $client->sentBody();

    expect($body['system'])->toBe([['type' => 'text', 'text' => 'Long stable guide', 'cache_control' => ['type' => 'ephemeral']]])
        ->and($body['tools'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['messages'][1]['content'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['messages'][0]['content'][0])->not->toHaveKey('cache_control')
        ->and($body['messages'][2]['content'][0])->not->toHaveKey('cache_control');
});

it('does not cache the only message of a single-turn prompt', function () {
    $client = cacheClient();

    Generate::text('Hi')->instructions('Guide')->cache()->model(Anthropic::model('claude-sonnet-4'))->run();

    expect($client->sentBody()['messages'][0]['content'][0])->not->toHaveKey('cache_control');
});

it('requests the one hour cache lifetime', function () {
    $client = cacheClient();

    Generate::text('Hi')->instructions('Guide')->cache(PromptCache::ttl('1h'))->model(Anthropic::model('claude-sonnet-4'))->run();

    expect($client->sentBody()['system'][0]['cache_control'])->toBe(['type' => 'ephemeral', 'ttl' => '1h']);
});

it('reports cache reads and writes with the whole prompt as input tokens', function () {
    cacheClient(['input_tokens' => 20, 'cache_read_input_tokens' => 800, 'cache_creation_input_tokens' => 180, 'output_tokens' => 10]);

    $usage = Generate::text('Hi')->model(Anthropic::model('claude-sonnet-4'))->run()->usage;

    expect($usage->inputTokens)->toBe(1000)
        ->and($usage->cachedInputTokens)->toBe(800)
        ->and($usage->cacheWriteInputTokens)->toBe(180)
        ->and($usage->cacheHitRate())->toEqualWithDelta(0.8, 0.0001);
});

it('keeps usage unchanged when the provider reports no cache fields', function () {
    cacheClient(['input_tokens' => 9, 'output_tokens' => 4]);

    $usage = Generate::text('Hi')->model(Anthropic::model('claude-sonnet-4'))->run()->usage;

    expect($usage->inputTokens)->toBe(9)->and($usage->cachedInputTokens)->toBeNull()->and($usage->cacheWriteInputTokens)->toBeNull();
});

it('reports cache usage when streaming', function () {
    $client = new FakeHttpClient(200, implode("\n\n", [
        'event: message_start' . "\n" . 'data: {"type":"message_start","message":{"id":"msg_1","usage":{"input_tokens":5,"cache_read_input_tokens":700,"cache_creation_input_tokens":0,"output_tokens":0}}}',
        'event: content_block_delta' . "\n" . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hi"}}',
        'event: message_delta' . "\n" . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}',
    ]) . "\n\n", 'text/event-stream');
    $factory = new Nyholm\Psr7\Factory\Psr17Factory();
    Generate::configure(new Sdk(httpClient: $client, requestFactory: $factory, streamFactory: $factory));
    Anthropic::create(['apiKey' => 'sk-ant-test']);

    $stream = Generate::text('Hi')->cache()->model(Anthropic::model('claude-sonnet-4'))->stream();
    foreach ($stream as $part) {
        // consume
    }
    $usage = $stream->result()->usage;

    expect($usage->inputTokens)->toBe(705)->and($usage->cachedInputTokens)->toBe(700)->and($usage->cacheWriteInputTokens)->toBe(0);
});

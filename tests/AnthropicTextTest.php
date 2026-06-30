<?php

declare(strict_types=1);

use AiSdk\Anthropic;
use AiSdk\Anthropic\Tests\Fakes\FakeHttpClient;
use AiSdk\Content;
use AiSdk\Generate;
use AiSdk\Schema;
use AiSdk\Support\Sdk;

afterEach(function () {
    Generate::reset();
    Anthropic::reset();
});

function configureAnthropicWith(FakeHttpClient $client): void
{
    $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    Generate::configure(new Sdk(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

it('generates text end to end through the Anthropic vertical', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'msg_1',
        'content' => [['type' => 'text', 'text' => 'Hello from Anthropic']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 9, 'output_tokens' => 4],
    ]));
    configureAnthropicWith($client);

    Anthropic::create(['apiKey' => 'sk-ant-test']);

    $result = Generate::text('Hi')->instructions('Be terse')->model(Anthropic::model('claude-sonnet-4'))->run();

    expect($result->text)->toBe('Hello from Anthropic')
        ->and($result->usage->inputTokens)->toBe(9);

    $body = $client->sentBody();
    expect($body['model'])->toBe('claude-sonnet-4')
        ->and($body['system'])->toBe('Be terse')
        ->and($body['messages'][0]['role'])->toBe('user')
        ->and($body['stream'])->toBeFalse();

    expect($client->lastRequest->getHeaderLine('x-api-key'))->toBe('sk-ant-test')
        ->and($client->lastRequest->getHeaderLine('anthropic-version'))->toBe('2023-06-01');
});

it('maps a 401 to an authentication exception', function () {
    $client = new FakeHttpClient(401, json_encode(['error' => ['message' => 'bad key']]));
    configureAnthropicWith($client);
    Anthropic::create(['apiKey' => 'sk-ant-bad']);

    Generate::text('Hi')->model(Anthropic::model('claude-sonnet-4'))->run();
})->throws(\AiSdk\Exceptions\AuthenticationException::class);

it('streams text deltas through Anthropic messages streaming', function () {
    $client = new FakeHttpClient(200, implode("\n\n", [
        'event: message_start' . "\n" . 'data: {"type":"message_start","message":{"id":"msg_1","usage":{"input_tokens":3,"output_tokens":0}}}',
        'event: content_block_delta' . "\n" . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hel"}}',
        'event: content_block_delta' . "\n" . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"lo"}}',
        'event: message_delta' . "\n" . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}',
        'event: message_stop' . "\n" . 'data: {"type":"message_stop"}',
    ]) . "\n\n", 'text/event-stream');
    configureAnthropicWith($client);

    Anthropic::create(['apiKey' => 'sk-ant-test']);

    $stream = Generate::text('Hi')
        ->model(Anthropic::model('claude-sonnet-4'))
        ->stream();

    expect(implode('', iterator_to_array($stream->chunks())))->toBe('Hello');

    $body = $client->sentBody();
    expect($body['stream'])->toBeTrue();
});

it('sends image and file inputs as native Anthropic content blocks', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'msg_1',
        'content' => [['type' => 'text', 'text' => 'Done']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 9, 'output_tokens' => 4],
    ]));
    configureAnthropicWith($client);

    Anthropic::create(['apiKey' => 'sk-ant-test']);

    Generate::text()
        ->messages([
            \AiSdk\Message::user([
                Content::text('Compare these.'),
                Content::image('raw-image', mimeType: 'image/png'),
                Content::file('JVBERi0=', mimeType: 'application/pdf', filename: 'report.pdf', encoding: \AiSdk\InputEncoding::Base64),
            ]),
        ])
        ->model(Anthropic::model('claude-sonnet-4'))
        ->run();

    $body = $client->sentBody();

    expect($body['messages'][0]['content'][1]['type'])->toBe('image')
        ->and($body['messages'][0]['content'][1]['source']['type'])->toBe('base64')
        ->and($body['messages'][0]['content'][2]['type'])->toBe('document')
        ->and($body['messages'][0]['content'][2]['source']['media_type'])->toBe('application/pdf');
});

it('blocks unsupported audio input before sending an Anthropic request', function () {
    $client = new FakeHttpClient(200, json_encode([]));
    configureAnthropicWith($client);
    Anthropic::create(['apiKey' => 'sk-ant-test']);

    Generate::text()
        ->messages([
            \AiSdk\Message::user([
                Content::text('Transcribe this.'),
                Content::audio('UklGRg==', mimeType: 'audio/wav', encoding: \AiSdk\InputEncoding::Base64),
            ]),
        ])
        ->model(Anthropic::model('claude-sonnet-4'))
        ->run();
})->throws(\AiSdk\Exceptions\CapabilityNotSupportedException::class);

it('merges structured output tool with user tools instead of replacing them', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'msg_1',
        'content' => [[
            'type' => 'tool_use',
            'id' => 'toolu_1',
            'name' => 'structured_output',
            'input' => ['city' => 'Lahore'],
        ]],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 9, 'output_tokens' => 4],
    ]));
    configureAnthropicWith($client);
    Anthropic::create(['apiKey' => 'sk-ant-test']);

    $weather = \AiSdk\Tool::make('weather', 'Get weather')
        ->input(Schema::string(name: 'city')->required())
        ->run(fn(string $city): string => "Sunny in {$city}");

    $result = Generate::text('Extract Lahore and keep tools available.')
        ->model(Anthropic::model('claude-sonnet-4'))
        ->tools($weather)
        ->output(Schema::object(
            name: 'address',
            properties: [
                Schema::string(name: 'city')->required(),
            ],
        ))
        ->run();

    $body = $client->sentBody();
    $toolNames = array_column($body['tools'], 'name');

    expect($toolNames)->toBe(['weather', 'structured_output'])
        ->and($body['tool_choice'])->toBe(['type' => 'tool', 'name' => 'structured_output'])
        ->and($result->output)->toBe(['city' => 'Lahore']);
});

it('reports adapted structured output from the model catalog', function () {
    Anthropic::create(['apiKey' => 'sk-ant-test']);

    $support = Anthropic::model('claude-sonnet-4')->capability(\AiSdk\Capability::StructuredOutput);

    expect($support->state)->toBe(\AiSdk\CapabilitySupportState::Adapted)
        ->and($support->strategy)->toContain('forced tool use');
});

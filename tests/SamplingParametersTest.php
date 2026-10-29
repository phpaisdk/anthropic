<?php

declare(strict_types=1);

use AiSdk\Anthropic;
use AiSdk\Anthropic\Tests\Fakes\FakeHttpClient;
use AiSdk\Generate;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    Anthropic::reset();
});

function samplingClient(bool $streaming): FakeHttpClient
{
    $body = $streaming
        ? "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"}}\n\n"
        : json_encode(['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn']);
    $client = new FakeHttpClient(200, $body, $streaming ? 'text/event-stream' : 'application/json');
    $factory = new Psr17Factory();
    Generate::configure(new Sdk(httpClient: $client, requestFactory: $factory, streamFactory: $factory));
    Anthropic::create(['apiKey' => 'test']);

    return $client;
}

it('sends only the sampling parameters the caller set, leaving model-specific limits to the API', function (array $parameters, bool $streaming) {
    $client = samplingClient($streaming);
    $pending = Generate::text('Hi')->model(Anthropic::model('any/future-model'));
    if (isset($parameters['temperature'])) {
        $pending->temperature($parameters['temperature']);
    }
    if (isset($parameters['top_p'])) {
        $pending->topP($parameters['top_p']);
    }
    if ($streaming) {
        foreach ($pending->stream() as $part) {
            // Consume the fixture.
        }
    } else {
        $pending->run();
    }

    $body = $client->sentBody();
    expect(array_intersect_key($body, array_flip(['temperature', 'top_p', 'top_k'])))->toEqual($parameters)
        ->and($body['model'])->toBe('any/future-model');
})->with([
    [[]],
    [['temperature' => 0.0]],
    [['top_p' => 0.9]],
])->with([false, true]);

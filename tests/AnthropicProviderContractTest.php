<?php

declare(strict_types=1);

use AiSdk\Anthropic\AnthropicOptions;
use AiSdk\Anthropic\AnthropicProvider;
use AiSdk\Contracts\Model;
use AiSdk\Contracts\TextProviderInterface;
use AiSdk\Exceptions\NoSuchModelException;
use AiSdk\Generate;

afterEach(fn() => Generate::reset());

it('implements the text provider contract', function () {
    $provider = new AnthropicProvider(new AnthropicOptions(apiKey: 'sk-ant-test'));

    expect($provider)->toBeInstanceOf(TextProviderInterface::class);
});

it('selects every model through the unified model reference', function () {
    $provider = new AnthropicProvider(new AnthropicOptions(apiKey: 'sk-ant-test'));

    $model = $provider->model('claude-sonnet-4');

    expect($model)->toBeInstanceOf(Model::class)
        ->and($model->provider())->toBe('anthropic')
        ->and($model->modelId())->toBe('claude-sonnet-4')
        ->and(is_callable([$provider, 'textModel']))->toBeFalse();
});

it('does not support image generation', function () {
    $provider = new AnthropicProvider(new AnthropicOptions(apiKey: 'sk-ant-test'));

    Generate::image('test')->model($provider->model('some-image-model'))->run();
})->throws(NoSuchModelException::class);

it('does not support speech generation', function () {
    $provider = new AnthropicProvider(new AnthropicOptions(apiKey: 'sk-ant-test'));

    Generate::speech('test')->model($provider->model('some-speech-model'))->run();
})->throws(NoSuchModelException::class);

it('does not support embedding generation', function () {
    $provider = new AnthropicProvider(new AnthropicOptions(apiKey: 'sk-ant-test'));

    Generate::embedding('test')->model($provider->model('some-embedding-model'))->run();
})->throws(NoSuchModelException::class);

it('rejects video models through the base provider contract', function () {
    $provider = new AnthropicProvider(new AnthropicOptions(apiKey: 'sk-ant-test'));

    Generate::video('test')->model($provider->model('unsupported'))->run();
})->throws(\AiSdk\Exceptions\NoSuchModelException::class);

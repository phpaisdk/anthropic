<?php

declare(strict_types=1);

use AiSdk\Anthropic\AnthropicOptions;
use AiSdk\Anthropic\AnthropicProvider;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TextProviderInterface;
use AiSdk\Exceptions\NoSuchModelException;

it('implements the text provider contract', function () {
    $provider = new AnthropicProvider(new AnthropicOptions(apiKey: 'sk-ant-test'));

    expect($provider)->toBeInstanceOf(TextProviderInterface::class);
});

it('resolves a text model through the text contract', function () {
    $provider = new AnthropicProvider(new AnthropicOptions(apiKey: 'sk-ant-test'));

    $model = $provider->textModel('claude-sonnet-4');

    expect($model)->toBeInstanceOf(TextModelInterface::class);
});

it('does not support image generation', function () {
    $provider = new AnthropicProvider(new AnthropicOptions(apiKey: 'sk-ant-test'));

    $provider->imageModel('some-image-model');
})->throws(NoSuchModelException::class);

it('does not support speech generation', function () {
    $provider = new AnthropicProvider(new AnthropicOptions(apiKey: 'sk-ant-test'));

    $provider->speechModel('some-speech-model');
})->throws(NoSuchModelException::class);

it('does not support embedding generation', function () {
    $provider = new AnthropicProvider(new AnthropicOptions(apiKey: 'sk-ant-test'));

    $provider->embeddingModel('some-embedding-model');
})->throws(NoSuchModelException::class);

it('rejects video models through the base provider contract', function () {
    $provider = new AnthropicProvider(new AnthropicOptions(apiKey: 'sk-ant-test'));

    $provider->videoModel('unsupported');
})->throws(\AiSdk\Exceptions\NoSuchModelException::class);

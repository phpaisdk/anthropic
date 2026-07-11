<?php

declare(strict_types=1);

use AiSdk\Anthropic;
use AiSdk\Generate;

afterEach(function () {
    Generate::reset();
    Anthropic::reset();
});

it('uses adapter capabilities for opaque Anthropic model ids', function () {
    Anthropic::create(['apiKey' => 'sk-ant-test']);
    $model = Anthropic::model('vendor/private-model');

    expect($model->modelId())->toBe('vendor/private-model');
});

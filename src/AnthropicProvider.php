<?php

declare(strict_types=1);

namespace AiSdk\Anthropic;

use AiSdk\Anthropic\Models\AnthropicTextModel;
use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\TextModelInterface;

final class AnthropicProvider extends BaseProvider
{
    public function __construct(public readonly AnthropicOptions $options) {}

    public function name(): string
    {
        return AnthropicOptions::PROVIDER_NAME;
    }

    public function textModel(string $modelId): TextModelInterface
    {
        return new AnthropicTextModel($modelId, $this->options, $this->modelRegistry());
    }
}

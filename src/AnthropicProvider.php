<?php

declare(strict_types=1);

namespace AiSdk\Anthropic;

use AiSdk\Anthropic\Models\AnthropicTextModel;
use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TextProviderInterface;

final class AnthropicProvider extends BaseProvider implements TextProviderInterface
{
    public function __construct(public readonly AnthropicOptions $options) {}

    public function files(): \AiSdk\Contracts\FileStoreInterface
    {
        return new \AiSdk\Anthropic\Files\AnthropicFileStore($this->options->sdk ?? \AiSdk\Generate::sdk(), $this->options->baseUrl, $this->options->authHeaders());
    }

    public function name(): string
    {
        return AnthropicOptions::PROVIDER_NAME;
    }

    protected function textModel(string $modelId): TextModelInterface
    {
        return new AnthropicTextModel($modelId, $this->options);
    }
}

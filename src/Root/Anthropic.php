<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Anthropic\AnthropicOptions;
use AiSdk\Anthropic\AnthropicProvider;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Support\Concerns\RegistersModels;

final class Anthropic
{
    use RegistersModels;

    private static ?AnthropicProvider $default = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public static function create(array $config = []): AnthropicProvider
    {
        return self::$default = new AnthropicProvider(AnthropicOptions::fromArray($config));
    }

    public static function default(): AnthropicProvider
    {
        return self::$default ??= self::create();
    }

    public static function reset(): void
    {
        self::$default = null;
    }

    public static function model(string $modelId): TextModelInterface
    {
        return self::default()->textModel($modelId);
    }
}

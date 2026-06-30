<?php

declare(strict_types=1);

namespace AiSdk\Anthropic\Support;

use AiSdk\Tool;

final class AnthropicToolConverter
{
    /**
     * @param  array<int, Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    public static function convert(array $tools): array
    {
        return array_map(
            fn(Tool $tool): array => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'input_schema' => $tool->inputSchemaForProvider(),
            ],
            array_values($tools),
        );
    }
}

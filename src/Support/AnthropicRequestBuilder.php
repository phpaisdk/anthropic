<?php

declare(strict_types=1);

namespace AiSdk\Anthropic\Support;

use AiSdk\Content;
use AiSdk\ContentSource;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Message;
use AiSdk\Outputs\Output;
use AiSdk\PromptCache;
use AiSdk\Requests\TextModelRequest;
use AiSdk\ToolChoice;

final class AnthropicRequestBuilder
{
    /** Anthropic requires max_tokens; applied only when the caller omits it. */
    public const int DEFAULT_MAX_TOKENS = 4096;

    /**
     * @return array<string, mixed>
     */
    public static function build(string $modelId, TextModelRequest $request, bool $stream): array
    {
        $maxTokens = $request->maxTokens ?? self::DEFAULT_MAX_TOKENS;
        $cacheControl = self::cacheControl($request);

        $body = [
            'model' => $modelId,
            'messages' => self::messages($request, $cacheControl),
            'max_tokens' => $maxTokens,
            'stream' => $stream,
        ];

        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        $system = self::system($request);
        if ($system !== null) {
            $body['system'] = $cacheControl === null
                ? $system
                : [['type' => 'text', 'text' => $system, 'cache_control' => $cacheControl]];
        }

        if ($request->topP !== null) {
            $body['top_p'] = $request->topP;
        }

        $tools = [];
        if ($request->tools !== [] && $request->toolChoice?->type !== ToolChoice::NONE) {
            $tools = AnthropicToolConverter::convert($request->tools);
        }

        if ($request->output !== null) {
            $structured = self::structuredOutput($request->output);
            if ($structured !== null) {
                $tools[] = $structured;
                $body['tool_choice'] = ['type' => 'tool', 'name' => 'structured_output'];
            }
        }

        if ($tools !== []) {
            if ($cacheControl !== null) {
                $tools[array_key_last($tools)]['cache_control'] = $cacheControl;
            }

            $body['tools'] = $tools;

            if ($request->output === null && $request->toolChoice !== null) {
                $body['tool_choice'] = self::toolChoice($request->toolChoice);
            }
        }

        if ($request->reasoning !== null) {
            $budget = (new AnthropicReasoningBudget())->calculate($request->reasoning, $maxTokens);
            $body['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budget];
        }

        return self::mergeOptions($body, $request->providerOptionsFor('anthropic'));
    }

    /** @return array<string, string> */
    private static function toolChoice(ToolChoice $choice): array
    {
        return match ($choice->type) {
            ToolChoice::AUTO => ['type' => 'auto'],
            ToolChoice::REQUIRED => ['type' => 'any'],
            ToolChoice::TOOL => ['type' => 'tool', 'name' => (string) $choice->toolName],
            ToolChoice::NONE => ['type' => 'auto'],
            default => ['type' => 'auto'],
        };
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function mergeOptions(array $body, array $options): array
    {
        $raw = $options['raw'] ?? null;
        unset($options['raw']);

        $body = array_replace($body, $options);

        return is_array($raw) ? array_replace($body, $raw) : $body;
    }

    /**
     * The cache_control marker for a PromptCache hint, or null when caching was not requested.
     *
     * @return array<string, string>|null
     */
    private static function cacheControl(TextModelRequest $request): ?array
    {
        if ($request->cache === null) {
            return null;
        }

        return $request->cache->ttl === PromptCache::TTL_1_HOUR
            ? ['type' => 'ephemeral', 'ttl' => '1h']
            : ['type' => 'ephemeral'];
    }

    /**
     * @param  array<string, string>|null  $cacheControl
     * @return array<int, array<string, mixed>>
     */
    private static function messages(TextModelRequest $request, ?array $cacheControl = null): array
    {
        $messages = [];

        foreach ($request->messages as $message) {
            if ($message->role === Message::ROLE_SYSTEM) {
                continue;
            }

            if ($message->role === Message::ROLE_TOOL) {
                $messages[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => $message->toolCallId,
                        'content' => $message->text(),
                    ]],
                ];

                continue;
            }

            $content = [];

            foreach ($message->content as $part) {
                $content[] = self::content($part);
            }

            foreach ($message->toolCalls as $toolCall) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'input' => $toolCall->arguments,
                ];
            }

            $messages[] = [
                'role' => $message->role === Message::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        // Cache everything before the latest turn: the stable prefix of the conversation.
        if ($cacheControl !== null && count($messages) >= 2) {
            $prefix = count($messages) - 2;
            $lastBlock = array_key_last($messages[$prefix]['content']);

            if ($lastBlock !== null) {
                $messages[$prefix]['content'][$lastBlock]['cache_control'] = $cacheControl;
            }
        }

        return $messages;
    }

    private static function system(TextModelRequest $request): ?string
    {
        $system = [];

        if ($request->system !== null && trim($request->system) !== '') {
            $system[] = $request->system;
        }

        foreach ($request->messages as $message) {
            if ($message->role !== Message::ROLE_SYSTEM) {
                continue;
            }

            $text = trim($message->text());
            if ($text !== '') {
                $system[] = $text;
            }
        }

        return $system === [] ? null : implode("\n\n", $system);
    }

    /**
     * @return array<string, mixed>
     */
    private static function content(Content $content): array
    {
        return match ($content->type) {
            Content::TYPE_TEXT => ['type' => 'text', 'text' => (string) $content->textValue()],
            Content::TYPE_IMAGE => self::media('image', $content),
            Content::TYPE_FILE => self::media('document', $content),
            default => throw new InvalidArgumentException("Unsupported Anthropic content type [{$content->type}]."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function media(string $type, Content $content): array
    {
        if (($file = $content->fileReference()) !== null) {
            return ['type' => $type, 'source' => ['type' => 'file', 'file_id' => $file->forProvider('anthropic')]];
        }
        if ($content->source() === ContentSource::Url) {
            return [
                'type' => $type,
                'source' => [
                    'type' => 'url',
                    'url' => (string) $content->url(),
                ],
            ];
        }

        return [
            'type' => $type,
            'source' => [
                'type' => 'base64',
                'media_type' => (string) $content->mimeType(),
                'data' => (string) $content->base64Data(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function structuredOutput(Output $output): ?array
    {
        if ($output->kind !== Output::KIND_OBJECT || $output->schema === null) {
            return null;
        }

        return [
            'name' => 'structured_output',
            'description' => 'Return the response as structured JSON.',
            'input_schema' => $output->schema->jsonSchema(),
        ];
    }
}

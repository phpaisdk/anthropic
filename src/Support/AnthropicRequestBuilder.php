<?php

declare(strict_types=1);

namespace AiSdk\Anthropic\Support;

use AiSdk\Content;
use AiSdk\ContentSource;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Message;
use AiSdk\Outputs\Output;
use AiSdk\Requests\TextModelRequest;

final class AnthropicRequestBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(string $modelId, TextModelRequest $request, bool $stream): array
    {
        $body = [
            'model' => $modelId,
            'messages' => self::messages($request),
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'stream' => $stream,
        ];

        $system = self::system($request);
        if ($system !== null) {
            $body['system'] = $system;
        }

        if ($request->topP !== null) {
            $body['top_p'] = $request->topP;
        }

        $tools = [];
        if ($request->tools !== []) {
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
            $body['tools'] = $tools;
        }

        if ($request->reasoning !== null) {
            $budget = (new AnthropicReasoningBudget())->calculate($request->reasoning, $request->maxTokens);
            $body['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budget];
        }

        $raw = $request->providerOptionsFor('anthropic')['raw'] ?? null;
        if (is_array($raw)) {
            $body = array_replace($body, $raw);
        }

        return $body;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function messages(TextModelRequest $request): array
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

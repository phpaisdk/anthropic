<?php

declare(strict_types=1);

use AiSdk\Anthropic\Support\AnthropicRequestBuilder;
use AiSdk\Content;
use AiSdk\Files\FileReference;
use AiSdk\Message;
use AiSdk\Requests\TextModelRequest;

it('maps stored pdfs and images to native file sources', function () {
    $request = new TextModelRequest([Message::user([Content::storedFile(new FileReference('anthropic', 'pdf')), Content::storedFile(new FileReference('anthropic', 'image'), image: true)])]);
    $body = AnthropicRequestBuilder::build('opaque', $request, false);
    expect($body['messages'][0]['content'])->toBe([
        ['type' => 'document', 'source' => ['type' => 'file', 'file_id' => 'pdf']],
        ['type' => 'image', 'source' => ['type' => 'file', 'file_id' => 'image']],
    ]);
    $wrong = new TextModelRequest([Message::user([Content::storedFile(new FileReference('openai', 'pdf'))])]);
    expect(fn() => AnthropicRequestBuilder::build('opaque', $wrong, false))->toThrow(\AiSdk\Exceptions\InvalidArgumentException::class);
});

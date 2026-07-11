# aisdk/anthropic

Official Anthropic provider for the PHP AI SDK.

## Installation

```bash
composer require aisdk/anthropic
```

## Basic Usage

```php
use AiSdk\Anthropic;
use AiSdk\Generate;

$result = Generate::text()
    ->model(Anthropic::model('claude-sonnet-4'))
    ->instructions('Write short, clear answers.')
    ->prompt('Explain closures in PHP.')
    ->run();

echo $result->text;
```

Default model shorthand:

```php
Generate::model(Anthropic::model('claude-sonnet-4'));

$result = Generate::text('Explain closures in PHP.')->run();
```

## Configuration

### Environment Variables

| Variable | Description | Default |
|---|---|---|
| `ANTHROPIC_API_KEY` | API key for authentication | Required |
| `ANTHROPIC_BASE_URL` | Base URL for API requests | `https://api.anthropic.com/v1` |
| `ANTHROPIC_VERSION` | API version header | `2023-06-01` |

### Programmatic Configuration

```php
$provider = Anthropic::create([
    'apiKey' => 'sk-ant-...',
    'baseUrl' => 'https://api.anthropic.com/v1',
    'version' => '2023-06-01',
    'headers' => ['anthropic-beta' => 'extended-thinking-2025-05-14'],
]);
```

## Supported Capabilities

| Capability | Support |
|---|---|
| Text generation | Native |
| Streaming | Native |
| Tool calling | Native |
| Structured output | Adapted (forced tool use) |
| Reasoning | Native (`thinking` blocks) |
| Text input | Native |
| Image input | Native |
| File input | Native (documents) |

## Streaming

```php
use AiSdk\Anthropic;
use AiSdk\Generate;

$stream = Generate::text('Tell me a story.')
    ->model(Anthropic::model('claude-sonnet-4'))
    ->stream();

foreach ($stream->chunks() as $chunk) {
    echo $chunk;
}

$result = $stream->run();
```

## Structured Output

Anthropic does not natively support `json_schema` response format. Structured output is adapted through forced tool use:

```php
use AiSdk\Anthropic;
use AiSdk\Generate;
use AiSdk\Schema;

$result = Generate::text()
    ->model(Anthropic::model('claude-sonnet-4'))
    ->prompt('Extract the city and country from: Lahore, Pakistan.')
    ->output(Schema::object(
        name: 'address',
        properties: [
            Schema::string(name: 'city')->required(),
            Schema::string(name: 'country')->required(),
        ],
    ))
    ->run();
```

## Reasoning

```php
use AiSdk\Anthropic;
use AiSdk\Generate;
use AiSdk\Reasoning;

$result = Generate::text('Solve: what is 2+2?')
    ->model(Anthropic::model('claude-sonnet-4'))
    ->reasoning(Reasoning::effort('high'))
    ->run();
```

## Tools

```php
use AiSdk\Anthropic;
use AiSdk\Generate;
use AiSdk\Schema;
use AiSdk\Tool;

$weather = Tool::make('weather', 'Get current weather')
    ->input(Schema::string(name: 'city')->required())
    ->run(fn (string $city): string => "Sunny in {$city}");

$result = Generate::text()
    ->model(Anthropic::model('claude-sonnet-4'))
    ->prompt('What is the weather in Lahore?')
    ->tool($weather)
    ->run();
```

## Model IDs and Capabilities

Anthropic model IDs pass through unchanged and do not need to be registered. The package does not ship a model inventory; the Anthropic API remains the authority on whether a particular model accepts a requested feature.

Capabilities describe what the Anthropic adapter can serialize. The Anthropic API returns a normalized SDK exception if the selected model or requested feature is rejected.

## Provider-Specific Options

Raw provider options can be passed as an escape hatch:

```php
$result = Generate::text('Hello')
    ->model(Anthropic::model('claude-sonnet-4'))
    ->providerOptions('anthropic', [
        'raw' => ['top_k' => 40],
    ])
    ->run();
```

## Testing

```bash
composer test
```

## Links

- [Core Package](https://github.com/phpaisdk/core)
- [Project Documentation](https://github.com/phpaisdk)

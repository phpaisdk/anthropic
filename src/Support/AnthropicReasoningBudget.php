<?php

declare(strict_types=1);

namespace AiSdk\Anthropic\Support;

use AiSdk\Reasoning;
use AiSdk\ReasoningBudget;

/**
 * Anthropic-specific reasoning budget calculator. Anthropic requires a
 * minimum of 1024 thinking tokens and the budget must be less than the
 * model's max_tokens. Providers can override these constants further.
 */
class AnthropicReasoningBudget extends ReasoningBudget
{
    protected const int MIN_BUDGET = 1024;

    protected const float DEFAULT_PERCENTAGE = 0.30;
}

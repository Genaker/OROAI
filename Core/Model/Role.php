<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

/** Enum of chat message roles used in LLM conversations. */
enum Role: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}

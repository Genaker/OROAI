<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

/** Describes an AI tool — its name, description, and JSON-Schema parameter definition. */
final readonly class ToolDefinition
{
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {
    }
}

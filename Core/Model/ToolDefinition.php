<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

final readonly class ToolDefinition
{
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}
}

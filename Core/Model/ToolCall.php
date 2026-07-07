<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Model;

final readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public string $argsJson,
    ) {}
}

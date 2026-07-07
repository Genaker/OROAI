<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Contract;

use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;

interface AiToolInterface
{
    public function getName(): string;

    public function getDefinition(): ToolDefinition;

    public function execute(array $arguments): ToolResult;
}

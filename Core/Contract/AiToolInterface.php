<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Contract;

use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;

/** Contract that every AI tool must implement to participate in the tool-use loop. */
interface AiToolInterface
{
    public function getName(): string;

    public function getDefinition(): ToolDefinition;

    public function execute(array $arguments): ToolResult;
}

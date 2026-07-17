<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

/** Contract for a sub-agent that resolves a delegated research task and returns one synthesized answer. */
interface ResearchAgentInterface
{
    public function investigate(string $task): string;
}

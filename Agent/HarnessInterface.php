<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

use Genaker\Bundle\OroAI\Core\Model\ChatMessage;

/** Contract for a harness that resolves customer questions in a controlled retry loop. */
interface HarnessInterface
{
    /**
     * @param ChatMessage[] $history
     */
    public function resolve(string $userMessage, array $history = []): HarnessResult;
}

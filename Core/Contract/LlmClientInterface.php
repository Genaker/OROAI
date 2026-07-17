<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Contract;

use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\LlmResponse;

/** Contract for LLM provider clients that send chat requests and return structured responses. */
interface LlmClientInterface
{
    public function chat(LlmRequest $request): LlmResponse;

    public function getName(): string;
}

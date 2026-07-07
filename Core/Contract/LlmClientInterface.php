<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Core\Contract;

use Genaker\Bundle\OroAI\Core\Model\LlmRequest;
use Genaker\Bundle\OroAI\Core\Model\LlmResponse;

interface LlmClientInterface
{
    public function chat(LlmRequest $request): LlmResponse;

    public function getName(): string;
}

<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

/** Contract for a provider of general (non-tool-specific) system-prompt guidelines. */
interface GuidelineProviderInterface
{
    /** @return string[] */
    public function getGuidelines(): array;
}

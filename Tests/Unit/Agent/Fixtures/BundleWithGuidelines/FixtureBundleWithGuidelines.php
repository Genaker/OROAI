<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleWithGuidelines;

/**
 * A stand-in "bundle class" for GuidelineProviderTest — proves a bundle can
 * contribute agent guidelines just by shipping its own
 * Resources/config/oro/oro_ai_guidelines.yml, with no code changes to
 * OroAiAgent or GuidelineProvider.
 */
final class FixtureBundleWithGuidelines
{
}

<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleWithoutGuidelines;

/**
 * A stand-in "bundle class" for GuidelineProviderTest with no
 * Resources/config/oro/oro_ai_guidelines.yml at all — proves bundles
 * without that file are silently skipped, not treated as an error.
 */
final class FixtureBundleWithoutGuidelines
{
}

<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleOverridingSkills;

/**
 * Fixture "bundle" registered AFTER FixtureBundleWithSkills — overrides the
 * markdown skill by key and removes another skill with a null value,
 * exactly like regular Oro cumulative config.
 */
final class FixtureBundleOverridingSkills
{
}

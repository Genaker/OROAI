<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleOverridingGuidelines;

/**
 * Fixture "bundle" registered AFTER FixtureBundleWithKeyedGuidelines — proves
 * a later bundle can override a guideline by re-declaring its key ("tone")
 * and remove one by declaring the key with a null value ("links: ~"),
 * exactly like regular Oro cumulative config.
 */
final class FixtureBundleOverridingGuidelines
{
}

<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent;

use Genaker\Bundle\OroAI\Agent\GuidelineProvider;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleOverridingGuidelines\FixtureBundleOverridingGuidelines;
use Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleWithKeyedGuidelines\FixtureBundleWithKeyedGuidelines;
use Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleWithGuidelines\FixtureBundleWithGuidelines;
use Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleWithMoreGuidelines\FixtureBundleWithMoreGuidelines;
use Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleWithoutGuidelines\FixtureBundleWithoutGuidelines;
use Oro\Component\Config\CumulativeResourceManager;
use PHPUnit\Framework\TestCase;

/**
 * GuidelineProvider enumerates every bundle registered in
 * CumulativeResourceManager (the same singleton DocFilesRagProvider and
 * Oro's own cross-bundle Resources/config/oro/*.yml loading rely on), so
 * these tests point that singleton at fixture "bundle" classes under
 * Fixtures/ instead of the real bundle list, then restore the original
 * bundles afterwards so later tests in the same process see real state.
 */
final class GuidelineProviderTest extends TestCase
{
    private array $originalBundles;

    protected function setUp(): void
    {
        $this->originalBundles = CumulativeResourceManager::getInstance()->getBundles();
    }

    protected function tearDown(): void
    {
        CumulativeResourceManager::getInstance()->setBundles($this->originalBundles);
    }

    private function useFixtureBundles(): void
    {
        CumulativeResourceManager::getInstance()->setBundles([
            'FixtureBundleWithGuidelines' => FixtureBundleWithGuidelines::class,
            'FixtureBundleWithMoreGuidelines' => FixtureBundleWithMoreGuidelines::class,
            'FixtureBundleWithoutGuidelines' => FixtureBundleWithoutGuidelines::class,
        ]);
    }

    public function testGetGuidelinesMergesAcrossBundles(): void
    {
        $this->useFixtureBundles();

        $guidelines = (new GuidelineProvider())->getGuidelines();

        self::assertContains('First fixture guideline.', $guidelines);
        self::assertContains('Second fixture guideline.', $guidelines);
        self::assertContains('Third fixture guideline from a different bundle.', $guidelines);
    }

    public function testGetGuidelinesSkipsBlankEntries(): void
    {
        $this->useFixtureBundles();

        $guidelines = (new GuidelineProvider())->getGuidelines();

        foreach ($guidelines as $guideline) {
            self::assertNotSame('', trim($guideline), 'Blank guideline entries must be filtered out.');
        }
    }

    public function testGetGuidelinesSkipsBundlesWithoutTheFile(): void
    {
        CumulativeResourceManager::getInstance()->setBundles([
            'FixtureBundleWithoutGuidelines' => FixtureBundleWithoutGuidelines::class,
        ]);

        self::assertSame([], (new GuidelineProvider())->getGuidelines());
    }

    public function testGetGuidelinesReturnsEmptyArrayWhenNoBundlesRegistered(): void
    {
        CumulativeResourceManager::getInstance()->setBundles([]);

        self::assertSame([], (new GuidelineProvider())->getGuidelines());
    }

    // ─────────────────────────────────────────────────────────────
    // Keyed guidelines: override / remove, Oro-cumulative-config style
    // ─────────────────────────────────────────────────────────────

    private function useKeyedFixtureBundles(): void
    {
        // Registration order matters: the overriding bundle comes second.
        CumulativeResourceManager::getInstance()->setBundles([
            'FixtureBundleWithKeyedGuidelines' => FixtureBundleWithKeyedGuidelines::class,
            'FixtureBundleOverridingGuidelines' => FixtureBundleOverridingGuidelines::class,
        ]);
    }

    public function testLaterBundleOverridesGuidelineByKey(): void
    {
        $this->useKeyedFixtureBundles();

        $guidelines = (new GuidelineProvider())->getGuidelines();

        self::assertContains('Be casual.', $guidelines);
        self::assertNotContains('Be formal.', $guidelines, 'Overridden value must not survive.');
    }

    public function testLaterBundleRemovesGuidelineWithNullValue(): void
    {
        $this->useKeyedFixtureBundles();

        $guidelines = (new GuidelineProvider())->getGuidelines();

        self::assertNotContains('Always add admin links.', $guidelines, '"links: ~" must remove the guideline.');
    }

    public function testLegacyListEntriesGetStableBundleAutoKeys(): void
    {
        $this->useFixtureBundles();

        $keyed = (new GuidelineProvider())->getKeyedGuidelines();

        self::assertSame('First fixture guideline.', $keyed['fixturebundlewithguidelines_0']);
        self::assertSame('Second fixture guideline.', $keyed['fixturebundlewithguidelines_1']);
        self::assertSame(
            'Third fixture guideline from a different bundle.',
            $keyed['fixturebundlewithmoreguidelines_0'],
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Admin "Additional Guidelines" (merged last, same key semantics)
    // ─────────────────────────────────────────────────────────────

    private function providerWithAdminText(string $text): GuidelineProvider
    {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('getAdditionalGuidelinesText')->willReturn($text);

        return new GuidelineProvider($config);
    }

    public function testAdminMappingOverridesAndRemovesBundleGuidelines(): void
    {
        $this->useKeyedFixtureBundles();

        $guidelines = $this->providerWithAdminText(
            "tone: 'Admin tone wins.'\nextra_rule: 'Added by the admin.'"
        )->getGuidelines();

        self::assertContains('Admin tone wins.', $guidelines);
        self::assertNotContains('Be casual.', $guidelines, 'Admin override beats every bundle.');
        self::assertContains('Added by the admin.', $guidelines);
    }

    public function testAdminNullValueRemovesBundleGuideline(): void
    {
        $this->useKeyedFixtureBundles();

        $guidelines = $this->providerWithAdminText('tone: ~')->getGuidelines();

        self::assertNotContains('Be casual.', $guidelines);
        self::assertNotContains('Be formal.', $guidelines);
    }

    public function testAdminPlainTextLinesAreAppended(): void
    {
        CumulativeResourceManager::getInstance()->setBundles([]);

        $guidelines = $this->providerWithAdminText(
            "Never expose raw SQL to end users\n\nPrefer metric units"
        )->getGuidelines();

        self::assertSame(['Never expose raw SQL to end users', 'Prefer metric units'], $guidelines);
    }

    public function testEmptyAdminTextChangesNothing(): void
    {
        $this->useKeyedFixtureBundles();

        $withAdmin = $this->providerWithAdminText('')->getGuidelines();
        $withoutConfig = (new GuidelineProvider())->getGuidelines();

        self::assertSame($withoutConfig, $withAdmin);
    }
}

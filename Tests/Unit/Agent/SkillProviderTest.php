<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Agent;

use Genaker\Bundle\OroAI\Agent\SkillProvider;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleOverridingSkills\FixtureBundleOverridingSkills;
use Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleWithSkills\FixtureBundleWithSkills;
use Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleWithoutGuidelines\FixtureBundleWithoutGuidelines;
use Oro\Component\Config\CumulativeResourceManager;
use PHPUnit\Framework\TestCase;

/**
 * SkillProvider enumerates bundles via the CumulativeResourceManager
 * singleton (same pattern as GuidelineProvider/DocFilesRagProvider), so these
 * tests point it at fixture "bundles" and restore the real list afterwards.
 */
final class SkillProviderTest extends TestCase
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

    private function useSkillFixtureBundles(bool $withOverridingBundle = false): void
    {
        $bundles = ['FixtureBundleWithSkills' => FixtureBundleWithSkills::class];
        if ($withOverridingBundle) {
            // Registration order matters: the overriding bundle comes second.
            $bundles['FixtureBundleOverridingSkills'] = FixtureBundleOverridingSkills::class;
        }
        CumulativeResourceManager::getInstance()->setBundles($bundles);
    }

    public function testMarkdownSkillIsLoadedWithFilenameKey(): void
    {
        $this->useSkillFixtureBundles();

        $skill = (new SkillProvider())->getSkill('md_skill');

        self::assertNotNull($skill);
        self::assertSame('Use for the markdown fixture case.', $skill['description']);
        self::assertStringContainsString('Step one from markdown.', $skill['body']);
        // Frontmatter must be stripped from the body.
        self::assertStringNotContainsString('---', $skill['body']);
        self::assertStringNotContainsString('description:', $skill['body']);
    }

    public function testFrontmatterNameOverridesFilenameKey(): void
    {
        $this->useSkillFixtureBundles();

        $skills = (new SkillProvider())->getSkills();

        self::assertArrayHasKey('renamed_by_frontmatter', $skills);
        self::assertArrayNotHasKey('named_skill', $skills);
    }

    public function testMarkdownFileWithoutFrontmatterIsSkipped(): void
    {
        $this->useSkillFixtureBundles();

        self::assertArrayNotHasKey('broken_no_frontmatter', (new SkillProvider())->getSkills());
    }

    public function testYamlSkillIsLoaded(): void
    {
        $this->useSkillFixtureBundles();

        $skill = (new SkillProvider())->getSkill('yml_skill');

        self::assertNotNull($skill);
        self::assertSame('Step one from YAML.', $skill['body']);
    }

    public function testYamlSkillWithoutBodyIsSkipped(): void
    {
        $this->useSkillFixtureBundles();

        self::assertNull((new SkillProvider())->getSkill('invalid_skill'));
    }

    public function testLaterBundleOverridesSkillByKey(): void
    {
        $this->useSkillFixtureBundles(withOverridingBundle: true);

        $skill = (new SkillProvider())->getSkill('md_skill');

        self::assertSame('Overridden trigger.', $skill['description']);
        self::assertSame('Overridden body wins.', $skill['body']);
    }

    public function testLaterBundleRemovesSkillWithNullValue(): void
    {
        $this->useSkillFixtureBundles(withOverridingBundle: true);

        self::assertNull((new SkillProvider())->getSkill('doomed_skill'));
    }

    public function testSkillSurvivesWithoutOverridingBundle(): void
    {
        $this->useSkillFixtureBundles();

        self::assertNotNull((new SkillProvider())->getSkill('doomed_skill'));
    }

    public function testBundleWithoutSkillFilesContributesNothing(): void
    {
        CumulativeResourceManager::getInstance()->setBundles([
            'FixtureBundleWithoutGuidelines' => FixtureBundleWithoutGuidelines::class,
        ]);

        self::assertSame([], (new SkillProvider())->getSkills());
    }

    public function testUnknownSkillReturnsNull(): void
    {
        $this->useSkillFixtureBundles();

        self::assertNull((new SkillProvider())->getSkill('does_not_exist'));
    }

    // ─────────────────────────────────────────────────────────────
    // Admin disable list (genaker_oro_ai.disabled_skills)
    // ─────────────────────────────────────────────────────────────

    private function providerWithDisabled(array $disabledKeys): SkillProvider
    {
        $config = $this->createMock(OroAiConfig::class);
        $config->method('getDisabledSkills')->willReturn($disabledKeys);

        return new SkillProvider($config);
    }

    public function testDisabledSkillIsHiddenFromAgent(): void
    {
        $this->useSkillFixtureBundles();

        $provider = $this->providerWithDisabled(['yml_skill']);

        self::assertNull($provider->getSkill('yml_skill'));
        self::assertArrayNotHasKey('yml_skill', $provider->getSkills());
        // Other skills stay enabled.
        self::assertNotNull($provider->getSkill('md_skill'));
    }

    public function testDisabledSkillStillListedByGetAllSkillsForTheAdminForm(): void
    {
        $this->useSkillFixtureBundles();

        $provider = $this->providerWithDisabled(['yml_skill']);

        self::assertArrayHasKey('yml_skill', $provider->getAllSkills());
    }

    public function testEmptyDisableListChangesNothing(): void
    {
        $this->useSkillFixtureBundles();

        self::assertSame(
            (new SkillProvider())->getSkills(),
            $this->providerWithDisabled([])->getSkills(),
        );
    }
}

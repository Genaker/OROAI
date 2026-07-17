<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Tools;

use Genaker\Bundle\OroAI\Agent\SkillProvider;
use Genaker\Bundle\OroAI\Tests\Unit\Agent\Fixtures\BundleWithSkills\FixtureBundleWithSkills;
use Genaker\Bundle\OroAI\Tools\SkillTool;
use Oro\Component\Config\CumulativeResourceManager;
use PHPUnit\Framework\TestCase;

/**
 * Uses the REAL SkillProvider (final, pure file reading — mocking it would
 * only test the mock) pointed at the fixture bundle via
 * CumulativeResourceManager, same pattern as SkillProviderTest.
 */
final class SkillToolTest extends TestCase
{
    private array $originalBundles;
    private SkillTool $tool;

    protected function setUp(): void
    {
        $this->originalBundles = CumulativeResourceManager::getInstance()->getBundles();
        CumulativeResourceManager::getInstance()->setBundles([
            'FixtureBundleWithSkills' => FixtureBundleWithSkills::class,
        ]);
        $this->tool = new SkillTool(new SkillProvider());
    }

    protected function tearDown(): void
    {
        CumulativeResourceManager::getInstance()->setBundles($this->originalBundles);
    }

    public function testGetNameReturnsSkill(): void
    {
        self::assertSame('skill', $this->tool->getName());
    }

    public function testDefinitionListsEverySkillWithItsTrigger(): void
    {
        $definition = $this->tool->getDefinition();

        self::assertStringContainsString(
            '- md_skill: Use for the markdown fixture case.',
            $definition->description,
        );
        self::assertStringContainsString(
            '- yml_skill: Use for the YAML fixture case.',
            $definition->description,
        );
        // Full bodies must NOT be in the prompt-visible catalog — that is the
        // whole point of skills vs guidelines.
        self::assertStringNotContainsString('Step one from markdown.', $definition->description);
        self::assertStringNotContainsString('Step one from YAML.', $definition->description);
    }

    public function testDefinitionWithNoSkillsSaysNoneRegistered(): void
    {
        CumulativeResourceManager::getInstance()->setBundles([]);

        self::assertStringContainsString(
            'No skills are currently registered',
            $this->tool->getDefinition()->description,
        );
    }

    public function testExecuteReturnsSkillBody(): void
    {
        $result = $this->tool->execute(['name' => 'yml_skill']);

        self::assertTrue($result->success);
        self::assertSame('yml_skill', $result->data['skill']);
        self::assertSame('Step one from YAML.', $result->data['instructions']);
    }

    public function testExecuteUnknownSkillListsAvailableOnes(): void
    {
        $result = $this->tool->execute(['name' => 'nope']);

        self::assertFalse($result->success);
        self::assertStringContainsString('Unknown skill "nope"', $result->errorMessage);
        self::assertStringContainsString('yml_skill', $result->errorMessage);
    }

    public function testExecuteWithoutNameReturnsError(): void
    {
        $result = $this->tool->execute([]);

        self::assertFalse($result->success);
        self::assertStringContainsString('"name" is required', $result->errorMessage);
    }
}

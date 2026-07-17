<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Oro\Component\Config\CumulativeResourceManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Collects declarative agent SKILLS — pure "how to do X" procedures a bundle
 * can ship without writing any PHP — from two per-bundle sources:
 *
 *  1. Markdown files (Claude-skill style): Resources/ai_skills/<skill_key>.md
 *     with a YAML frontmatter block holding the trigger description:
 *
 *         ---
 *         description: 'Use when the user asks how to create a shipment from the cart.'
 *         ---
 *         # Creating a shipment from the cart
 *         1. Check the cart has a route selected...
 *
 *     The skill key is the filename (without .md), overridable with a
 *     frontmatter `name:` entry.
 *
 *  2. YAML entries in Resources/config/oro/oro_ai_skills.yml (inline form,
 *     and the place to override/remove skills from other bundles):
 *
 *         oro_ai:
 *             skills:
 *                 create_shipment_from_cart:
 *                     description: 'Use when ...'
 *                     body: |
 *                         1. ...
 *                 some_other_bundles_skill: ~   # removes it
 *
 * A skill differs from a guideline by WHEN its text enters the model context:
 * guidelines are always in the system prompt (short, universal rules), while
 * a skill contributes only its one-line trigger description to the prompt
 * (via SkillTool's catalog) and its full body is loaded ON DEMAND when the
 * model invokes the `skill` tool — cheap to list, complete when needed,
 * never chunked like RAG search results.
 *
 * Merge semantics mirror GuidelineProvider / regular Oro cumulative config:
 * bundles are visited in kernel registration order; within a bundle the
 * Markdown skills load first, then the YAML entries (so YAML can override or
 * remove Markdown skills); a later bundle overrides an earlier bundle's
 * skill by re-declaring its key, and removes it with `the_key: ~`.
 */
final class SkillProvider
{
    private const string CONFIG_FILE = 'Resources/config/oro/oro_ai_skills.yml';
    private const string SKILLS_DIR = 'Resources/ai_skills';
    private const string ROOT_NODE = 'oro_ai';

    public function __construct(
        private readonly ?OroAiConfig $config = null,
    ) {
    }

    /**
     * The merged skill map after bundle overrides, MINUS the skills disabled
     * in the admin "Skills" list — what the agent actually sees.
     *
     * @return array<string, array{description: string, body: string}>
     */
    public function getSkills(): array
    {
        $skills = $this->getAllSkills();

        foreach ($this->config?->getDisabledSkills() ?? [] as $disabledKey) {
            unset($skills[$disabledKey]);
        }

        return $skills;
    }

    /**
     * Every merged skill INCLUDING disabled ones — for the admin form, which
     * must keep listing a disabled skill or it could never be re-enabled.
     *
     * @return array<string, array{description: string, body: string}>
     */
    public function getAllSkills(): array
    {
        $merged = [];

        foreach ($this->getBundleDirs() as $bundleDir) {
            $this->mergeMarkdownSkills($merged, $bundleDir);
            $this->mergeYamlSkills($merged, $bundleDir);
        }

        return $merged;
    }

    /** @return array{description: string, body: string}|null */
    public function getSkill(string $name): ?array
    {
        return $this->getSkills()[$name] ?? null;
    }

    /**
     * Markdown skill files: one skill per Resources/ai_skills/*.md, key =
     * filename (or frontmatter `name:`), trigger = frontmatter `description:`.
     *
     * @param array<string, array{description: string, body: string}> $merged
     */
    private function mergeMarkdownSkills(array &$merged, string $bundleDir): void
    {
        $dir = $bundleDir . '/' . self::SKILLS_DIR;
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*.md') ?: [];
        sort($files); // deterministic order within the bundle

        foreach ($files as $file) {
            $parsed = $this->parseMarkdownSkill($file);
            if ($parsed !== null) {
                [$key, $skill] = $parsed;
                $merged[$key] = $skill;
            }
        }
    }

    /**
     * @param array<string, array{description: string, body: string}> $merged
     */
    private function mergeYamlSkills(array &$merged, string $bundleDir): void
    {
        $file = $bundleDir . '/' . self::CONFIG_FILE;
        if (!is_file($file)) {
            return;
        }

        $parsed = Yaml::parseFile($file);
        $items = $parsed[self::ROOT_NODE]['skills'] ?? [];
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if ($value === null) {
                unset($merged[$key]);
                continue;
            }

            $skill = $this->normalizeSkill($value);
            if ($skill !== null) {
                $merged[$key] = $skill;
            }
        }
    }

    /**
     * Split a skill .md file into YAML frontmatter (--- ... ---) and body.
     * Files without a frontmatter `description:` are skipped — a skill
     * without a trigger can never be selected by the model.
     *
     * @return array{0: string, 1: array{description: string, body: string}}|null [key, skill]
     */
    private function parseMarkdownSkill(string $file): ?array
    {
        $content = (string) file_get_contents($file);

        if (!preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $content, $matches)) {
            return null;
        }

        try {
            $frontmatter = Yaml::parse($matches[1]);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($frontmatter)) {
            return null;
        }

        $description = trim((string) ($frontmatter['description'] ?? ''));
        $body = trim($matches[2]);
        if ($description === '' || $body === '') {
            return null;
        }

        $key = trim((string) ($frontmatter['name'] ?? '')) ?: basename($file, '.md');

        return [$key, ['description' => $description, 'body' => $body]];
    }

    /**
     * Validate one YAML skill entry: both description (the "when to use me"
     * trigger) and body (the full procedure) must be non-blank strings.
     *
     * @return array{description: string, body: string}|null
     */
    private function normalizeSkill(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $description = trim((string) ($value['description'] ?? ''));
        $body = trim((string) ($value['body'] ?? ''));

        if ($description === '' || $body === '') {
            return null;
        }

        return ['description' => $description, 'body' => $body];
    }

    /**
     * Bundle directories in KERNEL REGISTRATION ORDER, so "later bundle
     * overrides earlier bundle" behaves like regular Oro cumulative config.
     *
     * @return string[]
     */
    private function getBundleDirs(): array
    {
        $manager = CumulativeResourceManager::getInstance();
        $dirs = [];

        foreach ($manager->getBundles() as $bundleClass) {
            $dirs[] = rtrim($manager->getBundleDir($bundleClass), '/');
        }

        return $dirs;
    }
}

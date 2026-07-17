<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Agent;

use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Oro\Component\Config\CumulativeResourceManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Collects the AI agent's general (non-tool-specific) system-prompt
 * guidelines from every registered bundle's
 * Resources/config/oro/oro_ai_guidelines.yml, so any bundle can extend the
 * agent's behavior without editing OroAiAgent directly — just by dropping a
 * YAML file with the same shape.
 *
 * Merge semantics mirror regular Oro cumulative config: bundles are visited
 * in KERNEL REGISTRATION ORDER and every guideline has a KEY, so a later
 * bundle can override an earlier bundle's guideline by re-declaring its key,
 * or remove it by declaring the key with a null/blank value:
 *
 *   oro_ai:
 *       guidelines:
 *           tone: 'Keep answers concise.'     # add (or override an earlier bundle's "tone")
 *           some_other_key: ~                 # remove an earlier bundle's guideline
 *           - 'Legacy unkeyed entry.'         # auto-keyed "<bundle>_<index>" (still overridable)
 *
 * Admin-entered guidelines (System Configuration → Oro AI Assistant →
 * "Additional Guidelines", or the OROAI_ADDITIONAL_GUIDELINES env var) are
 * merged LAST with the same semantics, so an administrator can add, replace,
 * or remove any bundle guideline without a deployment.
 *
 * Tool-specific guidance ("when to use tool X") belongs on the tool's own
 * ToolDefinition::description instead — this provider is only for rules
 * that apply across the whole agent, not to one tool.
 */
final class GuidelineProvider implements GuidelineProviderInterface
{
    private const string CONFIG_FILE = 'Resources/config/oro/oro_ai_guidelines.yml';
    private const string ROOT_NODE = 'oro_ai';

    public function __construct(
        private readonly ?OroAiConfig $config = null,
    ) {
    }

    /** @return string[] merged guideline texts, in final merge order */
    public function getGuidelines(): array
    {
        return array_values($this->getKeyedGuidelines());
    }

    /**
     * The merged key => guideline map after all bundle and admin overrides —
     * useful to discover a guideline's key before overriding/removing it.
     *
     * @return array<string, string>
     */
    public function getKeyedGuidelines(): array
    {
        $merged = [];

        foreach ($this->findConfigFiles() as $bundleName => $file) {
            $parsed = Yaml::parseFile($file);
            $items = $parsed[self::ROOT_NODE]['guidelines'] ?? [];
            if (is_array($items)) {
                $this->mergeItems($merged, $items, strtolower($bundleName));
            }
        }

        $this->mergeItems($merged, $this->parseAdminGuidelines(), 'admin');

        return $merged;
    }

    /**
     * Apply one source's items onto the merged map, Oro-config style:
     *  - string key + non-blank value  => add/override that key
     *  - string key + null/blank value => REMOVE that key
     *  - integer key (legacy list)     => auto key "<prefix>_<index>"; blank
     *    entries are skipped (a list entry has no key to remove).
     *
     * @param array<string, string> $merged
     */
    private function mergeItems(array &$merged, array $items, string $autoKeyPrefix): void
    {
        foreach ($items as $key => $value) {
            $text = is_string($value) ? trim($value) : '';

            if (is_int($key)) {
                if ($text !== '') {
                    $merged[$autoKeyPrefix . '_' . $key] = $text;
                }
                continue;
            }

            if ($text === '') {
                unset($merged[$key]);
                continue;
            }

            $merged[$key] = $text;
        }
    }

    /**
     * Parse the admin "Additional Guidelines" text. A YAML mapping gets full
     * key semantics (override/remove); a YAML list or plain text lines become
     * additive entries auto-keyed admin_0, admin_1, …
     */
    private function parseAdminGuidelines(): array
    {
        $text = trim((string) $this->config?->getAdditionalGuidelinesText());
        if ($text === '') {
            return [];
        }

        try {
            $parsed = Yaml::parse($text);
        } catch (\Throwable) {
            $parsed = null;
        }

        if (is_array($parsed)) {
            return $parsed;
        }

        // Plain text: each non-empty line is one additive guideline.
        return array_values(array_filter(array_map('trim', explode("\n", $text))));
    }

    /**
     * Config files keyed by bundle name, in KERNEL REGISTRATION ORDER — the
     * order that makes "later bundle overrides earlier bundle" behave like
     * regular Oro cumulative config (not alphabetical file-path order).
     *
     * @return array<string, string> bundle name => file path
     */
    private function findConfigFiles(): array
    {
        $manager = CumulativeResourceManager::getInstance();
        $files = [];

        foreach ($manager->getBundles() as $bundleName => $bundleClass) {
            $file = rtrim($manager->getBundleDir($bundleClass), '/') . '/' . self::CONFIG_FILE;
            if (is_file($file)) {
                $files[(string) $bundleName] = $file;
            }
        }

        return $files;
    }
}

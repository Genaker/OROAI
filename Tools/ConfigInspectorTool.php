<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** AI tool that reads OroCommerce system configuration values by key or keyword search. */
final class ConfigInspectorTool implements AiToolInterface
{
    /**
     * Where each config section is edited in the admin UI:
     * prefix => [activeGroup, activeSubGroup] of the oro_config_configuration_system
     * route (the tree placement from each bundle's system_configuration.yml).
     * The URL is returned as settings_url so the assistant can answer "where do I
     * change this?" with a clickable admin link.
     */
    private const array SETTINGS_PAGES = [
        'genaker_oro_ai'       => ['general_setup', 'genaker_oroai_group'],
        'genaker_log_viewer'   => ['general_setup', 'genaker_log_viewer_group'],
        'genaker_comi_voyager' => ['general_setup', 'comivoyager_settings'],
    ];

    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getName(): string
    {
        return 'config_inspector';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'config_inspector',
            'Read OroCommerce system configuration values. Can get a specific config key or search config by keyword. Use this to answer questions about how the system is configured (shipping, tax, email, integrations, etc.).',
            [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['get', 'search'],
                        'description' => 'Action: "get" retrieves a specific config key value, "search" finds config keys matching a keyword.',
                    ],
                    'key' => [
                        'type' => 'string',
                        'description' => 'For "get": the full config key (e.g. "oro_email.smtp_host", "oro_locale.language"). For "search": a keyword to search in config key names.',
                    ],
                ],
                'required' => ['action', 'key'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $action = $arguments['action'] ?? '';
        $key = trim($arguments['key'] ?? '');

        if ($key === '') {
            return ToolResult::error('Parameter "key" is required.');
        }

        return match ($action) {
            'get' => $this->getValue($key),
            'search' => $this->searchKeys($key),
            default => ToolResult::error('Unknown action. Use "get" or "search".'),
        };
    }

    private function getValue(string $key): ToolResult
    {
        try {
            $value = $this->configManager->get($key);

            $data = [
                'key' => $key,
                'value' => $this->sanitizeValue($value),
                'type' => get_debug_type($value),
            ];

            $settingsUrl = $this->settingsUrl($key);
            if ($settingsUrl !== null) {
                $data['settings_url'] = $settingsUrl;
                $data['settings_url_note'] = 'Admin page where this setting is edited — include this URL in your answer.';
            }

            return ToolResult::success($data);
        } catch (\Throwable $e) {
            return ToolResult::error('Config key not found or error: ' . $e->getMessage());
        }
    }

    private function searchKeys(string $keyword): ToolResult
    {
        $keyword = strtolower($keyword);
        $prefixes = [
            'oro_email', 'oro_locale', 'oro_shipping', 'oro_tax', 'oro_pricing',
            'oro_customer', 'oro_order', 'oro_product', 'oro_catalog',
            'oro_website', 'oro_cms', 'oro_rfp', 'oro_sale', 'oro_payment',
            'oro_inventory', 'oro_warehouse', 'oro_promotion', 'oro_checkout',
            'oro_shopping_list', 'oro_user', 'oro_organization', 'oro_ui',
            'oro_navigation', 'oro_attachment', 'oro_notification',
            'genaker_oro_ai', 'genaker_log_viewer', 'genaker_comi_voyager',
        ];

        $matchingPrefixes = array_filter(
            $prefixes,
            static fn(string $p) => str_contains($p, $keyword),
        );

        if ($matchingPrefixes === []) {
            return ToolResult::success([
                'message' => "No known config prefixes match \"{$keyword}\".",
                'suggestion' => 'Use "get" action with a specific key, or try a broader keyword. Known prefixes: ' . implode(', ', $prefixes),
                'available_prefixes' => $prefixes,
            ]);
        }

        $matches = [];
        foreach ($matchingPrefixes as $prefix) {
            $commonKeys = $this->getCommonKeysForPrefix($prefix);
            foreach ($commonKeys as $key) {
                try {
                    $value = $this->configManager->get($key);
                    $matches[$key] = $this->sanitizeValue($value);
                } catch (\Throwable) {
                    // intentional
                }
            }
        }

        $settingsUrls = [];
        foreach ($matchingPrefixes as $prefix) {
            $settingsUrl = $this->settingsUrl($prefix);
            if ($settingsUrl !== null) {
                $settingsUrls[$prefix] = $settingsUrl;
            }
        }

        // settings_urls FIRST: the tool trace stores a 500-char summary of this
        // payload, and the link extractor for the chat widget only sees that
        // summary — a URL after a large found_values map would be truncated away.
        $data = [];
        if ($settingsUrls !== []) {
            $data['settings_urls'] = $settingsUrls;
            $data['settings_url_note'] = 'Admin pages where these settings are edited — include the URL in your answer.';
        }
        $data += [
            'matching_prefixes' => array_values($matchingPrefixes),
            'found_values' => $matches,
            'count' => count($matches),
            'note' => 'Use "get" action with a specific key for exact lookup.',
        ];

        return ToolResult::success($data);
    }

    /**
     * Admin system-config page URL for a config key or prefix, or null when the
     * section has no known page mapping.
     */
    private function settingsUrl(string $keyOrPrefix): ?string
    {
        $prefix = str_contains($keyOrPrefix, '.')
            ? substr($keyOrPrefix, 0, (int) strpos($keyOrPrefix, '.'))
            : $keyOrPrefix;

        if (!isset(self::SETTINGS_PAGES[$prefix])) {
            return null;
        }

        [$group, $subGroup] = self::SETTINGS_PAGES[$prefix];

        return $this->urlGenerator->generate('oro_config_configuration_system', [
            'activeGroup' => $group,
            'activeSubGroup' => $subGroup,
        ]);
    }

    /** @return string[] */
    private function getCommonKeysForPrefix(string $prefix): array
    {
        $map = [
            'oro_email' => ['oro_email.smtp_settings_host', 'oro_email.smtp_settings_port', 'oro_email.email_notification_sender_email', 'oro_email.email_notification_sender_name'],
            'oro_locale' => ['oro_locale.language', 'oro_locale.formatting_code', 'oro_locale.timezone', 'oro_locale.country'],
            'oro_ui' => ['oro_ui.application_url', 'oro_ui.navbar_position'],
            'genaker_oro_ai' => ['genaker_oro_ai.provider', 'genaker_oro_ai.model', 'genaker_oro_ai.temperature', 'genaker_oro_ai.max_iterations', 'genaker_oro_ai.sql_tool_enabled', 'genaker_oro_ai.rag_enabled', 'genaker_oro_ai.learning_enabled'],
        ];

        return $map[$prefix] ?? ["{$prefix}.enabled"];
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value) && (
            str_contains(strtolower($value), 'password')
            || str_contains(strtolower($value), 'secret')
            || str_contains(strtolower($value), 'sk-')
        )) {
            return '***REDACTED***';
        }

        if (is_object($value)) {
            return get_class($value) . ' (object)';
        }

        if (is_array($value) && count($value) > 20) {
            return array_slice($value, 0, 20) + ['...' => '(truncated)'];
        }

        return $value;
    }
}

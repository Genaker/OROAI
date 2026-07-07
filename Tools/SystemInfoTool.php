<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;

final class SystemInfoTool implements AiToolInterface
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
    ) {
    }

    public function getName(): string
    {
        return 'system_info';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'system_info',
            'Get system environment information — PHP version, extensions, memory, Symfony environment, Oro version. Use this to answer questions about the server setup or troubleshoot compatibility issues.',
            [
                'type' => 'object',
                'properties' => [
                    'section' => [
                        'type' => 'string',
                        'enum' => ['overview', 'php', 'extensions', 'memory', 'bundles'],
                        'description' => '"overview" = summary, "php" = PHP details, "extensions" = loaded extensions, "memory" = memory/disk usage, "bundles" = installed Oro bundles count.',
                    ],
                ],
                'required' => ['section'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $section = $arguments['section'] ?? 'overview';

        return match ($section) {
            'overview' => $this->overview(),
            'php' => $this->phpInfo(),
            'extensions' => ToolResult::success(['extensions' => get_loaded_extensions()]),
            'memory' => $this->memoryInfo(),
            'bundles' => $this->bundleInfo(),
            default => ToolResult::error('Unknown section. Use "overview", "php", "extensions", "memory", or "bundles".'),
        };
    }

    private function overview(): ToolResult
    {
        return ToolResult::success([
            'php_version' => PHP_VERSION,
            'symfony_env' => $this->environment,
            'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'timezone' => date_default_timezone_get(),
            'project_dir' => $this->projectDir,
        ]);
    }

    private function phpInfo(): ToolResult
    {
        return ToolResult::success([
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'ini_path' => php_ini_loaded_file(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'opcache_enabled' => ini_get('opcache.enable'),
            'xdebug' => extension_loaded('xdebug') ? phpversion('xdebug') : 'not loaded',
            'intl' => extension_loaded('intl'),
            'gd' => extension_loaded('gd'),
            'redis' => extension_loaded('redis') ? phpversion('redis') : 'not loaded (using predis)',
            'pgsql' => extension_loaded('pgsql'),
        ]);
    }

    private function memoryInfo(): ToolResult
    {
        $data = [
            'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'memory_limit' => ini_get('memory_limit'),
        ];

        if (function_exists('disk_free_space') && is_dir($this->projectDir)) {
            $data['disk_free_gb'] = round(disk_free_space($this->projectDir) / 1073741824, 1);
            $data['disk_total_gb'] = round(disk_total_space($this->projectDir) / 1073741824, 1);
        }

        return ToolResult::success($data);
    }

    private function bundleInfo(): ToolResult
    {
        $bundleFiles = glob($this->projectDir . '/src/*/Bundle/*/Resources/config/oro/bundles.yml') ?: [];
        $vendorBundles = glob($this->projectDir . '/vendor/oro/*/src/Oro/Bundle/*/Resources/config/oro/bundles.yml') ?: [];

        return ToolResult::success([
            'custom_bundle_configs' => count($bundleFiles),
            'oro_vendor_bundle_configs' => count($vendorBundles),
            'custom_paths' => array_map(
                static fn(string $p) => str_replace($this->projectDir . '/', '', $p),
                $bundleFiles,
            ),
        ]);
    }
}

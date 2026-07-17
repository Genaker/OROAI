<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Symfony\Component\Routing\RouterInterface;

/** AI tool that searches OroCommerce admin routes by keyword to find admin panel URLs. */
final class RouteSearchTool implements AiToolInterface
{
    public function __construct(
        private readonly RouterInterface $router,
    ) {
    }

    public function getName(): string
    {
        return 'route_search';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'route_search',
            'Search OroCommerce admin routes by keyword. Use this to find the exact URL for a feature, admin page, or API endpoint. '
            . 'Use when asked where to configure, set, enable, or manage something (e.g. "where do I set the shipping '
            . 'condition", "where are the Oro AI settings") — don\'t guess a path from memory.',
            [
                'type' => 'object',
                'properties' => [
                    'keyword' => [
                        'type' => 'string',
                        'description' => 'Search keyword to match against route names and paths (e.g. "customer", "order", "shipping", "config").',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results (default 20).',
                    ],
                ],
                'required' => ['keyword'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $keyword = strtolower(trim($arguments['keyword'] ?? ''));
        $limit = (int) ($arguments['limit'] ?? 20);

        if ($keyword === '') {
            return ToolResult::error('Parameter "keyword" is required.');
        }

        $routes = $this->router->getRouteCollection()->all();
        $matches = [];

        foreach ($routes as $name => $route) {
            $path = $route->getPath();

            if (!str_contains($path, '/admin')) {
                continue;
            }

            if (str_contains(strtolower($name), $keyword) || str_contains(strtolower($path), $keyword)) {
                $methods = $route->getMethods() ?: ['ANY'];
                $matches[] = [
                    'name' => $name,
                    'path' => $path,
                    'methods' => implode('|', $methods),
                ];
            }

            if (count($matches) >= $limit) {
                break;
            }
        }

        if ($matches === []) {
            return ToolResult::success([
                'message' => "No admin routes found matching \"{$keyword}\".",
                'suggestion' => 'Try a broader keyword like "order", "customer", "product", "config", "shipping".',
            ]);
        }

        return ToolResult::success([
            'count' => count($matches),
            'routes' => $matches,
        ]);
    }
}

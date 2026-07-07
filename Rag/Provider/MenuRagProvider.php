<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag\Provider;

use Genaker\Bundle\OroAI\Rag\Contract\RagProviderInterface;
use Genaker\Bundle\OroAI\Rag\RagDocument;
use Symfony\Component\Routing\RouterInterface;

final class MenuRagProvider implements RagProviderInterface
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    public function getName(): string
    {
        return 'menu';
    }

    public function getDescription(): string
    {
        return 'Admin panel routes and navigation paths from the Symfony router';
    }

    public function provide(): array
    {
        $collection = $this->router->getRouteCollection();
        $grouped = [];

        foreach ($collection->all() as $routeName => $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/admin/')) {
                continue;
            }

            $section = $this->sectionFromPath($path);
            $grouped[$section][] = [
                'route' => $routeName,
                'path'  => $path,
                'label' => $this->labelFromRoute($routeName),
            ];
        }

        ksort($grouped);

        $documents = [];

        foreach ($grouped as $section => $items) {
            // Split large sections into chunks of 20 routes each
            foreach (array_chunk($items, 20) as $chunkIndex => $chunk) {
                $lines = ["Admin navigation section: $section\n"];
                foreach ($chunk as $item) {
                    $lines[] = sprintf(
                        "- %s\n  Route: %s\n  URL: %s",
                        $item['label'],
                        $item['route'],
                        $item['path'],
                    );
                }

                $documents[] = new RagDocument(
                    id: md5('menu:' . $section . ':' . $chunkIndex),
                    text: implode("\n\n", $lines),
                    source: 'admin_menu:' . $section,
                );
            }
        }

        return $documents;
    }

    private function sectionFromPath(string $path): string
    {
        // /admin/product/... → product
        // /admin/customer/user/... → customer
        $parts = explode('/', ltrim($path, '/'));

        return $parts[1] ?? 'admin';
    }

    private function labelFromRoute(string $routeName): string
    {
        // oro_product_index → "Product Index"
        // oro_customer_user_create → "Customer User Create"
        $name = preg_replace('/^(oro_|genaker_)/', '', $routeName) ?? $routeName;
        $name = str_replace('_', ' ', $name);

        return ucwords($name);
    }
}

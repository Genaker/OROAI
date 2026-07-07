<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class EntityUrlTool implements AiToolInterface
{
    private const ENTITY_ROUTES = [
        'customer_user' => ['index' => 'oro_customer_customer_user_index', 'view' => 'oro_customer_customer_user_view', 'create' => 'oro_customer_customer_user_create'],
        'customer' => ['index' => 'oro_customer_customer_index', 'view' => 'oro_customer_customer_view'],
        'order' => ['index' => 'oro_order_index', 'view' => 'oro_order_view'],
        'user' => ['index' => 'oro_user_index', 'view' => 'oro_user_view'],
        'product' => ['index' => 'oro_product_index', 'view' => 'oro_product_view'],
        'shopping_list' => ['index' => 'oro_shopping_list_index', 'view' => 'oro_shopping_list_view'],
        'rfq' => ['index' => 'oro_rfp_request_index', 'view' => 'oro_rfp_request_view'],
        'quote' => ['index' => 'oro_sale_quote_index', 'view' => 'oro_sale_quote_view'],
        'contact' => ['index' => 'oro_contact_index', 'view' => 'oro_contact_view'],
        'email' => ['index' => 'oro_email_index', 'view' => 'oro_email_view'],
        'category' => ['index' => 'oro_catalog_category_index', 'view' => 'oro_catalog_category_view'],
        'warehouse' => ['index' => 'oro_warehouse_index', 'view' => 'oro_warehouse_view'],
        'inventory' => ['index' => 'oro_inventory_level_index'],
        'price_list' => ['index' => 'oro_pricing_price_list_index', 'view' => 'oro_pricing_price_list_view'],
        'payment_term' => ['index' => 'oro_payment_term_index', 'view' => 'oro_payment_term_view'],
        'shipping_rule' => ['index' => 'oro_shipping_methods_configs_rule_index', 'view' => 'oro_shipping_methods_configs_rule_view'],
        'content_block' => ['index' => 'oro_cms_content_block_index', 'view' => 'oro_cms_content_block_view'],
        'landing_page' => ['index' => 'oro_cms_page_index', 'view' => 'oro_cms_page_view'],
        'web_catalog' => ['index' => 'oro_web_catalog_index', 'view' => 'oro_web_catalog_view'],
        'promotion' => ['index' => 'oro_promotion_index', 'view' => 'oro_promotion_view'],
        'tax_rule' => ['index' => 'oro_tax_rule_index', 'view' => 'oro_tax_rule_view'],
    ];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getName(): string
    {
        return 'entity_url';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'entity_url',
            'Resolve an OroCommerce entity name to its admin panel URL. Supports index, view, and create actions.',
            [
                'type' => 'object',
                'properties' => [
                    'entity' => [
                        'type' => 'string',
                        'description' => 'Entity name, e.g. "customer_user", "order", "product".',
                    ],
                    'action' => [
                        'type' => 'string',
                        'enum' => ['index', 'view', 'create'],
                        'description' => 'The action URL to generate. Defaults to "index".',
                    ],
                    'id' => [
                        'type' => 'integer',
                        'description' => 'Entity ID, required for "view" action.',
                    ],
                ],
                'required' => ['entity'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $entity = strtolower(str_replace(' ', '_', trim($arguments['entity'] ?? '')));
        $action = $arguments['action'] ?? 'index';
        $id = $arguments['id'] ?? null;

        if ($entity === '') {
            return ToolResult::error('Parameter "entity" is required.');
        }

        $routes = self::ENTITY_ROUTES[$entity] ?? null;
        if ($routes === null) {
            return ToolResult::error(sprintf(
                'Unknown entity "%s". Available entities: %s',
                $entity,
                implode(', ', array_keys(self::ENTITY_ROUTES))
            ));
        }

        $routeName = $routes[$action] ?? null;
        if ($routeName === null) {
            return ToolResult::error(sprintf(
                'Action "%s" is not available for entity "%s". Available actions: %s',
                $action,
                $entity,
                implode(', ', array_keys($routes))
            ));
        }

        try {
            $params = ($action === 'view' && $id !== null) ? ['id' => (int) $id] : [];
            $url = $this->urlGenerator->generate($routeName, $params, UrlGeneratorInterface::ABSOLUTE_PATH);

            return ToolResult::success(['entity' => $entity, 'action' => $action, 'url' => $url]);
        } catch (\Throwable $e) {
            return ToolResult::error('URL generation failed: ' . $e->getMessage());
        }
    }
}

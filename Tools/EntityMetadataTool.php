<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;

final class EntityMetadataTool implements AiToolInterface
{
    private const ENTITY_ALIASES = [
        'customer_user' => 'Oro\Bundle\CustomerBundle\Entity\CustomerUser',
        'customer' => 'Oro\Bundle\CustomerBundle\Entity\Customer',
        'order' => 'Oro\Bundle\OrderBundle\Entity\Order',
        'order_line_item' => 'Oro\Bundle\OrderBundle\Entity\OrderLineItem',
        'user' => 'Oro\Bundle\UserBundle\Entity\User',
        'product' => 'Oro\Bundle\ProductBundle\Entity\Product',
        'product_unit' => 'Oro\Bundle\ProductBundle\Entity\ProductUnit',
        'shopping_list' => 'Oro\Bundle\ShoppingListBundle\Entity\ShoppingList',
        'contact' => 'Oro\Bundle\ContactBundle\Entity\Contact',
        'category' => 'Oro\Bundle\CatalogBundle\Entity\Category',
        'price_list' => 'Oro\Bundle\PricingBundle\Entity\PriceList',
        'quote' => 'Oro\Bundle\SaleBundle\Entity\Quote',
        'rfq' => 'Oro\Bundle\RFPBundle\Entity\Request',
        'promotion' => 'Oro\Bundle\PromotionBundle\Entity\Promotion',
        'web_catalog' => 'Oro\Bundle\WebCatalogBundle\Entity\WebCatalog',
        'landing_page' => 'Oro\Bundle\CMSBundle\Entity\Page',
        'content_block' => 'Oro\Bundle\CMSBundle\Entity\ContentBlock',
        'warehouse' => 'Oro\Bundle\WarehouseBundle\Entity\Warehouse',
        'payment_term' => 'Oro\Bundle\PaymentTermBundle\Entity\PaymentTerm',
        'email' => 'Oro\Bundle\EmailBundle\Entity\Email',
        'organization' => 'Oro\Bundle\OrganizationBundle\Entity\Organization',
        'business_unit' => 'Oro\Bundle\OrganizationBundle\Entity\BusinessUnit',
        'role' => 'Oro\Bundle\UserBundle\Entity\Role',
    ];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    public function getName(): string
    {
        return 'entity_metadata';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'entity_metadata',
            'Inspect OroCommerce entity metadata — fields, types, relations, and associations. Use this to understand entity structure before querying or when answering questions about data models.',
            [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['describe', 'list_entities', 'list_fields', 'relations'],
                        'description' => '"describe" = full entity info, "list_entities" = available entity aliases, "list_fields" = just field names/types, "relations" = associations only.',
                    ],
                    'entity' => [
                        'type' => 'string',
                        'description' => 'Entity alias (e.g. "order", "customer_user") or full class name.',
                    ],
                ],
                'required' => ['action'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $action = $arguments['action'] ?? '';

        if ($action === 'list_entities') {
            return ToolResult::success([
                'entities' => array_keys(self::ENTITY_ALIASES),
                'note' => 'You can also pass a full Doctrine entity class name.',
            ]);
        }

        $entity = trim($arguments['entity'] ?? '');
        if ($entity === '') {
            return ToolResult::error('Parameter "entity" is required for this action.');
        }

        $className = self::ENTITY_ALIASES[strtolower(str_replace(' ', '_', $entity))] ?? $entity;

        try {
            /** @var EntityManagerInterface $em */
            $em = $this->doctrine->getManagerForClass($className);
            if ($em === null) {
                return ToolResult::error("No entity manager found for class \"{$className}\".");
            }

            $meta = $em->getClassMetadata($className);
        } catch (\Throwable $e) {
            return ToolResult::error("Entity not found: {$e->getMessage()}. Use action \"list_entities\" to see available aliases.");
        }

        return match ($action) {
            'describe' => $this->describe($meta, $className),
            'list_fields' => $this->listFields($meta),
            'relations' => $this->listRelations($meta),
            default => ToolResult::error('Unknown action. Use "describe", "list_entities", "list_fields", or "relations".'),
        };
    }

    private function describe(object $meta, string $className): ToolResult
    {
        $fields = [];
        foreach ($meta->getFieldNames() as $name) {
            $mapping = $meta->getFieldMapping($name);
            $fields[$name] = [
                'type' => $mapping['type'],
                'nullable' => $mapping['nullable'] ?? false,
                'column' => $mapping['columnName'] ?? $name,
            ];
        }

        $relations = [];
        foreach ($meta->getAssociationNames() as $name) {
            $mapping = $meta->getAssociationMapping($name);
            $relations[$name] = [
                'type' => $this->associationType($mapping),
                'target' => $mapping['targetEntity'],
            ];
        }

        return ToolResult::success([
            'class' => $className,
            'table' => $meta->getTableName(),
            'identifier' => $meta->getIdentifierFieldNames(),
            'fields' => $fields,
            'relations' => $relations,
        ]);
    }

    private function listFields(object $meta): ToolResult
    {
        $fields = [];
        foreach ($meta->getFieldNames() as $name) {
            $mapping = $meta->getFieldMapping($name);
            $fields[$name] = $mapping['type'];
        }

        return ToolResult::success($fields);
    }

    private function listRelations(object $meta): ToolResult
    {
        $relations = [];
        foreach ($meta->getAssociationNames() as $name) {
            $mapping = $meta->getAssociationMapping($name);
            $relations[$name] = [
                'type' => $this->associationType($mapping),
                'target' => $mapping['targetEntity'],
                'inversedBy' => $mapping['inversedBy'] ?? null,
                'mappedBy' => $mapping['mappedBy'] ?? null,
            ];
        }

        return ToolResult::success($relations);
    }

    private function associationType(array $mapping): string
    {
        return match ($mapping['type'] ?? 0) {
            1 => 'OneToOne',
            2 => 'ManyToOne',
            4 => 'OneToMany',
            8 => 'ManyToMany',
            default => 'unknown',
        };
    }
}

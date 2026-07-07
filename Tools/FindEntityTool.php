<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Doctrine\Persistence\ManagerRegistry;
use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class FindEntityTool implements AiToolInterface
{
    private const ENTITY_CLASSES = [
        'customer_user' => 'Oro\Bundle\CustomerBundle\Entity\CustomerUser',
        'customer' => 'Oro\Bundle\CustomerBundle\Entity\Customer',
        'order' => 'Oro\Bundle\OrderBundle\Entity\Order',
        'user' => 'Oro\Bundle\UserBundle\Entity\User',
        'product' => 'Oro\Bundle\ProductBundle\Entity\Product',
        'shopping_list' => 'Oro\Bundle\ShoppingListBundle\Entity\ShoppingList',
        'contact' => 'Oro\Bundle\ContactBundle\Entity\Contact',
    ];

    private const ENTITY_VIEW_ROUTES = [
        'customer_user' => 'oro_customer_customer_user_view',
        'customer' => 'oro_customer_customer_view',
        'order' => 'oro_order_view',
        'user' => 'oro_user_view',
        'product' => 'oro_product_view',
        'shopping_list' => 'oro_shopping_list_view',
        'contact' => 'oro_contact_view',
    ];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getName(): string
    {
        return 'find_entity';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'find_entity',
            'Find OroCommerce entities by field value. Returns matching records with their admin URLs.',
            [
                'type' => 'object',
                'properties' => [
                    'entity' => [
                        'type' => 'string',
                        'description' => 'Entity name, e.g. "customer_user", "order", "product".',
                    ],
                    'field' => [
                        'type' => 'string',
                        'description' => 'The field name to search by, e.g. "email", "id", "sku".',
                    ],
                    'value' => [
                        'type' => 'string',
                        'description' => 'The value to search for.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results to return. Defaults to 5.',
                    ],
                ],
                'required' => ['entity', 'field', 'value'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $entityKey = strtolower(str_replace(' ', '_', trim($arguments['entity'] ?? '')));
        $field = trim($arguments['field'] ?? '');
        $value = $arguments['value'] ?? '';
        $limit = (int) ($arguments['limit'] ?? 5);

        if ($entityKey === '' || $field === '') {
            return ToolResult::error('Parameters "entity" and "field" are required.');
        }

        $className = self::ENTITY_CLASSES[$entityKey] ?? null;
        if ($className === null) {
            return ToolResult::error(sprintf(
                'Unknown entity "%s". Available entities: %s',
                $entityKey,
                implode(', ', array_keys(self::ENTITY_CLASSES))
            ));
        }

        try {
            $em = $this->doctrine->getManagerForClass($className);
            if ($em === null) {
                return ToolResult::error(sprintf('No entity manager found for class "%s".', $className));
            }

            $repository = $em->getRepository($className);
            $entities = $repository->findBy([$field => $value], null, $limit);

            $results = [];
            $viewRoute = self::ENTITY_VIEW_ROUTES[$entityKey] ?? null;

            foreach ($entities as $entity) {
                $id = method_exists($entity, 'getId') ? $entity->getId() : null;
                $label = $this->extractLabel($entity);
                $url = null;

                if ($viewRoute !== null && $id !== null) {
                    try {
                        $url = $this->urlGenerator->generate($viewRoute, ['id' => $id], UrlGeneratorInterface::ABSOLUTE_PATH);
                    } catch (\Throwable) {
                    }
                }

                $results[] = array_filter([
                    'id' => $id,
                    'label' => $label,
                    'url' => $url,
                ], static fn (mixed $v): bool => $v !== null);
            }

            return ToolResult::success([
                'entity' => $entityKey,
                'field' => $field,
                'value' => $value,
                'count' => count($results),
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Entity search failed: ' . $e->getMessage());
        }
    }

    private function extractLabel(object $entity): ?string
    {
        foreach (['getName', 'getEmail', 'getTitle', '__toString'] as $method) {
            if (method_exists($entity, $method)) {
                try {
                    return (string) $entity->$method();
                } catch (\Throwable) {
                }
            }
        }

        return null;
    }
}

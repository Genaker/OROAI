<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Tools;

use Genaker\Bundle\OroAI\Tools\EntityUrlTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class EntityUrlToolTest extends TestCase
{
    private UrlGeneratorInterface&MockObject $urlGenerator;
    private EntityUrlTool $tool;

    protected function setUp(): void
    {
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->tool = new EntityUrlTool($this->urlGenerator);
    }

    public function testGetName(): void
    {
        self::assertSame('entity_url', $this->tool->getName());
    }

    public function testGetDefinition(): void
    {
        $def = $this->tool->getDefinition();

        self::assertSame('entity_url', $def->name);
        self::assertNotEmpty($def->description);
        self::assertArrayHasKey('entity', $def->parameters['properties']);
        self::assertContains('entity', $def->parameters['required']);
    }

    public function testResolvesCustomerUserIndexRoute(): void
    {
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                'oro_customer_customer_user_index',
                [],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            )
            ->willReturn('/admin/customer/customer-user');

        $result = $this->tool->execute(['entity' => 'customer_user', 'action' => 'index']);

        self::assertTrue($result->success);
        self::assertSame('customer_user', $result->data['entity']);
        self::assertSame('index', $result->data['action']);
        self::assertSame('/admin/customer/customer-user', $result->data['url']);
    }

    public function testResolvesOrderViewRouteWithId(): void
    {
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                'oro_order_view',
                ['id' => 42],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            )
            ->willReturn('/admin/order/view/42');

        $result = $this->tool->execute([
            'entity' => 'order',
            'action' => 'view',
            'id' => 42,
        ]);

        self::assertTrue($result->success);
        self::assertSame('/admin/order/view/42', $result->data['url']);
    }

    public function testDefaultsToIndexAction(): void
    {
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                'oro_product_index',
                [],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            )
            ->willReturn('/admin/product');

        $result = $this->tool->execute(['entity' => 'product']);

        self::assertTrue($result->success);
        self::assertSame('index', $result->data['action']);
    }

    public function testReturnsErrorForUnknownEntity(): void
    {
        $result = $this->tool->execute(['entity' => 'nonexistent_entity']);

        self::assertFalse($result->success);
        self::assertStringContainsString('Unknown entity', $result->errorMessage);
        self::assertStringContainsString('nonexistent_entity', $result->errorMessage);
        self::assertStringContainsString('customer_user', $result->errorMessage); // Lists available
    }

    public function testReturnsErrorForEmptyEntity(): void
    {
        $result = $this->tool->execute(['entity' => '']);

        self::assertFalse($result->success);
        self::assertSame('Parameter "entity" is required.', $result->errorMessage);
    }

    public function testReturnsErrorForMissingEntity(): void
    {
        $result = $this->tool->execute([]);

        self::assertFalse($result->success);
        self::assertSame('Parameter "entity" is required.', $result->errorMessage);
    }

    public function testReturnsErrorForUnavailableAction(): void
    {
        // 'inventory' only has 'index', no 'view'
        $result = $this->tool->execute(['entity' => 'inventory', 'action' => 'view']);

        self::assertFalse($result->success);
        self::assertStringContainsString('Action "view" is not available', $result->errorMessage);
    }

    public function testNormalizesEntityNameWithSpaces(): void
    {
        $this->urlGenerator->method('generate')->willReturn('/admin/customer/customer-user');

        $result = $this->tool->execute(['entity' => 'customer user']);

        self::assertTrue($result->success);
        self::assertSame('customer_user', $result->data['entity']);
    }

    public function testNormalizesEntityNameUpperCase(): void
    {
        $this->urlGenerator->method('generate')->willReturn('/admin/order');

        $result = $this->tool->execute(['entity' => 'ORDER']);

        self::assertTrue($result->success);
        self::assertSame('order', $result->data['entity']);
    }

    public function testHandlesUrlGenerationException(): void
    {
        $this->urlGenerator->method('generate')
            ->willThrowException(new \RuntimeException('Route not found'));

        $result = $this->tool->execute(['entity' => 'order']);

        self::assertFalse($result->success);
        self::assertStringContainsString('URL generation failed', $result->errorMessage);
    }

    public function testCustomerUserCreateRoute(): void
    {
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                'oro_customer_customer_user_create',
                [],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            )
            ->willReturn('/admin/customer/customer-user/create');

        $result = $this->tool->execute(['entity' => 'customer_user', 'action' => 'create']);

        self::assertTrue($result->success);
        self::assertSame('create', $result->data['action']);
        self::assertSame('/admin/customer/customer-user/create', $result->data['url']);
    }
}

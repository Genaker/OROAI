<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Integration;

use Genaker\Bundle\LocalIntegrationTests\Util\IntegrationTestCase;
use Oro\Bundle\ConfigBundle\Controller\ConfigurationController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Verifies that requesting the "general-setup" config group (the bare URL
 * with a dash, which does not match the tree's underscore-named
 * "general_setup" node) renders without crashing with "Impossible to access
 * an attribute (vars) on a null variable" -- a bug in the upstream
 * @OroConfig/configPage.html.twig template when $form is null, now fixed by
 * the local override at templates/bundles/OroConfigBundle/configPage.html.twig.
 *
 * Calls the controller action directly (bypassing the security firewall)
 * so the test does not depend on authenticated admin credentials.
 */
class ConfigPageRenderTest extends IntegrationTestCase
{
    public function testGeneralSetupDashGroupRendersWithoutCrash(): void
    {
        $container = static::getContainer();

        $request = Request::create('http://localhost/admin/config/system/general-setup');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requestStack = $container->get('request_stack');
        $requestStack->push($request);

        try {
            /** @var ConfigurationController $controller */
            $controller = $container->get(ConfigurationController::class) ?? null;

            if ($controller === null) {
                $this->markTestSkipped('ConfigurationController service is not public in this container.');
            }

            $data = $controller->systemAction($request, 'general-setup', null);

            self::assertIsArray($data);
            self::assertArrayHasKey('form', $data);
            self::assertNull($data['form'], 'With an unresolvable dash group, form is expected to stay null.');

            $twig = $container->get('twig');
            $html = $twig->render('@OroConfig/configPage.html.twig', array_merge($data, [
                'pageTitle' => 'Test',
                'formAction' => '/test',
                'bap' => (object) ['layout' => '@OroUI/actions/index.html.twig'],
            ]));

            self::assertIsString($html);
        } catch (\Throwable $e) {
            self::fail(
                'Rendering general-setup (dash) must not throw. Got: '
                . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()
            );
        } finally {
            $requestStack->pop();
        }
    }
}

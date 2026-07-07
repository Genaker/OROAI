<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Integration;

use Genaker\Bundle\LocalIntegrationTests\Util\IntegrationTestCase;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\SecurityBundle\Authentication\Token\UsernamePasswordOrganizationToken;
use Oro\Bundle\UserBundle\Entity\Role;
use Oro\Bundle\UserBundle\Entity\User;
use Oro\Bundle\UserBundle\Entity\UserManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

/**
 * Full-stack coverage of POST /admin/oroai/chat/message — the exact route the
 * "AI Assistant" header widget calls.
 *
 * Unlike OroAIBundleTest::testChatControllerReturnsJsonNotHtml() (which calls
 * ChatController::messageAction() directly, bypassing routing/security), these
 * tests dispatch a real Symfony Request through the kernel, so they also
 * exercise routing and the #[AclAncestor('genaker_oroai_chat')] check that
 * guards the route. That check is what silently returned an HTML
 * redirect/login page instead of JSON for an unauthenticated session — the bug
 * this suite locks in.
 *
 * A dedicated backend admin user (OROAI_TEST_ADMIN_USERNAME, default
 * "oroai_test_admin") is created on first run if it doesn't already exist, so
 * these tests never depend on the real "admin" account's password. The
 * authenticated session is simulated by priming Oro's session handler with a
 * serialized security token under the "_security_main" key — the same
 * mechanism Symfony's own ContextListener reads on each request — rather than
 * performing a real HTTP login round-trip.
 *
 * Priming that session requires a real PHP session_start() call, which PHP
 * refuses once any output has already been sent (e.g. PHPUnit's own CLI
 * banner) — so this suite must run with output buffering enabled for the
 * whole process via -d output_buffering, not just an in-code ob_start().
 *
 * Run:
 *   INTEGRATION_TESTS_ENABLED=1 php -d output_buffering=4096 bin/phpunit \
 *     -c phpunit-dev.xml --testsuite oroai --filter ChatMessageEndpointTest
 */
class ChatMessageEndpointTest extends IntegrationTestCase
{
    private const SESSION_COOKIE_NAME = 'BAPID';

    private function ensureTestAdminUser(): ?User
    {
        $container = static::getContainer();
        $doctrine = $container->get('doctrine');
        $entityManager = $doctrine->getManagerForClass(User::class);
        $userRepository = $entityManager->getRepository(User::class);

        $username = getenv('OROAI_TEST_ADMIN_USERNAME') ?: 'oroai_test_admin';

        $user = $userRepository->findOneBy(['username' => $username]);
        if ($user !== null) {
            return $user;
        }

        $organization = $entityManager->getRepository(Organization::class)->findOneBy(['enabled' => true]);
        $administratorRole = $entityManager->getRepository(Role::class)->findOneBy(['role' => 'ROLE_ADMINISTRATOR']);

        if ($organization === null || $administratorRole === null) {
            return null;
        }

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($username . '@example.test');
        $user->setFirstName('OroAI');
        $user->setLastName('TestAdmin');
        $user->setEnabled(true);
        $user->setOrganization($organization);
        $user->addOrganization($organization);
        $user->addUserRole($administratorRole);
        $user->setPlainPassword(getenv('OROAI_TEST_ADMIN_PASSWORD') ?: 'OroAiTest123!');

        /** @var UserManager $userManager */
        $userManager = $container->get('oro_user.manager');
        $userManager->updateUser($user);

        return $user;
    }

    /**
     * Prime Oro's session storage with a serialized, authenticated security
     * token under the "_security_main" key, then dispatch the request
     * carrying that session's cookie — Symfony's ContextListener reads the
     * token back from the session on kernel.request, the same as a real
     * logged-in browser.
     */
    private function authenticatedPost(string $uri, array $content): Response
    {
        $user = $this->ensureTestAdminUser();
        if ($user === null) {
            $this->markTestSkipped('Could not find/create a test admin user (no organization or ROLE_ADMINISTRATOR in DB).');
        }

        $organization = $user->getOrganization();
        $token = new UsernamePasswordOrganizationToken($user, 'main', $organization, $user->getRoles());

        // NativeSessionStorage::start() refuses to run once any output has been
        // sent (e.g. PHPUnit's own CLI banner/progress output) -- but only
        // when session.use_cookies is enabled. We attach the session id to the
        // outgoing Request ourselves, so PHP never needs to emit a Set-Cookie
        // header for it. NativeSessionStorage::setOptions() itself no-ops once
        // headers are sent, so the ini value must be set directly, not via the
        // storage's constructor options.
        ini_set('session.use_cookies', '0');

        $handler = static::getContainer()->get('oro.session_handler');
        $session = new Session(new NativeSessionStorage(['name' => self::SESSION_COOKIE_NAME], $handler));
        $session->set('_security_main', serialize($token));
        $session->save();

        $server = array_merge($this->defaultServerVars(), [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $request = Request::create(
            $this->getBaseUrl() . $uri,
            'POST',
            [],
            [self::SESSION_COOKIE_NAME => $session->getId()],
            [],
            $server,
            json_encode($content, JSON_THROW_ON_ERROR)
        );
        $request->headers->set('Accept', 'application/json');

        return $this->dispatchRequest($request);
    }

    public function testAuthenticatedMessageReturnsJsonReply(): void
    {
        /** @var OroAiConfig $config */
        $config = static::getContainer()->get(OroAiConfig::class);
        if (!$config->isConfigured()) {
            $this->markTestSkipped('No API key configured — skipping live chat endpoint test.');
        }

        $response = $this->authenticatedPost('/admin/oroai/chat/message', [
            'message' => 'What is the current system status?',
            'history' => [],
        ]);

        $content = (string) $response->getContent();

        self::assertStringNotContainsString(
            '<',
            $content,
            'Endpoint must not return HTML (e.g. a login/ACL redirect page) — got: ' . substr($content, 0, 300)
        );
        self::assertJson($content, 'Response must be valid JSON');

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        // Accept 200 (LLM answered) or 500 (LLM/provider unavailable) — both prove
        // routing + ACL + controller responded correctly with JSON. A redirect or
        // access-denied HTML page would have failed the assertions above already.
        self::assertContains($response->getStatusCode(), [200, 500]);

        if ($response->getStatusCode() === 200) {
            self::assertArrayHasKey('reply', $data);
            self::assertNotEmpty($data['reply']);
        } else {
            self::assertArrayHasKey('error', $data);
        }
    }

    /**
     * A full two-turn conversation through the real HTTP endpoint: the first
     * message's history is empty, but the second carries the accumulated
     * history the JS widget builds up (oroai-chat.js's `history.push(...)`
     * after every exchange) -- exactly the shape that triggers
     * ChatController::parseHistory()'s `Role::tryFrom()` call.
     *
     * Regression guard: Role::tryFrom() used to fatal ("Attempted to load
     * class Role") on any request with non-empty history, because the Role
     * enum was defined inside ChatMessage.php with no file of its own, so it
     * only autoloaded as a side effect of ChatMessage loading first --
     * something that never happened before parseHistory() ran when history
     * had entries. A conversation's first message (empty history) always
     * worked; only the follow-up broke, which single-message tests can't
     * catch.
     */
    public function testAuthenticatedConversationWithFollowUpHistoryWorks(): void
    {
        /** @var OroAiConfig $config */
        $config = static::getContainer()->get(OroAiConfig::class);
        if (!$config->isConfigured()) {
            $this->markTestSkipped('No API key configured — skipping live chat endpoint test.');
        }

        $first = $this->authenticatedPost('/admin/oroai/chat/message', [
            'message' => 'Where are customer users?',
            'history' => [],
        ]);

        self::assertContains($first->getStatusCode(), [200, 500], 'First message must return JSON, not crash.');
        $firstData = json_decode((string) $first->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if ($first->getStatusCode() !== 200) {
            $this->markTestSkipped('First message failed (LLM/provider unavailable): ' . ($firstData['error'] ?? 'unknown'));
        }

        $second = $this->authenticatedPost('/admin/oroai/chat/message', [
            'message' => 'And what about orders?',
            'history' => [
                ['role' => 'user', 'content' => 'Where are customer users?'],
                ['role' => 'assistant', 'content' => $firstData['reply']],
            ],
        ]);

        $secondContent = (string) $second->getContent();

        self::assertStringNotContainsString(
            'Attempted to load class',
            $secondContent,
            'Follow-up message with non-empty history must not fatal on Role autoloading.'
        );
        self::assertJson($secondContent, 'Follow-up response must be valid JSON, not a raw fatal-error page.');

        $secondData = json_decode($secondContent, true, 512, JSON_THROW_ON_ERROR);
        self::assertContains($second->getStatusCode(), [200, 500]);

        if ($second->getStatusCode() === 200) {
            self::assertArrayHasKey('reply', $secondData);
            self::assertNotEmpty($secondData['reply']);
        } else {
            self::assertArrayHasKey('error', $secondData);
        }
    }

    public function testAuthenticatedEmptyMessageReturns400Json(): void
    {
        $response = $this->authenticatedPost('/admin/oroai/chat/message', [
            'message' => '',
            'history' => [],
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertJson((string) $response->getContent());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('required', $data['error']);
    }

    public function testUnauthenticatedRequestIsNotAJsonChatReply(): void
    {
        // No session/token primed — this is a plain anonymous request.
        $response = $this->post('/admin/oroai/chat/message', [
            'message' => 'hi',
            'history' => [],
        ]);

        $content = (string) $response->getContent();
        $data = json_decode($content, true);

        $isSuccessfulChatReply = $response->getStatusCode() === 200
            && is_array($data)
            && array_key_exists('reply', $data);

        self::assertFalse(
            $isSuccessfulChatReply,
            'Unauthenticated request must not receive a successful chat reply. Got status '
                . $response->getStatusCode() . ': ' . substr($content, 0, 300)
        );
    }
}

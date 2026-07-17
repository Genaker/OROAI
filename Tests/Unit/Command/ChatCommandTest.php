<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Command;

use Genaker\Bundle\OroAI\Command\ChatCommand;
use Genaker\Bundle\OroAI\Core\Model\ChatOutcome;
use Genaker\Bundle\OroAI\Service\ChatOrchestrator;
use Genaker\Bundle\OroAI\Service\ChatSessionStore;
use Genaker\Bundle\OroAI\Service\LlmErrorPresenter;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\UserBundle\Entity\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ChatCommandTest extends TestCase
{
    private ChatOrchestrator&MockObject $orchestrator;
    private OroAiConfig&MockObject $config;
    private LlmErrorPresenter&MockObject $errorPresenter;
    private TokenAccessorInterface&MockObject $tokenAccessor;
    private ConfigManager&MockObject $configManager;
    private ChatSessionStore&MockObject $sessionStore;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(ChatOrchestrator::class);
        $this->config = $this->createMock(OroAiConfig::class);
        $this->errorPresenter = $this->createMock(LlmErrorPresenter::class);
        $this->tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->sessionStore = $this->createMock(ChatSessionStore::class);

        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getProvider')->willReturn('gemini');
        $this->config->method('getModel')->willReturn('gemini-2.5-flash');
        $this->configManager->method('get')->with('oro_ui.application_url')->willReturn('https://oro.example.test');
    }

    private function tester(): CommandTester
    {
        $command = new ChatCommand(
            $this->orchestrator,
            $this->config,
            $this->errorPresenter,
            $this->tokenAccessor,
            $this->configManager,
            $this->sessionStore,
        );
        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('genaker:oroai:chat'));
    }

    private function outcome(string $reply, array $toolTrace = [], ?float $cost = 0.001): ChatOutcome
    {
        return new ChatOutcome(
            reply: $reply,
            toolTrace: $toolTrace,
            links: [],
            usage: ['prompt_tokens' => 100, 'completion_tokens' => 20, 'thinking_tokens' => 5, 'total_tokens' => 120],
            promptBreakdown: ['system_prompt' => 1700, 'guidelines' => 290, 'skills_catalog' => 768, 'tools' => 2000],
            cost: $cost !== null ? ['total' => $cost, 'input' => 0.0002, 'output' => 0.0008] : null,
            sessionId: 'sess1',
        );
    }

    public function testNotConfiguredReturnsFailureWithoutCallingTheOrchestrator(): void
    {
        $this->config = $this->createMock(OroAiConfig::class);
        $this->config->method('isConfigured')->willReturn(false);
        $this->orchestrator->expects(self::never())->method('handle');

        $tester = $this->tester();
        $exitCode = $tester->execute(['message' => 'hi']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('not configured', $tester->getDisplay());
    }

    public function testOneShotMessagePrintsTheReplyToolsAndUsage(): void
    {
        $this->orchestrator->expects(self::once())
            ->method('handle')
            ->with('where are customer users?', self::isType('string'), self::isType('callable'))
            ->willReturn($this->outcome(
                'Customer users are under Customers > Customer Users.',
                [['tool' => 'entity_url', 'args' => '{}', 'result' => '/admin/customer/user/']],
            ));

        $tester = $this->tester();
        $exitCode = $tester->execute(['message' => 'where are customer users?']);
        $display = $tester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Customer users are under Customers > Customer Users.', $display);
        self::assertStringContainsString('tools: entity_url', $display);
        self::assertStringContainsString('prompt ~1.7k', $display);
        self::assertStringContainsString('cost ≈$0.0010', $display);
    }

    public function testSkillToolInTraceIsShownWithItsName(): void
    {
        $this->orchestrator->method('handle')->willReturn($this->outcome(
            'ok',
            [['tool' => 'skill', 'args' => '{"name":"write_sql_report"}', 'result' => 'done']],
        ));

        $tester = $this->tester();
        $tester->execute(['message' => 'hi']);

        self::assertStringContainsString('tools: skill: write_sql_report', $tester->getDisplay());
    }

    public function testSuccessfulReplyWithNoCostDataIsStillASuccess(): void
    {
        // An unpriced model (TokenCostEstimator returns null) must not be
        // conflated with an errored turn — this exact regression was caught
        // and fixed before this test was written.
        $this->orchestrator->method('handle')->willReturn($this->outcome('ok', [], null));

        $tester = $this->tester();
        $exitCode = $tester->execute(['message' => 'hi']);

        self::assertSame(0, $exitCode);
    }

    public function testOrchestratorExceptionIsHumanizedAndReturnsFailure(): void
    {
        $exception = new \RuntimeException('HTTP/1.1 429 Too Many Requests');
        $this->orchestrator->method('handle')->willThrowException($exception);
        $this->errorPresenter->method('humanize')->with($exception)->willReturn('API rate limit exceeded.');
        $this->errorPresenter->method('detail')->willReturn(null);

        $tester = $this->tester();
        $exitCode = $tester->execute(['message' => 'hi']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('API rate limit exceeded.', $tester->getDisplay());
    }

    public function testSessionOptionIsPassedThroughToTheOrchestrator(): void
    {
        $this->orchestrator->expects(self::once())
            ->method('handle')
            ->with('hi', 'resume-me-123', self::isType('callable'))
            ->willReturn($this->outcome('ok'));

        $tester = $this->tester();
        $tester->execute(['message' => 'hi', '--session' => 'resume-me-123']);
    }

    public function testBannerShowsAnonymousWhenNoUserIsInTheSecurityContext(): void
    {
        $this->tokenAccessor->method('getUser')->willReturn(null);
        $this->orchestrator->method('handle')->willReturn($this->outcome('ok'));

        $tester = $this->tester();
        $tester->execute(['message' => 'hi']);

        self::assertStringContainsString('anonymous', $tester->getDisplay());
        self::assertStringContainsString('--current-user', $tester->getDisplay());
    }

    public function testBannerShowsTheImpersonatedUsername(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getUsername')->willReturn('oroai_test_admin');
        $this->tokenAccessor->method('getUser')->willReturn($user);
        $this->orchestrator->method('handle')->willReturn($this->outcome('ok'));

        $tester = $this->tester();
        $tester->execute(['message' => 'hi']);

        self::assertStringContainsString('running as oroai_test_admin', $tester->getDisplay());
    }

    public function testInteractiveModeSendsAMessageThenExits(): void
    {
        $this->orchestrator->expects(self::once())
            ->method('handle')
            ->with('hello there', self::isType('string'), self::isType('callable'))
            ->willReturn($this->outcome('hi yourself'));

        $tester = $this->tester();
        $tester->setInputs(['hello there', '/exit']);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('hi yourself', $tester->getDisplay());
        self::assertStringContainsString('Goodbye — 1 turn(s)', $tester->getDisplay());
    }

    public function testInteractiveModeIgnoresBlankLinesAndDoesNotCallTheOrchestrator(): void
    {
        $this->orchestrator->expects(self::never())->method('handle');

        $tester = $this->tester();
        $tester->setInputs(['', '   ', '/exit']);
        $tester->execute([]);

        self::assertStringContainsString('Goodbye.', $tester->getDisplay());
    }

    public function testSlashHelpListsCommandsWithoutCallingTheOrchestrator(): void
    {
        $this->orchestrator->expects(self::never())->method('handle');

        $tester = $this->tester();
        $tester->setInputs(['/help', '/exit']);
        $tester->execute([]);

        self::assertStringContainsString('/new', $tester->getDisplay());
        self::assertStringContainsString('/exit, /quit', $tester->getDisplay());
    }

    public function testUnknownSlashCommandWarnsAndContinues(): void
    {
        $this->orchestrator->expects(self::never())->method('handle');

        $tester = $this->tester();
        $tester->setInputs(['/bogus', '/exit']);
        $tester->execute([]);

        self::assertStringContainsString('Unknown command "/bogus"', $tester->getDisplay());
    }

    public function testSlashNewStartsAFreshSessionIdForTheNextTurn(): void
    {
        $seenSessionIds = [];
        $this->orchestrator->method('handle')
            ->willReturnCallback(function (string $message, string $sessionId) use (&$seenSessionIds): ChatOutcome {
                $seenSessionIds[] = $sessionId;

                return $this->outcome('ok');
            });

        $tester = $this->tester();
        $tester->setInputs(['first message', '/new', 'second message', '/exit']);
        $tester->execute([]);

        self::assertCount(2, $seenSessionIds);
        self::assertNotSame($seenSessionIds[0], $seenSessionIds[1], '/new must switch to a different session id');
    }

    public function testCtrlDEndsTheSessionGracefully(): void
    {
        $this->orchestrator->expects(self::never())->method('handle');

        // No trailing input after this to answer the question -> QuestionHelper
        // hits EOF, same as a real Ctrl+D on an empty terminal.
        $tester = $this->tester();
        $tester->setInputs([]);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Goodbye.', $tester->getDisplay());
    }

    public function testAdminPathInTheReplyBecomesAClickableHyperlinkWhenDecorated(): void
    {
        $this->orchestrator->method('handle')->willReturn($this->outcome(
            'Settings are at `/admin/config/system/general_setup/genaker_oroai_group`.',
        ));

        $tester = $this->tester();
        $tester->execute(['message' => 'where are the settings?'], ['decorated' => true]);
        $display = $tester->getDisplay(true);

        // OSC 8 terminal hyperlink (Symfony's <href=...> tag), resolved against
        // Oro's own Application URL setting -- not a guessed host/port.
        self::assertStringContainsString(
            "\033]8;;https://oro.example.test/admin/config/system/general_setup/genaker_oroai_group\033\\"
            . '/admin/config/system/general_setup/genaker_oroai_group'
            . "\033]8;;\033\\",
            $display,
        );
    }

    public function testAdminPathStaysPlainTextWhenOutputIsNotDecorated(): void
    {
        $this->orchestrator->method('handle')->willReturn($this->outcome(
            'Settings are at `/admin/config/system/general_setup/genaker_oroai_group`.',
        ));

        $tester = $this->tester();
        // CommandTester defaults to decorated=false -- the realistic case for
        // output piped to a file or a dumb terminal.
        $tester->execute(['message' => 'where are the settings?']);
        $display = $tester->getDisplay();

        self::assertStringContainsString(
            '/admin/config/system/general_setup/genaker_oroai_group',
            $display,
        );
        self::assertStringNotContainsString("\033]8;", $display, 'no OSC 8 escape bytes when not decorated');
    }

    public function testAdminPathStaysPlainTextWhenApplicationUrlIsNotConfigured(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->configManager->method('get')->with('oro_ui.application_url')->willReturn('');
        $this->orchestrator->method('handle')->willReturn($this->outcome('See /admin/config/system.'));

        $tester = $this->tester();
        $tester->execute(['message' => 'where?'], ['decorated' => true]);
        $display = $tester->getDisplay(true);

        self::assertStringContainsString('/admin/config/system', $display);
        self::assertStringNotContainsString("\033]8;", $display, 'nothing to resolve a relative path against');
    }

    public function testSlashHelpListsTheNewCommandsToo(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['/help', '/exit']);
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('/clear', $display);
        self::assertStringContainsString('/sessions', $display);
        self::assertStringContainsString('/resume', $display);
    }

    public function testSlashClearStartsAFreshSessionAndReprintsTheBanner(): void
    {
        $seenSessionIds = [];
        $this->orchestrator->method('handle')
            ->willReturnCallback(function (string $message, string $sessionId) use (&$seenSessionIds): ChatOutcome {
                $seenSessionIds[] = $sessionId;

                return $this->outcome('ok');
            });

        $tester = $this->tester();
        $tester->setInputs(['first message', '/clear', 'second message', '/exit']);
        $tester->execute([]);

        self::assertCount(2, $seenSessionIds);
        self::assertNotSame($seenSessionIds[0], $seenSessionIds[1], '/clear must switch to a different session id');
        // The banner ("OroAI Assistant · ...") is printed once at startup and
        // again by /clear.
        self::assertSame(2, substr_count($tester->getDisplay(), 'OroAI Assistant ·'));
    }

    public function testSlashSessionsListsRecentConversations(): void
    {
        $this->sessionStore->method('getSessions')->willReturn([
            ['id' => 'sess-a', 'title' => 'How many orders?', 'updated_at' => 1, 'count' => 4],
            ['id' => 'sess-b', 'title' => 'Where are customer users?', 'updated_at' => 2, 'count' => 2],
        ]);

        $tester = $this->tester();
        $tester->setInputs(['/sessions', '/exit']);
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('sess-a', $display);
        self::assertStringContainsString('How many orders?', $display);
        self::assertStringContainsString('sess-b', $display);
    }

    public function testSlashSessionsWithNoHistoryHintsAtCurrentUser(): void
    {
        $this->sessionStore->method('getSessions')->willReturn([]);
        $this->tokenAccessor->method('getUser')->willReturn(null);

        $tester = $this->tester();
        $tester->setInputs(['/sessions', '/exit']);
        $tester->execute([]);

        self::assertStringContainsString('--current-user', $tester->getDisplay());
    }

    public function testSlashResumeSwitchesToTheGivenSessionAndReportsMessageCount(): void
    {
        $this->sessionStore->method('getMessages')->with('sess-a')->willReturn([
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello'],
        ]);
        $this->orchestrator->expects(self::once())
            ->method('handle')
            ->with('follow up', 'sess-a', self::isType('callable'))
            ->willReturn($this->outcome('ok'));

        $tester = $this->tester();
        $tester->setInputs(['/resume sess-a', 'follow up', '/exit']);
        $tester->execute([]);

        self::assertStringContainsString('Resumed session sess-a (2 prior message(s))', $tester->getDisplay());
    }

    public function testSlashResumeWithoutAnIdShowsUsageAndDoesNotChangeTheSession(): void
    {
        $seenSessionIds = [];
        $this->orchestrator->method('handle')
            ->willReturnCallback(function (string $message, string $sessionId) use (&$seenSessionIds): ChatOutcome {
                $seenSessionIds[] = $sessionId;

                return $this->outcome('ok');
            });

        $tester = $this->tester();
        $tester->setInputs(['/resume', 'hi', '/exit']);
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Usage: /resume', $display);
        self::assertCount(1, $seenSessionIds);
    }

    public function testSlashResumeWithAnUnknownIdWarnsButStillSwitches(): void
    {
        $this->sessionStore->method('getMessages')->with('ghost')->willReturn([]);
        $this->orchestrator->expects(self::once())
            ->method('handle')
            ->with('hi', 'ghost', self::isType('callable'))
            ->willReturn($this->outcome('ok'));

        $tester = $this->tester();
        $tester->setInputs(['/resume ghost', 'hi', '/exit']);
        $tester->execute([]);

        self::assertStringContainsString('No saved messages for session "ghost"', $tester->getDisplay());
    }
}

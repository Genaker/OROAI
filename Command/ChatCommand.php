<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Command;

use Genaker\Bundle\OroAI\Core\Model\ChatOutcome;
use Genaker\Bundle\OroAI\Service\ChatOrchestrator;
use Genaker\Bundle\OroAI\Service\ChatSessionStore;
use Genaker\Bundle\OroAI\Service\LlmErrorPresenter;
use Genaker\Bundle\OroAI\Service\OroAiConfig;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Terminal chat client for the OroAI assistant — the exact same
 * ChatOrchestrator (agent/harness, RAG, tools, transcript, cost, session
 * persistence) the web widget calls, so a question gets the identical
 * answer whichever front end asks it. Not a reimplementation: a second
 * entry point onto ChatController's own application service.
 *
 * Two modes:
 *   - one-shot:    genaker:oroai:chat "where are customer users?"
 *   - interactive: genaker:oroai:chat   (REPL — /help lists in-chat commands)
 *
 * Recent chats / resume and any ACL-aware tool need a security context —
 * this deliberately does NOT reinvent user impersonation. Pass Oro's own
 * global `--current-user=<username>` (every console command already gets
 * it from ConsoleContextGlobalOptionsProvider, resolved before execute()
 * runs); without it the assistant still answers, it just runs anonymously
 * and ChatSessionStore silently doesn't save anything.
 */
#[AsCommand(
    name: 'genaker:oroai:chat',
    description: 'Chat with the OroAI assistant from the terminal — same agent, tools and RAG as the web widget',
)]
final class ChatCommand extends Command
{
    public function __construct(
        private readonly ChatOrchestrator $orchestrator,
        private readonly OroAiConfig $config,
        private readonly LlmErrorPresenter $errorPresenter,
        private readonly TokenAccessorInterface $tokenAccessor,
        private readonly ConfigManager $configManager,
        private readonly ChatSessionStore $sessionStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'message',
                InputArgument::OPTIONAL,
                'Ask once and exit. Omit to start an interactive session.',
            )
            ->addOption(
                'session',
                's',
                InputOption::VALUE_REQUIRED,
                'Resume a specific session id (same id shown in the web widget / debug transcripts) '
                . 'instead of starting a new conversation.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->config->isConfigured()) {
            $io->error(
                'AI Assistant is not configured. Set the API key in System → Configuration → '
                . 'General Setup → Oro AI Assistant, or via OROAI_API_KEY.'
            );

            return Command::FAILURE;
        }

        $sessionId = $input->getOption('session') ?? $this->newSessionId();

        $this->printBanner($io, $sessionId);

        $message = $input->getArgument('message');
        if ($message !== null) {
            $message = trim($message);
            // Nothing else shows the question in one-shot mode (unlike the REPL,
            // where the Question prompt itself already displayed "you ›").
            $io->writeln("<fg=cyan;options=bold>you ›</> {$message}");

            return $this->ask($io, $output, $message, $sessionId) !== null
                ? Command::SUCCESS
                : Command::FAILURE;
        }

        return $this->runInteractive($io, $output, $input, $sessionId);
    }

    private function runInteractive(SymfonyStyle $io, OutputInterface $output, InputInterface $input, string $sessionId): int
    {
        $questionHelper = $this->getHelper('question');
        // Deliberately NOT reset by /new, /clear or /resume — this tracks real
        // API $ spent for the whole terminal invocation, not the current
        // conversation, which is what a CLI user actually wants to know.
        $sessionCost = 0.0;
        $turns = 0;

        while (true) {
            $question = new Question('<fg=cyan;options=bold>you ›</> ');

            try {
                $message = $questionHelper->ask($input, $output, $question);
            } catch (MissingInputException) {
                // Ctrl+D (EOF) on stdin — QuestionHelper::ask() throws rather than
                // returning null when the Question has no default value; this is
                // the standard, expected way to end an interactive session.
                $io->newLine();
                break;
            }

            // A blank Enter-press (no text typed) returns null here, distinct from
            // the MissingInputException real EOF throws above — both mean "nothing
            // to send", so both fold into the same empty-string continue below.
            $message = trim((string) $message);
            if ($message === '') {
                continue;
            }

            if (str_starts_with($message, '/')) {
                [$cmd, $arg] = array_pad(explode(' ', $message, 2), 2, '');
                $arg = trim($arg);

                if ($cmd === '/exit' || $cmd === '/quit') {
                    break;
                }
                if ($cmd === '/new') {
                    $sessionId = $this->newSessionId();
                    $io->writeln("<comment>New conversation — session {$sessionId}</comment>");
                } elseif ($cmd === '/clear') {
                    $this->clearScreen($output);
                    $sessionId = $this->newSessionId();
                    $this->printBanner($io, $sessionId);
                } elseif ($cmd === '/sessions') {
                    $this->printSessions($io);
                } elseif ($cmd === '/resume') {
                    $sessionId = $this->resumeSession($io, $arg, $sessionId);
                } elseif ($cmd === '/id') {
                    $io->writeln("<comment>session: {$sessionId}</comment>");
                } elseif ($cmd === '/help') {
                    $this->printHelp($io);
                } else {
                    $io->warning("Unknown command \"{$cmd}\" — try /help.");
                }
                continue;
            }

            $outcome = $this->ask($io, $output, $message, $sessionId);
            if ($outcome !== null) {
                $sessionCost += $outcome->cost['total'] ?? 0.0;
                $turns++;
            }
        }

        $io->writeln($turns > 0
            ? sprintf('<comment>Goodbye — %d turn(s), session cost ≈$%s.</comment>', $turns, number_format($sessionCost, 4))
            : '<comment>Goodbye.</comment>');

        return Command::SUCCESS;
    }

    private function printHelp(SymfonyStyle $io): void
    {
        $io->table([], [
            ['/new', 'start a fresh conversation (new session id)'],
            ['/clear', 'clear the screen and start a fresh conversation'],
            ['/sessions', 'list recent conversations — the same list the web widget shows'],
            ['/resume <id>', 'switch to a different conversation by session id'],
            ['/id', 'print the current session id'],
            ['/exit, /quit', 'leave the chat (Ctrl+D also works)'],
        ]);
    }

    /** Clears the terminal — the CLI counterpart of the widget's Clear button wiping the message pane. */
    private function clearScreen(OutputInterface $output): void
    {
        if ($output->isDecorated()) {
            $output->write("\033[2J\033[H");
        }
    }

    /**
     * Same list ChatController::sessionsAction() returns to the widget's
     * "Recent chats" panel — most recent first, scoped to the current
     * --current-user (empty, silently, without one; see ChatSessionStore).
     */
    private function printSessions(SymfonyStyle $io): void
    {
        $sessions = $this->sessionStore->getSessions();
        if ($sessions === []) {
            $io->writeln($this->tokenAccessor->getUser() === null
                ? '<comment>No recent chats — pass --current-user=<username> to enable Recent chats.</comment>'
                : '<comment>No recent chats yet.</comment>');

            return;
        }

        $io->table(['session', 'title', 'messages'], array_map(
            static fn(array $s) => [$s['id'], $s['title'], (string) $s['count']],
            $sessions,
        ));
    }

    /**
     * Switches the active session id — ChatOrchestrator loads that session's
     * history on the very next turn, so nothing further is needed here to
     * actually "resume" it. Warns (but still switches) on an id with no
     * saved messages, since that's almost always a typo'd or expired id
     * rather than a deliberate choice, and silently proceeding would just
     * look like the assistant forgot everything.
     */
    private function resumeSession(SymfonyStyle $io, string $id, string $currentSessionId): string
    {
        if ($id === '') {
            $io->warning('Usage: /resume <session-id> — see /sessions for the list.');

            return $currentSessionId;
        }

        $count = count($this->sessionStore->getMessages($id));
        if ($count === 0) {
            $io->warning("No saved messages for session \"{$id}\" — continuing under that id anyway.");
        } else {
            $io->writeln("<comment>Resumed session {$id} ({$count} prior message(s)).</comment>");
        }

        return $id;
    }

    /**
     * Runs one turn through ChatOrchestrator — identical code path to
     * ChatController::messageAction() — and renders the outcome.
     * Returns null on error (already reported to $io), the ChatOutcome
     * on success (a successful reply may still have no cost, e.g. an
     * unpriced model — that's not a failure, so callers must not conflate
     * "no cost" with "errored").
     */
    private function ask(SymfonyStyle $io, OutputInterface $output, string $message, string $sessionId): ?ChatOutcome
    {
        $status = new LiveStatusLine($output);
        $status->start('thinking…');

        try {
            $outcome = $this->orchestrator->handle($message, $sessionId, $status->asCallback());
        } catch (\Throwable $e) {
            $status->clear();
            $io->writeln('<fg=red;options=bold>assistant ›</> <fg=red>' . $this->errorPresenter->humanize($e) . '</>');
            $detail = $this->errorPresenter->detail($e);
            if ($detail !== null && $output->isVerbose()) {
                $io->writeln('<fg=gray>' . $detail . '</>');
            }

            return null;
        }

        $status->clear();
        $this->renderOutcome($io, $outcome);

        return $outcome;
    }

    private function renderOutcome(SymfonyStyle $io, ChatOutcome $outcome): void
    {
        $width = max(40, (int) (getenv('COLUMNS') ?: 100));
        // Wrap the plain text first (correct visible-width math), THEN
        // linkify — an admin path has no internal whitespace so wordwrap()
        // never splits one mid-string, and inserting <href=...> tags before
        // wrapping would make wordwrap() count invisible escape characters
        // as visible width.
        $wrapped = wordwrap($outcome->reply, $width - 10, "\n           ", false);
        $io->writeln('<fg=green;options=bold>assistant ›</> ' . $this->linkify($wrapped));

        if ($outcome->toolTrace !== []) {
            $names = array_map($this->traceToolDisplay(...), $outcome->toolTrace);
            $io->writeln('<fg=gray>  tools: ' . implode(', ', $names) . '</>');
        }

        $io->writeln('<fg=gray>  ' . $this->formatUsageLine($outcome) . '</>');
        $io->newLine();
    }

    /** Mirrors oroai-chat.js's traceToolDisplay(): shows "skill: <name>" instead of the generic "skill" entry. */
    private function traceToolDisplay(array $entry): string
    {
        if ($entry['tool'] === 'skill') {
            $args = json_decode((string) $entry['args'], true);
            if (is_array($args) && isset($args['name'])) {
                return 'skill: ' . $args['name'];
            }
        }

        return $entry['tool'];
    }

    /**
     * Turns bare /admin/... paths in the reply into real clickable terminal
     * hyperlinks (OSC 8, via Symfony Console's <href=...> tag) — the CLI
     * counterpart of the widget's browser-side linkify(), which resolves the
     * same relative paths against the current page's own host. A CLI process
     * has no "current page", so this resolves against Oro's own Application
     * URL setting (System Configuration → General Setup) instead of
     * guessing a host/port — the same rule this bundle's own system prompt
     * gives the model itself for why it never emits a full absolute URL.
     * Terminals that don't support OSC 8 (or non-decorated/piped output)
     * just show the plain path — Symfony's formatter handles that fallback.
     */
    private function linkify(string $text): string
    {
        $baseUrl = rtrim((string) $this->configManager->get('oro_ui.application_url'), '/');
        if ($baseUrl === '') {
            return $text;
        }

        return preg_replace_callback(
            '#/admin/[^\s"\'<>()\[\]`]+#',
            static fn(array $m) => '<href=' . $baseUrl . $m[0] . '>' . $m[0] . '</>',
            $text,
        ) ?? $text;
    }

    /** Terminal counterpart of the widget's token bar — same numbers, same source, plain text. */
    private function formatUsageLine(ChatOutcome $outcome): string
    {
        $parts = [];
        foreach (['system_prompt' => 'prompt', 'guidelines' => 'guidance', 'skills_catalog' => 'skills', 'tools' => 'tools'] as $key => $label) {
            if (($outcome->promptBreakdown[$key] ?? 0) > 0) {
                $parts[] = $label . ' ~' . $this->formatTokenCount($outcome->promptBreakdown[$key]);
            }
        }

        $usage = $outcome->usage;
        if (($usage['completion_tokens'] ?? 0) > 0) {
            $parts[] = 'output ' . $this->formatTokenCount($usage['completion_tokens']);
        }
        if (($usage['thinking_tokens'] ?? 0) > 0) {
            $parts[] = 'thinking ' . $this->formatTokenCount($usage['thinking_tokens']);
        }
        if (($usage['prompt_tokens'] ?? 0) > 0) {
            $parts[] = 'in ' . $this->formatTokenCount($usage['prompt_tokens']);
        }
        if (($outcome->cost['total'] ?? null) !== null) {
            $parts[] = 'cost ≈$' . number_format($outcome->cost['total'], 4);
        }

        return implode(' · ', $parts);
    }

    private function formatTokenCount(int $n): string
    {
        return $n >= 1000 ? number_format($n / 1000, 1) . 'k' : (string) $n;
    }

    private function printBanner(SymfonyStyle $io, string $sessionId): void
    {
        $title = sprintf(' OroAI Assistant · %s (%s) ', $this->config->getProvider(), $this->config->getModel());
        $io->writeln('<bg=blue;fg=white;options=bold>' . str_pad($title, 60) . '</>');

        $user = $this->tokenAccessor->getUser();
        $io->writeln("<fg=gray>session {$sessionId}"
            . ($user !== null
                ? " · running as {$user->getUsername()}"
                : ' · anonymous (history not saved — pass --current-user=<username> to enable)')
            . '</>');
        $io->writeln('<fg=gray>Type a message, /help for commands, /exit or Ctrl+D to leave.</>');
        $io->newLine();
    }

    /** Same shape as the widget's newSessionId(): timestamp + random, sanitized to ChatSessionStore's alphabet. */
    private function newSessionId(): string
    {
        return 'cli' . dechex((int) (microtime(true) * 1000)) . bin2hex(random_bytes(3));
    }
}

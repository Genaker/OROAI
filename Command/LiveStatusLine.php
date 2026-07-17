<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Command;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Terminal counterpart of the web widget's rotating "thinking…" word plus
 * live tool-call checklist (ChatProgressStore / startProgressPolling() in
 * oroai-chat.js). ChatOrchestrator's call is a single blocking PHP call with
 * no timer thread, so unlike the browser this can't animate on its own — it
 * redraws in place (carriage return + clear-line) each time the agent's
 * onProgress callback fires, which is exactly when there is something new
 * worth showing.
 */
final class LiveStatusLine
{
    private const array SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private int $frame = 0;

    public function __construct(
        private readonly OutputInterface $output,
    ) {
    }

    public function start(string $message): void
    {
        $this->render($message);
    }

    /** @return callable(array): void suitable for ChatOrchestrator::handle()'s $onProgress */
    public function asCallback(): callable
    {
        return function (array $step): void {
            $this->render(self::describe($step));
        };
    }

    /** Erases the status line, leaving the cursor at the start of an empty line. */
    public function clear(): void
    {
        if (!$this->output->isDecorated()) {
            return;
        }

        $this->output->write("\r\033[2K");
    }

    private function render(string $text): void
    {
        $spinner = self::SPINNER_FRAMES[$this->frame % count(self::SPINNER_FRAMES)];
        $this->frame++;

        if (!$this->output->isDecorated()) {
            // Non-TTY (piped/redirected) output: one line per step, no carriage-return tricks.
            $this->output->writeln("[{$spinner}] {$text}");

            return;
        }

        $this->output->write("\r\033[2K<fg=yellow>{$spinner}</> <fg=gray>{$text}</>");
    }

    /** Pure step→text mapping, kept separate from rendering so it's unit-testable without a real Output. */
    public static function describe(array $step): string
    {
        return match ($step['type'] ?? null) {
            'tool_call' => 'running ' . self::toolLabel($step),
            'tool_result' => (($step['success'] ?? true) ? 'done ' : 'failed ') . ($step['tool'] ?? 'tool'),
            'harness_attempt' => sprintf('attempt %d/%d', $step['attempt'] ?? 1, $step['max'] ?? 1),
            'evaluating' => 'checking the answer…',
            default => 'thinking…',
        };
    }

    /** Same "skill: <name>" special-case as oroai-chat.js's traceToolDisplay(). */
    private static function toolLabel(array $step): string
    {
        $tool = $step['tool'] ?? 'tool';
        if ($tool === 'skill' && isset($step['args']['name'])) {
            return 'skill: ' . $step['args']['name'];
        }

        return $tool;
    }
}

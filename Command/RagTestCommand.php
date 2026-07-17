<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Command;

use Genaker\Bundle\OroAI\Rag\RagStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'genaker:oroai:rag:test',
    description: 'Search the RAG index with a text query and show matching documents with scores',
)]
/** Console command to test the RAG index by running a similarity search and displaying results. */
final class RagTestCommand extends Command
{
    public function __construct(
        private readonly RagStoreInterface $ragStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'query',
                InputArgument::REQUIRED,
                'Text to search for in the RAG index',
            )
            ->addOption(
                'top',
                'k',
                InputOption::VALUE_REQUIRED,
                'Number of top results to return',
                '5',
            )
            ->addOption(
                'full',
                null,
                InputOption::VALUE_NONE,
                'Show full document text (default: truncated to 300 chars)',
            );
    }

    /**
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $query = $input->getArgument('query');
        $topK  = max(1, (int) $input->getOption('top'));
        $full  = (bool) $input->getOption('full');

        $io->title('RAG Debug — Query: "' . $query . '"');
        $io->text([
            '  Provider : ' . (getenv('OROAI_PROVIDER') ?: $_SERVER['OROAI_PROVIDER'] ?? '(config)'),
            '  Top-K    : ' . $topK,
        ]);
        $io->newLine();

        $io->text('Embedding query…');
        $start = microtime(true);

        try {
            $hits = $this->ragStore->search($query, $topK);
        } catch (\Throwable $e) {
            $io->error('RAG search failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $elapsed = round((microtime(true) - $start) * 1000);
        $io->text(sprintf(
            'Search completed in <info>%d ms</info> — <info>%d</info> hit(s) found.',
            $elapsed,
            count($hits),
        ));
        $io->newLine();

        if ($hits === []) {
            $io->warning(
                'No documents matched. Try reindexing: genaker:oroai:rag:reindex --provider=docs --provider=config'
            );

            return Command::SUCCESS;
        }

        foreach ($hits as $rank => $hit) {
            $similarity = 1.0 - $hit->score;   // COSINE distance → similarity
            $bar        = $this->scoreBar($similarity);
            $text       = $full ? $hit->text : $this->truncate($hit->text, 300);

            $io->section(sprintf('#%d  source: %s', $rank + 1, $hit->source));
            $output->writeln(sprintf(
                '  Score (cosine distance): <comment>%.6f</comment>  |  Similarity: <info>%.1f%%</info>  %s',
                $hit->score,
                $similarity * 100,
                $bar,
            ));
            $io->newLine();
            $output->writeln('<fg=gray>' . $this->indent($text) . '</>');
            $io->newLine();
        }

        return Command::SUCCESS;
    }

    private function scoreBar(float $similarity): string
    {
        $filled = (int) round($similarity * 20);
        return '[' . str_repeat('█', $filled) . str_repeat('░', 20 - $filled) . ']';
    }

    private function truncate(string $text, int $maxLen): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;

        return mb_strlen($text) > $maxLen
            ? mb_substr($text, 0, $maxLen) . '…'
            : $text;
    }

    private function indent(string $text): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => '  ' . $line,
            explode("\n", $text),
        ));
    }
}

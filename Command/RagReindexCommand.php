<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Command;

use Genaker\Bundle\OroAI\Rag\Contract\RagProviderInterface;
use Genaker\Bundle\OroAI\Rag\RagIndexer;
use Genaker\Bundle\OroAI\Rag\RagStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'genaker:oroai:rag:reindex',
    description: 'Reindex the OroAI RAG knowledge base (docs, schema, menu, config, and any registered providers)',
)]
final class RagReindexCommand extends Command
{
    /** @param iterable<RagProviderInterface> $providers */
    public function __construct(
        private readonly RagIndexer $indexer,
        private readonly RagStoreInterface $ragStore,
        private readonly iterable $providers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'provider',
                'p',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Run only the named provider(s). Omit to run all. Available: docs, schema, menu, config',
            )
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List registered providers and exit')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear the RAG index before reindexing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Build provider map: name → provider
        $providerMap = [];
        foreach ($this->providers as $provider) {
            $providerMap[$provider->getName()] = $provider;
        }

        if ($input->getOption('list')) {
            $io->title('Registered RAG providers');
            $rows = [];
            foreach ($providerMap as $name => $provider) {
                $rows[] = [$name, $provider->getDescription()];
            }
            $io->table(['Name', 'Description'], $rows);

            return Command::SUCCESS;
        }

        $requested = $input->getOption('provider');
        $toRun = $requested !== []
            ? array_filter($providerMap, static fn($name) => in_array($name, $requested, true), ARRAY_FILTER_USE_KEY)
            : $providerMap;

        if ($toRun === []) {
            $available = implode(', ', array_keys($providerMap));
            $io->error(sprintf('No matching providers found. Requested: %s. Available: %s', implode(', ', $requested), $available));

            return Command::FAILURE;
        }

        if ($input->getOption('clear')) {
            $io->info('Clearing RAG index...');
            try {
                $this->ragStore->clear();
                $io->success('Index cleared.');
            } catch (\Throwable $e) {
                $io->warning('Could not clear index: ' . $e->getMessage());
            }
        }

        $total = 0;

        foreach ($toRun as $name => $provider) {
            $io->info(sprintf('[%s] %s', $name, $provider->getDescription()));
            try {
                $count = $this->indexer->indexFromProvider($provider);
                $total += $count;
                $io->success(sprintf('[%s] Indexed %d documents.', $name, $count));
            } catch (\Throwable $e) {
                $io->error(sprintf('[%s] Failed: %s', $name, $e->getMessage()));
            }
        }

        $io->success(sprintf('RAG reindex complete. Total: %d documents indexed.', $total));

        return Command::SUCCESS;
    }
}

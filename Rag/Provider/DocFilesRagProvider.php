<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag\Provider;

use Genaker\Bundle\OroAI\Rag\Contract\RagProviderInterface;
use Genaker\Bundle\OroAI\Rag\RagDocument;
use Genaker\Bundle\OroAI\Rag\TextChunker;
use Oro\Component\Config\CumulativeResourceManager;

/**
 * Indexes Markdown files from every registered bundle's Resources/rag/
 * directory (e.g. src/Genaker/Bundle/OroAI/Resources/rag/,
 * src/Egerdau/Bundle/AnyBundle/Resources/rag/, ...), not just one
 * hardcoded bundle. Any bundle can contribute docs to the RAG index simply
 * by dropping Markdown files into its own Resources/rag/ folder.
 *
 * Uses Oro's own CumulativeResourceManager to enumerate bundle directories —
 * the same mechanism Oro's Resources/config/oro/*.yml and Resources/views
 * cross-bundle overrides rely on — instead of hand-rolling kernel bundle
 * discovery.
 */
final class DocFilesRagProvider implements RagProviderInterface
{
    public function getName(): string
    {
        return 'docs';
    }

    public function getDescription(): string
    {
        return "Markdown documentation files from every bundle's Resources/rag/ directory";
    }

    public function provide(): array
    {
        $documents = [];

        foreach ($this->findMarkdownFiles() as ['file' => $file, 'bundle' => $bundleName]) {
            $content = file_get_contents($file);
            if ($content === false || trim($content) === '') {
                continue;
            }

            $basename = basename($file);
            $source = $bundleName . '/' . $basename;
            $chunks = TextChunker::chunk($content);

            foreach ($chunks as $index => $chunk) {
                $documents[] = new RagDocument(
                    id: md5($file . ':' . $index),
                    text: $chunk,
                    source: $source,
                    metadata: ['file' => $file, 'bundle' => $bundleName, 'chunk_index' => $index],
                );
            }
        }

        return $documents;
    }

    /**
     * @return list<array{file: string, bundle: string}>
     */
    private function findMarkdownFiles(): array
    {
        $manager = CumulativeResourceManager::getInstance();
        $files = [];

        foreach ($manager->getBundles() as $bundleName => $bundleClass) {
            $ragDir = rtrim($manager->getBundleDir($bundleClass), '/') . '/Resources/rag';
            $found = glob($ragDir . '/*.md');
            if ($found === false) {
                continue;
            }

            foreach ($found as $file) {
                $files[] = ['file' => $file, 'bundle' => $bundleName];
            }
        }

        usort($files, static fn (array $a, array $b): int => $a['file'] <=> $b['file']);

        return $files;
    }
}

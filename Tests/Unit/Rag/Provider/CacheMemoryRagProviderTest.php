<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Rag\Provider;

use Genaker\Bundle\OroAI\Rag\Provider\CacheMemoryRagProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CacheMemoryRagProvider.
 *
 * Each test gets its own isolated temp directory so there is no cross-test
 * pollution from files left on disk.  The directory and all its files are
 * cleaned up in tearDown().
 */
final class CacheMemoryRagProviderTest extends TestCase
{
    private string $memoryDir;

    protected function setUp(): void
    {
        $this->memoryDir = sys_get_temp_dir() . '/oroai_cache_memory_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->memoryDir)) {
            foreach (glob($this->memoryDir . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->memoryDir);
        }
    }

    private function makeProvider(): CacheMemoryRagProvider
    {
        return new CacheMemoryRagProvider($this->memoryDir);
    }

    private function writeMemoryFile(string $name, string $content): string
    {
        if (!is_dir($this->memoryDir)) {
            mkdir($this->memoryDir, 0755, true);
        }
        $path = $this->memoryDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    // ── Metadata ───────────────────────────────────────────────────────────

    public function testGetNameReturnsCacheMemory(): void
    {
        self::assertSame('cache_memory', $this->makeProvider()->getName());
    }

    public function testGetDescriptionMentionsMemory(): void
    {
        $desc = $this->makeProvider()->getDescription();
        self::assertStringContainsString('memory', strtolower($desc));
    }

    // ── Empty / missing directory ──────────────────────────────────────────

    public function testProvideReturnsEmptyArrayWhenDirectoryDoesNotExist(): void
    {
        $provider = new CacheMemoryRagProvider('/nonexistent/path/oroai_memory_xyz');

        self::assertSame([], $provider->provide());
    }

    public function testProvideReturnsEmptyArrayForEmptyDirectory(): void
    {
        mkdir($this->memoryDir, 0755, true);

        self::assertSame([], $this->makeProvider()->provide());
    }

    // ── Single file ────────────────────────────────────────────────────────

    public function testProvideIndexesSingleMarkdownFile(): void
    {
        $this->writeMemoryFile('2026-01-01_00-00-00_test-question.md', "# Q: test\n\nThe answer.");

        $docs = $this->makeProvider()->provide();

        self::assertNotEmpty($docs);
        self::assertStringContainsString('test', $docs[0]->text);
    }

    public function testDocumentSourcePrefixedWithMemorySlash(): void
    {
        $this->writeMemoryFile('2026-01-01_12-00-00_how-many-orders.md', "# Q: How many orders?\n\n42 orders.");

        $docs = $this->makeProvider()->provide();

        self::assertSame('memory/2026-01-01_12-00-00_how-many-orders.md', $docs[0]->source);
    }

    // ── Multiple files ─────────────────────────────────────────────────────

    public function testProvideIndexesAllMarkdownFiles(): void
    {
        $this->writeMemoryFile('2026-01-01_first.md', "# Q: first\n\nAnswer 1.");
        $this->writeMemoryFile('2026-01-02_second.md', "# Q: second\n\nAnswer 2.");
        $this->writeMemoryFile('2026-01-03_third.md', "# Q: third\n\nAnswer 3.");

        $docs = $this->makeProvider()->provide();
        $sources = array_unique(array_map(static fn ($d) => $d->source, $docs));

        self::assertCount(3, $sources);
    }

    // ── Empty / non-markdown files are skipped ────────────────────────────

    public function testBlankMarkdownFilesAreSkipped(): void
    {
        $this->writeMemoryFile('2026-01-01_blank.md', '   ');
        $this->writeMemoryFile('2026-01-01_real.md', '# Q: real question\n\nGood answer.');

        $docs = $this->makeProvider()->provide();
        $sources = array_map(static fn ($d) => $d->source, $docs);

        self::assertNotContains('memory/2026-01-01_blank.md', $sources);
        self::assertContains('memory/2026-01-01_real.md', $sources);
    }

    public function testNonMarkdownFilesAreNotIndexed(): void
    {
        if (!is_dir($this->memoryDir)) {
            mkdir($this->memoryDir, 0755, true);
        }
        file_put_contents($this->memoryDir . '/notes.txt', 'some text');
        file_put_contents($this->memoryDir . '/data.json', '{"key":"value"}');
        $this->writeMemoryFile('2026-01-01_answer.md', '# Q: real\n\nAnswer.');

        $docs = $this->makeProvider()->provide();
        foreach ($docs as $doc) {
            self::assertStringEndsWith('.md', basename($doc->source));
        }
    }

    // ── Document IDs ──────────────────────────────────────────────────────

    public function testDocumentIdsAreUniqueAcrossFiles(): void
    {
        $this->writeMemoryFile('2026-01-01_file-a.md', "# Q: first\n\nAnswer A.");
        $this->writeMemoryFile('2026-01-02_file-b.md', "# Q: second\n\nAnswer B.");

        $docs = $this->makeProvider()->provide();
        $ids = array_map(static fn ($d) => $d->id, $docs);

        self::assertCount(count($ids), array_unique($ids), 'Document IDs must be unique across files.');
    }

    public function testDocumentIdsAreDeterministicForSameContent(): void
    {
        $this->writeMemoryFile('2026-01-01_stable.md', "# Q: stable\n\nSame answer.");

        $docs1 = $this->makeProvider()->provide();
        $docs2 = $this->makeProvider()->provide();

        self::assertSame($docs1[0]->id, $docs2[0]->id, 'IDs must be deterministic for the same file.');
    }

    // ── Metadata ──────────────────────────────────────────────────────────

    public function testDocumentMetadataContainsFilePathAndChunkIndex(): void
    {
        $this->writeMemoryFile('2026-07-07_what-is-sku.md', "# Q: What is SKU?\n\nSKU = Stock Keeping Unit.");

        $docs = $this->makeProvider()->provide();

        self::assertArrayHasKey('file', $docs[0]->metadata);
        self::assertArrayHasKey('chunk_index', $docs[0]->metadata);
        self::assertStringContainsString('what-is-sku', $docs[0]->metadata['file']);
        self::assertSame(0, $docs[0]->metadata['chunk_index']);
    }
}

<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Rag\Provider;

use Genaker\Bundle\OroAI\Rag\Provider\DocFilesRagProvider;
use Genaker\Bundle\OroAI\Tests\Unit\Rag\Provider\Fixtures\BundleWithMultipleDocs\FixtureBundleWithMultipleDocs;
use Genaker\Bundle\OroAI\Tests\Unit\Rag\Provider\Fixtures\BundleWithOneDoc\FixtureBundleWithOneDoc;
use Genaker\Bundle\OroAI\Tests\Unit\Rag\Provider\Fixtures\BundleWithoutRagDir\FixtureBundleWithoutRagDir;
use Oro\Component\Config\CumulativeResourceManager;
use PHPUnit\Framework\TestCase;

/**
 * DocFilesRagProvider enumerates every bundle registered in
 * CumulativeResourceManager (the same singleton Oro's own cross-bundle
 * Resources/config/oro/*.yml and Resources/views loading relies on), so
 * these tests point that singleton at fixture "bundle" classes under
 * Fixtures/ instead of the real bundle list, then restore the original
 * bundles afterwards so later tests in the same process see real state.
 */
final class DocFilesRagProviderTest extends TestCase
{
    private array $originalBundles;

    protected function setUp(): void
    {
        // Snapshot (not clear()) so any already-cached real bundle list --
        // and the initializer's one-shot semantics -- survive this test.
        $this->originalBundles = CumulativeResourceManager::getInstance()->getBundles();
    }

    protected function tearDown(): void
    {
        CumulativeResourceManager::getInstance()->setBundles($this->originalBundles);
    }

    private function useFixtureBundles(): void
    {
        CumulativeResourceManager::getInstance()->setBundles([
            'FixtureBundleWithMultipleDocs' => FixtureBundleWithMultipleDocs::class,
            'FixtureBundleWithOneDoc' => FixtureBundleWithOneDoc::class,
            'FixtureBundleWithoutRagDir' => FixtureBundleWithoutRagDir::class,
        ]);
    }

    public function testGetName(): void
    {
        self::assertSame('docs', (new DocFilesRagProvider())->getName());
    }

    public function testGetDescriptionMentionsEveryBundle(): void
    {
        $description = (new DocFilesRagProvider())->getDescription();

        self::assertStringContainsString('every', $description);
        self::assertStringContainsString('Resources/rag', $description);
    }

    public function testProvideAggregatesMarkdownFilesAcrossBundles(): void
    {
        $this->useFixtureBundles();

        $documents = (new DocFilesRagProvider())->provide();

        $sources = array_map(static fn ($doc) => $doc->source, $documents);

        self::assertSame(
            [
                'FixtureBundleWithMultipleDocs/alpha.md',
                'FixtureBundleWithMultipleDocs/readme.md',
                'FixtureBundleWithOneDoc/readme.md',
            ],
            $sources,
            'Docs from every bundle with a Resources/rag/ directory must be present, sorted by file path.'
        );
    }

    public function testProvideDoesNotDropFilesWhenABundleHasMoreThanOneDoc(): void
    {
        $this->useFixtureBundles();

        $documents = (new DocFilesRagProvider())->provide();

        $fromMultiDocBundle = array_filter(
            $documents,
            static fn ($doc) => $doc->metadata['bundle'] === 'FixtureBundleWithMultipleDocs'
        );

        // Regression guard: an earlier implementation keyed an intermediate
        // array by bundle name, so array_flip() silently kept only the last
        // file per bundle whenever a bundle had more than one Markdown doc.
        self::assertCount(2, $fromMultiDocBundle);
    }

    public function testProvideSkipsNonMarkdownAndBlankFiles(): void
    {
        $this->useFixtureBundles();

        $documents = (new DocFilesRagProvider())->provide();

        foreach ($documents as $doc) {
            self::assertStringEndsNotWith('ignored.txt', $doc->source, 'Non-.md files must not be indexed.');
            self::assertStringEndsNotWith('empty.md', $doc->source, 'Blank files must be skipped.');
        }
    }

    public function testProvideSkipsBundlesWithoutARagDirectory(): void
    {
        $this->useFixtureBundles();

        $documents = (new DocFilesRagProvider())->provide();

        foreach ($documents as $doc) {
            self::assertNotSame('FixtureBundleWithoutRagDir', $doc->metadata['bundle']);
        }
    }

    public function testProvideIdsAreUniqueAcrossBundlesEvenWithSameBasename(): void
    {
        $this->useFixtureBundles();

        $documents = (new DocFilesRagProvider())->provide();

        // Both fixture bundles ship a readme.md -- ids must be derived from
        // the full file path, not just the basename, or they'd collide.
        $ids = array_map(static fn ($doc) => $doc->id, $documents);

        self::assertCount(count($ids), array_unique($ids));
    }

    public function testProvideMetadataIncludesFileAndBundle(): void
    {
        $this->useFixtureBundles();

        $documents = (new DocFilesRagProvider())->provide();
        $alpha = current(array_filter($documents, static fn ($doc) => $doc->source === 'FixtureBundleWithMultipleDocs/alpha.md'));

        self::assertNotFalse($alpha);
        self::assertSame('FixtureBundleWithMultipleDocs', $alpha->metadata['bundle']);
        self::assertStringEndsWith('alpha.md', $alpha->metadata['file']);
        self::assertSame(0, $alpha->metadata['chunk_index']);
        self::assertStringContainsString('Alpha document', $alpha->text);
    }

    public function testProvideReturnsEmptyArrayWhenNoBundleHasRagDocs(): void
    {
        CumulativeResourceManager::getInstance()->setBundles([
            'FixtureBundleWithoutRagDir' => FixtureBundleWithoutRagDir::class,
        ]);

        self::assertSame([], (new DocFilesRagProvider())->provide());
    }
}

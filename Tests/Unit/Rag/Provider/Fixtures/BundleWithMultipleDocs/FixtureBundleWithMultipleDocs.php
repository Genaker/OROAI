<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Rag\Provider\Fixtures\BundleWithMultipleDocs;

/**
 * A stand-in "bundle class" for DocFilesRagProviderTest — its only purpose
 * is to give CumulativeResourceManager::getBundleDir() a real, reflectable
 * class file whose directory contains a Resources/rag/ fixture folder with
 * more than one Markdown file (guards against the array_flip-by-bundle-name
 * bug that silently dropped all but one file per bundle).
 */
final class FixtureBundleWithMultipleDocs
{
}

<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tests\Unit\Rag\Provider\Fixtures\BundleWithoutRagDir;

/**
 * A stand-in "bundle class" for DocFilesRagProviderTest that deliberately
 * has NO Resources/rag/ directory at all — most real bundles fall into
 * this category, and the provider must skip them without error.
 */
final class FixtureBundleWithoutRagDir
{
}

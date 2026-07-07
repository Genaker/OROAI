<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\DependencyInjection;

use Oro\Bundle\ConfigBundle\DependencyInjection\SettingsBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class GenakerOroAIExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this->getConfiguration($configs, $container), $configs);
        $container->prependExtensionConfig($this->getAlias(), SettingsBuilder::getSettings($config));

        $container->setParameter('genaker_oroai.redis_default', 'redis://redis:6379');

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__) . '/Resources/config'));
        $loader->load('services.yml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('monolog', [
            'channels' => ['oroai'],
            'handlers' => [
                'oroai_file' => [
                    'type' => 'stream',
                    'path' => '%kernel.logs_dir%/oroai.log',
                    'level' => 'debug',
                    'channels' => ['oroai'],
                ],
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'genaker_oro_ai';
    }
}

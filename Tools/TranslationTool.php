<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TranslationTool implements AiToolInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getName(): string
    {
        return 'translation_lookup';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'translation_lookup',
            'Look up OroCommerce translation keys to find label text or resolve a label back to its translation key. Useful for understanding what a UI element says or finding the right key to customize.',
            [
                'type' => 'object',
                'properties' => [
                    'key' => [
                        'type' => 'string',
                        'description' => 'Translation key to look up (e.g. "oro.order.entity_label", "oro.customer.customeruser.entity_label").',
                    ],
                    'locale' => [
                        'type' => 'string',
                        'description' => 'Locale code (default "en").',
                    ],
                ],
                'required' => ['key'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $key = trim($arguments['key'] ?? '');
        $locale = $arguments['locale'] ?? 'en';

        if ($key === '') {
            return ToolResult::error('Parameter "key" is required.');
        }

        $translation = $this->translator->trans($key, [], null, $locale);
        $isMissing = $translation === $key;

        return ToolResult::success([
            'key' => $key,
            'translation' => $translation,
            'locale' => $locale,
            'found' => !$isMissing,
        ]);
    }
}

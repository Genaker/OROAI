<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Tools;

use Genaker\Bundle\OroAI\Agent\SkillProvider;
use Genaker\Bundle\OroAI\Core\Contract\AiToolInterface;
use Genaker\Bundle\OroAI\Core\Model\ToolDefinition;
use Genaker\Bundle\OroAI\Core\Model\ToolResult;

/**
 * AI tool that loads a named SKILL — a full step-by-step procedure declared in
 * YAML/Markdown by any bundle (see SkillProvider). The tool's own definition
 * doubles as the skill CATALOG: every skill's one-line trigger description is
 * rendered into the system prompt, while the full body costs tokens only when
 * the model actually invokes the skill.
 */
final class SkillTool implements AiToolInterface
{
    public function __construct(
        private readonly SkillProvider $skillProvider,
    ) {
    }

    public function getName(): string
    {
        return 'skill';
    }

    public function getDefinition(): ToolDefinition
    {
        return new ToolDefinition(
            'skill',
            $this->buildCatalogDescription(),
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'The skill key to load, exactly as listed in the tool description.',
                    ],
                ],
                'required' => ['name'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('Parameter "name" is required.');
        }

        $skill = $this->skillProvider->getSkill($name);
        if ($skill === null) {
            $available = array_keys($this->skillProvider->getSkills());

            return ToolResult::error(sprintf(
                'Unknown skill "%s". Available skills: %s',
                $name,
                $available === [] ? '(none registered)' : implode(', ', $available),
            ));
        }

        return ToolResult::success([
            'skill' => $name,
            'instructions' => $skill['body'],
            'note' => 'Follow these instructions step by step to complete the task.',
        ]);
    }

    /**
     * The catalog: one line per skill ("key — when to use it"), so triggers are
     * always visible to the model at minimal token cost.
     */
    private function buildCatalogDescription(): string
    {
        $skills = $this->skillProvider->getSkills();

        if ($skills === []) {
            return 'Load step-by-step instructions (a "skill") for a known procedure. '
                . 'No skills are currently registered.';
        }

        $lines = [];
        foreach ($skills as $key => $skill) {
            $lines[] = sprintf('- %s: %s', $key, $skill['description']);
        }

        return "Load the full step-by-step instructions for a named skill and follow them. "
            . "Call this BEFORE attempting a task that matches one of these skills:\n"
            . implode("\n", $lines);
    }
}

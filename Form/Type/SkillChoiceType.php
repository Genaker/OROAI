<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Form\Type;

use Genaker\Bundle\OroAI\Agent\SkillProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Checkbox list of every registered agent skill (including currently disabled
 * ones — the list is built from SkillProvider::getAllSkills(), otherwise a
 * disabled skill would vanish from the form and could never be re-enabled).
 * The TICKED skills are stored as the genaker_oro_ai.disabled_skills array,
 * so skills added later by any bundle default to enabled.
 */
class SkillChoiceType extends AbstractType
{
    public function __construct(private readonly SkillProvider $skillProvider)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices'  => $this->buildChoices(),
            'multiple' => true,
            'expanded' => true,
            'required' => false,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'genaker_oroai_skill_choice';
    }

    /** @return array<string, string> "key — trigger" => key */
    private function buildChoices(): array
    {
        $choices = [];

        foreach ($this->skillProvider->getAllSkills() as $key => $skill) {
            $label = sprintf('%s — %s', $key, $skill['description']);
            $choices[$label] = $key;
        }

        return $choices;
    }
}

<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Yaml\Yaml;

/** Symfony form type that renders a grouped choice list of available AI models from a YAML file. */
class AiModelChoiceType extends AbstractType
{
    public function __construct(private readonly string $modelsFile)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices'     => $this->buildChoices(),
            'placeholder' => '— provider default —',
            'required'    => false,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'genaker_oroai_model_choice';
    }

    private function buildChoices(): array
    {
        $data = Yaml::parseFile($this->modelsFile);
        $choices = [];

        foreach ($data['models'] ?? [] as $group => $models) {
            $label = ucfirst($group);
            foreach ($models as $model) {
                $choices[$label][$model['label']] = $model['value'];
            }
        }

        return $choices;
    }
}

<?php

declare(strict_types=1);

namespace App\Admin\Form\Type;

use App\Admin\Form\DataTransformer\JsonArrayToStringTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class JsonTextareaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new JsonArrayToStringTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'empty_data' => '',
            'invalid_message' => 'فرمت JSON نامعتبر است.',
        ]);
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }
}

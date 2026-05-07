<?php

declare(strict_types=1);

namespace App\Admin\Form\Type;

use App\Admin\UI\Crud\Support\JsonFieldFormatter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class JsonTextareaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (mixed $value): string => JsonFieldFormatter::format($value),
            static function (mixed $value): ?array {
                $text = trim((string) $value);
                if ('' === $text) {
                    return null;
                }

                $decoded = json_decode($text, true);
                if (JSON_ERROR_NONE !== json_last_error()) {
                    throw new TransformationFailedException('فرمت JSON نامعتبر است.');
                }
                if (!is_array($decoded)) {
                    throw new TransformationFailedException('JSON باید یک آبجکت یا آرایه باشد.');
                }

                return $decoded;
            }
        ));
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

<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

final class JsonTextareaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static function (mixed $value): string {
                if (!is_array($value) || [] === $value) {
                    return '';
                }

                return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            },
            static function (mixed $value): array {
                $json = trim((string) $value);
                if ('' === $json) {
                    return [];
                }

                try {
                    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new TransformationFailedException('Invalid JSON.', 0, $e);
                }

                if (!is_array($decoded)) {
                    throw new TransformationFailedException('JSON value must decode to an object or associative array.');
                }

                return $decoded;
            }
        ));
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }
}

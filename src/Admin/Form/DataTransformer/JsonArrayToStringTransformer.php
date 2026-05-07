<?php

declare(strict_types=1);

namespace App\Admin\Form\DataTransformer;

use App\Admin\UI\Crud\Support\JsonFieldFormatter;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

final class JsonArrayToStringTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        return JsonFieldFormatter::format($value);
    }

    public function reverseTransform(mixed $value): ?array
    {
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
}

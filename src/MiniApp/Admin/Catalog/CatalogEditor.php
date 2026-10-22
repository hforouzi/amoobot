<?php

declare(strict_types=1);

namespace App\MiniApp\Admin\Catalog;

use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppLocale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The save flow of the Mini App catalog forms (plans, trial plans, discount codes):
 *  1. the input is put onto the entity (CatalogInput) and checked against the entity's rules (the catalog admin
 *     service's violations): any problem is answered 422 with errors by field, for inline display;
 *  2. otherwise the save runs through AdminActions (confirm step when asked, rate limit, Idempotency-Key);
 *  3. an existing record is reloaded afterwards whatever happened, so input that was refused, waits for its
 *     confirmation or was a repeat is never flushed by a later write (the audit log flushes).
 * check() is the live validation: steps 1 and 3, nothing saved.
 */
final class CatalogEditor
{
    public function __construct(
        private readonly AdminActions $actions,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    /**
     * @param object|null                               $existing   the managed record being edited (null: a new one)
     * @param callable(): array<string, string>          $apply      puts the input on the entity; unreadable fields => key
     * @param callable(): array<string, list<string>>   $violations the entity's rules
     * @param callable(): ?string                        $confirm    the text to confirm, or null (asked after the input is valid)
     * @param callable(): array<string, mixed>           $save       saves and audits; the answer
     */
    public function submit(Request $request, MiniAppUser $user, int $target, ?object $existing, callable $apply, callable $violations, callable $confirm, callable $save): JsonResponse
    {
        try {
            $errors = $this->errors($request, $apply, $violations);
            if ([] !== $errors) {
                return $this->invalid($request, $errors);
            }

            return $this->actions->run($request, $user, $target, $confirm(), $save);
        } finally {
            $this->revert($existing);
        }
    }

    /**
     * @param callable(): array<string, string>        $apply
     * @param callable(): array<string, list<string>> $violations
     * @param callable(): list<string>                 $warnings   advice that never blocks saving (price plausibility)
     */
    public function check(Request $request, ?object $existing, callable $apply, callable $violations, callable $warnings): JsonResponse
    {
        try {
            $errors = $this->errors($request, $apply, $violations);

            return AdminActions::json(['errors' => (object) $errors, 'warnings' => [] === $errors ? $warnings() : []]);
        } finally {
            $this->revert($existing);
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    public function invalid(Request $request, array $errors): JsonResponse
    {
        return AdminActions::json([
            'error' => 'invalid',
            'message' => $this->translator->trans('admin.form.invalid', [], 'miniapp', MiniAppLocale::fromRequest($request)),
            'errors' => $errors,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * What the audit records about an edit: the names of the changed fields, and old -> new for prices and the
     * active flag (not secrets; any other value is left out).
     *
     * @param array<string, scalar|null> $before
     * @param array<string, scalar|null> $after
     * @param list<string>               $withValues
     *
     * @return array{changed: list<string>, values?: array<string, array{scalar|null, scalar|null}>}
     */
    public static function diff(array $before, array $after, array $withValues): array
    {
        $changed = [];
        $values = [];
        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) === $value) {
                continue;
            }
            $changed[] = $field;
            if (in_array($field, $withValues, true)) {
                $values[$field] = [$before[$field] ?? null, $value];
            }
        }

        return [] === $values ? ['changed' => $changed] : ['changed' => $changed, 'values' => $values];
    }

    /**
     * @param callable(): array<string, string>        $apply
     * @param callable(): array<string, list<string>> $violations
     *
     * @return array<string, list<string>>
     */
    private function errors(Request $request, callable $apply, callable $violations): array
    {
        $locale = MiniAppLocale::fromRequest($request);
        $errors = [];
        foreach ($apply() as $field => $key) {
            $errors[$field] = [$this->translator->trans($key, [], 'miniapp', $locale)];
        }
        if ([] !== $errors) {
            return $errors;
        }
        // The validator translates the entity's messages in the current locale: the request's (?lang=en).
        $this->localeSwitcher->setLocale($locale);

        return $violations();
    }

    private function revert(?object $existing): void
    {
        if (null !== $existing && $this->entityManager->contains($existing)) {
            $this->entityManager->refresh($existing);
        }
    }
}

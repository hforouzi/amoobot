<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Admin\Application\CustomerSearch;
use App\Entity\TelegramAccount;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: customers ("admin.c"), read-only (CUSTOMERS_VIEW): paged list and search by Telegram id, @username, name
 * or mobile; each opens the existing customer view (admin_user_view). Callbacks: l:<page>, s (search prompt).
 */
final class AdminCustomersHandler implements AdminArea
{
    private const SEARCH = 'customer_search';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminScreen $screen,
        private readonly CustomerSearch $customerSearch,
    ) {
    }

    public function area(): string
    {
        return 'c';
    }

    public function permissions(string $verb): array
    {
        return in_array($verb, ['l', 's'], true) ? [AdminPermission::CUSTOMERS_VIEW] : [];
    }

    public function handle(AdminRequest $request): void
    {
        if ('s' === $request->verb) {
            $this->screen->prompt($request, self::SEARCH, '', $this->screen->text('admin.customers.search_prompt'));

            return;
        }

        $repository = $this->entityManager->getRepository(TelegramAccount::class);
        [$page, $pages] = $this->screen->page($request->intArg(0), $repository->count([]));
        /** @var list<TelegramAccount> $accounts */
        $accounts = $repository->findBy([], ['id' => 'DESC'], AdminScreen::PAGE_SIZE, $page * AdminScreen::PAGE_SIZE);

        $this->show($request, $this->screen->text('admin.customers.title', ['page_info' => $this->screen->pageInfo($page, $pages)]), $accounts, $this->screen->pagerRow($page, $pages, 'admin.c:l:%d'));
    }

    public function promptPermission(string $kind): ?AdminPermission
    {
        return self::SEARCH === $kind ? AdminPermission::CUSTOMERS_VIEW : null;
    }

    public function answer(AdminRequest $request, string $kind, string $target, string $text): void
    {
        $query = trim($text);
        if (!CustomerSearch::isValidQuery($query)) {
            $this->screen->send($request, $this->screen->text('admin.customers.search_prompt'));

            return;
        }
        $this->screen->endPrompt($request);

        $accounts = $this->customerSearch->search($query, AdminScreen::PAGE_SIZE);

        $title = [] === $accounts
            ? $this->screen->text('admin.customers.search_empty', ['query' => $query])
            : $this->screen->text('admin.customers.search_results', ['query' => $query]);
        $this->show($request, $title, $accounts, []);
    }

    /**
     * @param list<TelegramAccount>                            $accounts
     * @param list<array{text: string, callback_data: string}> $pager
     */
    private function show(AdminRequest $request, string $title, array $accounts, array $pager): void
    {
        $lines = [$title];
        $rows = [];
        foreach ($accounts as $account) {
            $user = $account->getUser();
            $lines[] = $this->screen->text('admin.customers.line', [
                'id' => (int) $user->getId(),
                'name' => trim(($account->getFirstName() ?? '').' '.($account->getLastName() ?? '')) ?: ($user->getName() ?? '-'),
                'username' => $account->getUsername() ?: '-',
                'telegram_id' => $account->getTelegramId(),
                'mobile' => $user->getMobile() ?? '-',
            ]);
            $rows[] = [$this->screen->rawButton('👤 #'.$user->getId().' '.($account->getUsername() ? '@'.$account->getUsername() : $account->getTelegramId()), 'admin_user_view:'.$user->getId())];
        }
        if ([] === $accounts) {
            $lines[] = $this->screen->text('admin.list_empty');
        }
        $rows[] = [$this->screen->button('button.admin.search', 'admin.c:s')];

        $this->screen->send($request, implode("\n\n", $lines), $this->screen->listKeyboard($rows, $pager));
    }
}

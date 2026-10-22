<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\Support\AdminFormat;
use App\Entity\Order;
use App\Entity\User;
use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Customers: one page per shop customer (User) with their Telegram account, language, and how many services and
 * orders they have, linking to those lists and to the Telegram account. Replaces the separate Users and Telegram
 * accounts lists (/admin/user/... and the Telegram account list redirect here; Telegram account detail pages stay).
 *
 * Search: "#12" is the customer id, "@name" a Telegram username; anything else matches name, mobile, Telegram
 * username, Telegram id or (a bare number) the customer id.
 */
#[AdminRoute(path: '/customer', name: 'customer')]
class UserCrudController extends AbstractCrudController
{
    private const FORMATTED = 'admin/field/formatted.html.twig';

    /** @var array<class-string, array<int, int>> */
    private array $counts = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly AdminFormat $format,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInPlural('admin.customers')
            ->setDefaultSort(['id' => 'DESC'])
            // Searched in createIndexQueryBuilder(); a non-empty list keeps EasyAdmin's search box.
            ->setSearchFields(['name'])
            ->setHelp(Crud::PAGE_INDEX, 'customer.search_help');
    }

    public function configureActions(Actions $actions): Actions
    {
        // The customer's wallet (WALLETS_VIEW; an empty one is created on first open).
        $wallet = Action::new('customerWallet', 'admin.wallets', 'fa fa-money')
            ->linkToUrl(fn (User $user): string => $this->generateUrl('admin_wallet_customer', ['id' => $user->getId()]));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $wallet)
            ->setPermission('customerWallet', AdminPermission::WALLETS_VIEW->value);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $withoutQuery = new SearchDto($searchDto->getRequest(), $searchDto->getSearchableProperties(), '', $searchDto->getDefaultSort(), $searchDto->getCustomSort(), $searchDto->getAppliedFilters(), $searchDto->getSearchMode());
        $qb = parent::createIndexQueryBuilder($withoutQuery, $entityDto, $fields, $filters)
            ->leftJoin('entity.telegramAccount', 'customerTelegram')->addSelect('customerTelegram');

        $query = $searchDto->getQuery();
        if ('' === $query) {
            return $qb;
        }

        if (str_starts_with($query, '#')) {
            return $qb->andWhere('entity.id = :customerId')->setParameter('customerId', (int) substr($query, 1));
        }
        if (str_starts_with($query, '@')) {
            return $qb->andWhere('customerTelegram.username LIKE :customerUsername')->setParameter('customerUsername', self::like(substr($query, 1)).'%');
        }

        $match = $qb->expr()->orX(
            'entity.name LIKE :customerLike',
            'entity.mobile LIKE :customerLike',
            'customerTelegram.username LIKE :customerLike',
            'customerTelegram.telegramId = :customerExact',
        );
        $qb->setParameter('customerLike', '%'.self::like($query).'%')->setParameter('customerExact', $query);
        if (ctype_digit($query)) {
            $match->add('entity.id = :customerId');
            $qb->setParameter('customerId', (int) $query);
        }

        return $qb->andWhere($match);
    }

    /**
     * List: who (name, mobile), their Telegram account (@username, id, language), services, orders, status, joined.
     */
    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('name', 'customer.name')->setTemplatePath(self::FORMATTED)->formatValue(static function (mixed $value, ?User $user): string {
                $name = '' !== (string) $user?->getName() ? self::e((string) $user?->getName()) : AdminFormat::NONE;
                $mobile = (string) $user?->getMobile();

                return '' === $mobile ? $name : sprintf('%s<br><small class="text-muted" dir="ltr">%s</small>', $name, self::e($mobile));
            }),
            TextField::new('mobile', 'customer.mobile')->hideOnIndex(),
            Field::new('telegram', 'customer.telegram_account')
                ->setTemplatePath(self::FORMATTED)
                ->formatValue(function (mixed $value, ?User $user): string {
                    $account = $user?->getTelegramAccount();
                    if (null === $account) {
                        return AdminFormat::NONE;
                    }
                    $username = (string) $account->getUsername();
                    $id = $this->link($this->generateUrl('admin_telegram_account_detail', ['entityId' => $account->getId()]), '<bdi>'.self::e($account->getTelegramId()).'</bdi>', TelegramAccountCrudController::class, 'customer.telegram_account');
                    $language = (string) $account->getLanguageCode();

                    return sprintf(
                        '%s<small class="text-muted"> · %s%s</small>',
                        '' === $username ? '' : '<bdi>'.self::e('@'.$username).'</bdi><br>',
                        $id,
                        '' === $language ? '' : ' · <span title="'.self::e($this->translator->trans('customer.language', [], 'admin')).'">'.self::e($language).'</span>',
                    );
                })
                ->setSortable(false)
                ->hideOnForm(),
            Field::new('servicesCount', 'customer.services')
                ->setTemplatePath(self::FORMATTED)
                ->formatValue(fn (mixed $value, ?User $user): string => null === $user ? '' : $this->countLink($user, 'services'))
                ->setSortable(false)
                ->hideOnForm(),
            Field::new('ordersCount', 'customer.orders')
                ->setTemplatePath(self::FORMATTED)
                ->formatValue(fn (mixed $value, ?User $user): string => null === $user ? '' : $this->countLink($user, 'orders'))
                ->setSortable(false)
                ->hideOnForm(),
            TextField::new('status', 'customer.status')->setTemplatePath(self::FORMATTED)->formatValue(fn (mixed $v): string => $this->format->status($v)),
            DateTimeField::new('createdAt', 'customer.joined_at')->hideOnForm(),
            DateTimeField::new('updatedAt', 'common.updated_at')->hideOnForm()->hideOnIndex(),
        ];
    }

    /**
     * The number of services (not deleted, as the service list shows them) or orders, linked to that list filtered on
     * this customer when the admin may open it.
     */
    private function countLink(User $user, string $what): string
    {
        [$entity, $route, $controller] = 'services' === $what
            ? [VpnService::class, 'admin_vpn_service_index', VpnServiceCrudController::class]
            : [Order::class, 'admin_order_index', OrderCrudController::class];

        $count = sprintf('<span data-customer-count="%s">%d</span>', $what, $this->counts($entity)[(int) $user->getId()] ?? 0);
        $url = $this->generateUrl($route, ['filters' => ['user' => ['comparison' => '=', 'value' => (string) $user->getId()]]]);

        return $this->link($url, $count, $controller, 'customer.'.$what);
    }

    /**
     * Services (not deleted) or orders per customer, for every customer of this page at once: the customers the list
     * query loaded (Doctrine's identity map), one GROUP BY query per kind instead of one per row.
     *
     * @param class-string $entity
     *
     * @return array<int, int>
     */
    private function counts(string $entity): array
    {
        if (isset($this->counts[$entity])) {
            return $this->counts[$entity];
        }
        $ids = array_map('intval', array_keys($this->entityManager->getUnitOfWork()->getIdentityMap()[User::class] ?? []));
        $this->counts[$entity] = [];
        if ([] === $ids) {
            return [];
        }
        $qb = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(x.user) AS customer', 'COUNT(x.id) AS total')
            ->from($entity, 'x')
            ->andWhere('x.user IN (:customers)')
            ->setParameter('customers', $ids)
            ->groupBy('x.user');
        if (VpnService::class === $entity) {
            $qb->andWhere('x.status != :deleted')->setParameter('deleted', VpnServiceStatus::DELETED);
        }
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $this->counts[$entity][(int) $row['customer']] = (int) $row['total'];
        }

        return $this->counts[$entity];
    }

    /**
     * $html linked to $url when the admin may open $controller's pages (else just $html).
     *
     * @param class-string $controller
     */
    private function link(string $url, string $html, string $controller, string $title): string
    {
        if (!$this->isGranted(AdminCrudPermissions::view($controller))) {
            return $html;
        }

        return sprintf('<a href="%s" title="%s">%s</a>', self::e($url), self::e($this->translator->trans($title, [], 'admin')), $html);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function like(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}

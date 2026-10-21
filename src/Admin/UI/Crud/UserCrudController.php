<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Security\AdminCrudPermissions;
use App\Entity\Order;
use App\Entity\User;
use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
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

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
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
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $withoutQuery = new SearchDto($searchDto->getRequest(), $searchDto->getSearchableProperties(), '', $searchDto->getDefaultSort(), $searchDto->getCustomSort(), $searchDto->getAppliedFilters(), $searchDto->getSearchMode());
        $qb = parent::createIndexQueryBuilder($withoutQuery, $entityDto, $fields, $filters)
            ->leftJoin('entity.telegramAccount', 'customerTelegram');

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

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('name', 'customer.name'),
            TextField::new('mobile', 'customer.mobile'),
            Field::new('telegramUsername', 'customer.telegram_username')
                ->setTemplatePath(self::FORMATTED)
                ->formatValue(static function (mixed $value, ?User $user): string {
                    $username = $user?->getTelegramAccount()?->getUsername();

                    return null === $username || '' === $username ? '' : self::e('@'.$username);
                })
                ->setSortable(false)
                ->hideOnForm(),
            Field::new('telegramAccountId', 'customer.telegram_id')
                ->setTemplatePath(self::FORMATTED)
                ->formatValue(function (mixed $value, ?User $user): string {
                    $account = $user?->getTelegramAccount();
                    if (null === $account) {
                        return '';
                    }

                    return $this->link($this->generateUrl('admin_telegram_account_detail', ['entityId' => $account->getId()]), self::e($account->getTelegramId()), TelegramAccountCrudController::class, 'customer.telegram_account');
                })
                ->setSortable(false)
                ->hideOnForm(),
            Field::new('language', 'customer.language')
                ->setTemplatePath(self::FORMATTED)
                ->formatValue(static fn (mixed $value, ?User $user): string => self::e((string) $user?->getTelegramAccount()?->getLanguageCode()))
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
            TextField::new('status', 'customer.status'),
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

        $qb = $this->entityManager->createQueryBuilder()->select('COUNT(x.id)')->from($entity, 'x')->andWhere('x.user = :user')->setParameter('user', $user);
        if (VpnService::class === $entity) {
            $qb->andWhere('x.status != :deleted')->setParameter('deleted', VpnServiceStatus::DELETED);
        }
        $count = sprintf('<span data-customer-count="%s">%d</span>', $what, (int) $qb->getQuery()->getSingleScalarResult());
        $url = $this->generateUrl($route, ['filters' => ['user' => ['comparison' => '=', 'value' => (string) $user->getId()]]]);

        return $this->link($url, $count, $controller, 'customer.'.$what);
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

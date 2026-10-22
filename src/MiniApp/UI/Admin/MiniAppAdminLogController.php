<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Admin\UI\Support\AdminJsonFormatter;
use App\Bot\Domain\BotMessageDirection;
use App\Entity\AdminAuditLog;
use App\Entity\BotMessageLog;
use App\Entity\ServiceNotificationLog;
use App\MiniApp\Admin\AdminLists;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Logs in the Mini App admin (LOGS_VIEW, the super admin's, as on the web), read only and without export: the admin
 * audit log (filters: admin, action, source web / bot / cli / miniapp, date range, text), the notification log and
 * the bot message log (text search, direction, date range), a page at a time, newest first. An entry's JSON is shown
 * through AdminJsonFormatter (escaped, secret keys masked, size capped), as on the web; the lists show no payload.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminLogController extends AbstractController
{
    public const TABS = ['audit', 'notifications', 'messages'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MiniAppFragments $fragments,
    ) {
    }

    #[Route('/app/admin/logs', name: 'miniapp_admin_logs', defaults: ['_miniapp_section' => 'admin.logs'], methods: ['GET'])]
    public function list(Request $request): Response
    {
        $tab = in_array($request->query->getString('tab'), self::TABS, true) ? $request->query->getString('tab') : 'audit';
        $filters = $this->filters($request, $tab);
        $qb = match ($tab) {
            'audit' => $this->audit($filters),
            'notifications' => $this->notifications($filters),
            default => $this->messages($filters),
        };
        $page = AdminQuery::page($request);
        $items = $qb->setFirstResult($page * AdminLists::PAGE_SIZE)->setMaxResults(AdminLists::PAGE_SIZE + 1)->getQuery()->getResult();

        return $this->fragments->render($request, 'miniapp/admin/logs.html.twig', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'filters' => $filters,
            'params' => ['tab' => $tab] + array_filter($filters, static fn (?string $v): bool => null !== $v),
            'list' => ['items' => array_slice($items, 0, AdminLists::PAGE_SIZE), 'page' => $page, 'hasNext' => count($items) > AdminLists::PAGE_SIZE],
            'admins' => 'audit' === $tab ? $this->entityManager->createQueryBuilder()->select('DISTINCT a.actorLabel')->from(AdminAuditLog::class, 'a')->orderBy('a.actorLabel')->setMaxResults(100)->getQuery()->getSingleColumnResult() : [],
            'sources' => AdminAuditLog::SOURCES,
            'directions' => [BotMessageDirection::INCOMING, BotMessageDirection::OUTGOING],
        ]);
    }

    #[Route('/app/admin/logs/{kind}/{id}', name: 'miniapp_admin_log_entry', requirements: ['kind' => 'audit|notifications|messages', 'id' => '\d+'], defaults: ['_miniapp_section' => 'admin.logs'], methods: ['GET'])]
    public function entry(Request $request, string $kind, int $id): Response
    {
        $entry = $this->entityManager->find(match ($kind) {
            'audit' => AdminAuditLog::class,
            'notifications' => ServiceNotificationLog::class,
            default => BotMessageLog::class,
        }, $id) ?? throw new NotFoundHttpException();
        $payload = match (true) {
            $entry instanceof AdminAuditLog => $entry->getDetails(),
            $entry instanceof ServiceNotificationLog => $entry->getPayload(),
            default => $entry->getPayload(),
        };

        return $this->fragments->render($request, 'miniapp/admin/log_entry.html.twig', [
            'kind' => $kind,
            'entry' => $entry,
            'json' => AdminJsonFormatter::toPrettyHtml($payload),
        ]);
    }

    /**
     * The tab's filters from the query; an unknown value is left out.
     *
     * @return array<string, string|null>
     */
    private function filters(Request $request, string $tab): array
    {
        $date = static function (string $name) use ($request): ?string {
            $value = $request->query->getString($name);

            return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && false !== \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ? $value : null;
        };
        $filters = ['q' => AdminQuery::text($request), 'from' => $date('from'), 'to' => $date('to')];
        if ('audit' === $tab) {
            $source = $request->query->getString('source');
            $filters['source'] = in_array($source, AdminAuditLog::SOURCES, true) ? $source : null;
            $admin = trim($request->query->getString('admin'));
            $filters['admin'] = '' === $admin || mb_strlen($admin) > 180 ? null : $admin;
            $action = trim($request->query->getString('action'));
            $filters['action'] = 1 === preg_match('/^[a-z0-9_.]{1,64}$/', $action) ? $action : null;
        }
        if ('messages' === $tab) {
            $direction = $request->query->getString('direction');
            $filters['direction'] = in_array($direction, [BotMessageDirection::INCOMING, BotMessageDirection::OUTGOING], true) ? $direction : null;
        }

        return $filters;
    }

    /**
     * @param array<string, string|null> $filters
     */
    private function audit(array $filters): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()->select('a')->from(AdminAuditLog::class, 'a')->orderBy('a.createdAt', 'DESC')->addOrderBy('a.id', 'DESC');
        foreach (['source' => 'a.source', 'admin' => 'a.actorLabel', 'action' => 'a.action'] as $filter => $field) {
            if (null !== $filters[$filter]) {
                $qb->andWhere($field.' = :'.$filter)->setParameter($filter, $filters[$filter]);
            }
        }
        if (null !== $filters['q']) {
            $qb->andWhere('a.action LIKE :q OR a.actorLabel LIKE :q OR a.targetType LIKE :q OR a.targetId LIKE :q')->setParameter('q', self::like($filters['q']));
        }

        return $this->dates($qb, 'a.createdAt', $filters);
    }

    /**
     * @param array<string, string|null> $filters
     */
    private function notifications(array $filters): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()->select('n', 's', 'u')->from(ServiceNotificationLog::class, 'n')
            ->join('n.service', 's')->join('n.user', 'u')->orderBy('n.sentAt', 'DESC')->addOrderBy('n.id', 'DESC');
        if (null !== $filters['q']) {
            $qb->andWhere('n.type LIKE :q OR n.keyName LIKE :q OR s.username LIKE :q')->setParameter('q', self::like($filters['q']));
        }

        return $this->dates($qb, 'n.sentAt', $filters);
    }

    /**
     * @param array<string, string|null> $filters
     */
    private function messages(array $filters): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()->select('m')->from(BotMessageLog::class, 'm')->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC');
        if (null !== $filters['direction']) {
            $qb->andWhere('m.direction = :direction')->setParameter('direction', $filters['direction']);
        }
        if (null !== $filters['q']) {
            $qb->andWhere('m.telegramId = :exact OR m.updateType LIKE :q')->setParameter('exact', $filters['q'])->setParameter('q', self::like($filters['q']));
        }

        return $this->dates($qb, 'm.createdAt', $filters);
    }

    /**
     * @param array<string, string|null> $filters
     */
    private function dates(QueryBuilder $qb, string $field, array $filters): QueryBuilder
    {
        if (null !== $filters['from']) {
            $qb->andWhere($field.' >= :from')->setParameter('from', new \DateTimeImmutable($filters['from'].' 00:00:00'));
        }
        if (null !== $filters['to']) {
            $qb->andWhere($field.' < :to')->setParameter('to', (new \DateTimeImmutable($filters['to'].' 00:00:00'))->modify('+1 day'));
        }

        return $qb;
    }

    private static function like(string $value): string
    {
        return '%'.addcslashes($value, '%_\\').'%';
    }
}

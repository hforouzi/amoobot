<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\Setting;
use App\Shop\Application\SalesSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class SalesSettingsAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SalesSettingsProvider $salesSettingsProvider,
    ) {
    }

    #[Route('/settings/sales', name: 'admin_sales_settings', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $disabledMessage = trim((string) $request->request->get('sales_disabled_message', ''));
            if ('' === $disabledMessage) {
                $this->addFlash('danger', 'پیام غیرفعال بودن فروش نمی‌تواند خالی باشد.');
            } else {
                $this->upsertSetting(SalesSettingsProvider::NEW_ORDERS_ENABLED, $request->request->has('sales_new_orders_enabled') ? 'true' : 'false', 'boolean');
                $this->upsertSetting(SalesSettingsProvider::RENEWALS_ENABLED, $request->request->has('sales_renewals_enabled') ? 'true' : 'false', 'boolean');
                $this->upsertSetting(SalesSettingsProvider::ADD_TRAFFIC_ENABLED, $request->request->has('sales_add_traffic_enabled') ? 'true' : 'false', 'boolean');
                $this->upsertSetting(SalesSettingsProvider::DISABLED_MESSAGE, $disabledMessage, 'text');
                $this->entityManager->flush();
                $this->addFlash('success', 'تنظیمات فروش بروزرسانی شد.');
            }
        }

        return $this->render('admin/sales_settings.html.twig', [
            'values' => [
                'sales_new_orders_enabled' => $this->salesSettingsProvider->newOrdersEnabled(),
                'sales_renewals_enabled' => $this->salesSettingsProvider->renewalsEnabled(),
                'sales_add_traffic_enabled' => $this->salesSettingsProvider->addTrafficEnabled(),
                'sales_disabled_message' => $this->salesSettingsProvider->disabledMessage(),
            ],
            'keys' => [
                'sales_new_orders_enabled' => SalesSettingsProvider::NEW_ORDERS_ENABLED,
                'sales_renewals_enabled' => SalesSettingsProvider::RENEWALS_ENABLED,
                'sales_add_traffic_enabled' => SalesSettingsProvider::ADD_TRAFFIC_ENABLED,
                'sales_disabled_message' => SalesSettingsProvider::DISABLED_MESSAGE,
            ],
        ]);
    }

    private function upsertSetting(string $keyName, string $value, string $type): void
    {
        $setting = $this->entityManager->getRepository(Setting::class)->findOneBy(['keyName' => $keyName]);
        if (!$setting instanceof Setting) {
            $setting = (new Setting())->setKeyName($keyName);
            $this->entityManager->persist($setting);
        }

        $setting
            ->setValue($value)
            ->setType($type)
            ->setUpdatedAt(new \DateTimeImmutable());
    }
}

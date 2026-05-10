<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Payment;
use App\Entity\TelegramAccount;
use App\Entity\VpnService;
use App\Provisioning\Application\VpnAccessLinkGenerator;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiConfigGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:payment:preview-provision-config', description: 'Preview payment provisioning configText/subscriptionUrl without creating a client')]
final class PaymentPreviewProvisionConfigCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Sanaei3xuiConfigGenerator $configGenerator,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('paymentId', InputArgument::REQUIRED, 'Payment id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $paymentId = (int) $input->getArgument('paymentId');
        if ($paymentId <= 0) {
            $io->error('paymentId must be greater than zero.');

            return Command::FAILURE;
        }

        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment instanceof Payment) {
            $io->error('Payment not found.');

            return Command::FAILURE;
        }

        $order = $payment->getOrder();
        $plan = $order->getPlan();
        $inbound = $plan->getInbound();
        $panel = $inbound?->getPanel();
        if (null === $inbound || null === $panel) {
            $io->error('Order plan inbound/panel is not available.');

            return Command::FAILURE;
        }

        $telegram = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $order->getUser()]);
        $telegramId = $telegram?->getTelegramId() ?? (string) $order->getUser()->getId();
        $email = sprintf('tg_%s_order_%d', $telegramId, $order->getId());
        $uuid = Uuid::v4()->toRfc4122();
        $subId = bin2hex(random_bytes(8));

        $configText = $this->configGenerator->generateConfigText($inbound, $uuid, $email, $subId);

        $previewService = (new VpnService())
            ->setUser($order->getUser())
            ->setOrder($order)
            ->setPanel($panel)
            ->setInbound($inbound)
            ->setUsername($email)
            ->setClientEmail($email)
            ->setClientUuid($uuid)
            ->setSubId($subId)
            ->setConfigText($configText);

        $links = $this->vpnAccessLinkGenerator->generate($previewService);
        $subscriptionUrl = trim((string) ($links['subscriptionUrl'] ?? ''));
        $configLinks = array_values(array_filter(
            array_map('trim', explode("\n", $configText)),
            static fn (string $line): bool => '' !== $line
        ));

        $io->section('Preview context');
        $io->listing([
            sprintf('paymentId: %d', $payment->getId() ?? 0),
            sprintf('orderId: %d', $order->getId() ?? 0),
            sprintf('planId: %d', $plan->getId() ?? 0),
            sprintf('inboundId: %d', $inbound->getId() ?? 0),
            sprintf('panelId: %d', $panel->getId() ?? 0),
            sprintf('panelType: %s', (string) ($panel->getType() ?? '')),
            sprintf('uuid: %s', $uuid),
            sprintf('subId: %s', $subId),
            sprintf('generated_config_link_count: %d', count($configLinks)),
            sprintf('subscriptionUrl: %s', '' !== $subscriptionUrl ? $subscriptionUrl : '(none)'),
        ]);

        $io->section('Generated configText');
        if ('' === trim($configText)) {
            $io->warning('No config links generated.');

            return Command::FAILURE;
        }
        $io->writeln($configText);

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:debug-gateway-config', description: 'Print safe diagnostics for a payment gateway config')]
final class PaymentDebugGatewayConfigCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('gatewayId', InputArgument::REQUIRED, 'PaymentGateway id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $gatewayId = (int) $input->getArgument('gatewayId');
        if ($gatewayId <= 0) {
            $io->error('gatewayId must be greater than zero.');

            return Command::FAILURE;
        }

        $gateway = $this->entityManager->getRepository(PaymentGateway::class)->find($gatewayId);
        if (!$gateway instanceof PaymentGateway) {
            $io->error('Gateway not found.');

            return Command::FAILURE;
        }

        $config = is_array($gateway->getConfig()) ? $gateway->getConfig() : [];
        $create = is_array($config['create'] ?? null) ? $config['create'] : [];
        $verify = is_array($config['verify'] ?? null) ? $config['verify'] : [];
        $webhook = is_array($config['webhook'] ?? null) ? $config['webhook'] : [];
        $variables = is_array($config['variables'] ?? null) ? $config['variables'] : [];

        $io->section('Gateway diagnostics');
        $io->listing([
            sprintf('id: %d', $gateway->getId() ?? 0),
            sprintf('title: %s', $gateway->getTitle()),
            sprintf('type: %s', $gateway->getType()),
            sprintf('supported_custom_api: %s', PaymentGatewayType::CUSTOM_API === $gateway->getType() ? 'yes' : 'no'),
            sprintf('create_url: %s', trim((string) ($create['url'] ?? '')) ?: '(empty)'),
            sprintf('verify_url: %s', trim((string) ($verify['url'] ?? '')) ?: '(empty)'),
            sprintf('webhook_enabled: %s', $this->toBool($webhook['enabled'] ?? false) ? 'yes' : 'no'),
            sprintf('variable_keys: %s', implode(', ', $this->variableKeys($variables))),
        ]);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return list<string>
     */
    private function variableKeys(array $variables): array
    {
        $keys = [];
        foreach (array_keys($variables) as $key) {
            $text = trim((string) $key);
            if ('' !== $text) {
                $keys[] = $text;
            }
        }

        return [] === $keys ? ['(none)'] : $keys;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

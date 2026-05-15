<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Entity\StorePaymentMethod;
use App\Payment\Plugin\PluginConfigSchemaValidator;
use App\Plugin\PaymentPluginDoctor;
use App\Plugin\PluginRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:payment:repair-plugin-gateway', description: 'Create or repair a PaymentGateway for a valid payment plugin')]
final class PaymentRepairPluginGatewayCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PluginRegistry $pluginRegistry,
        private readonly PaymentPluginDoctor $doctor,
        private readonly PluginConfigSchemaValidator $schemaValidator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pluginCode', InputArgument::REQUIRED, 'Payment plugin code');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pluginCode = (string) $input->getArgument('pluginCode');
        $plugin = $this->pluginRegistry->findByCode($pluginCode);
        $doctor = $this->doctor->inspect($plugin);

        if (!$doctor->ok() || !$plugin instanceof Plugin) {
            $io->error('Doctor failed: '.$doctor->errorMessage());

            return Command::FAILURE;
        }

        $gateway = $this->entityManager->getRepository(PaymentGateway::class)->findOneBy(['pluginCode' => $pluginCode]);
        if (!$gateway instanceof PaymentGateway) {
            $gateway = (new PaymentGateway())
                ->setType($pluginCode)
                ->setPluginCode($pluginCode)
                ->setTitle($plugin->getDisplayName('en'))
                ->setCurrency('IRR')
                ->setIsActive(true);
            $this->entityManager->persist($gateway);
        }

        $schema = $plugin->getManifest()['configSchema'] ?? [];
        $config = array_replace(
            $this->schemaValidator->defaultConfig($schema),
            is_array($gateway->getConfig()) ? $gateway->getConfig() : []
        );
        $gateway
            ->setType($pluginCode)
            ->setPluginCode($pluginCode)
            ->setConfig($this->withoutEmptyValues($config))
            ->setUpdatedAt(new \DateTimeImmutable());

        $method = $this->entityManager->getRepository(StorePaymentMethod::class)->findOneBy(['gateway' => $gateway]);
        if (!$method instanceof StorePaymentMethod) {
            $method = (new StorePaymentMethod())
                ->setGateway($gateway)
                ->setTitle($gateway->getTitle())
                ->setIsActive(true)
                ->setCurrency($gateway->getCurrency())
                ->setSortOrder($this->nextSortOrder())
                ->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->persist($method);
        }

        $this->entityManager->flush();

        $missing = $this->schemaValidator->missingRequiredKeys($schema, $gateway->getConfig());
        $io->table(['field', 'value'], [
            ['plugin', $pluginCode],
            ['gateway id', (string) ($gateway->getId() ?? '')],
            ['store method id', (string) ($method->getId() ?? '')],
            ['configured', [] === $missing ? 'yes' : 'no'],
            ['missing keys', [] === $missing ? '-' : implode(', ', $missing)],
        ]);
        $io->success('Plugin gateway repair completed.');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function withoutEmptyValues(array $config): array
    {
        $result = [];
        foreach ($config as $key => $value) {
            if (null === $value || (is_string($value) && '' === trim($value))) {
                continue;
            }
            $result[(string) $key] = $value;
        }

        return $result;
    }

    private function nextSortOrder(): int
    {
        $max = $this->entityManager->getRepository(StorePaymentMethod::class)
            ->createQueryBuilder('method')
            ->select('MAX(method.sortOrder)')
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) ($max ?? 0)) + 10;
    }
}

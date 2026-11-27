<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Console\Command;

use PayPlug\SyliusPayPlugPlugin\Gateway\GatewayCredentials;
use PayPlug\SyliusPayPlugPlugin\Gateway\PproPaymentMethods;
use PayPlug\SyliusPayPlugPlugin\Ppro\PproConfigurationRefresherInterface;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'payplug:ppro:refresh', description: 'Store the current PayPlug amount and country limits of every PPRO payment method')]
final class RefreshPproConfigurationCommand extends Command
{
    public function __construct(
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
        private PproConfigurationRefresherInterface $pproConfigurationRefresher,
    ) {
        parent::__construct();
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failures = 0;
        $handled = 0;

        foreach ($this->paymentMethodRepository->findAll() as $paymentMethod) {
            $gatewayConfig = $paymentMethod->getGatewayConfig();
            if (null === $gatewayConfig || !PproPaymentMethods::isPpro($gatewayConfig->getFactoryName())) {
                continue;
            }

            ++$handled;
            if (!GatewayCredentials::isConnected($gatewayConfig->getConfig())) {
                $output->writeln(sprintf('Skipped "%s": not connected to a PayPlug account.', (string) $paymentMethod->getCode()));

                continue;
            }

            if ($this->pproConfigurationRefresher->refresh($paymentMethod)->refreshed) {
                $output->writeln(sprintf('Refreshed the PayPlug limits of "%s".', (string) $paymentMethod->getCode()));

                continue;
            }

            ++$failures;
            $output->writeln(sprintf('<error>Could not refresh the PayPlug limits of "%s"; see the payplug log channel.</error>', (string) $paymentMethod->getCode()));
        }

        if (0 === $handled) {
            $output->writeln('No PPRO payment method found.');
        }

        return 0 === $failures ? Command::SUCCESS : Command::FAILURE;
    }
}

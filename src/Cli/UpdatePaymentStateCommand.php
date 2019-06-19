<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * another great project.
 * You can find more information about us on https://bitbag.shop and write us
 * an email on mikolaj.krol@bitbag.pl.
 */

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Cli;

use PayPlug\SyliusPayPlugPlugin\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use PayPlug\SyliusPayPlugPlugin\Resolver\PaymentStateResolverInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class UpdatePaymentStateCommand extends Command
{
    /** @var PaymentRepositoryInterface */
    private $paymentRepository;

    /** @var PaymentStateResolverInterface */
    private $paymentStateResolver;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        PaymentRepositoryInterface $paymentRepository,
        PaymentStateResolverInterface $paymentStateResolver,
        LoggerInterface $logger
    ) {
        parent::__construct();

        $this->paymentRepository = $paymentRepository;
        $this->paymentStateResolver = $paymentStateResolver;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->setName('payplug:update-payment-state')
            ->setDescription('Updates the payments state.')
            ->setHelp('This command allows you to update the payments state for PayPlug gateway.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        /** @var PaymentInterface[] $payments */
        $payments = $this->paymentRepository->findAllActiveByGatewayFactoryName(PayPlugGatewayFactory::FACTORY_NAME);

        $updatesCount = 0;

        foreach ($payments as $payment) {
            $oldState = $payment->getState();
            $orderNumber = $payment->getOrder()->getNumber();

            try {
                $this->paymentStateResolver->resolve($payment);
            } catch (\Exception $exception) {
                $message = sprintf('An error occurred for the order #%s: %s', $orderNumber, $exception->getMessage());
                $this->logger->error($message);
                $output->writeln($message);
                continue;
            }

            if ($oldState !== $payment->getState()) {
                ++$updatesCount;
                $output->writeln(sprintf('Update payment state for order #%s: %s -> %s', $orderNumber, $oldState, $payment->getState()));
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('Updated: %d', $updatesCount));
    }
}

<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'payplug:capture-authorized-payments', description: 'Capture payplug authorized payments older than X days (default 6)')]
class CaptureAuthorizedPaymentCommand extends Command
{
    public $stateMachineFactory;

    public function __construct(
        // private Factory $stateMachineFactory,
        private PaymentRepositoryInterface $paymentRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Number of days to wait before capturing authorized payments', 6)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = \filter_var($input->getOption('days'), \FILTER_VALIDATE_INT);
        if (false === $days) {
            throw new \InvalidArgumentException('Invalid number of days provided.');
        }

        $payments = $this->paymentRepository->findAllAuthorizedOlderThanDays($days);

        if ($payments === []) {
            $this->logger->debug('[Payplug] No authorized payments found.');
        }

        foreach ($payments as $i => $payment) {
            $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
            $this->logger->info('[Payplug] Capturing payment {paymentId} (order #{orderNumber})', [
                'paymentId' => $payment->getId(),
                'orderNumber' => $payment->getOrder()?->getNumber() ?? 'N/A',
            ]);
            $output->writeln(sprintf('Capturing payment %d (order #%s)', $payment->getId(), $payment->getOrder()?->getNumber() ?? 'N/A'));

            try {
                $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);
            } catch (\Throwable $e) {
                $this->logger->critical('[Payplug] Error while capturing payment {paymentId}', [
                    'paymentId' => $payment->getId(),
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            if ($i % 10 === 0) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        return Command::SUCCESS;
    }
}

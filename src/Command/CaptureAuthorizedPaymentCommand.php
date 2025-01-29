<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentRepositoryInterface;
use Psr\Log\LoggerInterface;
use SM\Factory\Factory;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CaptureAuthorizedPaymentCommand extends Command
{
    private Factory $stateMachineFactory;
    private PaymentRepositoryInterface $paymentRepository;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    public function __construct(
        Factory $stateMachineFactory,
        PaymentRepositoryInterface $paymentRepository,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
    ) {
        $this->stateMachineFactory = $stateMachineFactory;
        $this->paymentRepository = $paymentRepository;
        $this->entityManager = $entityManager;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('payplug:capture-authorized-payments')
            ->setDescription('Capture payplug authorized payments older than X days (default 6)')
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Number of days to wait before capturing authorized payments', 6)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payments = $this->paymentRepository->findAllAuthorizedOlderThanDays($input->getOption('days'));
        if (\count($payments) === 0) {
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

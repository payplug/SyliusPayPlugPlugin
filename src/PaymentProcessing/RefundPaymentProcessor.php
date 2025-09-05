<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use Exception;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\Entity\RefundHistory;
use PayPlug\SyliusPayPlugPlugin\Gateway\AmericanExpressGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\ApplePayGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\BancontactGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\OneyGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Repository\RefundHistoryRepositoryInterface;
use Payum\Core\Model\GatewayConfigInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\Exception\UpdateHandlingException;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPayment;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Autoconfigure(public: true)]
final class RefundPaymentProcessor implements PaymentProcessorInterface
{
    public $payPlugApiClient;

    public function __construct(
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private RepositoryInterface $refundPaymentRepository,
        private RefundHistoryRepositoryInterface $payplugRefundHistoryRepository,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
    ) {
    }

    #[AsCompletedListener(workflow: PaymentTransitions::GRAPH, transition: PaymentTransitions::TRANSITION_REFUND)]
    public function onRefundCompleteTransitionEvent(CompletedEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof PaymentInterface) {
            return;
        }

        $this->process($subject);
    }

    public function process(PaymentInterface $payment): void
    {
        $this->prepare($payment);
        $details = $payment->getDetails();

        try {
            $this->payPlugApiClient->refundPayment($details['payment_id']);
        } catch (Exception $exception) {
            $message = $exception->getMessage();

            $this->logger->error('[PayPlug] RefundHistory Payment', ['error' => $message]);

            throw new UpdateHandlingException();
        }
    }

    public function processWithAmount(PaymentInterface $payment, int $amount, int $refundId): void
    {
        $this->prepare($payment);
        $details = $payment->getDetails();

        try {
            $refund = $this->payPlugApiClient->refundPaymentWithAmount($details['payment_id'], $amount, $refundId);
            $refunds = $details['refunds'] ?? [];
            $refunds[] = [
                'internal_id' => $refundId,
                'id' => $refund->id,
                'amount' => $refund->amount,
                'meta_data' => $refund->metadata,
            ];
            $details['refunds'] = $refunds;
            $payment->setDetails($details);

            /** @var RefundPayment $refundPayment */
            $refundPayment = $this->refundPaymentRepository->findOneBy(['id' => $refundId]);
            $refundHistory = new RefundHistory();
            $refundHistory
                ->setExternalId(null)
                ->setPayment($payment)
                ->setRefundPayment($refundPayment)
                ->setValue($amount)
                ->setProcessed(true)
            ;
            $this->payplugRefundHistoryRepository->add($refundHistory);
        } catch (Exception $exception) {
            $message = $exception->getMessage();

            $this->logger->error('[PayPlug] RefundHistory Payment', ['error' => $message]);

            throw new UpdateHandlingException();
        }
    }

    private function prepare(PaymentInterface $payment): void
    {
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $payment->getMethod();
        $details = $payment->getDetails();

        if (
            !$paymentMethod->getGatewayConfig() instanceof GatewayConfigInterface ||
            !\in_array($factoryName = $paymentMethod->getGatewayConfig()->getFactoryName(), [
                PayPlugGatewayFactory::FACTORY_NAME,
                OneyGatewayFactory::FACTORY_NAME,
                BancontactGatewayFactory::FACTORY_NAME,
                ApplePayGatewayFactory::FACTORY_NAME,
                AmericanExpressGatewayFactory::FACTORY_NAME,
            ], true)
        ) {
            return;
        }

        if (!isset($details['payment_id'])) {
            $this->requestStack->getSession()->getFlashBag()->add(
                'info',
                $this->translator->trans('payplug_sylius_payplug_plugin.ui.payment_refund_locally'),
            );

            return;
        }

        $this->logger->info('[PayPlug] Start refund payment', ['payment_id' => $details['payment_id']]);

        $this->payPlugApiClient = $this->apiClientFactory->createForPaymentMethod($paymentMethod);
    }
}

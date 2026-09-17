<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactoryInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class IntegratedPaymentController extends AbstractController
{
    /**
     * @param RepositoryInterface<\Sylius\Component\Core\Model\PaymentMethodInterface> $paymentMethodRepository
     */
    public function __construct(
        private CartContextInterface $cartContext,
        private RepositoryInterface $paymentMethodRepository,
        private OrderRepositoryInterface $orderRepository,
        private PayPlugPaymentDataCreator $paymentDataCreator,
        private PayPlugApiClientFactoryInterface $apiClientFactory,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The InitPayment action is called when the user clicks on the "Pay" button from the integratedPayment iframe.
     *
     * The actual payment (ie latest in cart state) of the order is sent to Payplug,
     * specifying the IntegratedPayment integration.
     *
     * @see https://docs.payplug.com/api/integratedref.html#trigger-a-payment
     */
    #[Route(path: '/{_locale}/payplug/integrated-payment/init/{paymentMethodId}', name: 'payplug_sylius_integrated_payment_init', methods: ['GET', 'POST'])]
    public function initPaymentAction(Request $request, int $paymentMethodId): Response
    {
        $order = null;
        if (\is_string($orderToken = $request->query->get('orderToken'))) {
            $order = $this->orderRepository->findOneByTokenValue($orderToken);
        }
        if (!$order instanceof \Sylius\Component\Order\Model\OrderInterface) {
            $order = $this->cartContext->getCart();
        }
        if (!$order instanceof OrderInterface) {
            throw $this->createNotFoundException('No order found');
        }

        $payment = $order->getLastPayment();
        if (!$payment instanceof PaymentInterface) {
            throw $this->createNotFoundException('No payment available');
        }

        // The id is shopper-supplied, so it is resolved against the order before anything is
        // done with it — see resolvePaymentMethod().
        $paymentMethod = $this->resolvePaymentMethod($paymentMethodId, $order);
        $payment->setMethod($paymentMethod);

        $paymentData = $this->paymentDataCreator->create($payment);
        // Mandatory
        $paymentData['integration'] = PayPlugApiClientInterface::INTEGRATED_PAYMENT_INTEGRATION;
        $this->logger->debug('Payplug Payment data for creation', $paymentData->getArrayCopy());

        $apiClient = $this->apiClientFactory->createForPaymentMethod($paymentMethod);
        $payplugPayment = $apiClient->createPayment($paymentData->getArrayCopy());
        $this->logger->debug('PayPlug payment created', (array) $payplugPayment);

        $paymentData['payment_id'] = $payplugPayment->id;
        $paymentData['is_live'] = $payplugPayment->is_live;
        $payment->setDetails($paymentData->getArrayCopy());

        $this->entityManager->flush();

        return new JsonResponse([
            'payment_id' => $payplugPayment->id,
        ], Response::HTTP_CREATED);
    }

    /**
     * Resolves the shopper-supplied payment method id, refusing anything the order could not
     * legitimately be paid with.
     *
     * The channel check is the load-bearing one. Since PRE-3628 several CB gateway configs may
     * coexist, one per channel, each connected to a different PayPlug account, and
     * createForPaymentMethod() faithfully resolves whichever account this id names. Without it a
     * shopper checking out on channel A could pass channel B's payment method id and have the
     * payment created on merchant B's account, while the IPN — resolving the same method — verifies
     * against B's key and marks order A paid.
     */
    private function resolvePaymentMethod(int $paymentMethodId, OrderInterface $order): PaymentMethodInterface
    {
        $paymentMethod = $this->paymentMethodRepository->find($paymentMethodId);

        if (!$paymentMethod instanceof PaymentMethodInterface) {
            throw $this->createNotFoundException();
        }

        if (PayPlugGatewayFactory::FACTORY_NAME !== $paymentMethod->getGatewayConfig()?->getFactoryName()) {
            throw new BadRequestHttpException('Unsupported payment method of Integrated Payment');
        }

        if (!$paymentMethod->isEnabled()) {
            throw new BadRequestHttpException('The payment method is not enabled.');
        }

        if (!$this->isAvailableOnChannelOf($paymentMethod, $order)) {
            throw new BadRequestHttpException('The payment method is not available on this channel.');
        }

        return $paymentMethod;
    }

    /**
     * Matched on channel code rather than object identity, the same way
     * GatewayChannelConflictChecker does: the order and the payment method may carry channel
     * instances from different unit-of-work states.
     */
    private function isAvailableOnChannelOf(PaymentMethodInterface $paymentMethod, OrderInterface $order): bool
    {
        $channelCode = $order->getChannel()?->getCode();

        if (null === $channelCode) {
            return false;
        }

        foreach ($paymentMethod->getChannels() as $channel) {
            if ($channelCode === $channel->getCode()) {
                return true;
            }
        }

        return false;
    }
}

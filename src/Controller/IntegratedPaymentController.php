<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
use PayPlug\SyliusPayPlugPlugin\Creator\PayPlugPaymentDataCreator;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class IntegratedPaymentController extends AbstractController
{
    private CartContextInterface $cartContext;
    /**
     * @var RepositoryInterface<\Sylius\Component\Core\Model\PaymentMethodInterface>
     */
    private RepositoryInterface $paymentMethodRepository;
    private PayPlugPaymentDataCreator $paymentDataCreator;
    private PayPlugApiClientFactory $apiClientFactory;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    /**
     * @param RepositoryInterface<\Sylius\Component\Core\Model\PaymentMethodInterface> $paymentMethodRepository
     */
    public function __construct(
        CartContextInterface $cartContext,
        RepositoryInterface $paymentMethodRepository,
        PayPlugPaymentDataCreator $paymentDataCreator,
        PayPlugApiClientFactory $apiClientFactory,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ) {
        $this->cartContext = $cartContext;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->paymentDataCreator = $paymentDataCreator;
        $this->apiClientFactory = $apiClientFactory;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    /**
     * The InitPayment action is called when the user clicks on the "Pay" button from the integratedPayment iframe.
     *
     * The actual payment (ie latest in cart state) of the order is sent to Payplug,
     * specifying the IntegratedPayment integration.
     *
     * @see https://docs.payplug.com/api/integratedref.html#trigger-a-payment
     *
     * @TODO: handle save card checkbox
     */
    public function initPaymentAction(Request $request, int $paymentMethodId): Response
    {
        $paymentMethod = $this->paymentMethodRepository->find($paymentMethodId);
        if (!$paymentMethod instanceof PaymentMethodInterface) {
            throw $this->createNotFoundException();
        }

        $cart = $this->cartContext->getCart();
        $payment = $cart->getLastPayment(PaymentInterface::STATE_CART);
        if (!$payment instanceof PaymentInterface) {
            throw $this->createNotFoundException('No payment available on cart');
        }

        $payment->setMethod($paymentMethod);
        $paymentData = $this->paymentDataCreator->create($payment, $paymentMethod->getGatewayConfig()?->getFactoryName());
        // Mandatory
        $paymentData['integration'] = 'INTEGRATED_PAYMENT';
        $this->logger->debug('Payment data', $paymentData->getArrayCopy());

        $apiClient = $this->apiClientFactory->create($paymentMethod->getGatewayConfig()?->getFactoryName());
        $payplugPayment = $apiClient->createPayment($paymentData->getArrayCopy());
        $this->logger->debug('PayPlug payment', (array) $payplugPayment);

        $paymentData['payment_id'] = $payplugPayment->id;
        $paymentData['is_live'] = $payplugPayment->is_live;
        $payment->setDetails($paymentData->getArrayCopy());

        $this->entityManager->flush();

        return new JsonResponse([
            'payment_id' => $payplugPayment->id,
        ], Response::HTTP_CREATED);
    }
}

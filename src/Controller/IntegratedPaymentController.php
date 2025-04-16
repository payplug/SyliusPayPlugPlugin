<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientFactory;
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

#[AsController]
final class IntegratedPaymentController extends AbstractController
{
    private CartContextInterface $cartContext;
    /**
     * @var RepositoryInterface<\Sylius\Component\Core\Model\PaymentMethodInterface>
     */
    private RepositoryInterface $paymentMethodRepository;
    private OrderRepositoryInterface $orderRepository;
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
        OrderRepositoryInterface $orderRepository,
        PayPlugPaymentDataCreator $paymentDataCreator,
        PayPlugApiClientFactory $apiClientFactory,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ) {
        $this->cartContext = $cartContext;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->orderRepository = $orderRepository;
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
     */
    public function initPaymentAction(Request $request, int $paymentMethodId): Response
    {
        $paymentMethod = $this->paymentMethodRepository->find($paymentMethodId);
        if (!$paymentMethod instanceof PaymentMethodInterface) {
            throw $this->createNotFoundException();
        }

        $order = null;
        if (\is_string($orderToken = $request->query->get('orderToken'))) {
            $order = $this->orderRepository->findOneByTokenValue($orderToken);
        }
        if (null === $order) {
            $order = $this->cartContext->getCart();
        }
        if (!$order instanceof OrderInterface) {
            throw $this->createNotFoundException('No order found');
        }

        $payment = $order->getLastPayment();
        if (!$payment instanceof PaymentInterface) {
            throw $this->createNotFoundException('No payment available');
        }

        $payment->setMethod($paymentMethod);
        $factoryName = $paymentMethod->getGatewayConfig()?->getFactoryName();
        if (PayPlugGatewayFactory::FACTORY_NAME !== $factoryName) {
            throw new BadRequestHttpException('Unsupported payment method of Integrated Payment');
        }

        $paymentData = $this->paymentDataCreator->create($payment, $factoryName);
        // Mandatory
        $paymentData['integration'] = PayPlugApiClientInterface::INTEGRATED_PAYMENT_INTEGRATION;
        $this->logger->debug('Payplug Payment data for creation', $paymentData->getArrayCopy());

        $apiClient = $this->apiClientFactory->create($factoryName);
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
}

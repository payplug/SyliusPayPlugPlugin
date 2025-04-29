<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use PayPlug\SyliusPayPlugPlugin\Exception\Payment\PaymentNotCompletedException;
use PayPlug\SyliusPayPlugPlugin\Provider\Payment\ApplePayPaymentProvider;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\OrderBundle\Controller\OrderController as BaseOrderController;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Resource\Exception\UpdateHandlingException;
use Sylius\Component\Resource\ResourceActions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Service\Attribute\Required;
use Webmozart\Assert\Assert;

#[AsController]
final class OrderController extends BaseOrderController
{
    private const APPLE_ERROR_RESPONSE_CODE = 0;

    private const APPLE_SUCCESS_RESPONSE_CODE = 1;

    #[Required]
    private StateMachineInterface $stateMachineAbstraction;
    #[Required]
    private ApplePayPaymentProvider $applePayPaymentProvider;
    #[Required]
    private LockFactory $lockFactory;
    #[Required]
    private LoggerInterface $logger;

    #[Route(
        path: '/payplug/apple-pay/prepare/{orderId}',
        name: 'payplug_shop_checkout_apple_prepare',
        options: [
            '_sylius' => [
                'flash' => false,
                'repository' => [
                    'method' => 'find',
                    'arguments' => [
                        'expr:service("PayPlug\\SyliusPayPlugPlugin\\Provider\\ApplePayOrderProvider").getCurrentCart()',
                    ],
                ],
            ],
        ],
        methods: ['GET', 'POST'],
    )]
    public function initiateApplePaySessionAction(Request $request): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);
        $this->isGrantedOr403($configuration, ResourceActions::UPDATE);

        /** @var OrderInterface $resource */
        $resource = $this->findOr404($configuration);

        if (OrderPaymentStates::STATE_PAID === $resource->getPaymentState()) {
            throw new AccessDeniedException();
        }

        /** @var ResourceControllerEvent $event */
        $event = $this->eventDispatcher->dispatchPreEvent(ResourceActions::UPDATE, $configuration, $resource);

        if ($event->isStopped() && !$configuration->isHtmlRequest()) {
            throw new HttpException($event->getErrorCode(), $event->getMessage());
        }

        if ($event->isStopped()) {
            $eventResponse = $event->getResponse();
            if (null !== $eventResponse) {
                return $eventResponse;
            }

            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        }

        try {
            $postEvent = $this->eventDispatcher->dispatchPostEvent(ResourceActions::UPDATE, $configuration, $resource);

            $postEventResponse = $postEvent->getResponse();

            if ($postEventResponse instanceof \Symfony\Component\HttpFoundation\Response) {
                return $postEventResponse;
            }

            $initializeEvent = $this->eventDispatcher->dispatchInitializeEvent(ResourceActions::UPDATE, $configuration, $resource);

            $initializeEventResponse = $initializeEvent->getResponse();

            if ($initializeEventResponse instanceof \Symfony\Component\HttpFoundation\Response) {
                return $initializeEventResponse;
            }

            if ($this->stateMachineAbstraction->can($resource, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT)) {
                $this->stateMachineAbstraction->apply($resource, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
            }

            $payment = $this->applePayPaymentProvider->provide($request, $resource);

            $this->manager->flush();

            return new JsonResponse([
                'success' => true,
                'merchant_session' => $payment->getDetails()['merchant_session'],
            ]);
        } catch (UpdateHandlingException $exception) {
            $this->logger->error('Could update ApplePay order', [
                'order_id' => $resource->getId(),
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        } catch (\Exception) {
            try {
                $this->applePayPaymentProvider->cancel($resource);
            } catch (\Throwable $throwable) {
                $this->logger->error('Could not cancel ApplePay payment', [
                    'order_id' => $resource->getId(),
                    'code' => $throwable->getCode(),
                    'message' => $throwable->getMessage(),
                    'trace' => $throwable->getTraceAsString(),
                ]);
            }

            $request->getSession()->getFlashBag()->add('error', 'sylius.payment.cancelled');
            $dataResponse = [];
            $redirect = $this->redirectToRoute('sylius_shop_checkout_select_payment');
            $dataResponse['returnUrl'] = $redirect->getTargetUrl();
            $dataResponse['responseToApple'] = ['status' => self::APPLE_ERROR_RESPONSE_CODE];

            $response = [
                'success' => false,
                'data' => $dataResponse,
            ];

            return new JsonResponse($response, Response::HTTP_OK);
        }
    }

    #[Route(
        path: '/payplug/apple-pay/complete/{orderId}',
        name: 'payplug_shop_checkout_apple_confirm',
        options: [
            '_sylius' => [
                'flash' => false,
                'repository' => [
                    'method' => 'find',
                    'arguments' => [
                        'expr:service("PayPlug\\SyliusPayPlugPlugin\\Provider\\ApplePayOrderProvider").getCurrentCart()',
                    ],
                ],
            ],
        ],
        methods: ['GET', 'POST'],
    )]
    public function confirmApplePayPaymentAction(Request $request): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);

        $this->isGrantedOr403($configuration, ResourceActions::UPDATE);

        /** @var OrderInterface $resource */
        $resource = $this->findOr404($configuration);

        /** @var ResourceControllerEvent $event */
        $event = $this->eventDispatcher->dispatchPreEvent(ResourceActions::UPDATE, $configuration, $resource);

        if ($event->isStopped() && !$configuration->isHtmlRequest()) {
            throw new HttpException($event->getErrorCode(), $event->getMessage());
        }

        if ($event->isStopped()) {
            $eventResponse = $event->getResponse();
            if (null !== $eventResponse) {
                return $eventResponse;
            }

            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        }

        try {
            $lastPayment = $this->applePayPaymentProvider->patch($request, $resource);

            if (PaymentInterface::STATE_COMPLETED !== $lastPayment->getState()) {
                throw new PaymentNotCompletedException();
            }
        } catch (\Exception | PaymentNotCompletedException $exception) {
            try {
                $this->applePayPaymentProvider->cancel($resource);
            } catch (\Throwable $throwable) {
                $this->logger->error('Could not cancel ApplePay payment', [
                    'order_id' => $resource->getId(),
                    'code' => $throwable->getCode(),
                    'message' => $throwable->getMessage(),
                    'trace' => $throwable->getTraceAsString(),
                ]);
            }

            $request->getSession()->getFlashBag()->add('error', 'sylius.payment.cancelled');
            $redirect = $this->redirectToRoute('sylius_shop_checkout_select_payment');
            $dataResponse = [];
            $dataResponse['returnUrl'] = $redirect->getTargetUrl();
            $dataResponse['responseToApple'] = ['status' => self::APPLE_ERROR_RESPONSE_CODE];
            $dataResponse['errors'] = 'Payment not created';
            $dataResponse['message'] = $exception->getMessage();

            return new JsonResponse($dataResponse, Response::HTTP_BAD_REQUEST);
        }

        $this->manager->flush();

        $postEvent = $this->eventDispatcher->dispatchPostEvent(ResourceActions::UPDATE, $configuration, $resource);

        $postEventResponse = $postEvent->getResponse();

        if ($postEventResponse instanceof \Symfony\Component\HttpFoundation\Response) {
            return $postEventResponse;
        }

        $initializeEvent = $this->eventDispatcher->dispatchInitializeEvent(ResourceActions::UPDATE, $configuration, $resource);

        $initializeEventResponse = $initializeEvent->getResponse();

        if ($initializeEventResponse instanceof \Symfony\Component\HttpFoundation\Response) {
            return $initializeEventResponse;
        }

        $order = $lastPayment->getOrder();
        Assert::isInstanceOf($order, OrderInterface::class);

        if ($this->stateMachineAbstraction->can($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE)) {
            $this->stateMachineAbstraction->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        }

        $this->manager->flush();

        $request->getSession()->set('sylius_order_id', $order->getId());
        $dataResponse = [];
        $redirect = $this->redirectToRoute('sylius_shop_order_thank_you', ['_locale' => $order->getLocaleCode()]);
        $dataResponse['returnUrl'] = $redirect->getTargetUrl();
        $dataResponse['responseToApple'] = ['status' => self::APPLE_SUCCESS_RESPONSE_CODE];

        $response = [
            'success' => true,
            'data' => $dataResponse,
        ];

        return new JsonResponse($response, Response::HTTP_OK);
    }

    #[Route(
        path: '/payplug/apple-pay/cancel/{orderId}',
        name: 'payplug_shop_checkout_apple_cancel',
        options: [
            '_sylius' => [
                'flash' => false,
                'repository' => [
                    'method' => 'find',
                    'arguments' => [
                        'expr:service("PayPlug\\SyliusPayPlugPlugin\\Provider\\ApplePayOrderProvider").getCurrentCart()',
                    ],
                ],
            ],
        ],
        methods: ['GET', 'POST'],
    )]
    public function cancelApplePaySessionAction(Request $request): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);

        $this->isGrantedOr403($configuration, ResourceActions::UPDATE);

        /** @var OrderInterface $resource */
        $resource = $this->findOr404($configuration);

        $lock = $this->lockFactory->createLock('apple_pay_cancel' . $resource->getId());
        $lock->acquire(true);

        /** @var ResourceControllerEvent $event */
        $event = $this->eventDispatcher->dispatchPreEvent(ResourceActions::UPDATE, $configuration, $resource);

        if ($event->isStopped() && !$configuration->isHtmlRequest()) {
            throw new HttpException($event->getErrorCode(), $event->getMessage());
        }

        if ($event->isStopped()) {
            $eventResponse = $event->getResponse();
            if (null !== $eventResponse) {
                return $eventResponse;
            }

            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->resourceUpdateHandler->handle($resource, $configuration, $this->manager);

            $postEvent = $this->eventDispatcher->dispatchPostEvent(ResourceActions::UPDATE, $configuration, $resource);

            $postEventResponse = $postEvent->getResponse();

            if ($postEventResponse instanceof \Symfony\Component\HttpFoundation\Response) {
                return $postEventResponse;
            }

            $initializeEvent = $this->eventDispatcher->dispatchInitializeEvent(ResourceActions::UPDATE, $configuration, $resource);

            $initializeEventResponse = $initializeEvent->getResponse();

            if ($initializeEventResponse instanceof \Symfony\Component\HttpFoundation\Response) {
                return $initializeEventResponse;
            }

            try {
                $this->applePayPaymentProvider->cancel($resource);
            } catch (\Throwable $throwable) {
                $this->logger->error('Could not cancel ApplePay payment', [
                    'order_id' => $resource->getId(),
                    'code' => $throwable->getCode(),
                    'message' => $throwable->getMessage(),
                    'trace' => $throwable->getTraceAsString(),
                ]);
            }

            if ($this->stateMachineAbstraction->can($resource, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING)) {
                $this->stateMachineAbstraction->apply($resource, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
            }
            $this->manager->flush();

            $request->getSession()->getFlashBag()->add('error', 'sylius.payment.cancelled');

            $dataResponse = [];
            $redirect = $this->redirectToRoute('sylius_shop_checkout_select_payment', ['_locale' => $resource->getLocaleCode()]);

            if (OrderCheckoutStates::STATE_COMPLETED === $resource->getCheckoutState()) {
                $redirect = $this->redirectToRoute('sylius_shop_order_show', [
                    'tokenValue' => $resource->getTokenValue(),
                    '_locale' => $resource->getLocaleCode(),
                ]);
            }

            $dataResponse['returnUrl'] = $redirect->getTargetUrl();
            $dataResponse['responseToApple'] = ['status' => self::APPLE_SUCCESS_RESPONSE_CODE];

            $response = [
                'success' => true,
                'data' => $dataResponse,
            ];

            $lock->release();

            return new JsonResponse($response);
        } catch (UpdateHandlingException $exception) {
            $this->logger->error('Could update ApplePay order', [
                'order_id' => $resource->getId(),
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $exception) {
            $this->logger->error('Could not cancel ApplePay payment', [
                'order_id' => $resource->getId(),
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            $request->getSession()->getFlashBag()->add('error', 'sylius.payment.cancelled');

            $dataResponse = [];

            $redirect = $this->redirectToRoute('sylius_shop_checkout_select_payment');

            if (OrderInterface::STATE_NEW === $resource->getState()) {
                $redirect = $this->redirectToRoute('sylius_shop_order_show', [
                    'tokenValue' => $resource->getTokenValue(),
                    '_locale' => $resource->getLocaleCode(),
                ]);
            }

            $dataResponse['returnUrl'] = $redirect->getTargetUrl();
            $dataResponse['responseToApple'] = ['status' => self::APPLE_ERROR_RESPONSE_CODE];

            $response = [
                'success' => false,
                'data' => $dataResponse,
            ];

            return new JsonResponse($response, Response::HTTP_OK);
        }
    }
}

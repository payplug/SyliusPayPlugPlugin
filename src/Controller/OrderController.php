<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use PayPlug\SyliusPayPlugPlugin\Exception\Payment\PaymentNotCompletedException;
use PayPlug\SyliusPayPlugPlugin\Provider\Payment\ApplePayPaymentProvider;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\OrderBundle\Controller\OrderController as BaseOrderController;
use Sylius\Bundle\ResourceBundle\Controller\AuthorizationCheckerInterface;
use Sylius\Bundle\ResourceBundle\Controller\EventDispatcherInterface;
use Sylius\Bundle\ResourceBundle\Controller\FlashHelperInterface;
use Sylius\Bundle\ResourceBundle\Controller\NewResourceFactoryInterface;
use Sylius\Bundle\ResourceBundle\Controller\RedirectHandlerInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfigurationFactoryInterface;
use Sylius\Bundle\ResourceBundle\Controller\ResourceDeleteHandlerInterface;
use Sylius\Bundle\ResourceBundle\Controller\ResourceFormFactoryInterface;
use Sylius\Bundle\ResourceBundle\Controller\ResourcesCollectionProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\ResourceUpdateHandlerInterface;
use Sylius\Bundle\ResourceBundle\Controller\SingleResourceProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\StateMachineInterface;
use Sylius\Bundle\ResourceBundle\Controller\ViewHandlerInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Resource\Exception\UpdateHandlingException;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Metadata\MetadataInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\Component\Resource\ResourceActions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Webmozart\Assert\Assert;

final class OrderController extends BaseOrderController
{
    private const APPLE_ERROR_RESPONSE_CODE = 0;
    private const APPLE_SUCCESS_RESPONSE_CODE = 1;

    private ApplePayPaymentProvider $applePayPaymentProvider;
    private \SM\Factory\FactoryInterface $stateMachineFactory;
    private LockFactory $lockFactory;
    private LoggerInterface $logger;

    public function __construct(
        MetadataInterface $metadata,
        RequestConfigurationFactoryInterface $requestConfigurationFactory,
        ?ViewHandlerInterface $viewHandler,
        RepositoryInterface $repository,
        FactoryInterface $factory,
        NewResourceFactoryInterface $newResourceFactory,
        ObjectManager $manager,
        SingleResourceProviderInterface $singleResourceProvider,
        ResourcesCollectionProviderInterface $resourcesFinder,
        ResourceFormFactoryInterface $resourceFormFactory,
        RedirectHandlerInterface $redirectHandler,
        FlashHelperInterface $flashHelper,
        AuthorizationCheckerInterface $authorizationChecker,
        EventDispatcherInterface $eventDispatcher,
        ?StateMachineInterface $stateMachine,
        ResourceUpdateHandlerInterface $resourceUpdateHandler,
        ResourceDeleteHandlerInterface $resourceDeleteHandler,
        ApplePayPaymentProvider $applePayPaymentProvider,
        LockFactory $lockFactory,
        LoggerInterface $logger
    ) {
        parent::__construct(
            $metadata,
            $requestConfigurationFactory,
            $viewHandler,
            $repository,
            $factory,
            $newResourceFactory,
            $manager,
            $singleResourceProvider,
            $resourcesFinder,
            $resourceFormFactory,
            $redirectHandler,
            $flashHelper,
            $authorizationChecker,
            $eventDispatcher,
            $stateMachine,
            $resourceUpdateHandler,
            $resourceDeleteHandler
        );

        $this->applePayPaymentProvider = $applePayPaymentProvider;
        $this->lockFactory = $lockFactory;
        $this->logger = $logger;
    }

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

            if (null !== $postEventResponse) {
                return $postEventResponse;
            }

            $initializeEvent = $this->eventDispatcher->dispatchInitializeEvent(ResourceActions::UPDATE, $configuration, $resource);

            $initializeEventResponse = $initializeEvent->getResponse();

            if (null !== $initializeEventResponse) {
                return $initializeEventResponse;
            }

            $orderCheckoutStateMachine = $this->stateMachineFactory->get($resource, OrderCheckoutTransitions::GRAPH);

            if ($orderCheckoutStateMachine->can('select_payment')) {
                $orderCheckoutStateMachine->apply('select_payment');
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
        } catch (\Exception $exception) {
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
        } catch (\Exception|PaymentNotCompletedException $exception) {
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

        if (null !== $postEventResponse) {
            return $postEventResponse;
        }

        $initializeEvent = $this->eventDispatcher->dispatchInitializeEvent(ResourceActions::UPDATE, $configuration, $resource);

        $initializeEventResponse = $initializeEvent->getResponse();

        if (null !== $initializeEventResponse) {
            return $initializeEventResponse;
        }

        $order = $lastPayment->getOrder();
        Assert::isInstanceOf($order, OrderInterface::class);

        $orderCheckoutStateMachine = $this->stateMachineFactory->get($order, OrderCheckoutTransitions::GRAPH);

        if ($orderCheckoutStateMachine->can('complete')) {
            $orderCheckoutStateMachine->apply('complete');
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

    public function cancelApplePaySessionAction(Request $request): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);

        $this->isGrantedOr403($configuration, ResourceActions::UPDATE);

        /** @var OrderInterface $resource */
        $resource = $this->findOr404($configuration);

        $lock = $this->lockFactory->createLock('apple_pay_cancel'.$resource->getId());
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

            if (null !== $postEventResponse) {
                return $postEventResponse;
            }

            $initializeEvent = $this->eventDispatcher->dispatchInitializeEvent(ResourceActions::UPDATE, $configuration, $resource);

            $initializeEventResponse = $initializeEvent->getResponse();

            if (null !== $initializeEventResponse) {
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

            $orderCheckoutStateMachine = $this->stateMachineFactory->get($resource, OrderCheckoutTransitions::GRAPH);

            if ($orderCheckoutStateMachine->can('select_shipping')) {
                $orderCheckoutStateMachine->apply('select_shipping');
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

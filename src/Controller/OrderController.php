<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use PayPlug\SyliusPayPlugPlugin\Exception\Payment\PaymentNotCompletedException;
use PayPlug\SyliusPayPlugPlugin\Provider\Payment\ApplePayPaymentProvider;
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
use Webmozart\Assert\Assert;

final class OrderController extends BaseOrderController
{
    private const APPLE_ERROR_RESPONSE_CODE = 1;
    private const APPLE_SUCCESS_RESPONSE_CODE = 0;

    private ApplePayPaymentProvider $applePayPaymentProvider;

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
        ApplePayPaymentProvider $applePayPaymentProvider
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
    }

    public function initiateApplePaySessionAction(Request $request): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);

        $this->isGrantedOr403($configuration, ResourceActions::UPDATE);
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

            /** @var OrderInterface $currentCart */
            $currentCart = $this->getCurrentCart();

            $payment = $this->applePayPaymentProvider->provide($request, $currentCart);

            return new JsonResponse([
                'success' => true,
                'merchant_session' => $payment->getDetails()['merchant_session'],
            ]);
        } catch (UpdateHandlingException $exception) {
            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $exception) {
            $this->addFlash('error', 'sylius.payment.cancelled');
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

        /** @var OrderInterface $currentCart */
        $currentCart = $this->getCurrentCart();

        try {
            $lastPayment = $this->applePayPaymentProvider->patch($request, $currentCart);

            if (PaymentInterface::STATE_COMPLETED !== $lastPayment->getState()) {
                throw new PaymentNotCompletedException();
            }
        } catch (\Exception|PaymentNotCompletedException $exception) {
            $this->addFlash('error', 'sylius.payment.cancelled');
            $redirect = $this->redirectToRoute('sylius_shop_checkout_select_payment');
            $dataResponse = [];
            $dataResponse['returnUrl'] = $redirect->getTargetUrl();
            $dataResponse['responseToApple'] = ['status' => self::APPLE_ERROR_RESPONSE_CODE];
            $dataResponse['errors'] = 'Payment not created';
            $dataResponse['message'] = $exception->getMessage();

            return new JsonResponse($dataResponse, Response::HTTP_BAD_REQUEST);
        }

        $this->manager->flush();

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

        $order = $lastPayment->getOrder();
        Assert::isInstanceOf($order, OrderInterface::class);

        $request->getSession()->set('sylius_order_id', $order->getId());
        $dataResponse = [];
        $redirect = $this->redirectToRoute('sylius_shop_order_thank_you');
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

            /** @var OrderInterface $currentCart */
            $currentCart = $this->getCurrentCart();

            $this->applePayPaymentProvider->cancel($currentCart);

            $this->addFlash('error', 'sylius.payment.cancelled');

            $dataResponse = [];
            $redirect = $this->redirectToRoute('sylius_shop_checkout_select_payment');
            $dataResponse['returnUrl'] = $redirect->getTargetUrl();
            $dataResponse['responseToApple'] = ['status' => self::APPLE_SUCCESS_RESPONSE_CODE];

            $response = [
                'success' => true,
                'data' => $dataResponse,
            ];

            return new JsonResponse($response);
        } catch (UpdateHandlingException $exception) {
            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $exception) {
            $this->addFlash('error', 'sylius.payment.cancelled');

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
}

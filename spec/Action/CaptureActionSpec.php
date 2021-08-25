<?php

declare(strict_types=1);

namespace spec\PayPlug\SyliusPayPlugPlugin\Action;

use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\Action\CaptureAction;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayInterface;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Security\GenericTokenFactory;
use Payum\Core\Security\TokenInterface;
use PhpSpec\ObjectBehavior;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CaptureActionSpec extends ObjectBehavior
{
    function let(LoggerInterface $logger, FlashBagInterface $flashBag, TranslatorInterface $translator): void
    {
        $this->beConstructedWith($logger, $flashBag, $translator);
    }

    function it_is_initializable(): void
    {
        $this->shouldHaveType(CaptureAction::class);
    }

    function it_implements_action_interface(): void
    {
        $this->shouldHaveType(ActionInterface::class);
    }

    function it_implements_api_aware_interface(): void
    {
        $this->shouldHaveType(ApiAwareInterface::class);
    }

    function it_implements_gateway_aware_interface(): void
    {
        $this->shouldHaveType(GatewayAwareInterface::class);
    }

    function it_executes(
        Capture $request,
        ArrayObject $arrayObject,
        TokenInterface $token,
        GatewayInterface $gateway,
        PayPlugApiClientInterface $payPlugApiClient,
        GenericTokenFactory $genericTokenFactory,
        TokenInterface $notifyToken
    ): void {
        $payment = \Mockery::mock('payment', Payment::class);

        $payment->id = 1;
        $payment->is_live = true;
        $payment->hosted_payment = (object) [
            'payment_url' => 'test',
        ];

        $this->setGateway($gateway);
        $this->setApi($payPlugApiClient);
        $this->setGenericTokenFactory($genericTokenFactory);

        $arrayObject->getArrayCopy()->willReturn([]);
        $request->getModel()->willReturn($arrayObject);
        $request->getFirstModel()->willReturn($payment);
        $request->getToken()->willReturn($token);
        $token->getTargetUrl()->willReturn('url');
        $token->getAfterUrl()->willReturn('url');
        $token->getGatewayName()->willReturn('test');
        $token->getDetails()->willReturn([]);
        $genericTokenFactory->createNotifyToken('test', [])->willReturn($notifyToken);
        $notifyToken->getTargetUrl()->willReturn('url');
        $notifyToken->getHash()->willReturn('test');
        $payPlugApiClient->createPayment([])->willReturn($payment);
        $arrayObject->offsetGet('order_number')->willReturn('000001');

        $arrayObject->offsetExists('status')->shouldBeCalled();
        $arrayObject->offsetSet('hosted_payment', ['return_url' => 'url', 'cancel_url' => 'url?&status=canceled'])->shouldBeCalled();
        $arrayObject->offsetSet('notification_url', 'url')->shouldBeCalled();
        $arrayObject->offsetSet('payment_id', 1)->shouldBeCalled();
        $arrayObject->offsetSet('is_live', true)->shouldBeCalled();
        $arrayObject->offsetSet('status', PayPlugApiClientInterface::STATUS_CREATED)->shouldBeCalled();

        $this
            ->shouldThrow(HttpRedirect::class)
            ->during('execute', [$request])
        ;
    }

    function it_supports_only_capture_request_and_array_access(
        Capture $request,
        \ArrayAccess $arrayAccess
    ): void {
        $request->getModel()->willReturn($arrayAccess);
        $this->supports($request)->shouldReturn(true);
    }
}

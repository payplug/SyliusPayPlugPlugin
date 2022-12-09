<?php

declare(strict_types=1);

namespace spec\PayPlug\SyliusPayPlugPlugin\Action;

use Payplug\Resource\Payment;
use PayPlug\SyliusPayPlugPlugin\Action\CaptureAction;
use PayPlug\SyliusPayPlugPlugin\ApiClient\PayPlugApiClientInterface;
use PayPlug\SyliusPayPlugPlugin\PaymentProcessing\AbortPaymentProcessor;
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
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CaptureActionSpec extends ObjectBehavior
{
    public function let(LoggerInterface $logger, TranslatorInterface $translator, RequestStack $requestStack): void
    {
        $this->beConstructedWith($logger, $translator, $requestStack);
    }

    public function it_is_initializable(): void
    {
        $this->shouldHaveType(CaptureAction::class);
    }

    public function it_implements_action_interface(): void
    {
        $this->shouldHaveType(ActionInterface::class);
    }

    public function it_implements_api_aware_interface(): void
    {
        $this->shouldHaveType(ApiAwareInterface::class);
    }

    public function it_implements_gateway_aware_interface(): void
    {
        $this->shouldHaveType(GatewayAwareInterface::class);
    }

    public function it_executes(
        Capture $request,
        ArrayObject $arrayObject,
        TokenInterface $token,
        GatewayInterface $gateway,
        PayPlugApiClientInterface $payPlugApiClient,
        GenericTokenFactory $genericTokenFactory,
        TokenInterface $notifyToken,
        PaymentInterface $payment
    ): void {
        $payplugPayment = \Mockery::mock('payment', Payment::class);

        $payplugPayment->id = 1;
        $payplugPayment->is_live = true;
        $payplugPayment->hosted_payment = (object) [
            'payment_url' => 'test',
        ];

        $this->setGateway($gateway);
        $this->setApi($payPlugApiClient);
        $this->setGenericTokenFactory($genericTokenFactory);

        $arrayObject->getArrayCopy()->willReturn([]);
        $request->getModel()->willReturn($arrayObject);

        $request->getFirstModel()->willReturn($payment);
        $payment->getDetails()->willReturn(['status' => PayPlugApiClientInterface::STATUS_CREATED]);

        $request->getToken()->willReturn($token);
        $token->getTargetUrl()->willReturn('url');
        $token->getAfterUrl()->willReturn('url');
        $token->getGatewayName()->willReturn('test');
        $token->getDetails()->willReturn([]);
        $genericTokenFactory->createNotifyToken('test', [])->willReturn($notifyToken);
        $notifyToken->getTargetUrl()->willReturn('url');
        $notifyToken->getHash()->willReturn('test');
        $payPlugApiClient->createPayment([])->willReturn($payplugPayment);
        $arrayObject->offsetGet('order_number')->willReturn('000001');
        $arrayObject->offsetGet('initiator')->shouldBeCalled();

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

    public function it_supports_only_capture_request_and_array_access(
        Capture $request,
        \ArrayAccess $arrayAccess
    ): void {
        $request->getModel()->willReturn($arrayAccess);
        $this->supports($request)->shouldReturn(true);
    }
}

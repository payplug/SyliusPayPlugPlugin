<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Validator\Constraints;

use Doctrine\Common\Collections\ArrayCollection;
use PayPlug\SyliusPayPlugPlugin\Checker\GatewayChannelConflictChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\HasNoGatewayChannelConflict;
use PayPlug\SyliusPayPlugPlugin\Gateway\Validator\Constraints\HasNoGatewayChannelConflictValidator;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

final class HasNoGatewayChannelConflictValidatorTest extends ConstraintValidatorTestCase
{
    /** @var PaymentMethodRepositoryInterface&MockObject */
    private PaymentMethodRepositoryInterface $paymentMethodRepository;

    protected function createValidator(): HasNoGatewayChannelConflictValidator
    {
        $this->paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);

        return new HasNoGatewayChannelConflictValidator(
            new GatewayChannelConflictChecker($this->paymentMethodRepository),
        );
    }

    /**
     * The point of the constraint: the same rule the admin form enforces, reached through
     * PaymentMethodValidator, so a resource-controller or API write cannot leave two enabled
     * gateways of one factory sharing a channel.
     */
    public function testValidate_channelClaimedByAnotherEnabledGateway_addsAViolationOnChannels(): void
    {
        $channel = $this->channel('WEB_FR');

        $this->givenEnabledGateways([
            $this->paymentMethod(id: 7, channels: [$channel], name: 'CB — France'),
        ]);

        $this->validator->validate(
            $this->paymentMethod(id: 8, channels: [$channel]),
            new HasNoGatewayChannelConflict(),
        );

        $this->buildViolation('payplug_sylius_payplug_plugin.form.gateway_channel_conflict')
            ->setParameter('%channel%', 'WEB_FR')
            ->setParameter('%payment_method%', 'CB — France')
            ->atPath('property.path.channels')
            ->assertRaised();
    }

    public function testValidate_disjointChannels_addsNoViolation(): void
    {
        $this->givenEnabledGateways([
            $this->paymentMethod(id: 7, channels: [$this->channel('WEB_FR')], name: 'CB — France'),
        ]);

        $this->validator->validate(
            $this->paymentMethod(id: 8, channels: [$this->channel('WEB_IT')]),
            new HasNoGatewayChannelConflict(),
        );

        $this->assertNoViolation();
    }

    /**
     * A disabled subject claims nothing — that is what makes logging a gateway out release its
     * channels for another config (PRE-3632). The repository must not even be consulted.
     */
    public function testValidate_disabledSubject_isNotEvenChecked(): void
    {
        $this->paymentMethodRepository->expects(self::never())->method('findEnabledByGatewayName');

        $this->validator->validate(
            $this->paymentMethod(id: 8, channels: [$this->channel('WEB_FR')], enabled: false),
            new HasNoGatewayChannelConflict(),
        );

        $this->assertNoViolation();
    }

    public function testValidate_aValueThatIsNotAPaymentMethod_addsNoViolation(): void
    {
        $this->validator->validate('not a payment method', new HasNoGatewayChannelConflict());

        $this->assertNoViolation();
    }

    public function testValidate_theWrongConstraint_throws(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate($this->paymentMethod(id: 8, channels: []), new NotBlank());
    }

    /**
     * @param list<PaymentMethodInterface> $paymentMethods
     */
    private function givenEnabledGateways(array $paymentMethods): void
    {
        $this->paymentMethodRepository
            ->method('findEnabledByGatewayName')
            ->with(PayPlugGatewayFactory::FACTORY_NAME)
            ->willReturn($paymentMethods);
    }

    /**
     * @param list<ChannelInterface> $channels
     */
    private function paymentMethod(
        int $id,
        array $channels,
        string $name = 'CB',
        bool $enabled = true,
    ): PaymentMethodInterface&MockObject {
        $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(PayPlugGatewayFactory::FACTORY_NAME);

        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getId')->willReturn($id);
        $paymentMethod->method('getName')->willReturn($name);
        $paymentMethod->method('isEnabled')->willReturn($enabled);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);
        $paymentMethod->method('getChannels')->willReturn(new ArrayCollection($channels));

        return $paymentMethod;
    }

    private function channel(string $code): ChannelInterface&MockObject
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);

        return $channel;
    }
}

<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Extension;

use PayPlug\SyliusPayPlugPlugin\Checker\GatewayChannelConflictChecker;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Extension\PaymentMethodTypeExtension;
use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PayPlugGatewayConfigurationType;
use PayPlug\SyliusPayPlugPlugin\Gateway\PayPlugGatewayFactory;
use PayPlug\SyliusPayPlugPlugin\Repository\PaymentMethodRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Real-form coverage for {@see PaymentMethodTypeExtension}, complementing the mock-based
 * {@see PaymentMethodTypeExtensionTest}.
 *
 * The companion test drives the captured listeners directly against mocked `FormInterface`s, which
 * makes it fast and precise about the composition but blind to three things this extension actually
 * depends on:
 *
 * 1. that `$form->add('channels', ChannelChoiceType::class, $alreadyResolvedOptions + [...])`
 *    survives a *second* trip through ChannelChoiceType's real OptionsResolver and its normalizers;
 * 2. that an error added to the `channels` child stays there instead of bubbling to the root
 *    (ChoiceType sets `error_bubbling => false`, unlike a compound FormType);
 * 3. that `Form::add()` from inside POST_SET_DATA really re-maps the model data into the replaced
 *    child, rather than leaving it empty.
 *
 * All three are facts about Symfony, not about this plugin, so they are exactly the kind of thing
 * that changes under a minor bump without any of our own code moving.
 *
 * The root form here is assembled by hand rather than through Sylius' own PaymentMethodType, whose
 * constructor drags in the translations tree, GatewayConfigType and two generators. What it does
 * reproduce faithfully is the shape this extension reaches into: a root carrying `enabled`, a real
 * `channels` ChannelChoiceType and a `gatewayConfig.config` subtree, with the extension's listeners
 * attached to the real root builder.
 */
final class PaymentMethodTypeExtensionFormSubmissionTest extends TypeTestCase
{
    private const CONFLICT_MESSAGE_ID = 'payplug_sylius_payplug_plugin.form.gateway_channel_conflict';

    private ChannelInterface $channelFr;

    private ChannelInterface $channelIt;

    /** @var PaymentMethodRepositoryInterface&MockObject */
    private PaymentMethodRepositoryInterface $paymentMethodRepository;

    protected function setUp(): void
    {
        $this->channelFr = $this->buildChannel('WEB_FR', 'EUR');
        $this->channelIt = $this->buildChannel('WEB_IT', 'EUR');
        $this->paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);

        parent::setUp();
    }

    protected function getTypes(): array
    {
        $channelRepository = $this->createMock(RepositoryInterface::class);
        $channelRepository->method('findAll')->willReturn([$this->channelFr, $this->channelIt]);

        return [
            new ChannelChoiceType($channelRepository),
            new PayPlugGatewayConfigurationType($this->translator()),
        ];
    }

    /**
     * A channel another *enabled* CB gateway already holds must come back as a submit-time error on
     * the `channels` field itself — not bubbled to the root, where the admin form would render it
     * detached from the field that caused it.
     */
    public function testSubmit_channelClaimedByAnotherEnabledGateway_isRejectedOnTheChannelsField(): void
    {
        $this->givenRivalGatewayHolding($this->channelFr, 'CB — France');

        $form = $this->createRootForm($this->payPlugPaymentMethod());

        $form->submit([
            'enabled' => '1',
            'channels' => ['WEB_FR'],
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertFalse($form->isValid());

        $channelsErrors = $form->get('channels')->getErrors();
        self::assertCount(1, $channelsErrors);
        self::assertSame(
            self::CONFLICT_MESSAGE_ID . '|WEB_FR|CB — France',
            $channelsErrors[0]->getMessage(),
        );

        self::assertCount(
            0,
            $form->getErrors(),
            'The conflict must stay on the channels field — ChoiceType does not bubble.',
        );
    }

    /**
     * The mirror case: a free channel submits cleanly. Guards against the rule firing on everything.
     */
    public function testSubmit_channelHeldByNobody_isAccepted(): void
    {
        $this->givenRivalGatewayHolding($this->channelFr, 'CB — France');

        $form = $this->createRootForm($this->payPlugPaymentMethod());

        $form->submit([
            'enabled' => '1',
            'channels' => ['WEB_IT'],
        ]);

        self::assertTrue($form->isValid(), 'WEB_IT is held by nobody, so the submission must pass.');
        self::assertCount(0, $form->get('channels')->getErrors(true));
    }

    /**
     * PRE-3629, through the real ChannelChoiceType: the replaced `channels` child must still render
     * both choices — proving the resolved options survived the second pass through the resolver —
     * with only the claimed one carrying `disabled` and a `title` naming the claiming method.
     */
    public function testSetData_channelClaimedByAnotherEnabledGateway_isRenderedDisabled(): void
    {
        $this->givenRivalGatewayHolding($this->channelIt, 'CB — Italy');

        $form = $this->createRootForm($this->payPlugPaymentMethod());

        $channelChoices = $form->get('channels')->createView()->children;

        self::assertCount(2, $channelChoices, 'Both channels must still be offered.');

        $byValue = [];
        foreach ($channelChoices as $choiceView) {
            $byValue[$choiceView->vars['value']] = $choiceView->vars['attr'];
        }

        self::assertArrayNotHasKey('disabled', $byValue['WEB_FR'], 'WEB_FR is free and must stay selectable.');
        self::assertTrue($byValue['WEB_IT']['disabled'] ?? false, 'WEB_IT is claimed and must be disabled.');
        self::assertSame(
            self::CONFLICT_MESSAGE_ID . '|WEB_IT|CB — Italy',
            $byValue['WEB_IT']['title'] ?? null,
        );
    }

    /**
     * Channels the edited method already holds stay selectable even when the checker would report
     * them: browsers do not submit disabled checkboxes, so disabling a *checked* one would silently
     * drop the channel on save. This is the render-side half of that rule, exercised against the
     * real ChoiceType rather than a stub.
     */
    public function testSetData_channelAlreadyHeldByTheEditedMethod_staysSelectable(): void
    {
        $subject = $this->payPlugPaymentMethod();
        $subject->addChannel($this->channelFr);

        $this->givenRivalGatewayHolding($this->channelFr, 'CB — France');

        $form = $this->createRootForm($subject);

        foreach ($form->get('channels')->createView()->children as $choiceView) {
            if ('WEB_FR' === $choiceView->vars['value']) {
                self::assertArrayNotHasKey(
                    'disabled',
                    $choiceView->vars['attr'],
                    'A channel the edited method already holds must stay selectable.',
                );
            }
        }
    }

    /**
     * Rivals are what `findEnabledByGatewayName()` returns, so stubbing that one method is enough to
     * drive the real GatewayChannelConflictChecker.
     */
    private function givenRivalGatewayHolding(ChannelInterface $channel, string $name): void
    {
        $rival = $this->createMock(PaymentMethodInterface::class);
        $rival->method('getId')->willReturn(99);
        $rival->method('getName')->willReturn($name);
        $rival->method('isEnabled')->willReturn(true);
        $rival->method('getChannels')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$channel]));

        $this->paymentMethodRepository
            ->method('findEnabledByGatewayName')
            ->with(PayPlugGatewayFactory::FACTORY_NAME)
            ->willReturn([$rival]);
    }

    private function payPlugPaymentMethod(): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(PayPlugGatewayFactory::FACTORY_NAME);
        $gatewayConfig->setGatewayName(PayPlugGatewayFactory::FACTORY_NAME);
        // Redirected mode: neither display-mode flag set. The CB type only gates on base currency
        // for integrated payment, so this keeps these tests about the channel-conflict rule alone.
        $gatewayConfig->setConfig([
            PayPlugGatewayFactory::INTEGRATED_PAYMENT => false,
            PayPlugGatewayFactory::HOSTED_FIELDS => false,
        ]);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode('payplug_cb');
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }

    /**
     * The shape `PaymentMethodTypeExtension` reaches into: `enabled`, a real `channels`
     * ChannelChoiceType, and `gatewayConfig.config` — with the extension's own listeners attached
     * to the real root builder, so they run through Symfony's real event dispatch.
     */
    private function createRootForm(PaymentMethod $paymentMethod): FormInterface
    {
        $extension = new PaymentMethodTypeExtension(
            new GatewayChannelConflictChecker($this->paymentMethodRepository),
            $this->translator(),
        );

        $builder = $this->factory->createBuilder(FormType::class, $paymentMethod, [
            'data_class' => PaymentMethod::class,
        ]);

        $builder->add('enabled', CheckboxType::class, ['required' => false]);
        $builder->add('channels', ChannelChoiceType::class, [
            'multiple' => true,
            'expanded' => true,
        ]);

        $gatewayConfig = $builder->create('gatewayConfig', FormType::class, ['mapped' => false]);
        $gatewayConfig->add('config', PayPlugGatewayConfigurationType::class, ['mapped' => false]);
        $builder->add($gatewayConfig);

        $extension->buildForm($builder, []);

        return $builder->getForm();
    }

    private function buildChannel(string $code, string $currencyCode): ChannelInterface
    {
        $currency = new Currency();
        $currency->setCode($currencyCode);

        $channel = new Channel();
        $channel->setCode($code);
        $channel->setName($code);
        $channel->setBaseCurrency($currency);

        return $channel;
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => \implode(
                '|',
                [$id, ...\array_values($parameters)],
            ),
        );

        return $translator;
    }
}

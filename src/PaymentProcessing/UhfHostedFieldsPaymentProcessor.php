<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\PaymentProcessing;

use PayPlug\SyliusPayPlugPlugin\ApiClient\UnifiedApiHostedPaymentServiceFactory;
use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Exceptions\PayplugException;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Real, UPC-backed implementation of HostedFieldsPaymentProcessorInterface. Called synchronously
 * from PostPaymentSelectEventSubscriber::handleHostedFieldsToken() on the
 * sylius.order.post_payment event — never through the Payum/PaymentRequest capture pipeline,
 * which Hosted Fields checkout does not use. Communicates its outcome back to the caller purely
 * through Payment::details (redirect_url / error), matching this interface's void return type.
 */
final class UhfHostedFieldsPaymentProcessor implements HostedFieldsPaymentProcessorInterface
{
    public function __construct(
        private UnifiedApiHostedPaymentServiceFactory $hostedPaymentServiceFactory,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function process(PaymentInterface $payment, string $hfToken, string $selectedBrand, bool $saveCard): void
    {
        $method = $payment->getMethod();
        if (null === $method) {
            throw new \LogicException('Payment method is not set for the payment.');
        }

        $notificationUrl = $this->urlGenerator->generate(
            'sylius_payment_method_notify',
            ['code' => $method->getCode()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $common = new CommonFieldsDto(
            $this->hostedPaymentServiceFactory->getAccountId($method),
            $payment->getAmount() ?? 0,
            $payment->getCurrencyCode() ?? '',
            (string) $payment->getOrder()?->getId(), // @phpstan-ignore-line
        );
        $common->notificationUrl = $notificationUrl;

        $dto = new HostedFieldDto(
            $common,
            $hfToken,
            null,
            null,
            ['details' => ['selectedBrand' => $selectedBrand]],
        );

        try {
            $result = $this->hostedPaymentServiceFactory->createForPaymentMethod($method)->createHostedPayment($dto);
        } catch (PayplugException $exception) {
            $this->logger->error('[PayPlug] UHF payment creation failed', ['error' => $exception->getMessage()]);
            $payment->setDetails(['error' => $exception->getMessage()]);

            return;
        }

        $responseBody = \json_decode($result->body, true);
        $operationId = \is_array($responseBody) && \is_string($responseBody['id'] ?? null) ? $responseBody['id'] : null;

        $payment->setDetails([
            'status' => PaymentInterface::STATE_PROCESSING,
            'payment_id' => $operationId,
            'redirect_url' => $result->redirectUrl,
            'hosted_fields_selected_brand' => $selectedBrand,
            'hosted_fields_save_card' => $saveCard,
        ]);
    }
}

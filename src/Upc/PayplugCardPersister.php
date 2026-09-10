<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use PayPlug\SyliusPayPlugPlugin\Entity\Card;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface as CorePaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

/**
 * Persists a Card entity from a Unified API alias — shared by CaptureHostedPaymentRequestHandler
 * (frictionless capture, where the alias/card metadata is available synchronously) and
 * HostedFieldsWebhookNotificationHandler (a 3DS-challenge capture never returns an alias at
 * capture time — see that handler's own docblock — so this is the only save point for a 3DS
 * payment).
 */
final class PayplugCardPersister
{
    private const LAST4_PATTERN = '/^\d{4}$/D';

    private const COUNTRY_CODE_PATTERN = '/^[A-Za-z]{2}$/D';

    private const MAX_EXPIRATION_YEARS_AHEAD = 20;

    // Mirrors ALLOWED_BRANDS in assets/shop/controllers/hosted-fields_controller.js — that JS
    // check is client-side only and trivially bypassed by posting the form directly, so it must
    // be re-enforced here before hosted_fields_selected_brand is trusted as a fallback.
    private const ALLOWED_BRANDS = ['CB', 'VISA', 'MASTERCARD'];

    public function __construct(
        private FactoryInterface $payplugCardFactory,
        private RepositoryInterface $payplugCardRepository,
        private ManagerRegistry $managerRegistry,
    ) {
    }

    /**
     * @param mixed[] $details the payment's own details, used as a fallback source for card
     *                         fields $fetchedCardData doesn't carry
     * @param array{brand?: string, last4?: string, expirationMonth?: int, expirationYear?: int} $fetchedCardData
     *                freshly extracted card metadata, takes precedence over $details when present
     */
    public function persist(
        string $aliasId,
        PaymentInterface $payment,
        PaymentMethodInterface $method,
        array $details,
        array $fetchedCardData,
    ): void {
        $order = $payment->getOrder();

        $customer = $order?->getCustomer();
        if (null === $customer) {
            return;
        }

        // Payment::getMethod() is only typed to the base Payment component's PaymentMethodInterface,
        // but Card::$paymentMethod is a mandatory (non-nullable) association requiring Sylius
        // Core's narrower PaymentMethodInterface (the only kind Sylius actually wires up at
        // runtime) — bail out before building a Card at all rather than persisting one with that
        // required field left unset, which would only fail later at flush time.
        if (!$method instanceof CorePaymentMethodInterface) {
            return;
        }

        $gatewayConfig = $method->getGatewayConfig()?->getConfig() ?? [];
        $isLive = true === ($gatewayConfig['live'] ?? false);

        // Guards against double-saving the same alias — e.g. a 3DS payment whose webhook fires
        // more than once for reasons outside isTreated()'s own operation-id dedupe (a different
        // operation id notifying the same alias), or any future path that also calls persist()
        // for an alias already stored. This check-then-act is not by itself race-proof — the
        // synchronous frictionless capture and the async webhook can both reach persist() for the
        // same alias — so a DB-level unique constraint on (external_id, is_live) backs it up; see
        // the catch below.
        if (null !== $this->payplugCardRepository->findOneBy(['externalId' => $aliasId, 'isLive' => $isLive])) {
            return;
        }

        // $details' own hosted_fields_* values are fully client-controlled (see
        // hosted-fields_controller.js) — used only as a display-only fallback for whatever
        // $fetchedCardData (PayPlug's own API/webhook data) doesn't carry, and validated here
        // rather than trusted as-is: anything not matching the expected shape is discarded as if
        // it were absent.
        $currentYear = (int) (new \DateTimeImmutable())->format('Y');
        $brand = $fetchedCardData['brand'] ?? $this->sanitizeBrand($details['hosted_fields_selected_brand'] ?? null);
        $last4 = $fetchedCardData['last4']
            ?? $this->sanitizeString($details['hosted_fields_last4'] ?? null, self::LAST4_PATTERN);
        $expirationMonth = $fetchedCardData['expirationMonth']
            ?? $this->sanitizeIntInRange($details['hosted_fields_expiration_month'] ?? null, 1, 12);
        $expirationYear = $fetchedCardData['expirationYear']
            ?? $this->sanitizeIntInRange(
                $details['hosted_fields_expiration_year'] ?? null,
                $currentYear,
                $currentYear + self::MAX_EXPIRATION_YEARS_AHEAD,
            );
        // No card country field exists on the operation resource (confirmed against a real
        // staging response) — $countryCode keeps relying entirely on the client-submitted
        // $details value.
        $countryCodeCandidate = $this->sanitizeString($details['hosted_fields_country'] ?? null, self::COUNTRY_CODE_PATTERN);
        $countryCode = null !== $countryCodeCandidate ? \strtoupper($countryCodeCandidate) : null;

        /** @var Card $card */
        $card = $this->payplugCardFactory->createNew();
        $card
            ->setCustomer($customer)
            ->setExternalId($aliasId)
            ->setBrand(\is_string($brand) ? $brand : '')
            ->setLast4(\is_string($last4) ? $last4 : '')
            ->setExpirationMonth(\is_int($expirationMonth) ? $expirationMonth : 0)
            ->setExpirationYear(\is_int($expirationYear) ? $expirationYear : 0)
            ->setCountryCode(\is_string($countryCode) ? $countryCode : '')
            ->setIsLive($isLive)
            ->setPaymentMethod($method)
        ;

        try {
            $this->payplugCardRepository->add($card);
        } catch (UniqueConstraintViolationException) {
            // The findOneBy() guard above lost a race against a concurrent persist() call for the
            // same alias — that other call already stored the canonical Card row, so there is
            // nothing left to do here. Doctrine's UnitOfWork closes the EntityManager on ANY
            // flush failure, catch included — reset the registry so Doctrine work resolved fresh
            // after this point doesn't inherit the now-closed instance.
            $this->managerRegistry->resetManager();
        }
    }

    private function sanitizeBrand(mixed $value): ?string
    {
        return \is_string($value) && \in_array($value, self::ALLOWED_BRANDS, true) ? $value : null;
    }

    private function sanitizeString(mixed $value, string $pattern): ?string
    {
        return \is_string($value) && 1 === \preg_match($pattern, $value) ? $value : null;
    }

    private function sanitizeIntInRange(mixed $value, int $min, int $max): ?int
    {
        return \is_int($value) && $value >= $min && $value <= $max ? $value : null;
    }
}

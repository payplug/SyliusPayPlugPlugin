<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Upc;

use PayplugUnifiedCore\Dto\AddressDto;
use PayplugUnifiedCore\Dto\BillingDto;
use PayplugUnifiedCore\Dto\ContactDto;
use PayplugUnifiedCore\Dto\ShippingDto;
use PayplugUnifiedCore\Exceptions\InvalidPhoneNumberException;
use PayplugUnifiedCore\Utilities\Helpers\PhoneHelper;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Builds the Unified API's optional billing/shipping payment payload blocks
 * (CommonFieldsDto::$billing/$shipping) from a Sylius order's own billing/shipping addresses —
 * shared by both the Hosted Fields token flow (CaptureHostedPaymentRequestHandler) and the
 * pay-with-an-existing-alias flow (CaptureAliasPaymentRequestHandler), so this mapping only lives
 * in one place.
 */
final class OrderAddressDtoCreator
{
    /**
     * @param bool $requireRegionMatch true: the phone number must belong to one of the address's
     *      phoneRegions(); false: any valid number is sent, formatted in its own country.
     *      Defaults to true to keep the previous behaviour for any other caller: the Hosted
     *      Fields capture path (PaymentCaptureContextBuilder) passes false explicitly.
     */
    public function createBilling(OrderInterface $order, bool $requireRegionMatch = true): ?BillingDto
    {
        $address = $order->getBillingAddress();
        if (null === $address) {
            return null;
        }

        return new BillingDto(
            $this->buildAddress($address),
            $this->buildContact($address, $requireRegionMatch),
            $this->title($order),
        );
    }

    /**
     * @param bool $requireRegionMatch same as createBilling()
     */
    public function createShipping(OrderInterface $order, bool $requireRegionMatch = true): ?ShippingDto
    {
        $address = $order->getShippingAddress();
        if (null === $address) {
            return null;
        }

        return new ShippingDto(
            $this->buildAddress($address),
            $this->buildContact($address, $requireRegionMatch),
            $order->getCustomer()?->getEmail(),
            $address->getCompany(),
        );
    }

    private function buildContact(AddressInterface $address, bool $requireRegionMatch): ContactDto
    {
        [$phone, $mobilePhone] = $this->splitPhone($address, $requireRegionMatch);

        return new ContactDto($address->getFirstName(), $address->getLastName(), $phone, $mobilePhone);
    }

    private function buildAddress(AddressInterface $address): AddressDto
    {
        return new AddressDto(
            $address->getStreet(),
            $address->getCity(),
            $address->getCountryCode(),
            // AddressDto::$state is documented as 0-3 chars, so a short province CODE (e.g. "75")
            // fits where the full province NAME (used elsewhere in this plugin's legacy
            // PayPlugPaymentDataCreator) would not — but Sylius province codes aren't always that
            // short (e.g. ISO-3166-2-style "US-CA"), so only pass one through when it actually
            // fits, rather than risk sending a malformed state to the Unified API.
            $this->shortProvinceCode($address),
            $address->getPostcode(),
        );
    }

    private function shortProvinceCode(AddressInterface $address): ?string
    {
        $provinceCode = $address->getProvinceCode();

        return null !== $provinceCode && \strlen($provinceCode) <= 3 ? $provinceCode : null;
    }

    /**
     * @return array{0: string|null, 1: string|null} [phone, mobilePhone] — Sylius stores only one
     *      phone number per address, so only one of the two ever comes back non-null here, matching
     *      whichever type PhoneHelper::isMobile() detects it as; an unparseable/invalid number is
     *      treated as absent rather than failing the whole payment over supplementary contact data.
     *      The number is read in each region of phoneRegions() in turn, the first one accepting it wins.
     */
    private function splitPhone(AddressInterface $address, bool $requireRegionMatch): array
    {
        $rawPhone = $address->getPhoneNumber();
        $countryCode = $address->getCountryCode();
        if (null === $rawPhone || '' === $rawPhone || null === $countryCode) {
            return [null, null];
        }

        foreach ($this->phoneRegions($countryCode) as $region) {
            try {
                $e164Phone = PhoneHelper::toE164($rawPhone, $region, $requireRegionMatch);
                $isMobile = PhoneHelper::isMobile($rawPhone, $region, $requireRegionMatch);
            } catch (InvalidPhoneNumberException) {
                continue;
            }

            return $isMobile ? [null, $e164Phone] : [$e164Phone, null];
        }

        return [null, null];
    }

    /**
     * @return list<string> the address country first, then the regions sharing its numbering, then
     *      FR for an overseas territory sharing the French numbering
     */
    private function phoneRegions(string $countryCode): array
    {
        return PhoneRegionResolver::resolve($countryCode);
    }

    private function title(OrderInterface $order): ?string
    {
        $gender = $order->getCustomer()?->getGender();

        return null !== $gender ? CustomerTitleResolver::resolve($gender) : null;
    }
}

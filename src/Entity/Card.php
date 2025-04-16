<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Customer\Model\CustomerInterface;
use Sylius\Component\Resource\Model\ResourceInterface;

/**
 * @ORM\Entity()
 *
 * @ORM\Table("payplug_cards")
 */
#[ORM\Entity]
#[ORM\Table(name: 'payplug_cards')]
class Card implements ResourceInterface
{
    /**
     * @var int
     *
     * @ORM\Id()
     *
     * @ORM\GeneratedValue()
     *
     * @ORM\Column(type="integer")
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="external_id", type="string", nullable=false)
     */
    #[ORM\Column(name: 'external_id', type: Types::STRING, nullable: false)]
    private $externalId;

    /**
     * @var string
     *
     * @ORM\Column(name="last4", type="string")
     */
    #[ORM\Column(name: 'last4', type: Types::STRING)]
    private $last4;

    /**
     * @var string
     *
     * @ORM\Column(name="country", type="string")
     */
    #[ORM\Column(name: 'country', type: Types::STRING)]
    private $countryCode;

    /**
     * @var int
     *
     * @ORM\Column(name="exp_month", type="integer")
     */
    #[ORM\Column(name: 'exp_month', type: Types::INTEGER)]
    private $expirationMonth;

    /**
     * @var int
     *
     * @ORM\Column(name="exp_year", type="integer")
     */
    #[ORM\Column(name: 'exp_year', type: Types::INTEGER)]
    private $expirationYear;

    /**
     * @var string
     *
     * @ORM\Column(name="brand", type="string")
     */
    #[ORM\Column(name: 'brand', type: Types::STRING)]
    private $brand;

    /**
     * @var bool
     *
     * @ORM\Column(name="is_live", type="boolean")
     */
    #[ORM\Column(name: 'is_live', type: Types::BOOLEAN)]
    private $isLive;

    /**
     * @var CustomerInterface
     *
     * @ORM\ManyToOne(targetEntity=\Sylius\Component\Customer\Model\CustomerInterface::class, inversedBy="cards")
     *
     * @ORM\JoinColumn(nullable=false)
     */
    #[ORM\ManyToOne(targetEntity: CustomerInterface::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(nullable: false)]
    private $customer;

    /**
     * @var \Sylius\Component\Core\Model\PaymentMethodInterface
     *
     * @ORM\ManyToOne(targetEntity=\Sylius\Component\Core\Model\PaymentMethodInterface::class, inversedBy="cards")
     *
     * @ORM\JoinColumn(nullable=false)
     */
    #[ORM\ManyToOne(targetEntity: PaymentMethodInterface::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(nullable: false)]
    private $paymentMethod;

    public function getId(): int
    {
        return $this->id;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getLast4(): string
    {
        return $this->last4;
    }

    public function setLast4(string $last4): self
    {
        $this->last4 = $last4;

        return $this;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function setCountryCode(string $countryCode): self
    {
        $this->countryCode = $countryCode;

        return $this;
    }

    public function getExpirationMonth(): int
    {
        return $this->expirationMonth;
    }

    public function setExpirationMonth(int $expirationMonth): self
    {
        $this->expirationMonth = $expirationMonth;

        return $this;
    }

    public function getExpirationYear(): int
    {
        return $this->expirationYear;
    }

    public function setExpirationYear(int $expirationYear): self
    {
        $this->expirationYear = $expirationYear;

        return $this;
    }

    public function getBrand(): string
    {
        return $this->brand;
    }

    public function setBrand(string $brand): self
    {
        $this->brand = $brand;

        return $this;
    }

    public function isLive(): bool
    {
        return $this->isLive;
    }

    public function setIsLive(bool $isLive): self
    {
        $this->isLive = $isLive;

        return $this;
    }

    public function getCustomer(): CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(CustomerInterface $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function getPaymentMethod(): PaymentMethodInterface
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(PaymentMethodInterface $paymentMethod): self
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }
}

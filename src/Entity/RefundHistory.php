<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Entity;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Resource\Model\ResourceInterface;
use Sylius\RefundPlugin\Entity\RefundPayment;
use Webmozart\Assert\Assert;

/**
 * @ORM\Entity()
 *
 * @ORM\Table("payplug_refund_history")
 */
#[ORM\Entity]
#[ORM\Table(name: 'payplug_refund_history')]
class RefundHistory implements ResourceInterface
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
     * @var RefundPayment|null
     *
     * @ORM\OneToOne(targetEntity="Sylius\RefundPlugin\Entity\RefundPayment")
     *
     * @ORM\JoinColumn(name="refund_payment_id", nullable=true)
     */
    #[ORM\OneToOne(targetEntity: RefundPayment::class)]
    #[ORM\JoinColumn(name: 'refund_payment_id', nullable: true)]
    private $refundPayment;

    /**
     * @var string|null
     *
     * @ORM\Column(type="string", nullable=true)
     */
    #[ORM\Column(type: Types::STRING, nullable: true)]
    private $externalId;

    /**
     * @var PaymentInterface|null
     *
     * @ORM\ManyToOne(targetEntity="\Sylius\Component\Core\Model\PaymentInterface", inversedBy="refundHistories")
     *
     * @ORM\JoinColumn(name="payment_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    #[ORM\ManyToOne(targetEntity: PaymentInterface::class, inversedBy: 'refundHistories')]
    #[ORM\JoinColumn(name: 'payment_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private $payment;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private $value;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private $processed = false;

    /**
     * @var DateTimeInterface
     *
     * @ORM\Column(type="datetime")
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRefundPayment(): ?RefundPayment
    {
        return $this->refundPayment;
    }

    public function setRefundPayment(?RefundPayment $refundPayment): self
    {
        $this->refundPayment = $refundPayment;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getPayment(): ?PaymentInterface
    {
        return $this->payment;
    }

    public function setPayment(?PaymentInterface $payment): self
    {
        $this->payment = $payment;

        return $this;
    }

    public function isProcessed(): bool
    {
        return $this->processed;
    }

    public function setProcessed(bool $processed): self
    {
        $this->processed = $processed;

        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        Assert::isInstanceOf($this->createdAt, DateTime::class);

        return DateTimeImmutable::createFromMutable($this->createdAt);
    }

    public function setCreatedAt(DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}

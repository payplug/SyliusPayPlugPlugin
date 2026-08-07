<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Entity;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Resource\Model\ResourceInterface;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'payplug_operations')]
#[ORM\UniqueConstraint(name: 'UNIQ_PAYPLUG_OPERATIONS_OPERATION_ID', columns: ['operation_id'])]
#[ORM\Index(name: 'IDX_PAYPLUG_OPERATIONS_ORDER_ID', columns: ['order_id'])]
class PayplugOperation implements ResourceInterface
{
    /** @var int */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private $id;

    #[ORM\Column(name: 'operation_id', type: Types::STRING)]
    private string $operationId;

    #[ORM\Column(name: 'order_id', type: Types::STRING)]
    private string $orderId;

    #[ORM\Column(name: 'exec_code', type: Types::STRING)]
    private string $execCode;

    #[ORM\Column(name: 'outcome', type: Types::STRING)]
    private string $outcome;

    #[ORM\Column(name: 'amount', type: Types::INTEGER)]
    private int $amount;

    #[ORM\Column(name: 'treated', type: Types::BOOLEAN)]
    private bool $treated = false;

    /** @var DateTimeInterface */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private $createdAt;

    public function __construct(string $operationId, string $orderId, string $execCode, string $outcome, int $amount)
    {
        $this->operationId = $operationId;
        $this->orderId = $orderId;
        $this->execCode = $execCode;
        $this->outcome = $outcome;
        $this->amount = $amount;
        $this->createdAt = new DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getOperationId(): string
    {
        return $this->operationId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getExecCode(): string
    {
        return $this->execCode;
    }

    public function setExecCode(string $execCode): void
    {
        $this->execCode = $execCode;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function setOutcome(string $outcome): void
    {
        $this->outcome = $outcome;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): void
    {
        $this->amount = $amount;
    }

    public function isTreated(): bool
    {
        return $this->treated;
    }

    public function markTreated(): void
    {
        $this->treated = true;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        Assert::isInstanceOf($this->createdAt, DateTime::class);

        return DateTimeImmutable::createFromMutable($this->createdAt);
    }
}

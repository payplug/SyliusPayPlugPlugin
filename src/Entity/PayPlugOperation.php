<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use PayplugUnifiedCore\DataValues\OperationData;

/**
 * @ORM\Entity()
 *
 * @ORM\Table("payplug_upc_operation")
 */
#[ORM\Entity]
#[ORM\Table(name: 'payplug_upc_operation')]
#[ORM\Index(columns: ['order_id'], name: 'idx_payplug_upc_operation_order_id')]
class PayPlugOperation
{
    /**
     * @var int|null
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
     * @ORM\Column(name="order_id", type="string")
     */
    #[ORM\Column(name: 'order_id', type: Types::STRING)]
    private $orderId;

    /**
     * @var string
     *
     * @ORM\Column(name="operation_id", type="string", unique=true)
     */
    #[ORM\Column(name: 'operation_id', type: Types::STRING, unique: true)]
    private $operationId;

    /**
     * @var string
     *
     * @ORM\Column(name="exec_code", type="string")
     */
    #[ORM\Column(name: 'exec_code', type: Types::STRING)]
    private $execCode;

    /**
     * @var string
     *
     * @ORM\Column(name="outcome", type="string")
     */
    #[ORM\Column(name: 'outcome', type: Types::STRING)]
    private $outcome;

    /**
     * @var int
     *
     * @ORM\Column(name="amount", type="integer")
     */
    #[ORM\Column(name: 'amount', type: Types::INTEGER)]
    private $amount;

    /**
     * @var bool
     *
     * @ORM\Column(name="treated", type="boolean")
     */
    #[ORM\Column(name: 'treated', type: Types::BOOLEAN)]
    private $treated = false;

    /**
     * @var \DateTimeImmutable
     *
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private $createdAt;

    public function __construct(string $orderId, string $operationId, string $execCode, string $outcome, int $amount)
    {
        $this->orderId = $orderId;
        $this->operationId = $operationId;
        $this->execCode = $execCode;
        $this->outcome = $outcome;
        $this->amount = $amount;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getOperationId(): string
    {
        return $this->operationId;
    }

    public function getExecCode(): string
    {
        return $this->execCode;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function isTreated(): bool
    {
        return $this->treated;
    }

    public function markTreated(): void
    {
        $this->treated = true;
    }

    public function toOperationData(): OperationData
    {
        return new OperationData($this->operationId, $this->execCode, $this->outcome, $this->amount, $this->orderId);
    }
}

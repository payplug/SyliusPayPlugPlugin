<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Spike\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use PayplugUnifiedCore\Models\OperationData;

/**
 * PRE-3469 spike: normalized Doctrine schema for OperationData — not shipped code.
 *
 * Friction found: the plugin currently stores the Payplug payment id inline in Sylius's own
 * Payment::details JSON blob and looks it up with a `LIKE '%id%'` query (see
 * PaymentRepository::findOneByPayPlugPaymentId) — there is no separate "operation" table today.
 * That works for the single id lookup it was built for, but it cannot support
 * IPaymentRepository::markTreated()/isTreated() (needs its own indexed idempotency flag) or
 * getByOperationId() (needs operationId to be a real, indexed column, not a substring match
 * inside a serialized blob). Hence this new table rather than extending the existing one.
 *
 * Separately, this class living outside src/Entity/ means it is not auto-registered the way
 * this plugin's other entities are (see config/resources.yaml, which wires Card and
 * RefundHistory as `sylius_resource` entries) — a real (non-spike) version of this table would
 * need either a `sylius_resource` entry or an explicit `doctrine.orm.mappings` prepend for
 * whatever directory it lives in.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payplug_operation')]
#[ORM\UniqueConstraint(name: 'payplug_operation_id_unique', columns: ['operation_id'])]
#[ORM\Index(name: 'payplug_operation_order_id_idx', columns: ['order_id'])]
class PayplugOperation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'operation_id', type: Types::STRING)]
    private string $operationId;

    #[ORM\Column(name: 'exec_code', type: Types::STRING)]
    private string $execCode;

    #[ORM\Column(name: 'outcome', type: Types::STRING)]
    private string $outcome;

    #[ORM\Column(name: 'amount', type: Types::INTEGER)]
    private int $amount;

    #[ORM\Column(name: 'order_id', type: Types::STRING)]
    private string $orderId;

    #[ORM\Column(name: 'treated', type: Types::BOOLEAN)]
    private bool $treated = false;

    #[ORM\Column(name: 'treated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $treatedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $operationId, string $execCode, string $outcome, int $amount, string $orderId)
    {
        $this->operationId = $operationId;
        $this->execCode = $execCode;
        $this->outcome = $outcome;
        $this->amount = $amount;
        $this->orderId = $orderId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function fromOperationData(OperationData $operationData): self
    {
        return new self(
            $operationData->operationId,
            $operationData->execCode,
            $operationData->outcome,
            $operationData->amount,
            $operationData->orderId,
        );
    }

    public function updateFromOperationData(OperationData $operationData): void
    {
        $this->execCode = $operationData->execCode;
        $this->outcome = $operationData->outcome;
        $this->amount = $operationData->amount;
    }

    public function toOperationData(): OperationData
    {
        return new OperationData($this->operationId, $this->execCode, $this->outcome, $this->amount, $this->orderId);
    }

    public function getOperationId(): string
    {
        return $this->operationId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function isTreated(): bool
    {
        return $this->treated;
    }

    public function markTreated(): void
    {
        $this->treated = true;
        $this->treatedAt = new \DateTimeImmutable();
    }
}

<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Repository\Fixture;

use Doctrine\ORM\Mapping as ORM;

/** The payment columns PaymentRepository queries, mapped under the names and types Sylius uses. */
#[ORM\Entity]
#[ORM\Table(name: 'query_payment')]
class QueryPayment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @param mixed[] $details */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: QueryOrder::class)]
        #[ORM\JoinColumn(nullable: false)]
        private QueryOrder $order,
        #[ORM\Column]
        private int $amount,
        #[ORM\Column]
        private string $state,
        #[ORM\Column(type: 'json')]
        private array $details,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}

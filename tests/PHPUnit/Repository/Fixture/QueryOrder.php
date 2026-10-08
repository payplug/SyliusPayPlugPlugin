<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Repository\Fixture;

use Doctrine\ORM\Mapping as ORM;

/** The order columns PaymentRepository queries, mapped under the names Sylius uses. */
#[ORM\Entity]
#[ORM\Table(name: 'query_order')]
class QueryOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column]
        private string $number,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }
}

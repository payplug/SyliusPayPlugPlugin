<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use PayPlug\SyliusPayPlugPlugin\Entity\RefundHistory;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

interface RefundHistoryRepositoryInterface extends RepositoryInterface
{
    public function findLastRefundForPayment(PaymentInterface $payment): ?RefundHistory;
}

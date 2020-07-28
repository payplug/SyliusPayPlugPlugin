<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use PayPlug\SyliusPayPlugPlugin\Entity\RefundHistory;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\PaymentInterface;

final class RefundHistoryRepository extends EntityRepository implements RefundHistoryRepositoryInterface
{
    public function findLastRefundForPayment(PaymentInterface $payment): ?RefundHistory
    {
        return $this->createQueryBuilder('refund_history')
            ->where('refund_history.payment = :payment')
            ->andWhere('refund_history.processed = :processed')
            ->setParameter('payment', $payment)
            ->setParameter('processed', false)
            ->orderBy('refund_history.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}

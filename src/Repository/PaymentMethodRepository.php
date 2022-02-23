<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface as BasePaymentMethodRepositoryInterface;
use Sylius\Component\Resource\Model\ResourceInterface;

final class PaymentMethodRepository implements PaymentMethodRepositoryInterface
{
    private BasePaymentMethodRepositoryInterface $decorated;

    public function __construct(BasePaymentMethodRepositoryInterface $decorated)
    {
        $this->decorated = $decorated;
    }

    public function findOneByGatewayName(string $gatewayFactoryName): ?PaymentMethodInterface
    {
        return $this->decorated->createQueryBuilder('o')
            ->innerJoin('o.gatewayConfig', 'gatewayConfig')
            ->where('gatewayConfig.factoryName = :gatewayFactoryName')
            ->setParameter('gatewayFactoryName', $gatewayFactoryName)
            ->getQuery()
            ->setMaxResults(1)
            ->getSingleResult()
        ;
    }

    /**
     * @inheritdoc
     */
    public function find($id)
    {
        return $this->decorated->find($id);
    }

    public function findAll()
    {
        return $this->decorated->findAll();
    }

    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
    {
        return $this->decorated->findBy($criteria, $orderBy, $limit, $offset);
    }

    public function findOneBy(array $criteria)
    {
        return $this->decorated->findOneBy($criteria);
    }

    public function getClassName()
    {
        return $this->getClassName();
    }

    public function createListQueryBuilder(string $locale): QueryBuilder
    {
        return $this->decorated->createListQueryBuilder($locale);
    }

    public function findEnabledForChannel(ChannelInterface $channel): array
    {
        return $this->decorated->findEnabledForChannel($channel);
    }

    public function findByName(string $name, string $locale): array
    {
        return $this->decorated->findByName($name, $locale);
    }

    public function createPaginator(array $criteria = [], array $sorting = []): iterable
    {
        return $this->decorated->createPaginator($criteria, $sorting);
    }

    public function add(ResourceInterface $resource): void
    {
        $this->decorated->add($resource);
    }

    public function remove(ResourceInterface $resource): void
    {
        $this->decorated->remove($resource);
    }
}

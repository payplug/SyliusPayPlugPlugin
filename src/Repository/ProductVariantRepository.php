<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductVariantRepository as BaseProductVariantRepository;
use Sylius\Component\Core\Model\ProductVariantInterface;

class ProductVariantRepository extends BaseProductVariantRepository implements ProductVariantRepositoryInterface
{
    public function findVariantByProductCodeAndProductOptionValue(
        string $productCode,
        string $variantOptionCode
    ): ?ProductVariantInterface {
        $query = $this->createQueryBuilderByProductCodeWithoutLocale($productCode);
        $query->innerJoin('o.optionValues', 'optionValue');
        $query->innerJoin('optionValue.option', 'option', 'WITH', 'option = optionValue.option');

        $query->andWhere('optionValue.code = :optionCode');
        $query->setParameter('optionCode', $variantOptionCode);

        return $query->getQuery()->getOneOrNullResult();
    }

    private function createQueryBuilderByProductCodeWithoutLocale(string $productCode): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.product', 'product')
            ->andWhere('product.code = :productCode')
            ->setParameter('productCode', $productCode)
        ;
    }
}

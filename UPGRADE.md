# Upgrading from 1.0.0

1. Skip the faulty migration

`php bin/console doctrine:migrations:version "PayPlug\SyliusPayPlugPlugin\Migrations\Version20210410143918" --add`

2. Execute the new migrations to keep the database up to date

`php bin/console doctrine:migration:migrate`

4. Create a new migration to fix the old one (Version20210410143918)

`php bin/console doctrine:migrations:diff --namespace="App\Migrations" --formatted`

6. Execute the new migration

`php bin/console doctrine:migration:migrate`

# Upgrading to 1.2.0

Add Traits for Customer and PaymentMethod entities

1. `App\Entity\Customer\Customer`


   ```php
   <?php

   declare(strict_types=1);

   namespace App\Entity\Customer;

   use Doctrine\ORM\Mapping as ORM;
   use PayPlug\SyliusPayPlugPlugin\Entity\Traits\CustomerTrait;
   use Sylius\Component\Core\Model\Customer as BaseCustomer;

   /**
   * @ORM\Entity
   * @ORM\Table(name="sylius_customer")
   */
   class Customer extends BaseCustomer
   {
      use CustomerTrait;
   }
   ``` 

2. `App\Entity\Payment\PaymentMethod`


   ```php
   <?php
   
   declare(strict_types=1);
   
   namespace App\Entity\Payment;
   
   use Doctrine\ORM\Mapping as ORM;
   use PayPlug\SyliusPayPlugPlugin\Entity\Traits\PaymentMethodTrait;
   use Sylius\Component\Core\Model\PaymentMethod as BasePaymentMethod;
   use Sylius\Component\Payment\Model\PaymentMethodTranslationInterface;
   
   /**
    * @ORM\Entity
    * @ORM\Table(name="sylius_payment_method")
    */
   class PaymentMethod extends BasePaymentMethod
   {
       use PaymentMethodTrait;
   
       protected function createTranslation(): PaymentMethodTranslationInterface
       {
           return new PaymentMethodTranslation();
       }
   }
   ``` 

Run the migration migrate utility to keep the database up to date

`php bin/console doctrine:migration:migrate`

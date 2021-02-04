# Oney Integration

## Require the phone number and check its type

By default the phone number on Sylius is not required when checking out but Oney requires a phone number of type mobile to be able to checkout using it.

The following documentation will give you an example to help you met the Oney requirements.

1. First we need to extend the Sylius Address Type to mark the phoneNumber as required.

    File: `src/Form/Extension/AddressTypeExtension.php`
    
    ```php
    <?php
    
    namespace App\Form\Extension;
    
    use Sylius\Bundle\AddressingBundle\Form\Type\AddressType;
    use Symfony\Component\Form\AbstractTypeExtension;
    use Symfony\Component\Form\FormBuilderInterface;
    
    class AddressTypeExtension extends AbstractTypeExtension
    {
        public function buildForm(FormBuilderInterface $builder, array $options): void
        {
            $builder->get('phoneNumber')->setRequired(true);
        }
    
        public static function getExtendedTypes(): iterable
        {
            return [AddressType::class];
        }
    }
    ```

2. Enable the newly created extention to allow Sylius to find it.

    File: `config/services.yaml`
    ```yaml
    services:
    #...
        app.form.extension.checkout.address:
            class: App\Form\Extension\AddressTypeExtension
            tags:
                - { name: form.type_extension, extended_type: Sylius\Bundle\AddressingBundle\Form\Type\AddressType }
    ```

3. Create the validator constraint that will validate if the phone number provided is of type mobile.

    File: `src/Validator/Constraint/IsMobilePhoneNumber.php`
    
    ```php
    <?php
    
    namespace App\Validator\Constraint;
    
    use Symfony\Component\Validator\Constraint;
    
    /**
     * @Annotation
     */
    class IsMobilePhoneNumber extends Constraint
    {
        public $message = '{{ string }} is not a valid mobile number.';
    }
    ```

    File: `src/Validator/Constraint/IsMobilePhoneNumberValidator.php`
    
    ```php
    <?php
    
    namespace App\Validator\Constraint;
    
    use libphonenumber\PhoneNumberType;
    use libphonenumber\PhoneNumberUtil;
    use Sylius\Component\Resource\Exception\UnexpectedTypeException;
    use Symfony\Component\Validator\Constraint;
    use Symfony\Component\Validator\ConstraintValidator;
    
    class IsMobilePhoneNumberValidator extends ConstraintValidator
    {
        public function validate($value, Constraint $constraint)
        {
            if (!$constraint instanceof IsMobilePhoneNumber) {
                throw new UnexpectedTypeException($constraint, IsMobilePhoneNumber::class);
            }
    
            if (null === $value) {
                return;
            }

            $phoneNumberUtil = PhoneNumberUtil::getInstance();
            $parsedNumber = $phoneNumberUtil->parse($value, 'FR');
    
            if (!$phoneNumberUtil->isValidNumber($parsedNumber) ||
                $phoneNumberUtil->getNumberType($parsedNumber) !== PhoneNumberType::MOBILE) {
    
                $this->context->buildViolation($constraint->message)
                    ->setParameter('{{ string }}', $value)
                    ->addViolation();
            }
        }
    }
    ```

4. Apply the newly created validator to the Address entity

    File: `config/validator/address.yaml`
    
    ```yaml
    App\Entity\Addressing\Address:
         properties:
             phoneNumber:
                 - NotBlank:
                       groups: ['sylius']
                 - App\Validator\Constraint\IsMobilePhoneNumber:
                       groups: [ 'sylius' ]
    ```

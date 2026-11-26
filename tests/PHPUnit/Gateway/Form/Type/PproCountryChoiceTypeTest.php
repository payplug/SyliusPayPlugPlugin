<?php

declare(strict_types=1);

namespace Tests\PayPlug\SyliusPayPlugPlugin\PHPUnit\Gateway\Form\Type;

use PayPlug\SyliusPayPlugPlugin\Gateway\Form\Type\PproCountryChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Intl\Countries;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

final class PproCountryChoiceTypeTest extends TypeTestCase
{
    public function testChoicesAreEveryCountryWithoutAllowedOrAvailableCountries(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class);

        self::assertCount(\count(Countries::getCountryCodes()), $this->choiceValues($form));
    }

    public function testChoicesAreTheAllowedCountriesWithoutAvailableCountries(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, null, ['allowed_countries' => ['DE', 'BE']]);

        self::assertSame(['DE', 'BE'], $this->choiceValues($form));
    }

    public function testChoicesAreTheAvailableCountriesWhenEveryCountryIsAllowed(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, null, ['available_countries' => ['FR', 'BE']]);

        self::assertSame(['BE', 'FR'], $this->choiceValues($form));
    }

    public function testChoicesAreTheAllowedCountriesThatAreAvailableInTheAllowedOrder(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, null, [
            'allowed_countries' => ['NL', 'DE', 'BE'],
            'available_countries' => ['BE', 'FR', 'NL'],
        ]);

        self::assertSame(['NL', 'BE'], $this->choiceValues($form));
    }

    public function testChoicesAreEmptyWhenNoAllowedCountryIsAvailable(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, null, [
            'allowed_countries' => ['BE', 'DE'],
            'available_countries' => ['FR'],
        ]);

        self::assertSame([], $this->choiceValues($form));
        self::assertTrue($form->isDisabled());
    }

    public function testFieldWithoutChoicesKeepsTheStoredCountriesOnSubmit(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, ['BE'], [
            'allowed_countries' => ['BE', 'DE'],
            'available_countries' => ['FR'],
        ]);

        $form->submit([]);

        self::assertSame(['BE'], $form->getData());
    }

    public function testChoicesAreEmptyWithoutAnyAvailableCountry(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, null, ['available_countries' => []]);

        self::assertSame([], $this->choiceValues($form));
    }

    public function testCountryThatIsNotAvailableIsRefused(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, null, [
            'allowed_countries' => ['BE', 'DE'],
            'available_countries' => ['BE'],
        ]);

        $form->submit(['DE']);

        self::assertFalse($form->isValid());
        self::assertSame('The selected choice is invalid.', $form->getErrors()[0]?->getMessage());
        self::assertSame([], $form->getData());
    }

    public function testEveryVisibleChoiceSelectedMapsToNullWhenNothingWasExcluded(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, null, [
            'allowed_countries' => ['BE', 'DE', 'NL'],
            'available_countries' => ['BE', 'NL'],
        ]);

        $form->submit(['NL', 'BE']);

        self::assertNull($form->getData());
    }

    public function testEveryAllowedCountrySelectedMapsToNull(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, ['BE'], [
            'allowed_countries' => ['BE', 'NL'],
            'available_countries' => ['BE', 'FR', 'NL'],
        ]);

        $form->submit(['NL', 'BE']);

        self::assertNull($form->getData());
    }

    public function testEveryVisibleChoiceSelectedKeepsACountryExcludedOutOfView(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, ['BE'], [
            'allowed_countries' => ['BE', 'DE', 'NL'],
            'available_countries' => ['BE', 'NL'],
        ]);

        $form->submit(['NL', 'BE']);

        self::assertSame(['BE', 'NL'], $form->getData());
    }

    public function testRestrictionEqualToTheVisibleChoicesIsKeptWhenSubmittedUnchanged(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, ['FR'], [
            'allowed_countries' => ['FR', 'BE', 'IT', 'ES', 'NL'],
            'available_countries' => ['FR'],
        ]);

        $form->submit(['FR']);

        self::assertSame(['FR'], $form->getData());
    }

    public function testStoredCountryOutOfViewIsKeptOnSubmit(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, ['BE', 'DE'], [
            'allowed_countries' => ['BE', 'DE', 'NL'],
            'available_countries' => ['BE', 'NL'],
        ]);

        $form->submit(['BE']);

        self::assertSame(['BE', 'DE'], $form->getData());
    }

    public function testStoredCountryNoLongerAllowedIsNotKeptOnSubmit(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, ['BE', 'DE'], [
            'allowed_countries' => ['BE', 'NL'],
            'available_countries' => ['BE', 'NL'],
        ]);

        $form->submit(['BE']);

        self::assertSame(['BE'], $form->getData());
    }

    public function testDeselectingAVisibleCountryWithoutRestrictionKeepsTheCountriesOutOfView(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, null, [
            'allowed_countries' => ['BE', 'DE', 'NL'],
            'available_countries' => ['BE', 'NL'],
        ]);

        $form->submit(['BE']);

        self::assertSame(['BE', 'DE'], $form->getData());
    }

    public function testNoCountrySelectedIsAnEmptyListWithoutRestriction(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, null, [
            'allowed_countries' => ['BE', 'DE', 'NL'],
            'available_countries' => ['BE', 'NL'],
        ]);

        $form->submit([]);

        self::assertSame([], $form->getData());
    }

    public function testStoredCountryThatIsNotAvailableIsNotRendered(): void
    {
        $form = $this->factory->create(PproCountryChoiceType::class, ['BE', 'DE'], [
            'allowed_countries' => ['BE', 'DE'],
            'available_countries' => ['BE', 'FR'],
        ]);

        self::assertSame(['BE'], $form->createView()->vars['value']);
    }

    public function testAvailableCountriesMustBeAListOfCodes(): void
    {
        $this->expectException(InvalidOptionsException::class);

        $this->factory->create(PproCountryChoiceType::class, null, ['available_countries' => 'FR']);
    }

    /**
     * @return list<string>
     */
    private function choiceValues(FormInterface $form): array
    {
        return array_values(array_map(
            static fn ($choiceView): string => $choiceView->value,
            $form->createView()->vars['choices'],
        ));
    }
}

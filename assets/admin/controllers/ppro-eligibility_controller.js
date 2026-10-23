import { Controller } from '@hotwired/stimulus';

import { validateAmounts, validateCountries } from './ppro-eligibility_validation.js';

/*
 * Live feedback on the PPRO amount and country settings. It never blocks the submit: the
 * server-side validation is the one that refuses an invalid save.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static values = {
    minBound: Number,
    maxBound: Number,
    messages: Object,
  };

  static targets = ['minField', 'maxField', 'minError', 'maxError', 'countriesField', 'countriesError'];

  connect() {
    this.minInput = this.minFieldTarget.querySelector('input');
    this.maxInput = this.maxFieldTarget.querySelector('input');
    this.countriesSelect = this.countriesFieldTarget.querySelector('select');

    this.onAmountInput = () => this.validateAmounts();
    this.onCountriesChange = () => this.validateCountries();

    [this.minInput, this.maxInput].forEach((input) => input?.addEventListener('input', this.onAmountInput));
    this.countriesSelect?.addEventListener('change', this.onCountriesChange);
  }

  disconnect() {
    [this.minInput, this.maxInput].forEach((input) => input?.removeEventListener('input', this.onAmountInput));
    this.countriesSelect?.removeEventListener('change', this.onCountriesChange);
  }

  validateAmounts() {
    if (!this.minInput || !this.maxInput || this.minInput.disabled) {
      return;
    }

    const errors = validateAmounts({
      min: this.minInput.value,
      max: this.maxInput.value,
      minBound: this.hasMinBoundValue ? this.minBoundValue : undefined,
      maxBound: this.hasMaxBoundValue ? this.maxBoundValue : undefined,
    });

    this.showError(this.minInput, this.minErrorTarget, errors.min);
    this.showError(this.maxInput, this.maxErrorTarget, errors.max);
  }

  validateCountries() {
    if (!this.countriesSelect || this.countriesSelect.disabled) {
      return;
    }

    this.showError(this.countriesSelect, this.countriesErrorTarget, validateCountries(this.countriesSelect.selectedOptions.length));
  }

  showError(field, errorElement, messageKey) {
    errorElement.textContent = messageKey ? this.messagesValue[messageKey] ?? '' : '';
    errorElement.hidden = !messageKey;
    field.classList.toggle('is-invalid', Boolean(messageKey));
  }
}

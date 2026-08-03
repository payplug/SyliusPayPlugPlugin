import { Controller } from '@hotwired/stimulus';

const ALLOWED_BRANDS = ['CB', 'VISA', 'MASTERCARD'];

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['error', 'submitButton'];

  connect() {
    if (typeof payplug_hosted_fields_params === 'undefined') {
      return;
    }

    this.form = this.element.closest('form');
    this.hfields = window.dalenys.hostedFields({
      key: {
        id: payplug_hosted_fields_params.key_id,
        value: payplug_hosted_fields_params.key_value,
      },
      fields: {
        brand: { id: 'brand-container' },
        card: { id: 'card-container' },
        expiry: { id: 'expiry-container' },
        cryptogram: { id: 'cvv-container' },
      },
      location: payplug_hosted_fields_params.locale,
    });
    this.hfields.load();

    if (this.hasSubmitButtonTarget) {
      this.submitButtonTarget.addEventListener('click', (event) => {
        event.preventDefault();
        this.tokenizeAndSubmit();
      });
    }
  }

  tokenizeAndSubmit() {
    this.hideError();
    this.hfields.createToken((result) => {
      if (result.execCode !== '0000') {
        this.showError(payplug_hosted_fields_params.error.tokenization_failed);
        return;
      }

      const selectedBrand = (result.selectedBrand || '').toUpperCase();
      if (!ALLOWED_BRANDS.includes(selectedBrand)) {
        this.showError(payplug_hosted_fields_params.error.unsupported_brand);
        return;
      }

      const saveCardElement = this.element.querySelector('#hostedfields_savecard');
      const saveCard = null !== saveCardElement && saveCardElement.checked;

      this.form.querySelector('#hostedfields_token').value = result.hfToken;
      this.form.querySelector('#hostedfields_selected_brand').value = selectedBrand;
      this.form.querySelector('#hostedfields_save_card').value = saveCard ? 'true' : 'false';
      this.form.submit();
    });
  }

  showError(message) {
    if (!this.hasErrorTarget) {
      return;
    }
    this.errorTarget.textContent = message;
    this.errorTarget.classList.remove('payplugHostedFields__error--hide');
  }

  hideError() {
    if (!this.hasErrorTarget) {
      return;
    }
    this.errorTarget.textContent = '';
    this.errorTarget.classList.add('payplugHostedFields__error--hide');
  }
}

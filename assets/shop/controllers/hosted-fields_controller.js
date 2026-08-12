import { Controller } from '@hotwired/stimulus';

const ALLOWED_BRANDS = ['CB', 'VISA', 'MASTERCARD'];

// Applied inside each hosted iframe (Dalenys renders these rules into the field's own
// document). The SDK only accepts a small whitelist of CSS properties here — anything
// outside it (we tried "height": SDK logged "Css property ... is not supported" and threw,
// which aborted ALL fields, not just the one it complained about) — so stick to exactly the
// properties confirmed by PayPlug's own documented example (font-size/color/font-style).
// Background and sizing/centering of the field's content are NOT controllable this way.
const FIELD_STYLE = {
  input: {
    'font-size': '14px',
    color: '#2B343D',
    'background-color': 'transparent',
  },
  '::placeholder': {
    'font-size': '14px',
    color: '#969a9f',
  },
};

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['container', 'error', 'submitButton'];

  connect() {
    if (typeof payplug_hosted_fields_params === 'undefined') {
      return;
    }

    this.form = this.element.closest('form');
    this.hfields = null;

    if (this.hasSubmitButtonTarget) {
      this.submitButtonTarget.addEventListener('click', (event) => {
        event.preventDefault();
        this.tokenizeAndSubmit();
      });
    }

    // Stimulus connects as soon as the markup is in the DOM, even though the payment method
    // container starts hidden (see shop/select_payment/choice.html.twig). Mounting the
    // cross-origin Dalenys iframes into a display:none container breaks their rendering, so
    // load them only once this payment method is actually selected.
    const isChecked = this.getPaymentMethodSelectors({
      methodCode: payplug_hosted_fields_params.payment_method_code,
      checked: true,
    });
    if (isChecked.length) {
      this.openFields();
    }

    this.getPaymentMethodSelectors().forEach((element) => {
      element.addEventListener('change', (e) => {
        if (payplug_hosted_fields_params.payment_method_code === e.currentTarget.value && e.currentTarget.checked) {
          this.openFields();
        }
      });
    });
  }

  handleShow(event) {
    if (this.hasContainerTarget) {
      import('jquery').then(({ default: $ }) => {
        $(this.containerTarget).slideDown();
      });
      this.openFields();
      this.containerTarget.dataset.paymentInlineSubmit = "true";
      this.element.dispatchEvent(new CustomEvent('payment-method-state-change', { bubbles: true }));
    }
  }

  handleHide(event) {
    if (this.hasContainerTarget) {
      import('jquery').then(({ default: $ }) => {
        $(this.containerTarget).slideUp();
      });
      this.closeFields();
      this.containerTarget.dataset.paymentInlineSubmit = "false";
      this.element.dispatchEvent(new CustomEvent('payment-method-state-change', { bubbles: true }));
    }
  }

  getPaymentMethodSelectors({ methodCode, checked } = {}) {
    const baseSelector = '[id*=checkout_select_payment_payments]';

    if (methodCode) {
      if (checked) {
        return document.querySelectorAll(`${baseSelector}[value=${methodCode}]:checked`);
      }
      return document.querySelectorAll(`${baseSelector}[value=${methodCode}]`);
    }
    return document.querySelectorAll(baseSelector);
  }

  openFields() {
    if (this.hasContainerTarget) {
      this.containerTarget.classList.add('payplugHostedFields--loaded');
    }
    if (null === this.hfields) {
      this.load();
    }
  }

  closeFields() {
    if (this.hasContainerTarget) {
      this.containerTarget.classList.remove('payplugHostedFields--loaded');
    }
  }

  load() {
    this.hfields = window.dalenys.hostedFields({
      companyId: payplug_hosted_fields_params.companyId,
      fields: {
        brand:       { id: "brand-container", version: 2, style: FIELD_STYLE },
        card:        { id: "card-container", placeholder: "•••• •••• •••• ••••", enableAutospacing: true, style: FIELD_STYLE },
        expiry:      { id: "expiry-container", placeholder: "MM/AA", style: FIELD_STYLE },
        cryptogram:  { id: "cvv-container", placeholder: "CVV", style: FIELD_STYLE },
      },

      locale: payplug_hosted_fields_params.locale,
    });
    this.hfields.load();
  }

  tokenizeAndSubmit() {
    if (null === this.hfields) {
      // Fields were never mounted (payment method not selected yet): nothing to tokenize.
      return;
    }

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
      // last4/expirationMonth/expirationYear/country field names are unverified against a real
      // createToken() response (no vendored SDK docs/types exist in this repo to confirm them) —
      // if wrong, these silently fall back to '' rather than error, so double-check against a
      // real sandbox response if saved-card metadata (Card::$last4/$expirationMonth/etc.) ever
      // looks wrong in practice.
      this.form.querySelector('#hostedfields_last4').value = result.last4 || '';
      this.form.querySelector('#hostedfields_exp_month').value = result.expirationMonth || '';
      this.form.querySelector('#hostedfields_exp_year').value = result.expirationYear || '';
      this.form.querySelector('#hostedfields_country').value = result.country || '';
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

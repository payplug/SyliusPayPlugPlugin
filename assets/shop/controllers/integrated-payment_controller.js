import { Controller } from '@hotwired/stimulus';
import WebFont from 'webfontloader';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['container']
  static values = {
    code: String,
    factoryName: String,
  }
  initialize() {
    WebFont.load({
      google: {
        families: ["Poppins:400,600"],
      },
    });
  }
  connect() {
    this.options = {
      api: null,
      cartId: null,
      form: {},
      fieldsValid: {
        cardHolder: false,
        pan: false,
        cvv: false,
        exp: false,
      },
      fieldsEmpty: {
        cardHolder: true,
        pan: true,
        cvv: true,
        exp: true,
      },
      inputStyle: {
        default: {
          color: '#2B343D',
          fontFamily: 'Poppins, Arial, sans-serif',
          fontSize: '14px',
          textAlign: 'left',
          '::placeholder': {
            color: '#969a9f',
          },
          ':focus': {
            color: '#2B343D',
          }
        },
        invalid: {
          color: '#E91932'
        }
      },
      save_card: false,
      schemes: null,
      scheme: null,
    };

    // Verify if we have required data
    // TODO: Pass as param
    if (typeof payplug_integrated_payment_params === 'undefined') {
      return;
    }

    this.form = this.element.closest('form');

    if (payplug_integrated_payment_params.has_saved_cards) {
      const otherCardRadio = this.element.querySelector('#payplug_choice_card_other');
      const payplugRadio = document.querySelector(`[id*="checkout_select_payment_payments"][value="${payplug_integrated_payment_params.payment_method_code}"]`);

      // Initial check
      if (
          (otherCardRadio && otherCardRadio.checked && payplugRadio && payplugRadio.checked) ||
          (otherCardRadio && otherCardRadio.checked && !payplugRadio && document.querySelector('.payplug-payment-choice__input:checked'))
      ) {
        this.openFields();
      }

      this.element.querySelectorAll('.payment-choice__input, [id*="checkout_select_payment_payments"]').forEach((element) => {
        element.addEventListener('change', (e) => {
          const isOtherCardChecked = this.element.querySelector('#payplug_choice_card_other')?.checked;
          const isPayplugSelected = document.querySelector(`[id*="checkout_select_payment_payments"][value="${payplug_integrated_payment_params.payment_method_code}"]`)?.checked;

          if (isOtherCardChecked && isPayplugSelected) {
            this.openFields();
          }
        })
      })
      return;
    }

    const isChecked = this.getPaymentMethodSelectors({ methodCode: payplug_integrated_payment_params.payment_method_code, checked: true });
    if (isChecked.length) {
      this.openFields();
    }

    const selectPaymentMethodsField = this.getPaymentMethodSelectors();
    selectPaymentMethodsField.forEach((element) => {
      // On payment method select, open fields if payplug selected
      element.addEventListener('change', (e) => {
        if (payplug_integrated_payment_params.payment_method_code === e.currentTarget.value && e.currentTarget.checked) {
          this.openFields();
        }
      })
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
    this.containerTarget.classList.add('payplugIntegratedPayment--loaded');
    if (null === this.options.api) {
      this.load();
    }
  }

  closeFields() {
    this.containerTarget.classList.remove('payplugIntegratedPayment--loaded');
  }

  load() {
    this.options.api = new window.Payplug.IntegratedPayment(payplug_integrated_payment_params.is_test_mode);
    this.options.api.setDisplayMode3ds(window.Payplug.DisplayMode3ds.LIGHTBOX);

    const container = this.hasContainerTarget ? this.containerTarget : this.element;

    this.options.form.cardHolder = this.options.api.cardHolder(
      container.querySelector('.cardHolder-input-container'),
      {
        default: this.options.inputStyle.default,
        placeholder: payplug_integrated_payment_params.cardholder
      }
    );
    this.options.form.pan = this.options.api.cardNumber(
      container.querySelector('.pan-input-container'),
      {
        default: this.options.inputStyle.default,
        placeholder: payplug_integrated_payment_params.pan
      }
    );
    this.options.form.cvv = this.options.api.cvv(
      container.querySelector('.cvv-input-container'),
      {
        default: this.options.inputStyle.default,
        placeholder: payplug_integrated_payment_params.cvv
      }
    );
    this.options.form.exp = this.options.api.expiration(
      container.querySelector('.exp-input-container'),
      {
        default: this.options.inputStyle.default,
        placeholder: payplug_integrated_payment_params.exp
      }
    );
    this.options.schemes = this.options.api.getSupportedSchemes();
    this.bindEvents();
    this.fieldValidation();
  }
  bindEvents() {
    const container = this.hasContainerTarget ? this.containerTarget : this.element;
    const paidButton = container.querySelector('#paid');
    if (paidButton) {
      paidButton.addEventListener('click', (event) => {
        event.preventDefault();
        this.options.api.validateForm();
      });
    }

    this.options.api.onValidateForm(async ({ isFormValid }) => {
      if (isFormValid) {
        this.toggleLoader();
        const saveCardElement = this.element.querySelector('#savecard');
        if (null !== saveCardElement) {
          this.options.save_card = saveCardElement.checked;
        }
        const chosenScheme = this.element.querySelector('input.schemeOptions:checked');
        this.options.scheme = window.Payplug.Scheme.AUTO;
        if (null !== chosenScheme) {
          this.options.scheme = chosenScheme.value;
        }
        if (payplug_integrated_payment_params.payment_id !== undefined) {
          this.options.api.pay(payplug_integrated_payment_params.payment_id, this.options.scheme, {save_card: this.options.save_card});
          return;
        }
        const response = await fetch(payplug_integrated_payment_params.routes.init_payment, {method: 'POST'});
        const data = await response.json();
        this.options.api.pay(data.payment_id, this.options.scheme, {save_card: this.options.save_card});
      }
    });
    this.options.api.onCompleted((event) => {
      if (event.error) {
        console.error(event.error);
        return;
      }
      document.querySelector('input[name=payplug_integrated_payment_token]').value = event.token;
      this.form.submit();
    });
  }
  fieldValidation () {
    const container = this.hasContainerTarget ? this.containerTarget : this.element;
    Object.keys(this.options.form).forEach((key) => {
      const field = this.options.form[key];
      field.onChange((err) => {
        if (err.error) {
          container.querySelector(`.payplugIntegratedPayment__error--${key}`).classList.remove('payplugIntegratedPayment__error--hide');
          container.querySelector(`.${key}-input-container`).classList.add('payplugIntegratedPayment__container--invalid');
          if (err.error.name === "FIELD_EMPTY") {
            container.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".emptyField").classList.remove('payplugIntegratedPayment__error--hide');
            container.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".invalidField").classList.add('payplugIntegratedPayment__error--hide');
          } else {
            container.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".invalidField").classList.remove('payplugIntegratedPayment__error--hide');
            container.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".emptyField").classList.add('payplugIntegratedPayment__error--hide');
          }
        } else {
          container.querySelector(`.payplugIntegratedPayment__error--${key}`).classList.add('payplugIntegratedPayment__error--hide');
          container.querySelector(`.${key}-input-container`).classList.remove('payplugIntegratedPayment__container--invalid');
          container.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".invalidField").classList.add('payplugIntegratedPayment__error--hide');
          container.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".emptyField").classList.add('payplugIntegratedPayment__error--hide');
          this.options.fieldsValid[key] = true;
          this.options.fieldsEmpty[key] = false;
        }
      });
    })
  }
  toggleLoader() {
    const container = this.hasContainerTarget ? this.containerTarget : this.element;
    container.querySelector('.sylius-shop-loader').classList.toggle('d-none');
  }
}

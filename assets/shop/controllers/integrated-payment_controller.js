import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static values = {
    code: String,
    factoryName: String,
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
    if (payplug_integrated_payment_params === undefined) {
      return;
    }

    if (payplug_integrated_payment_params.has_saved_cards) {
      document.querySelectorAll('.payment-choice__input, .payment-item input[type=radio]:not([name=schemeOptions])')
        .forEach((element) => {
          element.addEventListener('change', (e) => { // TODO: function
            if (
              'payplug_choice_card_other' === e.currentTarget.id
              && e.currentTarget.checked
              || e.target.value === payplug_integrated_payment_params.payment_method_code
              && document.querySelector('#payplug_choice_card_other').checked
            ) {
              this.openFields(); // TODO: this.openFields()
              return;
            }
            this.closeFields();
          })
        })
      return;
    }

    const paplugIsChecked = this.getPaymentMethodSelectors({ methodCode: payplug_integrated_payment_params.payment_method_code, checked: true });
    if (paplugIsChecked.length) {
      this.openFields();
    }

    const selectPaymentMethodsField= this.getPaymentMethodSelectors();
    selectPaymentMethodsField.forEach((element) => {
      // On payment method select, open or close card fields
      element.addEventListener('change', (e) => {
        if (payplug_integrated_payment_params.payment_method_code === e.currentTarget.value && e.currentTarget.checked) {
          this.openFields();
          return;
        }
        this.closeFields();
      })
    });
  }

  getPaymentMethodSelectors({ methodCode, checked } = {}) {
    // const baseSelector = '[id*=sylius_checkout_select_payment_payments]'; // old id
    const baseSelector = '[id*=sylius_shop_checkout_select_payment_payments]';

    if (methodCode) {
      if (checked) {
        return document.querySelectorAll(`${baseSelector}[value=${methodCode}]:checked`);
      }
      return document.querySelectorAll(`${baseSelector}[value=${methodCode}]`);
    }
    return document.querySelectorAll(baseSelector);
  }

  openFields() {
    document.querySelector('.payplugIntegratedPayment').classList.add('payplugIntegratedPayment--loaded');
    document.querySelector('button[type=submit]').classList.add('disabled');
    if (null === this.options.api) {
      this.load();
    }
  }
  closeFields() {
    document.querySelector('.payplugIntegratedPayment').classList.remove('payplugIntegratedPayment--loaded');
    document.querySelector('button[type=submit]').classList.remove('disabled');
  }

  load() {
    this.options.api = new Payplug.IntegratedPayment(payplug_integrated_payment_params.is_test_mode);

    this.options.api.setDisplayMode3ds(Payplug.DisplayMode3ds.LIGHTBOX);

    this.options.form.cardHolder = this.options.api.cardHolder(
      document.querySelector('.cardHolder-input-container'),
      {
        default: this.options.inputStyle.default,
        placeholder: payplug_integrated_payment_params.cardholder
      }
    );
    this.options.form.pan = this.options.api.cardNumber(
      document.querySelector('.pan-input-container'),
      {
        default: this.options.inputStyle.default,
        placeholder: payplug_integrated_payment_params.pan
      }
    );
    this.options.form.cvv = this.options.api.cvv(
      document.querySelector('.cvv-input-container'),
      {
        default: this.options.inputStyle.default,
        placeholder: payplug_integrated_payment_params.cvv
      }
    );
    this.options.form.exp = this.options.api.expiration(
      document.querySelector('.exp-input-container'),
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
    document.querySelector('#paid').addEventListener('click', (event) => {
      event.preventDefault();

      this.options.api.validateForm();
    });
    this.options.api.onValidateForm(async (aaa) => {
      const {isFormValid} = aaa;
      if (isFormValid) {
        this.toggleLoader();
        const saveCardElement = document.querySelector('#savecard');
        if (null !== saveCardElement) {
          this.options.save_card = saveCardElement.checked;
        }
        const chosenScheme = document.querySelector('[name=schemeOptions]:checked');
        this.options.scheme = Payplug.Scheme.AUTO;
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
      document.querySelector('form[name=sylius_shop_checkout_select_payment]').submit();
    });
  }

  fieldValidation () {
    Object.keys(this.options.form).forEach((key) => {
      const field = this.options.form[key];
      field.onChange((err) => {
        if (err.error) {
          document.querySelector(`.payplugIntegratedPayment__error--${key}`).classList.remove('payplugIntegratedPayment__error--hide');
          document.querySelector(`.${key}-input-container`).classList.add('payplugIntegratedPayment__container--invalid');
          if (err.error.name === "FIELD_EMPTY") {
            document.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".emptyField").classList.remove('payplugIntegratedPayment__error--hide');
            document.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".invalidField").classList.add('payplugIntegratedPayment__error--hide');
          } else {
            document.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".invalidField").classList.remove('payplugIntegratedPayment__error--hide');
            document.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".emptyField").classList.add('payplugIntegratedPayment__error--hide');
          }
        } else {
          document.querySelector(`.payplugIntegratedPayment__error--${key}`).classList.add('payplugIntegratedPayment__error--hide');
          document.querySelector(`.${key}-input-container`).classList.remove('payplugIntegratedPayment__container--invalid');
          document.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".invalidField").classList.add('payplugIntegratedPayment__error--hide');
          document.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".emptyField").classList.add('payplugIntegratedPayment__error--hide');
          this.options.fieldsValid[key] = true;
          this.options.fieldsEmpty[key] = false;
        }
      });
    })
  }
  fieldValidationNOT_WORKING () {
    $.each(this.options.form, function (key, field) {
      field.onChange(function(err) {
        if (err.error) {
          document.querySelector(`.payplugIntegratedPayment__error--${key}`).classList.remove('payplugIntegratedPayment__error--hide');
          document.querySelector(`.${key}-input-container`).classList.add('payplugIntegratedPayment__container--invalid');
          if (err.error.name === "FIELD_EMPTY") {
            document.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".emptyField").classList.remove('payplugIntegratedPayment__error--hide');
            document.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".invalidField").classList.add('payplugIntegratedPayment__error--hide');
          } else {
            document.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".invalidField").classList.remove('payplugIntegratedPayment__error--hide');
            document.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".emptyField").classList.add('payplugIntegratedPayment__error--hide');
          }
        } else {
          document.querySelector(`.payplugIntegratedPayment__error--${key}`).classList.add('payplugIntegratedPayment__error--hide');
          document.querySelector(`.${key}-input-container`).classList.remove('payplugIntegratedPayment__container--invalid');
          document.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".invalidField").classList.add('payplugIntegratedPayment__error--hide');
          document.querySelector(`.payplugIntegratedPayment__error--${key}`).querySelector(".emptyField").classList.add('payplugIntegratedPayment__error--hide');
          this.options.fieldsValid[key] = true;
          this.options.fieldsEmpty[key] = false;
        }
      });
    });
  }

  toggleLoader() {
    document.querySelector('.payplugIntegratedPayment').querySelector('.dimmer').classList.toggle('active');
  }
}

const IntegratedPayment = {
  options: {
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
  },
  init() {
    if (payplug_integrated_payment_params === undefined) {
      return;
    }
    if (payplug_integrated_payment_params.has_saved_cards) {
      document.querySelectorAll('.payment-choice__input, .payment-item input[type=radio]').forEach((element) => {
        element.addEventListener('change', (e) => {
          if (
            'payplug_choice_card_other' === e.currentTarget.id && e.currentTarget.checked ||
            e.target.value === payplug_integrated_payment_params.payment_method_code && document.querySelector('#payplug_choice_card_other').checked
          ) {
            IntegratedPayment.openFields();
            return;
          }
          IntegratedPayment.closeFields();
        })
      })
      return;
    }
    if (document.querySelector(`[id*=sylius_checkout_select_payment_payments][value=${payplug_integrated_payment_params.payment_method_code}]:checked`)) {
      IntegratedPayment.openFields();
    }
    document.querySelectorAll('[id*=sylius_checkout_select_payment_payments]').forEach((element) => {
      element.addEventListener('change', (e) => {
        if (payplug_integrated_payment_params.payment_method_code === e.currentTarget.value && e.currentTarget.checked) {
          IntegratedPayment.openFields();
          return;
        }
        IntegratedPayment.closeFields();
      })
    });
  },
  openFields() {
    document.querySelector('.payplugIntegratedPayment').classList.add('payplugIntegratedPayment--loaded');
    document.querySelector('button[type=submit]').classList.add('disabled');
    if (null === IntegratedPayment.options.api) {
      IntegratedPayment.load();
    }
  },
  closeFields() {
    document.querySelector('.payplugIntegratedPayment').classList.remove('payplugIntegratedPayment--loaded');
    document.querySelector('button[type=submit]').classList.remove('disabled');
  },
  load() {
    IntegratedPayment.options.api = integratedPaymentApi = new Payplug.IntegratedPayment(payplug_integrated_payment_params.is_test_mode);
    integratedPaymentApi.setDisplayMode3ds(Payplug.DisplayMode3ds.LIGHTBOX);
    IntegratedPayment.options.form.cardHolder = integratedPaymentApi.cardHolder(
      document.querySelector('.cardHolder-input-container'),
      {
        default: IntegratedPayment.options.inputStyle.default,
        placeholder: payplug_integrated_payment_params.cardholder
      }
    );
    IntegratedPayment.options.form.pan = integratedPaymentApi.cardNumber(
      document.querySelector('.pan-input-container'),
      {
        default: IntegratedPayment.options.inputStyle.default,
        placeholder: payplug_integrated_payment_params.pan
      }
    );
    IntegratedPayment.options.form.cvv = integratedPaymentApi.cvv(
      document.querySelector('.cvv-input-container'),
      {
        default: IntegratedPayment.options.inputStyle.default,
        placeholder: payplug_integrated_payment_params.cvv
      }
    );
    IntegratedPayment.options.form.exp = integratedPaymentApi.expiration(
      document.querySelector('.exp-input-container'),
      {
        default: IntegratedPayment.options.inputStyle.default,
        placeholder: payplug_integrated_payment_params.exp
      }
    );
    IntegratedPayment.options.schemes = integratedPaymentApi.getSupportedSchemes();
    IntegratedPayment.bindEvents();
    IntegratedPayment.fieldValidation();
  },
  bindEvents() {
    document.querySelector('#paid').addEventListener('click', (event) => {
      event.preventDefault();
      integratedPaymentApi.validateForm();
    });
    integratedPaymentApi.onValidateForm(async ({isFormValid}) => {
      if (isFormValid) {
        const saveCardElement = document.querySelector('#savecard');
        if (null !== saveCardElement) {
          IntegratedPayment.options.save_card = saveCardElement.checked;
        }
        const chosenScheme = document.querySelector('[name=schemeOptions]:checked');
        IntegratedPayment.options.scheme = Payplug.Scheme.AUTO;
        if (null !== chosenScheme) {
          IntegratedPayment.options.scheme = chosenScheme.value;
        }
        if (payplug_integrated_payment_params.payment_id !== undefined) {
          integratedPaymentApi.pay(payplug_integrated_payment_params.payment_id, IntegratedPayment.options.scheme, {save_card: IntegratedPayment.options.save_card});
          return;
        }
        const response = await fetch(payplug_integrated_payment_params.routes.init_payment, {method: 'POST'});
        const data = await response.json();
        integratedPaymentApi.pay(data.payment_id, IntegratedPayment.options.scheme, {save_card: IntegratedPayment.options.save_card});
      }
    });
    integratedPaymentApi.onCompleted((event) => {
      if (event.error) {
        console.error(event.error);
        return;
      }
      document.querySelector('input[name=payplug_integrated_payment_token]').value = event.token;
      document.querySelector('form[name=sylius_checkout_select_payment]').submit();
    });
  },
  fieldValidation () {
    $.each(IntegratedPayment.options.form, function (key, field) {
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
          IntegratedPayment.options.fieldsValid[key] = true;
          IntegratedPayment.options.fieldsEmpty[key] = false;
        }
      });
    });
  }
};

document.addEventListener("DOMContentLoaded", IntegratedPayment.init, false);

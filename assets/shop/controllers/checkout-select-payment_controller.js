import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['trigger'];

  connect() {
    this.toggleGateway();

    const form = document.querySelector('form[name="sylius_shop_checkout_select_payment"]');
    form.addEventListener('submit', (event) => {
      this.handleForm();
    });
  }
  toggleGateway() {
    let checkedPaymentMethodInput;

    $(this.triggerTargets).each((k, v) => {
      const paymentMethodInputId = $(v).data('payment-input-id');
      checkedPaymentMethodInput = $(`#${paymentMethodInputId}:checked`);
      if (checkedPaymentMethodInput.length) {
        return false;
      }
    })

    if (checkedPaymentMethodInput.length) {
      $(`.payment-method-choice[data-payment-input-id="${$(checkedPaymentMethodInput).attr('id')}"]`).show();
    }

    const $inputs = $('input[id*=sylius_shop_checkout_select_payment_payments]');
    $inputs.on('change', (event) => {
      const clickedPaymentMethodId = $(event.currentTarget).attr('id');
      $('.payment-method-choice').slideUp(); // Hide others
      $(`.payment-method-choice[data-payment-input-id="${clickedPaymentMethodId}"]`).slideDown(); // Show current
    });
  }
  handleForm() {
    if ($('.checkbox-oney :radio:checked').length) {
      $('.checkbox-payplug').closest('.payment-item').find('.payment-choice__input:checked').prop('checked', false);
    } else if ($('.checkbox-payplug :radio:checked').length) {
      $('.checkbox-oney').closest('.payment-item').find('.payment-choice__input:checked').prop('checked', false);
    }

    $('input#payplug_choice_card_other').attr('disabled', true);
    $('form[name="sylius_shop_checkout_select_payment_payments"]').submit();
  }
}

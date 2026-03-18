import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['trigger'];
  form = null;

  connect() {
    this.form = this.element.closest('form') || document.querySelector('form[name*="checkout_select_payment"]');
    this.findNextStepButton();

    this.handleStateChange = this.handleStateChange.bind(this);
    this.element.addEventListener('payment-method-state-change', this.handleStateChange);

    this.toggleGateway();

    if (this.form) {
      this.form.addEventListener('submit', (event) => {
        this.handleForm();
      });
    }
  }

  disconnect() {
    this.element.removeEventListener('payment-method-state-change', this.handleStateChange);
  }

  handleStateChange(event) {
    this.updateNextStepButtonVisibility(event.target);
  }

  findNextStepButton() {
    if (!this.form) return;
    this.nextStepButton = this.form.querySelector('button[type="submit"], button:not([type])');
  }

  toggleGateway() {
    const $inputs = $(this.element).find('input[id*="checkout_select_payment_payments"]');
    const checkedInput = $inputs.filter(':checked')[0];

    // Hide all initially
    this.triggerTargets.forEach(target => $(target).hide());

    if (checkedInput) {
      const currentTarget = this.triggerTargets.find(target => target.dataset.paymentInputId === checkedInput.id);
      if (currentTarget) {
        $(currentTarget).show();
        this.updateNextStepButtonVisibility(currentTarget);
      } else {
        this.toggleNextStepButton(true);
      }
    }

    $inputs.on('change', (event) => {
      const clickedPaymentMethodId = event.currentTarget.id;

      // Hide all choices
      this.triggerTargets.forEach(target => $(target).slideUp());

      const currentTarget = this.triggerTargets.find(target => target.dataset.paymentInputId === clickedPaymentMethodId);
      if (currentTarget) {
        $(currentTarget).slideDown(() => {
          this.updateNextStepButtonVisibility(currentTarget);
        });
      } else {
        this.toggleNextStepButton(true);
      }
    });
  }

  updateNextStepButtonVisibility(container) {
    let targetContainer = container;

    if (!targetContainer || !targetContainer.dataset.paymentInputId) {
      // Fallback to currently checked input
      const checkedInput = this.element.querySelector('input[id*="checkout_select_payment_payments"]:checked');
      if (checkedInput) {
        targetContainer = this.triggerTargets.find(target => target.dataset.paymentInputId === checkedInput.id);
      }
    }

    if (!targetContainer) {
      this.toggleNextStepButton(true);
      return;
    }

    const handlesSubmit = targetContainer.dataset.paymentHandlesSubmit === 'true' ||
                         targetContainer.querySelector('[data-payment-handles-submit="true"]') !== null;

    this.toggleNextStepButton(!handlesSubmit);
  }

  toggleNextStepButton(show) {
    if (!this.nextStepButton) {
      this.findNextStepButton();
    }
    if (!this.nextStepButton) return;

    if (show) {
      this.nextStepButton.classList.remove('d-none');
      this.nextStepButton.disabled = false;
      this.nextStepButton.style.display = '';
    } else {
      this.nextStepButton.classList.add('d-none');
      this.nextStepButton.disabled = true;
      this.nextStepButton.style.display = 'none';
    }
  }

  handleForm() {
    if ($('.checkbox-oney :radio:checked').length) {
      $('.checkbox-payplug').closest('.oney-payment-choice__item, .payplug-payment-choice__item, .form-check, .payment-item').find('.payment-choice__input:checked').prop('checked', false);
    } else if ($('.checkbox-payplug :radio:checked').length) {
      $('.checkbox-oney').closest('.oney-payment-choice__item, .payplug-payment-choice__item, .form-check, .payment-item').find('.payment-choice__input:checked').prop('checked', false);
    }

    const otherCardOther = document.querySelector('input#payplug_choice_card_other');
    if (otherCardOther) {
      otherCardOther.disabled = true;
    }
  }
}

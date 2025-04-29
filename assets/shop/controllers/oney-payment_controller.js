import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';

export default class extends Controller {
  connect() {
    this.options = {
      trigger: '.payment-method-choice',
      completeInfo: {
        modal: '.oney-complete-info-popin',
        area: '.ui.grid',
      },
    };

    this.toggleGateway();
    if (typeof completeInfoRoute !== 'undefined') {
      this.modalAppear();
    }

    this.tabs();
    window.addEventListener('resize', () => {
      setTimeout(this.tabs, 100);
    });

    this.tabsHandler();

    const form = document.querySelector('form[name="sylius_shop_checkout_select_payment"]');
    form.addEventListener('submit', (event) => {
      this.handleForm();
    });
  }

  toggleGateway() {
    const paymentMethodSelector = $(this.options.trigger);
    const paymentMethodInputId = paymentMethodSelector.data('payment-input-id');
    const checkedPaymentMethodInput = $(`#${paymentMethodInputId}:checked`);

    if (checkedPaymentMethodInput.length) {
      $(`.payment-method-choice[data-payment-input-id="${paymentMethodInputId}"]`).show();
    }

    //document.querySelectorAll('input[id*="sylius_shop_checkout_select_payment_payments"]').addEventListener('change', (event) => {alert('change!')});
    const $inputs = $('input[id*=sylius_shop_checkout_select_payment_payments]');
    $inputs.on('change', (event) => {
      const clickedPaymentMethodId = $(event.currentTarget).attr('id');
      $('.payment-method-choice').slideUp(); // Hide others
      $(`.payment-method-choice[data-payment-input-id="${clickedPaymentMethodId}"]`).slideDown(); // Show current
    });
  }

  tabs() {
    if (window.innerWidth <= 991) {
      $('.oney-payment-choice__item').hide();
      setTimeout(() => {
        $.each($('.oney-payment-choice__input'), (k, el) => {
          if ($(el).is(':checked')) {
            $(el).parent().show();
            $(`a.tablink[data-id=${$(el).val()}]`).addClass('active');
          }
        });
      }, 1);
    } else {
      $('.oney-payment-choice__item').show();
      $('a.tablink').removeClass('active');
    }
  }
  tabsHandler() {
    const $tabLinks = $('a.tablink');
    $.each($tabLinks, (k, el) => {
      $(el).click(function (evt) {
        $('a.tablink').removeClass('active');
        $(this).addClass('active');
        $('.oney-payment-choice__item').hide();
        $(`#${$(this).data('id')}`).show();
        $(`input[value=${$(this).data('id')}`).prop('checked', true);
      });
    });
  }

  modalAppear() {
    const self = this;
    let path = completeInfoRoute;
    $.get(path).then((data) => {
      $('body .pusher').append('<div class="overlay"></div>');
      $(self.options.completeInfo.area).addClass("inactive");
      $(self.options.completeInfo.area).parent().append(data);
      self.modalEvents();
    });
  }
  modalFadeaway() {
    $(this.options.completeInfo.modal).fadeOut(300, () => {
      $(this.options.completeInfo.area).removeClass('inactive');
      $('.overlay').hide();
    });
  }
  modalSubmit(evt) {
    const self = this;
    evt.preventDefault();
    $(evt.currentTarget).addClass('loading');

    $.ajax({
      method: 'post',
      url: completeInfoRoute,
      data: $(evt.currentTarget).serialize(),
      success: function (res) {
        if (Array.isArray(res)) {
          $(`${self.options.completeInfo.modal}__content`).fadeOut(() => {
            $(`${self.options.completeInfo.modal}__success`).show();
          });
          setTimeout(() => {
            self.modalFadeaway();
          }, 2500);
        } else {
          $(self.options.completeInfo.modal).html(res);
        }
        self.modalEvents();
      },
      error: function (res) {
        console.log(res);
      },
    });
  }
  modalEvents() {
    $('.close').on('click', () => {
      this.modalFadeaway();
    });
    $('form[name=form]').on('submit', (e) => {
      this.modalSubmit(e);
    });
  }
  handleForm() {
    if ($('.checkbox-oney :radio:checked').length) {
      $('.checkbox-payplug').closest('.payment-item').find('.payment-choice__input:checked').prop('checked', false);
    } else if ($('.checkbox-payplug :radio:checked').length) {
      $('.checkbox-oney').closest('.payment-item').find('.payment-choice__input:checked').prop('checked', false);
    }

    $('input#payplug_choice_card_other').attr('disabled', true);
    $('form[name="sylius_shop_checkout_select_payment_payments"]').submit(); // old id: sylius_checkout_select_payment
  }
}

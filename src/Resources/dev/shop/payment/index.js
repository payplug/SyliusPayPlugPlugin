const Payment = {
  options: {
    trigger: ".payment-method-choice",
    completeInfo: {
      modal: ".oney-complete-info-popin",
      area: ".ui.grid",
    },
  },
  init(options) {
    if (typeof options === "undefined") {
      options = this.options;
    }
    this.options = $.extend(true, {}, options);
    Payment.toggleGateway();
    if (typeof completeInfoRoute !== "undefined") {
      Payment.modalAppear();
    }
    Payment.tabs();
    $(window).on("resize", () => {
      setTimeout(Payment.tabs, 100);
    });
    Payment.tabsHandler();
    Payment.applePayHandler();
    $('form[name="sylius_checkout_select_payment"]').on("submit", () => {
      Payment.handleForm();
    });
  },
  toggleGateway() {
    const paymentMethodInputId = $(this.options.trigger).data("payment-input-id");
    const checkedPaymentMethodInput = $(`#${paymentMethodInputId}:checked`);
    if (checkedPaymentMethodInput.length) {
      $(`.payment-method-choice[data-payment-input-id="${paymentMethodInputId}"]`).show();
    }
    $("input[id*=sylius_checkout_select_payment]").on("change", (event) => {
      const clickedPaymentMethodId = $(event.currentTarget).attr("id");
      $(".payment-method-choice").slideUp();
      $(`.payment-method-choice[data-payment-input-id="${clickedPaymentMethodId}"]`).slideDown();
    });
  },
  tabs() {
    if (window.innerWidth <= 991) {
      $(".oney-payment-choice__item").hide();
      setTimeout(() => {
        $.each($(".oney-payment-choice__input"), (k, el) => {
          if ($(el).is(":checked")) {
            $(el).parent().show();
            $(`a.tablink[data-id=${$(el).val()}]`).addClass("active");
          }
        });
      }, 1);
    } else {
      $(".oney-payment-choice__item").show();
      $("a.tablink").removeClass("active");
    }
  },
  tabsHandler() {
    $.each($("a.tablink"), (k, el) => {
      $(el).click(function (evt) {
        $("a.tablink").removeClass("active");
        $(this).addClass("active");
        $(".oney-payment-choice__item").hide();
        $(`#${$(this).data("id")}`).show();
        $(`input[value=${$(this).data("id")}`).prop("checked", true);
      });
    });
  },
  disableNextStepButton() {
    const nextStepButton = $('form[name="sylius_checkout_select_payment"] .select-payment-submit #next-step');
    nextStepButton.replaceWith(
        $("<span/>", {
          id: 'next-step',
          class: 'ui large disabled icon labeled button',
          html: nextStepButton.html()
        })
    );
  },
  enableNextStepButton() {
    const nextStepButton = $('form[name="sylius_checkout_select_payment"] .select-payment-submit #next-step');
    nextStepButton.replaceWith(
        $("<button/>", {
          type: 'submit',
          id: 'next-step',
          class: 'ui large primary icon labeled button',
          html: nextStepButton.html()
        })
    );
  },
  showApplePayButton() {
    const applePayButton = $(document).find("apple-pay-button");
    if (applePayButton.length) {
      applePayButton.addClass('enabled');
    }
  },
  hideApplePayButton() {
    const applePayButton = $(document).find("apple-pay-button.enabled");
    if (applePayButton.length) {
      applePayButton.removeClass('enabled');
    }
  },
  applePayHandler() {
    const applePayChoice = $(".payment-item .checkbox-applepay input:radio");
    if (applePayChoice) {
      if (applePayChoice.is(':checked')) {
        Payment.disableNextStepButton();
        Payment.showApplePayButton();
      } else {
        Payment.enableNextStepButton();
        Payment.hideApplePayButton();
      }
    }
    $(".payment-item .checkbox input:radio").on('change', this.onPaymentMethodChoice);
    $(document).find("apple-pay-button").on('click', this.onApplePayButtonClick);
  },
  onPaymentMethodChoice(event) {
    const isApplePay = $(event.currentTarget).closest('.checkbox-applepay').length;
    if (isApplePay) {
      Payment.showApplePayButton();
      Payment.disableNextStepButton();
    } else {
      Payment.hideApplePayButton();
      Payment.enableNextStepButton();
    }
  },
  onApplePayButtonClick(event) {
    const applePayButton = $(event.currentTarget);

    if (applePaySessionRequestSettings === undefined) {
      console.error('Invalid Apple Pay settings!');
      return false;
    }

    // Create ApplePaySession
    const session = new ApplePaySession(3, applePaySessionRequestSettings);

    session.onvalidatemerchant = async event => {
      $.ajax({
        url: applePayButton.data('validate-merchant-route'),
        method: 'POST',
        cache: false,
        data: {},
        success: (authorization) => {
          let result = authorization.merchant_session;
          console.log(result);

          if (authorization.success === true) {
            console.log(authorization.merchant_session);
            session.completeMerchantValidation(result);
          } else {
            session.abort();
          }
        },
        error: (XHR, status, error) => {
          console.log(XHR, status, error);
          session.abort();
          window.location.reload();
        },
      });
    };

    session.onpaymentauthorized = event => {
      $.ajax({
        url: applePayButton.data('payment-authorized-route'),
        method: 'POST',
        cache: false,
        data: {
          token: event.payment.token
        },
        success: (authorization) => {
          try {
            var apple_pay_Session_status = ApplePaySession.STATUS_SUCCESS;

            console.log(authorization);
            console.log(authorization.data.responseToApple.status);
            if (authorization.data.responseToApple.status != 1) {
              apple_pay_Session_status = ApplePaySession.STATUS_FAILURE;
            }

            const result = {
              "status": apple_pay_Session_status
            };

            console.log(apple_pay_Session_status);
            console.log(result);

            session.completePayment(result);

            console.log(authorization.data.returnUrl);
            window.location.href = authorization.data.returnUrl;
          } catch (err) {
            console.error(err);
            window.location.reload();
          }
        },
        error: (XHR, status, error) => {
          console.log(XHR, status, error);
          session.abort();
          window.location.reload();
        },
      });
    };

    session.oncancel = event => {
      console.log('Cancelling Apple Pay session!');

      $.ajax({
        url: applePayButton.data('session-cancel-route'),
        cache: false,
        method: 'POST',
        data: {},
        success: (authorization) => {
          console.log('Cancelled!');
          console.log(authorization.data.returnUrl);
          window.location.href = authorization.data.returnUrl;
        },
        error: (XHR, status, error) => {
          console.log(XHR, status, error);
          window.location.reload();
        },
      });
    };

    session.begin();
  },
  modalAppear() {
    const self = this;
    let path = completeInfoRoute;
    $.get(path).then((data) => {
      $("body .pusher").append("<div class='overlay'></div>");
      $(self.options.completeInfo.area).addClass("inactive");
      $(self.options.completeInfo.area).parent().append(data);
      self.modalEvents();
    });
  },
  modalFadeaway() {
    $(this.options.completeInfo.modal).fadeOut(300, () => {
      $(this.options.completeInfo.area).removeClass("inactive");
      $(".overlay").hide();
    });
  },
  modalSubmit(evt) {
    const self = this;
    evt.preventDefault();
    $(evt.currentTarget).addClass("loading");

    $.ajax({
      method: "post",
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
  },
  modalEvents() {
    $(".close").on("click", () => {
      this.modalFadeaway();
    });
    $("form[name=form]").on("submit", (e) => {
      this.modalSubmit(e);
    });
  },
  handleForm() {
    if ($(".checkbox-oney :radio:checked").length) {
      $(".checkbox-payplug").closest(".payment-item").find(".payment-choice__input:checked").prop("checked", false);
    } else if ($(".checkbox-payplug :radio:checked").length) {
      $(".checkbox-oney").closest(".payment-item").find(".payment-choice__input:checked").prop("checked", false);
    }

    $("input#payplug_choice_card_other").attr("disabled", true);
    $('form[name="sylius_checkout_select_payment"]').submit();
  }
};

document.addEventListener("DOMContentLoaded", Payment.init, false);

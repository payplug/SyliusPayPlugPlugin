import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  connect() {
    this.applePayHandler();
  }
  showApplePayButton() {
    const applePayButton = $(document).find("apple-pay-button");
    if (applePayButton.length) {
      applePayButton.addClass('enabled');
    }
  }
  hideApplePayButton() {
    const applePayButton = $(document).find("apple-pay-button.enabled");
    if (applePayButton.length) {
      applePayButton.removeClass('enabled');
    }
  }
  applePayHandler() {
    const applePayChoice = $(".payment-item .checkbox-applepay input:radio");
    if (applePayChoice) {
      if (applePayChoice.is(':checked')) {
        this.disableNextStepButton();
        this.showApplePayButton();
      } else {
        this.enableNextStepButton();
        this.hideApplePayButton();
      }
    }
    $(".payment-item .checkbox input:radio").on('change', this.onPaymentMethodChoice);
    $(document).find("apple-pay-button").on('click', this.onApplePayButtonClick);
  }
  onPaymentMethodChoice(event) {
    const isApplePay = $(event.currentTarget).closest('.checkbox-applepay').length;
    if (isApplePay) {
      this.showApplePayButton();
      this.disableNextStepButton();
    } else {
      this.hideApplePayButton();
      this.enableNextStepButton();
    }
  }
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
  }
  disableNextStepButton() {
    const nextStepButton = $('form[name*="checkout_select_payment"] [data-test-next-step]');
    nextStepButton.replaceWith(
      $("<span/>", {
        id: 'next-step',
        class: 'btn btn-primary btn-icon',
        html: nextStepButton.html()
      })
    );
  }
  enableNextStepButton() {
    const nextStepButton = $('form[name*="checkout_select_payment"] [data-test-next-step]');
    nextStepButton.replaceWith(
      $("<button/>", {
        type: 'submit',
        id: 'next-step',
        class: 'btn btn-primary btn-icon',
        html: nextStepButton.html()
      })
    );
  }
}

import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static values = {
    paymentInputId: String,
    settings: Object,
    notice: String,
  }

  connect() {
    this.applePayButton = this.element.querySelector('apple-pay-button');
    this.onApplePayButtonClick = this.onApplePayButtonClick.bind(this);

    if (this.applePayButton) {
      this.applePayButton.addEventListener('click', this.onApplePayButtonClick);
    }

    this.retryCount = 0;
    this.initApplePay();
  }

  initApplePay() {
    if (typeof window.ApplePaySession !== 'undefined') {
      this.checkSupport();
      return;
    }

    if (this.retryCount < 20) { // Try for 2 seconds
      this.retryCount++;
      setTimeout(() => this.initApplePay(), 100);
    } else {
      this.disablePaymentMethod();
    }
  }

  checkSupport() {
    try {
      const isSupported = window.ApplePaySession.canMakePayments();

      if (isSupported && this.applePayButton) {
        this.applePayButton.classList.add('enabled');
      } else {
        this.disablePaymentMethod();
      }
    } catch (e) {
      this.disablePaymentMethod();
    }
  }

  disablePaymentMethod() {
    if (!this.paymentInputIdValue) return;

    const input = document.getElementById(this.paymentInputIdValue);
    if (!input) return;

    const container = input.closest('.card, .item, .form-check, .field');
    if (container) {
      container.style.opacity = '0.5';
      container.style.pointerEvents = 'none';
      container.classList.add('apple-pay-ineligible');

      // Add a small info message if not already present
      if (!container.querySelector('.apple-pay-notice')) {
        const notice = document.createElement('div');
        notice.className = 'apple-pay-notice small text-muted mt-2';
        notice.style.padding = '0 1rem 1rem';
        notice.innerText = this.noticeValue || 'Apple Pay is not available on this browser or device.';
        container.appendChild(notice);
      }

      if (input.checked) {
        input.checked = false;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    input.disabled = true;

    if (this.element) {
      this.element.style.display = 'none';
    }
  }

  async onApplePayButtonClick(event) {
    const applePayButton = event.currentTarget;

    if (!this.settingsValue) {
      console.error('Invalid Apple Pay settings!');
      return;
    }

    if (typeof window.ApplePaySession === 'undefined') {
      return;
    }

    // Prepare settings
    const settings = { ...this.settingsValue };
    if (settings.applePayDomain) {
      settings.applicationData = btoa(JSON.stringify({
        'apple_pay_domain': settings.applePayDomain
      }));
      delete settings.applePayDomain;
    }

    try {
      const version = window.ApplePaySession.supportsVersion(14) ? 14 : 3;
      const session = new window.ApplePaySession(version, settings);

      session.onvalidatemerchant = async (event) => {
        try {
          const response = await fetch(applePayButton.dataset.validateMerchantRoute, {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
            },
          });
          const authorization = await response.json();

          if (authorization.success === true) {
            session.completeMerchantValidation(authorization.merchant_session);
          } else {
            session.abort();
          }
        } catch (error) {
          console.error(error);
          session.abort();
          window.location.reload();
        }
      };

      session.onpaymentauthorized = async (event) => {
        try {
          const response = await fetch(applePayButton.dataset.paymentAuthorizedRoute, {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Content-Type': 'application/json',
            },
            body: JSON.stringify({
              token: event.payment.token,
            }),
          });
          const authorization = await response.json();

          let applePaySessionStatus = window.ApplePaySession.STATUS_SUCCESS;

          if (authorization.data.responseToApple.status !== 1) {
            applePaySessionStatus = window.ApplePaySession.STATUS_FAILURE;
          }

          session.completePayment({ "status": applePaySessionStatus });
          window.location.href = authorization.data.returnUrl;
        } catch (error) {
          console.error(error);
          window.location.reload();
        }
      };

      session.oncancel = async (event) => {
        try {
          const response = await fetch(applePayButton.dataset.sessionCancelRoute, {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
            },
          });
          const authorization = await response.json();
          window.location.href = authorization.data.returnUrl;
        } catch (error) {
          console.error(error);
          window.location.reload();
        }
      };

      session.begin();
    } catch (e) {
      console.error('Failed to create Apple Pay session:', e);
    }
  }
}

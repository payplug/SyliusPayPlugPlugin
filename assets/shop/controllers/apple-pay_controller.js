import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static values = {
    paymentInputId: String,
    settings: Object,
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
      console.warn('Apple Pay SDK not fully initialized or not supported on this platform.');
    }
  }

  checkSupport() {
    try {
      // Version 14 is the minimum for QR Code support on non-Safari browsers
      const isSupported = window.ApplePaySession.canMakePayments() || window.ApplePaySession.supportsVersion(14);

      if (isSupported && this.applePayButton) {
        this.applePayButton.classList.add('enabled');
      } else {
        this.hidePaymentMethod();
      }
    } catch (e) {
      console.error('Error checking Apple Pay support:', e);
      this.hidePaymentMethod();
    }
  }

  hidePaymentMethod() {
    if (!this.paymentInputIdValue) return;

    const input = document.getElementById(this.paymentInputIdValue);
    if (!input) return;

    // Hide the entire payment item (usually a .item, .payment-item or .form-check)
    const container = input.closest('.item, .payment-item, .form-check, [data-test-payment-item]');
    if (container) {
      container.style.display = 'none';
      
      // If the hidden input was checked, we should probably uncheck it
      if (input.checked) {
        input.checked = false;
        // Trigger change to let other controllers (like checkout-select-payment) know
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
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
          const formData = this.toFormData({
            token: event.payment.token
          });

          const response = await fetch(applePayButton.dataset.paymentAuthorizedRoute, {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
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

  toFormData(obj, formData = new FormData(), prefix = '') {
    for (const key in obj) {
      if (Object.prototype.hasOwnProperty.call(obj, key)) {
        const value = obj[key];
        const name = prefix ? `${prefix}[${key}]` : key;
        if (typeof value === 'object' && value !== null && !(value instanceof File) && !(value instanceof Blob)) {
          this.toFormData(value, formData, name);
        } else {
          formData.append(name, value);
        }
      }
    }
    return formData;
  }
}

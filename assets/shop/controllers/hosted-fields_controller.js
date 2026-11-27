import { Controller } from '@hotwired/stimulus';

// Applied inside each hosted iframe (Dalenys renders these rules into the field's own document).
// The SDK checks this object against a whitelist (color, font-family, font-size, font-style,
// font-variant, font-weight, font-smooth, line-height, letter-spacing, text-decoration,
// text-shadow, text-transform, background-color) and any other property, such as "height", makes
// it throw and leaves ALL fields unstyled. A quoted font-family value is rejected the same way, so
// the stack stays unquoted; the page fonts are not available in the cross-origin iframes, hence
// the system stack, shared with the cardholder input. The line-height is the 46px left inside the
// 48px field once its two 1px borders are removed, which centres the text vertically.
const FIELD_STYLE = {
  input: {
    'font-family': 'Helvetica, Arial, sans-serif',
    'font-size': '14px',
    'line-height': '46px',
    color: '#2B343D',
    'background-color': 'transparent',
  },
  '::placeholder': {
    color: '#969a9f',
  },
};

const FIELD_BOX_IDS = {
  card: 'card-container',
  expiry: 'expiry-container',
  cryptogram: 'cvv-container',
};

const NETWORK_LABEL_GAP = 4;

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['container', 'error', 'submitButton', 'holder'];

  connect() {
    if (typeof payplug_hosted_fields_params === 'undefined') {
      return;
    }

    this.form = this.element.closest('form');
    this.hfields = null;
    this.loadPending = false;
    this.visibilityObserver = null;
    this.networkLabel = null;
    this.networkLabelStyleObserver = null;
    this.networkLabelObservers = [];
    this.placeNetworkLabelOnResize = null;

    this.watchHolder();

    if (this.hasSubmitButtonTarget) {
      this.submitButtonTarget.addEventListener('click', (event) => {
        event.preventDefault();
        this.tokenizeAndSubmit();
      });
    }

    // With saved cards on offer, selecting the payment method is no longer enough to mean "show
    // the card form": the customer must also have chosen "pay with another card". Nothing else
    // establishes that initial state — handleShow()/handleHide() are only ever reached from the
    // change actions wired onto the choice radios in
    // templates/form/sylius_checkout_select_payment_row.html.twig, and no change event fires on
    // page load — so without this branch a pre-selected saved card still renders the fields open
    // (the --loaded class alone drives their visibility). Mirrors integrated-payment_controller.
    if (payplug_hosted_fields_params.has_saved_cards) {
      this.watchCardChoice();

      return;
    }

    // Stimulus connects as soon as the markup is in the DOM, even though the payment method
    // container starts hidden (see shop/select_payment/choice.html.twig). Mounting the
    // cross-origin Dalenys iframes into a display:none container breaks their rendering, so
    // load them only once this payment method is actually selected.
    const isChecked = this.getPaymentMethodSelectors({
      methodCode: payplug_hosted_fields_params.payment_method_code,
      checked: true,
    });
    if (isChecked.length) {
      this.openFields();
    }

    this.getPaymentMethodSelectors().forEach((element) => {
      element.addEventListener('change', (e) => {
        if (payplug_hosted_fields_params.payment_method_code === e.currentTarget.value && e.currentTarget.checked) {
          this.openFields();
        }
      });
    });
  }

  // Opens the fields only while BOTH "pay with another card" and this payment method are
  // selected — on load and on every subsequent change to either set of radios. Closing is left
  // to handleHide(), which the choice radios already trigger and which also resets
  // data-payment-inline-submit.
  watchCardChoice() {
    if (this.isOtherCardChosen()) {
      this.openFields();
    }

    this.element
      .querySelectorAll('.payment-choice__input, [id*="checkout_select_payment_payments"]')
      .forEach((element) => {
        element.addEventListener('change', () => {
          if (this.isOtherCardChosen()) {
            this.openFields();
          }
        });
      });
  }

  isOtherCardChosen() {
    if (true !== this.element.querySelector('#payplug_choice_card_other')?.checked) {
      return false;
    }

    // The payment method radio is absent when the shop offers only one method — Sylius renders
    // no choice at all in that case, so a checked card choice is on its own enough to mean this
    // method is the one being paid with.
    const methodRadio = document.querySelector(
      `[id*="checkout_select_payment_payments"][value="${payplug_hosted_fields_params.payment_method_code}"]`,
    );

    return null === methodRadio
      ? null !== document.querySelector('.payplug-payment-choice__input:checked')
      : methodRadio.checked;
  }

  disconnect() {
    this.visibilityObserver?.disconnect();
    this.visibilityObserver = null;
    this.networkLabelObservers.forEach((observer) => observer.disconnect());
    this.networkLabelObservers = [];
    // Each SDK instance appends its own #info-container to <body>: left there, the label of this
    // dead instance would stay on screen after a re-render and be the one getElementById() finds.
    this.networkLabel?.remove();
    this.networkLabel = null;
    this.networkLabelStyleObserver = null;
    if (null !== this.placeNetworkLabelOnResize) {
      window.removeEventListener('resize', this.placeNetworkLabelOnResize);
      this.placeNetworkLabelOnResize = null;
    }
  }

  handleShow(event) {
    if (this.hasContainerTarget) {
      import('jquery').then(({ default: $ }) => {
        $(this.containerTarget).slideDown(() => this.placeNetworkLabel());
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
    if (this.hasContainerTarget) {
      this.containerTarget.classList.add('payplugHostedFields--loaded');
    }
    if (null === this.hfields && !this.loadPending) {
      this.loadPending = true;
      this.whenVisible(() => {
        this.loadPending = false;
        this.load();
      });
    }
  }

  // Cross-origin iframes mounted in a display:none ancestor render broken. Every entry point
  // (connect with a preselected method, the radio change handlers, handleShow) can run before the
  // payment method wrapper (shop/select_payment/choice.html.twig, shown by
  // checkout-select-payment_controller) is displayed, so loading waits for the container to get
  // a box.
  whenVisible(callback) {
    const target = this.hasContainerTarget ? this.containerTarget : this.element;
    if (target.getClientRects().length > 0) {
      callback();

      return;
    }

    this.visibilityObserver = new ResizeObserver((entries, observer) => {
      if (0 === target.getClientRects().length) {
        return;
      }
      observer.disconnect();
      this.visibilityObserver = null;
      callback();
    });
    this.visibilityObserver.observe(target);
  }

  closeFields() {
    if (this.hasContainerTarget) {
      this.containerTarget.classList.remove('payplugHostedFields--loaded');
    }
  }

  load() {
    this.hfields = window.dalenys.hostedFields({
      companyId: payplug_hosted_fields_params.companyId,
      fields: {
        brand:       { id: "brand-container", version: 2, useInlineSelection: true },
        card:        { ...this.fieldListeners('card'), id: "card-container", placeholder: "•••• •••• •••• ••••", enableAutospacing: true, style: FIELD_STYLE },
        expiry:      { ...this.fieldListeners('expiry'), id: "expiry-container", placeholder: "MM/AA", style: FIELD_STYLE },
        cryptogram:  { ...this.fieldListeners('cryptogram'), id: "cvv-container", placeholder: "CVV", style: FIELD_STYLE },
      },

      locale: payplug_hosted_fields_params.locale,
    });
    this.hfields.load();
    this.watchNetworkLabel();
  }

  fieldListeners(name) {
    const listener = (event) => this.handleFieldEvent(name, event);

    return { onInput: listener, onBlur: listener, onFocus: listener };
  }

  handleFieldEvent(name, event) {
    const box = document.getElementById(FIELD_BOX_IDS[name]);
    if (null === box) {
      return;
    }

    switch (event.type) {
      case 'focus':
        box.classList.add('is-focus');
        break;
      case 'blur':
        box.classList.remove('is-focus');
        break;
      case 'valid':
        this.setFieldState(name, box, 'valid', null);
        break;
      case 'invalid':
        this.setFieldState(name, box, 'invalid', event.message || payplug_hosted_fields_params.error.tokenization_failed);
        break;
      case 'empty':
        this.setFieldState(name, box, null, null);
        break;
    }
  }

  setFieldState(name, box, state, message) {
    box.classList.toggle('is-valid', 'valid' === state);
    box.classList.toggle('is-invalid', 'invalid' === state);

    if ('holder' === name && this.hasHolderTarget) {
      this.holderTarget.setAttribute('aria-invalid', 'invalid' === state ? 'true' : 'false');
    }

    const error = this.element.querySelector(`.payplugHostedFields__fieldError[data-field="${name}"]`);
    if (null !== error) {
      // The SDK reports "invalid" on every keystroke: rewriting the same text would make screen
      // readers announce the live region again each time.
      const text = message || '';
      if (error.textContent !== text) {
        error.textContent = text;
      }
      error.classList.toggle('is-visible', null !== message);
    }
  }

  watchHolder() {
    if (!this.hasHolderTarget) {
      return;
    }

    const box = this.holderTarget.closest('.payplugHostedFields__container');
    this.holderTarget.addEventListener('focus', () => box.classList.add('is-focus'));
    this.holderTarget.addEventListener('blur', () => {
      box.classList.remove('is-focus');
      if ('' !== this.holderTarget.value) {
        this.validateHolder();
      }
    });
    this.holderTarget.addEventListener('input', () => {
      if (box.classList.contains('is-invalid')) {
        this.validateHolder();
      }
    });
    // Enter in a text input natively submits the checkout form, without a Hosted Fields token.
    this.holderTarget.addEventListener('keydown', (event) => {
      if ('Enter' !== event.key || event.isComposing) {
        return;
      }
      event.preventDefault();
      if (!event.repeat) {
        this.tokenizeAndSubmit();
      }
    });
  }

  holderName() {
    return this.hasHolderTarget ? this.holderTarget.value.normalize('NFC').trim() : '';
  }

  // Same rule as HostedFieldsCaptureData on the server: composed (NFC, so an accent typed or pasted
  // as a separate combining mark counts once, as the shopper sees it), trimmed, counted in
  // characters (code points, like mb_strlen) rather than UTF-16 units.
  holderError() {
    const name = this.holderName();
    if ('' === name) {
      return payplug_hosted_fields_params.error.holder_required;
    }

    const length = [...name].length;
    const { min, max } = payplug_hosted_fields_params.card_holder_length;

    return length < min || length > max ? payplug_hosted_fields_params.error.holder_length : null;
  }

  validateHolder() {
    if (!this.hasHolderTarget) {
      return false;
    }

    const message = this.holderError();
    this.setFieldState('holder', this.holderTarget.closest('.payplugHostedFields__container'), null === message ? 'valid' : 'invalid', message);

    return null === message;
  }

  // The SDK appends its "select your network" label (#info-container) to <body> and positions it
  // only when it is shown and on window resize, in viewport coordinates and without reserving any
  // space, so it ends up over the next fields as soon as the page scrolls or the form moves. It is
  // moved here under the card field, right-aligned like the SDK does, after every change of its
  // style and of the form's size, while the container reserves the room for it.
  watchNetworkLabel() {
    const label = document.getElementById('info-container');
    if (null === label) {
      const bodyObserver = new MutationObserver(() => {
        if (null !== document.getElementById('info-container')) {
          bodyObserver.disconnect();
          this.watchNetworkLabel();
        }
      });
      bodyObserver.observe(document.body, { childList: true });
      this.networkLabelObservers.push(bodyObserver);

      return;
    }

    this.networkLabel = label;
    this.networkLabelStyleObserver = new MutationObserver(() => this.placeNetworkLabel());
    this.networkLabelStyleObserver.observe(label, { attributes: true, attributeFilter: ['style'] });
    this.networkLabelObservers.push(this.networkLabelStyleObserver);

    if (this.hasContainerTarget) {
      const resizeObserver = new ResizeObserver(() => this.placeNetworkLabel());
      resizeObserver.observe(this.containerTarget);
      this.networkLabelObservers.push(resizeObserver);
    }

    this.placeNetworkLabelOnResize = () => this.placeNetworkLabel();
    window.addEventListener('resize', this.placeNetworkLabelOnResize);
    this.placeNetworkLabel();
  }

  placeNetworkLabel() {
    const label = this.networkLabel;
    if (null === label) {
      return;
    }

    const isShown = 'none' !== label.style.display;
    if (this.hasContainerTarget) {
      this.containerTarget.classList.toggle('payplugHostedFields--networkSelection', isShown);
    }

    const cardBox = document.getElementById(FIELD_BOX_IDS.card);
    if (!isShown || null === cardBox) {
      return;
    }

    const rect = cardBox.getBoundingClientRect();
    const top = `${Math.round(window.scrollY + rect.bottom + NETWORK_LABEL_GAP)}px`;
    const left = `${Math.round(window.scrollX + rect.right - label.offsetWidth)}px`;
    if (label.style.top !== top || label.style.left !== left) {
      label.style.setProperty('top', top, 'important');
      label.style.setProperty('left', left, 'important');
      // Our own style write must not re-trigger the observer that called us.
      this.networkLabelStyleObserver.takeRecords();
    }
  }

  tokenizeAndSubmit() {
    if (null === this.hfields) {
      // Fields were never mounted (payment method not selected yet): nothing to tokenize.
      return;
    }

    this.hideError();
    if (!this.validateHolder()) {
      if (this.hasHolderTarget) {
        this.holderTarget.focus();
      } else {
        this.showError(payplug_hosted_fields_params.error.holder_required);
      }

      return;
    }

    this.hfields.createToken((result) => {
      if (result.execCode !== '0000') {
        this.showError(payplug_hosted_fields_params.error.tokenization_failed);
        return;
      }

      const selectedBrand = (result.selectedBrand || '').toUpperCase();
      if (!payplug_hosted_fields_params.allowed_brands.includes(selectedBrand)) {
        this.showError(payplug_hosted_fields_params.error.unsupported_brand);
        return;
      }

      const saveCardElement = this.element.querySelector('#hostedfields_savecard');
      const saveCard = null !== saveCardElement && saveCardElement.checked;

      this.form.querySelector('#hostedfields_token').value = result.hfToken;
      this.form.querySelector('#hostedfields_selected_brand').value = selectedBrand;
      this.form.querySelector('#hostedfields_save_card').value = saveCard ? 'true' : 'false';
      // last4/expirationMonth/expirationYear/country field names are unverified against a real
      // createToken() response (no vendored SDK docs/types exist in this repo to confirm them) —
      // if wrong, these silently fall back to '' rather than error. This data is fully
      // client-controlled and only ever used as a display-only fallback for a saved card's
      // metadata when PayPlug's own operation-fetch is unavailable — PayplugCardPersister
      // validates the format of each field (4-digit last4, 1-12 month, a plausible year, a
      // 2-letter country) before trusting any of it, and discards anything that doesn't match
      // rather than persisting it as-is.
      this.form.querySelector('#hostedfields_last4').value = result.last4 || '';
      this.form.querySelector('#hostedfields_exp_month').value = result.expirationMonth || '';
      this.form.querySelector('#hostedfields_exp_year').value = result.expirationYear || '';
      this.form.querySelector('#hostedfields_country').value = result.country || '';
      this.form.querySelector('#hostedfields_card_holder_name').value = this.holderName();
      this.form.submit();
    });
  }

  showError(message) {
    if (!this.hasErrorTarget) {
      return;
    }
    this.errorTarget.textContent = message;
    this.errorTarget.classList.remove('payplugHostedFields__error--hide');
  }

  hideError() {
    if (!this.hasErrorTarget) {
      return;
    }
    this.errorTarget.textContent = '';
    this.errorTarget.classList.add('payplugHostedFields__error--hide');
  }
}

import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static values = {
    inputId: String,
    logoUrl: String,
    className: String,
  };
  connect() {
    if (!this.inputIdValue || !this.logoUrlValue) {
      return;
    }

    const label = document.querySelector(`[for="${this.inputIdValue}"]`);
    label.style.setProperty('--logo', `url(${this.logoUrlValue})`);
    label.classList.add('payment-label-with-image', this.classNameValue);
  }
}

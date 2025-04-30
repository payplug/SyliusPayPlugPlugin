import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';

export default class extends Controller {
  static values = {
    inputId: String,
    logoUrl: String,
  };
  connect() {
    const label = document.querySelector(`[for="${this.inputIdValue}"]`);
    label.style.setProperty('--logo', `url(${this.logoUrlValue})`);
    label.classList.add('american-express-label');
  }
}

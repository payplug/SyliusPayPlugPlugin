import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static values = {
    modal: String,
    area: String,
    route: String,
  }
  connect() {
    this.tabs();
    window.addEventListener('resize', () => {
      setTimeout(this.tabs, 100);
    });
    this.tabsHandler();
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
  routeValueChanged() {
    if (!this.hasRouteValue) {
      return;
    }
    this.modalAppear();
  }
  modalAppear() {
    let path = this.routeValue;
    $.get(path).then((data) => {
      const modalTpl = document.querySelector('.modal');
      this.modal = new window.bootstrap.Modal(modalTpl);
      $(modalTpl).find('.modal-body').html(data);
      this.modal.show();
      this.bindModalEvents();
    });
  }
  modalSubmit(e) {
    if (!this.hasRouteValue) {
      return;
    }
    e.preventDefault();
    $('.sylius-shop-loader').toggleClass("d-none");
    $.ajax({
      method: 'post',
      url: this.routeValue,
      data: $(e.currentTarget).serialize(),
      success: (res) => {
        if (Array.isArray(res)) {
          $(`${this.modalValue}__content`).fadeOut(() => {
            $(`${this.modalValue}__success`).show();
          });
          setTimeout(() => {
            this.modal.hide();
            window.location.reload();
          }, 2500);
        }
      },
      error: (res) => {
        $(this.modalValue).html(res.responseText);
        this.bindModalEvents();
      },
      complete: () => {
        $('.sylius-shop-loader').toggleClass("d-none");
      }
    });
  }
  bindModalEvents() {
    $('form[name=form]').on('submit', (e) => this.modalSubmit(e));
  }
}

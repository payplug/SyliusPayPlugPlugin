import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  connect() {
    this.options = {
      completeInfo: {
        modal: '.oney-complete-info-popin',
        area: '.ui.grid',
      },
    };

    if (typeof completeInfoRoute !== 'undefined') {
      this.modalAppear();
    }

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
}

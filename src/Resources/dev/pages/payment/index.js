const Payment = {
  options: {
    trigger: ".oney-payment-choice",
    completeInfo: {
      modal: ".oney-complete-info-popin",
      area: ".ui.steps + .ui.grid",
    },
  },
  init() {
    Payment.toggleGateway();
    if (typeof completeInfoRoute !== "undefined") {
      Payment.modalAppear();
    }
  },
  toggleGateway() {
    const self = this;
    if ($(`#${$(this.options.trigger).data("oney-input-id")}`).is(":checked")) {
      $(this.options.trigger).show();
    }
    $("input[id*=sylius_checkout_select_payment]").on("change", function () {
      if ($(this).val() === "oney") {
        $(self.options.trigger).slideDown();
      } else {
        $(self.options.trigger).slideUp();
      }
    });
  },
  modalAppear() {
    const self = this;
    let path = completeInfoRoute;
    $.get(path).then(function (data) {
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
    $.ajax({
      method: "post",
      url: completeInfoRoute,
      data: $(evt.currentTarget).serialize(),
      success: function (res) {
        if (Array.isArray(res)) {
          $(self.options.completeInfo.modal + "__content").fadeOut(function () {
            $(self.options.completeInfo.modal + "__success").show();
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
};

document.addEventListener("DOMContentLoaded", Payment.init, false);

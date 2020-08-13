const Payment = {
  options: {
    trigger: ".oney-payment-choice",
  },
  init() {
    Payment.toggleGateway();
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
};

document.addEventListener("DOMContentLoaded", Payment.init, false);

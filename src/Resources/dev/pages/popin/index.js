const Popin = {
  handlers: {
    info: ".oney-info",
    popin: ".oney-popin",
  },
  triggers: {
    option: "cartItem_variant",
    quantity: "cartItem_quantity",
  },
  productMeta: [],
  init() {
    if (typeof productMeta !== "undefined") {
      Popin.watch();
    }
    Popin.fade();
    Popin.closeHandler();
  },
  watch() {
    for (const prop in this.triggers) {
      const $selectors = $(`[id*=${this.triggers[prop]}`);
      productMeta[prop] = $selectors.val();
      $selectors.on("input", (e) => {
        productMeta[prop] = $(e.currentTarget).val();
        this.check();
      });
    }
    this.productMeta = productMeta;
  },
  check() {
    const self = this;
    $(this.handlers.info).find(".dimmer").addClass("active");
    $.ajax({
      url: this.productMeta.url,
      data: this.productMeta,
      success: function (res) {
        $(self.handlers.info)
          .find("img")
          .attr("src", self.productMeta.img[res.isEligible]);
        if (res.isEligible) {
          $(self.handlers.popin).removeClass("disabled").addClass("enabled");
        } else {
          $(self.handlers.popin).removeClass("enabled").addClass("disabled");
        }
        $(self.handlers.info).find(".dimmer").removeClass("active");
      },
    });
  },
  fade() {
    $(this.handlers.info).on("click", (e) => {
      e.stopPropagation();
      if (
        !$(this.handlers.popin).is(":empty") &&
        this.productMeta.length === 0
      ) {
        $(this.handlers.popin).fadeIn();
        return;
      }
      const self = this;
      // content not loaded yet
      $(this.handlers.info).find(".dimmer").addClass("active");
      $.ajax({
        url: $(this.handlers.popin).data("popin-url"),
        data: this.productMeta,
        success: function (res) {
          $(self.handlers.popin).html(res);
          $(self.handlers.info).find(".dimmer").removeClass("active");
          $(self.handlers.popin).fadeIn();
          Popin.closeHandler();
        },
        error: function (res) {
          console.log(res);
        },
      });
    });
  },
  closeHandler() {
    $(this.handlers.popin)
      .find("a.close")
      .on("click", (e) => {
        e.stopPropagation();
        $(this.handlers.popin).fadeOut();
      });
  },
};

document.addEventListener("DOMContentLoaded", Popin.init, false);

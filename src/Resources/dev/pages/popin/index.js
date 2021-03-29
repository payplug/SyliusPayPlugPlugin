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
  storage: [],
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
        e.preventDefault();
        productMeta[prop] = $(e.currentTarget).val();
        this.check();
      });
    }
    this.productMeta = productMeta;
  },
  check() {
    const self = this;
    this.storage = [];
    $.ajax({
      url: this.productMeta.url,
      data: this.productMeta,
      success: function (res) {
        $(self.handlers.info)
          .find("img:first")
          .attr("src", self.productMeta.img[res.isEligible]);
        res.isEligible
          ? $(self.handlers.popin).removeClass("disabled").addClass("enabled")
          : $(self.handlers.popin).removeClass("enabled").addClass("disabled");
      },
    });
  },
  fade() {
    $(this.handlers.info).on("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (
        !$(this.handlers.popin).is(":empty") &&
        $(this.handlers.popin).text().trim() === this.storage
      ) {
        $(this.handlers.popin).fadeIn();
        return;
      }
      // content not loaded yet
      this.toggleLoader();
      this.load();
    });
  },
  load() {
    const self = this;
    $.ajax({
      url: $(this.handlers.popin).data("popin-url"),
      data: this.productMeta,
      success: function (res) {
        self.storage = $(res).text().trim();
        $(self.handlers.popin).html(res);
        self.toggleLoader();
        $(self.handlers.popin).fadeIn();
        Popin.closeHandler();
      },
      error: function (res) {
        console.log(res);
      },
    });
  },
  toggleLoader() {
    $(this.handlers.info)
      .toggleClass("loading")
      .find(".dimmer")
      .toggleClass("active");
  },
  closeHandler() {
    $("html")
      .not(this.handlers.popin)
      .on("click", (e) => {
        e.stopPropagation();
        $(this.handlers.popin).fadeOut();
      });
    $(this.handlers.popin)
      .find("a.close")
      .on("click", (e) => {
        e.stopPropagation();
        $(this.handlers.popin).fadeOut();
      });
  },
};

document.addEventListener("DOMContentLoaded", Popin.init, false);

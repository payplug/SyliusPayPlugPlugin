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
      $selectors.on(
        "input",
        this.debounce((e) => {
          e.preventDefault();
          productMeta[prop] = $(e.currentTarget).val();
          this.check();
        }, 500)
      );
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
  /**
   * https://davidwalsh.name/javascript-debounce-function
   */
  debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
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
      },
      error: function () {
        $(self.handlers.popin).removeClass("enabled").addClass("disabled")
          .html(`
            <div class="oney-popin__header">
              <a class="close" href="javascript:void(0);" title="${translations.close}">
                  <span></span><span></span>
              </a>
            </div>
            <div class="oney-popin__content">
              <p class="reasons">${translations.reason}</p>
            </div>
          `);
      },
      complete: function () {
        self.toggleLoader();
        $(self.handlers.popin).fadeIn();
        Popin.closeHandler();
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

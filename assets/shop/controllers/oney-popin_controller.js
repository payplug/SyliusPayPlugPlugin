import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';
import WebFont from 'webfontloader';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['popin', 'variantCodes'];
  static values = {
    eligibleUrl: String,
    popinUrl: String,
    productMeta: Object,
    imagesMap: Object,
    translations: Object,
  }
  inputs = {
    option: "cartItem_variant",
    quantity: "cartItem_quantity",
  }
  storage = []
  initialize() {
    WebFont.load({
      google: {
        families: ["Poppins:400,600"],
      },
    });
  }
  connect() {
    if (this.hasProductMetaValue) {
      this.watch();
    }
    this.fade();
    this.closeHandler();
  }
  watch() {
    for (const prop in this.inputs) {
      const $selectors = $(`[id*=${this.inputs[prop]}`);
      if ($selectors.length > 0) {
        this.handleProductOptionsChange($selectors, prop);
      }
      this.productMetaValue = {
        ...this.productMetaValue,
        [prop]: $selectors.val(),
      };
      $selectors.on(
        "input",
        this.debounce((e) => {
          e.preventDefault();
          if ($selectors.length > 0) {
            this.handleProductOptionsChange($selectors, prop);
          }
          this.productMetaValue = {
            ...this.productMetaValue,
            [prop]: $(e.currentTarget).val(),
          };
          this.check();
        }, 500)
      );
    }
  }
  handleProductOptionsChange($selectors, prop) {
    if (prop === "option") {
      let selector = "";
      $selectors.each((k, v) => {
        const select = $(v);
        const option = select.find("option:selected").val();
        selector += `[data-${select.attr("data-option")}="${option}"]`;
      });
      return (this.productMetaValue = {
        ...this.productMetaValue,
        product_variant_code: $(this.variantCodesTarget).find(selector).attr("data-value")
      });
    }
  }
  check() {
    this.storage = [];
    this.toggleLoader();
    $.ajax({
      url: this.eligibleUrlValue,
      data: this.productMetaValue,
      success: (res) => {
        $(this.element).find(".oney-logo").attr("src", this.imagesMapValue[res.isEligible ? 'enabled' : 'disabled']);
        res.isEligible
          ? $(this.popinTarget).removeClass("disabled").addClass("enabled")
          : $(this.popinTarget).removeClass("enabled").addClass("disabled");
      },
      complete: () => {
        this.toggleLoader();
      }
    });
  }
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
  }
  fade() {
    $(this.element).on("click", (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (this.hasEligibleUrlValue) {
        if (!$(this.popinTarget).is(":empty") && $(this.popinTarget).text().trim() === this.storage) {
          $(this.popinTarget).fadeIn();
          return;
        }
      }
      // content isn't loaded yet or cart route
      this.toggleLoader();
      this.load();
    });
  }
  load() {
    $.ajax({
      url: this.popinUrlValue,
      data: this.productMetaValue,
      success: (res) => {
        if (res.includes(this.translationsValue.reason)) {
          $(this.popinTarget).removeClass("enabled").addClass("disabled");
        }
        this.storage = $(res).text().trim();
        $(this.popinTarget).html(res);
      },
      error: ()=>  {
        $(this.popinTarget).removeClass("enabled").addClass("disabled").html(`
          <div class="oney-popin__header">
            <a class="close" href="javascript:void(0);" title="${this.translationsValue.close}">
              <span></span><span></span>
            </a>
          </div>
          <div class="oney-popin__content">
            <p class="reasons">${this.translationsValue.reason}</p>
          </div>
        `);
      },
      complete: ()=>  {
        this.toggleLoader();
        $(this.popinTarget).fadeIn();
        this.closeHandler();
      },
    });
  }
  toggleLoader() {
    $(this.element).find('.sylius-shop-loader').toggleClass("d-none");
  }
  closeHandler() {
    $("html")
      .not(this.popinTarget)
      .on("click", (e) => {
        e.stopPropagation();
        $(this.popinTarget).fadeOut();
      });
    $(this.popinTarget)
      .find("a.close")
      .on("click", (e) => {
        e.stopPropagation();
        $(this.popinTarget).fadeOut();
      });
  }
}

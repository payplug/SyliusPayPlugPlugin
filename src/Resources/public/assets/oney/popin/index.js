// modules are defined as an array
// [ module function, map of requires ]
//
// map of requires is short require name -> numeric require
//
// anything defined in a previous bundle is accessed via the
// orig method which is the require for previous bundles

(function(modules, entry, mainEntry, parcelRequireName, globalName) {
  /* eslint-disable no-undef */
  var globalObject =
    typeof globalThis !== 'undefined'
      ? globalThis
      : typeof self !== 'undefined'
      ? self
      : typeof window !== 'undefined'
      ? window
      : typeof global !== 'undefined'
      ? global
      : {};
  /* eslint-enable no-undef */

  // Save the require from previous bundle to this closure if any
  var previousRequire =
    typeof globalObject[parcelRequireName] === 'function' &&
    globalObject[parcelRequireName];

  var cache = previousRequire.cache || {};
  // Do not use `require` to prevent Webpack from trying to bundle this call
  var nodeRequire =
    typeof module !== 'undefined' &&
    typeof module.require === 'function' &&
    module.require.bind(module);

  function newRequire(name, jumped) {
    if (!cache[name]) {
      if (!modules[name]) {
        // if we cannot find the module within our internal map or
        // cache jump to the current global require ie. the last bundle
        // that was added to the page.
        var currentRequire =
          typeof globalObject[parcelRequireName] === 'function' &&
          globalObject[parcelRequireName];
        if (!jumped && currentRequire) {
          return currentRequire(name, true);
        }

        // If there are other bundles on this page the require from the
        // previous one is saved to 'previousRequire'. Repeat this as
        // many times as there are bundles until the module is found or
        // we exhaust the require chain.
        if (previousRequire) {
          return previousRequire(name, true);
        }

        // Try the node require function if it exists.
        if (nodeRequire && typeof name === 'string') {
          return nodeRequire(name);
        }

        var err = new Error("Cannot find module '" + name + "'");
        err.code = 'MODULE_NOT_FOUND';
        throw err;
      }

      localRequire.resolve = resolve;
      localRequire.cache = {};

      var module = (cache[name] = new newRequire.Module(name));

      modules[name][0].call(
        module.exports,
        localRequire,
        module,
        module.exports,
        this
      );
    }

    return cache[name].exports;

    function localRequire(x) {
      return newRequire(localRequire.resolve(x));
    }

    function resolve(x) {
      return modules[name][1][x] || x;
    }
  }

  function Module(moduleName) {
    this.id = moduleName;
    this.bundle = newRequire;
    this.exports = {};
  }

  newRequire.isParcelRequire = true;
  newRequire.Module = Module;
  newRequire.modules = modules;
  newRequire.cache = cache;
  newRequire.parent = previousRequire;
  newRequire.register = function(id, exports) {
    modules[id] = [
      function(require, module) {
        module.exports = exports;
      },
      {},
    ];
  };

  Object.defineProperty(newRequire, 'root', {
    get: function() {
      return globalObject[parcelRequireName];
    },
  });

  globalObject[parcelRequireName] = newRequire;

  for (var i = 0; i < entry.length; i++) {
    newRequire(entry[i]);
  }

  if (mainEntry) {
    // Expose entry point to Node, AMD or browser globals
    // Based on https://github.com/ForbesLindesay/umd/blob/master/template.js
    var mainExports = newRequire(mainEntry);

    // CommonJS
    if (typeof exports === 'object' && typeof module !== 'undefined') {
      module.exports = mainExports;

      // RequireJS
    } else if (typeof define === 'function' && define.amd) {
      define(function() {
        return mainExports;
      });

      // <script>
    } else if (globalName) {
      this[globalName] = mainExports;
    }
  }
})({"pRn2d":[function(require,module,exports) {
"use strict";
var Popin = {
  handlers: {
    info: ".oney-info",
    popin: ".oney-popin",
    codes: "#payplug-product-variant-codes"
  },
  triggers: {
    option: "cartItem_variant",
    quantity: "cartItem_quantity"
  },
  productMeta: [],
  storage: [],
  init: function init() {
    if (typeof productMeta !== "undefined") {
      Popin.watch();
    }
    Popin.fade();
    Popin.closeHandler();
  },
  watch: function watch() {
    var _this = this;
    var _loop = function _loop(prop) {
      var $selectors = $(("[id*=").concat(_this.triggers[prop]));
      if ($selectors.length > 0) {
        _this.handleProductOptionsChange($selectors, prop);
      }
      productMeta[prop] = $selectors.val();
      $selectors.on("input", _this.debounce(function (e) {
        e.preventDefault();
        if ($selectors.length > 0) {
          _this.handleProductOptionsChange($selectors, prop);
        }
        productMeta[prop] = $(e.currentTarget).val();
        _this.check();
      }, 500));
    };
    for (var prop in this.triggers) {
      _loop(prop);
    }
    this.productMeta = productMeta;
  },
  /**
  * @see Sylius/Bundle/ShopBundle/Resources/private/js/sylius-variants-prices.js
  * @param $selectors
  * @param prop
  */
  handleProductOptionsChange: function handleProductOptionsChange($selectors, prop) {
    if (prop === 'option') {
      var selector = '';
      $selectors.each(function (k, v) {
        var select = $(v);
        var option = select.find('option:selected').val();
        selector += ("[data-").concat(select.attr('data-option'), "=\"").concat(option, "\"]");
      });
      return productMeta.product_variant_code = $(this.handlers.codes).find(selector).attr('data-value');
    }
  },
  check: function check() {
    var self = this;
    this.storage = [];
    $.ajax({
      url: this.productMeta.url,
      data: this.productMeta,
      success: function success(res) {
        $(self.handlers.info).find("img:first").attr("src", self.productMeta.img[res.isEligible]);
        res.isEligible ? $(self.handlers.popin).removeClass("disabled").addClass("enabled") : $(self.handlers.popin).removeClass("enabled").addClass("disabled");
      }
    });
  },
  /**
  * https://davidwalsh.name/javascript-debounce-function
  */
  debounce: function debounce(func, wait) {
    var timeout;
    return function executedFunction() {
      for (var _len = arguments.length, args = new Array(_len), _key = 0; _key < _len; _key++) {
        args[_key] = arguments[_key];
      }
      var later = function later() {
        clearTimeout(timeout);
        func.apply(void 0, args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  },
  fade: function fade() {
    var _this2 = this;
    $(this.handlers.info).on("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (!$(_this2.handlers.popin).is(":empty") && $(_this2.handlers.popin).text().trim() === _this2.storage) {
        $(_this2.handlers.popin).fadeIn();
        return;
      }
      // content not loaded yet
      _this2.toggleLoader();
      _this2.load();
    });
  },
  load: function load() {
    var self = this;
    $.ajax({
      url: $(this.handlers.popin).data("popin-url"),
      data: this.productMeta,
      success: function success(res) {
        if (res.includes(translations.reason)) {
          $(self.handlers.popin).removeClass("enabled").addClass("disabled");
        }
        self.storage = $(res).text().trim();
        $(self.handlers.popin).html(res);
      },
      error: function error() {
        $(self.handlers.popin).removeClass("enabled").addClass("disabled").html(("\n            <div class=\"oney-popin__header\">\n              <a class=\"close\" href=\"javascript:void(0);\" title=\"").concat(translations.close, "\">\n                  <span></span><span></span>\n              </a>\n            </div>\n            <div class=\"oney-popin__content\">\n              <p class=\"reasons\">").concat(translations.reason, "</p>\n            </div>\n          "));
      },
      complete: function complete() {
        self.toggleLoader();
        $(self.handlers.popin).fadeIn();
        Popin.closeHandler();
      }
    });
  },
  toggleLoader: function toggleLoader() {
    $(this.handlers.info).toggleClass("loading").find(".dimmer").toggleClass("active");
  },
  closeHandler: function closeHandler() {
    var _this3 = this;
    $("html").not(this.handlers.popin).on("click", function (e) {
      e.stopPropagation();
      $(_this3.handlers.popin).fadeOut();
    });
    $(this.handlers.popin).find("a.close").on("click", function (e) {
      e.stopPropagation();
      $(_this3.handlers.popin).fadeOut();
    });
  }
};
document.addEventListener("DOMContentLoaded", Popin.init, false);

},{}]},["pRn2d"], "pRn2d", "parcelRequire17ca")

//# sourceMappingURL=index.js.map

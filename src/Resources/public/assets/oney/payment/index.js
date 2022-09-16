// modules are defined as an array
// [ module function, map of requires ]
//
// map of requires is short require name -> numeric require
//
// anything defined in a previous bundle is accessed via the
// orig method which is the require for previous bundles

(function (modules, entry, mainEntry, parcelRequireName, globalName) {
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
      var res = localRequire.resolve(x);
      return res === false ? {} : newRequire(res);
    }

    function resolve(x) {
      var id = modules[name][1][x];
      return id != null ? id : x;
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
  newRequire.register = function (id, exports) {
    modules[id] = [
      function (require, module) {
        module.exports = exports;
      },
      {},
    ];
  };

  Object.defineProperty(newRequire, 'root', {
    get: function () {
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
      define(function () {
        return mainExports;
      });

      // <script>
    } else if (globalName) {
      this[globalName] = mainExports;
    }
  }
})({"eoE2w":[function(require,module,exports) {
var Payment = {
    options: {
        trigger: ".payment-method-choice",
        completeInfo: {
            modal: ".oney-complete-info-popin",
            area: ".ui.steps + .ui.grid"
        }
    },
    init: function(options) {
        if (typeof options === "undefined") options = this.options;
        this.options = $.extend(true, {}, options);
        Payment.toggleGateway();
        if (typeof completeInfoRoute !== "undefined") Payment.modalAppear();
        Payment.tabs();
        $(window).on("resize", function() {
            setTimeout(Payment.tabs, 100);
        });
        Payment.tabsHandler();
    },
    toggleGateway: function() {
        var paymentMethodInputId = $(this.options.trigger).data("payment-input-id");
        var checkedPaymentMethodInput = $("#".concat(paymentMethodInputId, ":checked"));
        if (checkedPaymentMethodInput.length) $('.payment-method-choice[data-payment-input-id="'.concat(paymentMethodInputId, '"]')).show();
        $("input[id*=sylius_checkout_select_payment]").on("change", function(event) {
            var clickedPaymentMethodId = $(event.currentTarget).attr("id");
            $(".payment-method-choice").slideUp();
            $('.payment-method-choice[data-payment-input-id="'.concat(clickedPaymentMethodId, '"]')).slideDown();
        });
    },
    tabs: function() {
        if (window.innerWidth <= 991) {
            $(".oney-payment-choice__item").hide();
            setTimeout(function() {
                $.each($(".oney-payment-choice__input"), function(k, el) {
                    if ($(el).is(":checked")) {
                        $(el).parent().show();
                        $("a.tablink[data-id=".concat($(el).val(), "]")).addClass("active");
                    }
                });
            }, 1);
        } else {
            $(".oney-payment-choice__item").show();
            $("a.tablink").removeClass("active");
        }
    },
    tabsHandler: function() {
        $.each($("a.tablink"), function(k, el) {
            $(el).click(function(evt) {
                $("a.tablink").removeClass("active");
                $(this).addClass("active");
                $(".oney-payment-choice__item").hide();
                $("#".concat($(this).data("id"))).show();
                $("input[value=".concat($(this).data("id"))).prop("checked", true);
            });
        });
    },
    modalAppear: function() {
        var self = this;
        var path = completeInfoRoute;
        $.get(path).then(function(data) {
            $("body .pusher").append("<div class='overlay'></div>");
            $(self.options.completeInfo.area).addClass("inactive");
            $(self.options.completeInfo.area).parent().append(data);
            self.modalEvents();
        });
    },
    modalFadeaway: function() {
        var _this = this;
        $(this.options.completeInfo.modal).fadeOut(300, function() {
            $(_this.options.completeInfo.area).removeClass("inactive");
            $(".overlay").hide();
        });
    },
    modalSubmit: function(evt) {
        var self = this;
        evt.preventDefault();
        $(evt.currentTarget).addClass("loading");
        $.ajax({
            method: "post",
            url: completeInfoRoute,
            data: $(evt.currentTarget).serialize(),
            success: function success(res) {
                if (Array.isArray(res)) {
                    $("".concat(self.options.completeInfo.modal, "__content")).fadeOut(function() {
                        $("".concat(self.options.completeInfo.modal, "__success")).show();
                    });
                    setTimeout(function() {
                        self.modalFadeaway();
                    }, 2500);
                } else $(self.options.completeInfo.modal).html(res);
                self.modalEvents();
            },
            error: function error(res) {
                console.log(res);
            }
        });
    },
    modalEvents: function() {
        var _this = this;
        $(".close").on("click", function() {
            _this.modalFadeaway();
        });
        $("form[name=form]").on("submit", function(e) {
            _this.modalSubmit(e);
        });
    }
};
var onDocumentLoad = function onDocumentLoad(event) {
    Payment.init();
    $('form[name="sylius_checkout_select_payment"] button[type="submit"]').on("click", function(event) {
        if ($(".checkbox-oney :radio:checked").length) $(".checkbox-payplug").closest(".payment-item").find(".payment-choice__input:checked").prop("checked", false);
        else if ($(".checkbox-payplug :radio:checked").length) $(".checkbox-oney").closest(".payment-item").find(".payment-choice__input:checked").prop("checked", false);
        $("input#payplug_choice_card_other").attr("disabled", true);
        $('form[name="sylius_checkout_select_payment"]').submit();
    });
};
document.addEventListener("DOMContentLoaded", onDocumentLoad, false);

},{}]},["eoE2w"], "eoE2w", "parcelRequireeafa")

//# sourceMappingURL=index.js.map

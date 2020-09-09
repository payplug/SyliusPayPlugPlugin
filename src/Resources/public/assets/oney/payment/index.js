// modules are defined as an array
// [ module function, map of requires ]
//
// map of requires is short require name -> numeric require
//
// anything defined in a previous bundle is accessed via the
// orig method which is the require for previous bundles
parcelRequire = (function (modules, cache, entry, globalName) {
  // Save the require from previous bundle to this closure if any
  var previousRequire = typeof parcelRequire === 'function' && parcelRequire;
  var nodeRequire = typeof require === 'function' && require;

  function newRequire(name, jumped) {
    if (!cache[name]) {
      if (!modules[name]) {
        // if we cannot find the module within our internal map or
        // cache jump to the current global require ie. the last bundle
        // that was added to the page.
        var currentRequire = typeof parcelRequire === 'function' && parcelRequire;
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

        var err = new Error('Cannot find module \'' + name + '\'');
        err.code = 'MODULE_NOT_FOUND';
        throw err;
      }

      localRequire.resolve = resolve;
      localRequire.cache = {};

      var module = cache[name] = new newRequire.Module(name);

      modules[name][0].call(module.exports, localRequire, module, module.exports, this);
    }

    return cache[name].exports;

    function localRequire(x){
      return newRequire(localRequire.resolve(x));
    }

    function resolve(x){
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
  newRequire.register = function (id, exports) {
    modules[id] = [function (require, module) {
      module.exports = exports;
    }, {}];
  };

  var error;
  for (var i = 0; i < entry.length; i++) {
    try {
      newRequire(entry[i]);
    } catch (e) {
      // Save first error but execute all entries
      if (!error) {
        error = e;
      }
    }
  }

  if (entry.length) {
    // Expose entry point to Node, AMD or browser globals
    // Based on https://github.com/ForbesLindesay/umd/blob/master/template.js
    var mainExports = newRequire(entry[entry.length - 1]);

    // CommonJS
    if (typeof exports === "object" && typeof module !== "undefined") {
      module.exports = mainExports;

    // RequireJS
    } else if (typeof define === "function" && define.amd) {
     define(function () {
       return mainExports;
     });

    // <script>
    } else if (globalName) {
      this[globalName] = mainExports;
    }
  }

  // Override the current require with this new one
  parcelRequire = newRequire;

  if (error) {
    // throw error from earlier, _after updating parcelRequire_
    throw error;
  }

  return newRequire;
})({"payment/index.js":[function(require,module,exports) {
var Payment = {
  options: {
    trigger: ".oney-payment-choice",
    completeInfo: {
      modal: ".oney-complete-info-popin",
      area: ".ui.steps + .ui.grid"
    }
  },
  init: function init() {
    Payment.toggleGateway();

    if (typeof completeInfoRoute !== "undefined") {
      Payment.modalAppear();
    }
  },
  toggleGateway: function toggleGateway() {
    var self = this;

    if ($("#".concat($(this.options.trigger).data("oney-input-id"))).is(":checked")) {
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
  modalAppear: function modalAppear() {
    var self = this;
    var path = completeInfoRoute;
    $.get(path).then(function (data) {
      $("body .pusher").append("<div class='overlay'></div>");
      $(self.options.completeInfo.area).addClass("inactive");
      $(self.options.completeInfo.area).parent().append(data);
      self.modalEvents();
    });
  },
  modalFadeaway: function modalFadeaway() {
    var _this = this;

    $(this.options.completeInfo.modal).fadeOut(300, function () {
      $(_this.options.completeInfo.area).removeClass("inactive");
      $(".overlay").hide();
    });
  },
  modalSubmit: function modalSubmit(evt) {
    var self = this;
    evt.preventDefault();
    $.ajax({
      method: "post",
      url: completeInfoRoute,
      data: $(evt.currentTarget).serialize(),
      success: function success(res) {
        if (Array.isArray(res)) {
          $(self.options.completeInfo.modal + "__content").fadeOut(function () {
            $(self.options.completeInfo.modal + "__success").show();
          });
          setTimeout(function () {
            self.modalFadeaway();
          }, 2500);
        } else {
          $(self.options.completeInfo.modal).html(res);
        }

        self.modalEvents();
      },
      error: function error(res) {
        console.log(res);
      }
    });
  },
  modalEvents: function modalEvents() {
    var _this2 = this;

    $(".close").on("click", function () {
      _this2.modalFadeaway();
    });
    $("form[name=form]").on("submit", function (e) {
      _this2.modalSubmit(e);
    });
  }
};
document.addEventListener("DOMContentLoaded", Payment.init, false);
},{}]},{},["payment/index.js"], null)
//# sourceMappingURL=/payment/index.js.map
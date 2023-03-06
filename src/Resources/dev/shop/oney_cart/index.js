const Cart = {
  init() {
    Cart.position();
  },
  position() {
    let el = $(".oney-info");
    el.next().insertBefore(el);
  },
};

document.addEventListener("DOMContentLoaded", Cart.init, false);

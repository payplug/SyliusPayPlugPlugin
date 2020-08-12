const Popin = {
  init() {
    Popin.fade();
  },
  fade() {
    $(".oney-info").on("click", (e) => {
      e.stopPropagation();
      $(".oney-popin").fadeIn();
    });
    $(".oney-popin a.close").on("click", (e) => {
      e.stopPropagation();
      $(".oney-popin").fadeOut();
    });
  },
};

document.addEventListener("DOMContentLoaded", Popin.init, false);

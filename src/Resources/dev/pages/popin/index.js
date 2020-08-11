const Popin = {
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

document.addEventListener("DOMContentLoaded", Popin.fade, false);

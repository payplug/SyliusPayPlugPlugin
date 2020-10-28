const Popin = {
  init() {
    Popin.fade();
    Popin.closeHandler();
  },
  fade() {
    $(".oney-info").on("click", (e) => {
      e.stopPropagation();
      if ($(".oney-popin").is(':empty')) {
        // content not loaded yet
        $(".oney-info .dimmer").toggleClass('active');
        $.ajax({
          url: $(".oney-popin").data('popin-url'),
          success: function (res) {
            $(".oney-popin").html(res);
            $(".oney-info .dimmer").toggleClass('active');
            $(".oney-popin").fadeIn();
            Popin.closeHandler();
          },
          error: function (res) {
            console.log(res);
          },
        });
      } else {
        $(".oney-popin").fadeIn();
      }
    });
  },
  closeHandler() {
    $(".oney-popin a.close").on("click", (e) => {
      e.stopPropagation();
      $(".oney-popin").fadeOut();
    });
  },
};

document.addEventListener("DOMContentLoaded", Popin.init, false);

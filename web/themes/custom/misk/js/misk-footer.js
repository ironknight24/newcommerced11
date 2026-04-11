(function (Drupal, once) {
  Drupal.behaviors.miskFooterBackToTop = {
    attach: function (context) {
      once("miskFooterBackToTop", "#misk-back-to-top", context).forEach((button) => {
        button.addEventListener("click", (event) => {
          event.preventDefault();
          window.scrollTo({ top: 0, behavior: "smooth" });
        });
      });
    },
  };
})(Drupal, once);

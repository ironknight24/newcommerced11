(function (Drupal, once) {
  Drupal.behaviors.miskHero = {
    attach: function (context) {
      once("miskHero", "#misk-hero-slider", context).forEach((slider) => {
        const slides = slider.querySelectorAll(".misk-slide");
        const heroRoot = slider.closest(".misk-hero");
        const dotsContainer = heroRoot
          ? heroRoot.querySelector("#misk-dots")
          : null;

        if (
          !slides.length ||
          !dotsContainer ||
          dotsContainer.id !== "misk-dots"
        ) {
          return;
        }

        dotsContainer.textContent = "";

        let current = 0;

        const baseDot =
          "inline-flex h-1 w-10 shrink-0 cursor-pointer rounded-full border-0 p-0 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:ring-offset-2 focus-visible:ring-offset-transparent";
        const inactiveDot = "bg-white/70";
        const activeDot = "bg-sky-300";

        slides.forEach((_, i) => {
          const dot = document.createElement("button");
          dot.type = "button";
          dot.setAttribute("aria-label", Drupal.t("Go to slide @num", {
            "@num": i + 1,
          }));
          dot.className = `${baseDot} ${inactiveDot}`;
          dot.addEventListener("click", () => showSlide(i));
          dotsContainer.appendChild(dot);
        });

        const dots = dotsContainer.querySelectorAll("button");

        function showSlide(index) {
          slides.forEach((slide, i) => {
            const active = i === index;
            slide.classList.toggle("opacity-100", active);
            slide.classList.toggle("opacity-0", !active);
            slide.classList.toggle("pointer-events-none", !active);
            slide.setAttribute("aria-hidden", active ? "false" : "true");
          });

          dots.forEach((dot, i) => {
            dot.classList.toggle(activeDot, i === index);
            dot.classList.toggle(inactiveDot, i !== index);
            dot.setAttribute("aria-current", i === index ? "true" : "false");
          });

          current = index;
        }

        function nextSlide() {
          current = (current + 1) % slides.length;
          showSlide(current);
        }

        showSlide(0);
        setInterval(nextSlide, 4000);
      });
    },
  };
})(Drupal, once);

// Dancker Systems – bevægelse på siden
// Biblioteker (ligger lokalt i js/vendor/, ingen eksterne kald):
//   GSAP + ScrollTrigger + SplitText: animationer, der følger scroll
//   Lenis: blød scroll
// Siden virker fuldt ud uden denne fil. Den tilføjer kun bevægelse.

gsap.registerPlugin(ScrollTrigger, SplitText);

// matchMedia: alt herinde kører KUN, hvis brugeren ikke har bedt om mindre bevægelse.
// Skifter brugeren indstilling, rydder GSAP selv op.
const mm = gsap.matchMedia();

mm.add("(prefers-reduced-motion: no-preference)", () => {

  // ---------- 1. Blød scroll (Lenis) ----------
  // anchors: menulinks (#cases osv.) glider også blødt.
  // offset: på store skærme hænger menuen fast øverst, så vi stopper lidt før, så overskriften ikke gemmes.
  const topBar = document.querySelector(".top-bar");
  const menuHoejde = topBar && getComputedStyle(topBar).position === "sticky" ? topBar.offsetHeight : 0;
  const lenis = new Lenis({ anchors: { offset: -menuHoejde } });
  lenis.on("scroll", ScrollTrigger.update);          // ScrollTrigger skal vide, hvor vi er
  gsap.ticker.add((tid) => lenis.raf(tid * 1000));   // Lenis kører i samme takt som GSAP
  gsap.ticker.lagSmoothing(0);


  // ---------- 2. Tekst, der tændes ord for ord (idé: "Scroll Reveal") ----------
  // SplitText deler teksten op i ord. Hvert ord går fra svagt til fuldt, mens man scroller forbi.
  document.querySelectorAll(".stor-tekst").forEach((tekst) => {
    const delt = SplitText.create(tekst, { type: "words" });
    gsap.fromTo(delt.words,
      { opacity: 0.15 },
      {
        opacity: 1, stagger: 0.1, ease: "none",
        scrollTrigger: { trigger: tekst, start: "top 85%", end: "bottom 45%", scrub: true },
      });
  });


  // ---------- 3. Casekortet folder sig ud (idé: "Scroll Expand") ----------
  // Telefonen glider lidt op, mens man scroller, så den ser ud til at ligge foran browseren (parallax).
  const stort = document.querySelector(".case-stor");
  if (stort) {
    gsap.fromTo(stort.querySelector(".case-mobil"),
      { yPercent: 5 },
      {
        yPercent: -5, ease: "none",
        scrollTrigger: { trigger: stort, start: "top bottom", end: "bottom top", scrub: true },
      });
  }


  // ---------- 4. Elementer glider op, når de kommer ind på skærmen ----------
  // batch samler elementer, der kommer ind samtidig, og lader dem komme én efter én.
  // Ikke alle sider har alle elementer, så vi samler kun dem, der findes på den aktuelle side.
  const glid = gsap.utils.toArray(
    ".sektion-titel, .sektion-intro, .ydelser li, .case-cta, .om-historie, .fordele li, .forloeb li, " +
    ".cta-gitter > *, .kontakt-stor, .kontakt-mail, .case-tekst > *");
  if (glid.length) {
    gsap.set(glid, { y: 40, opacity: 0 });
    ScrollTrigger.batch(glid, {
      start: "top 90%",
      once: true,
      onEnter: (elementer) => gsap.to(elementer, {
        y: 0, opacity: 1, duration: 0.9, stagger: 0.08, ease: "power3.out",
      }),
    });
  }


  // ---------- 4b. Ydelser: linjer tegnes, og titlerne glider op ----------
  // .anim-klar på <html> skjuler linjerne, indtil de skal tegnes (se style.css).
  document.documentElement.classList.add("anim-klar");

  // Genvejene i toppen kommer ind én efter én, og stregerne under dem tegnes
  const genveje = document.querySelector(".ydelse-genveje");
  if (genveje) {
    gsap.from(genveje.children, {
      opacity: 0, x: -30, duration: 0.8, stagger: 0.12, ease: "power3.out", delay: 0.25,
      onStart: () => genveje.classList.add("tegnet"),
    });
  }

  // Hver ydelse: stregen over den tegnes, titlen glider op, og teksten kommer ind fra højre
  gsap.utils.toArray(".ydelse-detalje").forEach((raekke) => {
    const tl = gsap.timeline({
      scrollTrigger: { trigger: raekke, start: "top 80%", once: true },
      onStart: () => raekke.classList.add("tegnet"),
    });
    tl.from(raekke.querySelector("h2"), { yPercent: 110, duration: 0.9, ease: "power3.out" }, 0.15)
      .from(raekke.querySelector(".ydelse-nr"), { opacity: 0, y: 12, duration: 0.5 }, 0.2)
      .from(raekke.querySelectorAll(".ydelse-tekst, .punkt-liste li"),
        { opacity: 0, x: 24, duration: 0.6, stagger: 0.08, ease: "power2.out" }, 0.35);
  });


  // ---------- 5. Processen: en linje fyldes, og numrene udfyldes ----------
  const forloeb = document.querySelector(".forloeb");
  if (forloeb) {
    const linje = document.querySelector(".forloeb-linje");
    const trinListe = [...forloeb.querySelectorAll("li")];
    const halvtNummer = 20; // halvdelen af nummerets størrelse (2.5rem = 40 px)

    // Linjen går fra midten af første nummer til midten af sidste.
    // Står trinene på én række (computer), er linjen vandret, ellers lodret.
    // Den måles igen, hver gang ScrollTrigger genberegner (fx når vinduet ændrer størrelse).
    const placerLinje = () => {
      const foerste = trinListe[0], sidste = trinListe[trinListe.length - 1];
      const vandret = trinListe[1].offsetTop === foerste.offsetTop;
      linje.classList.toggle("vandret", vandret);
      linje.style.top = `${foerste.offsetTop + halvtNummer - (vandret ? 1 : 0)}px`;
      linje.style.left = `${foerste.offsetLeft + halvtNummer - (vandret ? 0 : 1)}px`;
      linje.style.width = vandret ? `${sidste.offsetLeft - foerste.offsetLeft}px` : "";
      linje.style.height = vandret ? "" : `${sidste.offsetTop - foerste.offsetTop}px`;
      linje.classList.add("vis");
    };
    placerLinje();
    ScrollTrigger.addEventListener("refreshInit", placerLinje);

    // --fyld går fra 0 til 1, mens man scroller. Hvert nummer fyldes, når linjen når det.
    gsap.fromTo(".forloeb-linje span",
      { "--fyld": 0 },
      {
        "--fyld": 1, ease: "none",
        scrollTrigger: {
          trigger: forloeb, start: "top 80%", end: "bottom 55%", scrub: true,
          onUpdate: (self) => trinListe.forEach((trin, i) =>
            trin.classList.toggle("naaet", self.progress >= i / (trinListe.length - 1) - 0.01)),
        },
      });
  }


  // ---------- 6. Magnetiske knapper (idé: "Magnet") ----------
  // Kun på enheder med mus. Knappen trækkes en smule mod musen og falder tilbage.
  if (window.matchMedia("(hover: hover)").matches) {
    document.querySelectorAll(".knap, .nav-kontakt").forEach((knap) => {
      knap.addEventListener("mousemove", (e) => {
        const r = knap.getBoundingClientRect();
        const x = e.clientX - (r.left + r.width / 2);
        const y = e.clientY - (r.top + r.height / 2);
        gsap.to(knap, { x: x * 0.25, y: y * 0.35, duration: 0.4, ease: "power3.out" });
      });
      knap.addEventListener("mouseleave", () => {
        // Rolig tilbagevenden uden at svinge forbi (Apple: ingen "bounce", når brugeren ikke har kastet noget)
        gsap.to(knap, { x: 0, y: 0, duration: 0.5, ease: "power3.out" });
      });
    });
  }


  // ---------- 7. Lys, der følger musen på kortet (idé: "Spotlight Card") ----------
  // JavaScript sætter kun musens position som CSS-variabler. Selve lyset tegnes i style.css.
  document.querySelectorAll(".case-cta").forEach((kort) => {
    kort.addEventListener("mousemove", (e) => {
      const r = kort.getBoundingClientRect();
      kort.style.setProperty("--mx", `${e.clientX - r.left}px`);
      kort.style.setProperty("--my", `${e.clientY - r.top}px`);
    });
  });

  // Oprydning, hvis brugeren slår "reducer bevægelse" til, mens siden er åben
  return () => {
    lenis.destroy();
    document.documentElement.classList.remove("anim-klar");
  };
});


// ---------- Kontaktformular ----------
// Kører altid (også med "reducer bevægelse"), fordi det ikke er en animation.
// Formularen sender ikke noget til en server. Når man trykker send, bygger vi en mail
// med det udfyldte og åbner brugerens eget mailprogram. Browseren tjekker de påkrævede
// felter, før "submit" overhovedet sker.
const formular = document.getElementById("kontaktformular");
if (formular) {
  formular.addEventListener("submit", (e) => {
    e.preventDefault();
    const data = new FormData(formular);
    const emne = `Forespørgsel: ${data.get("Opgave")} – ${data.get("Navn")}`;
    const tekst = [
      `Navn: ${data.get("Navn")}`,
      `E-mail: ${data.get("E-mail")}`,
      `Virksomhed: ${data.get("Virksomhed") || "-"}`,
      `Opgave: ${data.get("Opgave")}`,
      "",
      data.get("Besked"),
    ].join("\n");
    window.location.href =
      `mailto:kontakt@danckersystems.dk?subject=${encodeURIComponent(emne)}&body=${encodeURIComponent(tekst)}`;
  });
}

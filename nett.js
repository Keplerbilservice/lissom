/* Skriptet til serversidene (app/nett/). Lite og uten avhengigheter: menyen
   paa telefon, hover-stilene, samtykket til besoeksmaaling og Google-taggen,
   feltene som bytter paa forsida. Legges inline i sida av Nett::dokument().
   Alt her finnes ogsaa i appen (lissom-2108.html); reglene er de samme. */
(function () {
  'use strict';
  var d = document;

  /* ── Toppen ─────────────────────────────────────────────────────────── */
  // Gjennomsiktig over heroen; hvit med skygge naar man har rullet 8 px.
  var topper = d.querySelectorAll('header[data-nett-topp="overlay"]');
  function rullet() {
    var r = window.scrollY > 8;
    for (var i = 0; i < topper.length; i++) {
      if (r) topper[i].setAttribute('data-rullet', ''); else topper[i].removeAttribute('data-rullet');
    }
  }
  if (topper.length) { rullet(); window.addEventListener('scroll', rullet, { passive: true }); }

  // Menyen paa telefon.
  var knapper = d.querySelectorAll('button[data-nett-meny]');
  for (var k = 0; k < knapper.length; k++) {
    knapper[k].addEventListener('click', function () {
      var hode = this.closest('header');
      var meny = hode && hode.querySelector('nav[data-nett-mobilmeny]');
      if (!meny) return;
      var aapen = meny.hasAttribute('hidden');
      if (aapen) meny.removeAttribute('hidden'); else meny.setAttribute('hidden', '');
      this.setAttribute('aria-expanded', aapen ? 'true' : 'false');
      var streker = this.querySelectorAll('span');
      if (streker.length === 3) {
        streker[0].style.transform = aapen ? 'translateY(8px) rotate(45deg)' : 'none';
        streker[1].style.opacity = aapen ? '0' : '1';
        streker[2].style.transform = aapen ? 'translateY(-8px) rotate(-45deg)' : 'none';
      }
    });
  }

  // Knapper som gaar et sted (data-href). Knapp, ikke lenke, der appens
  // stiler sikter paa <button>.
  d.addEventListener('click', function (e) {
    var el = e.target.closest && e.target.closest('[data-href]');
    if (el) { location.href = el.getAttribute('data-href'); }
  });

  /* ── Hover-stilene («style-hover» i appen) ──────────────────────────── */
  d.addEventListener('mouseover', function (e) {
    var el = e.target.closest && e.target.closest('[data-hover]');
    if (!el || el.__hover) return;
    el.__hover = el.getAttribute('style') || '';
    el.setAttribute('style', el.__hover + ';' + el.getAttribute('data-hover'));
  });
  d.addEventListener('mouseout', function (e) {
    var el = e.target.closest && e.target.closest('[data-hover]');
    if (!el || el.__hover === undefined) return;
    if (e.relatedTarget && el.contains(e.relatedTarget)) return;
    el.setAttribute('style', el.__hover);
    el.__hover = undefined;
  });

  /* ── Feltene som bytter ─────────────────────────────────────────────── */
  // Referansekundene: 6 s per felt (eieren, 21. september 2026: «6 sekunder
  // er passe» — sto paa 12), 0,8 s toning. Produktene paa mobil:
  // 6 s. Stopper naar musa eller fingeren er over, og for godt naar noen
  // trykker paa en prikk — som tonBytt() i appen.
  var rolig = false;
  try { rolig = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

  function felt(navn, antall, opphold, tegn) {
    var el = d.querySelector('[data-nett-rot="' + navn + '"]');
    if (!el || antall < 2) return;
    var nr = 0, pause = false, laast = false, klokke = null;
    var prikker = d.querySelectorAll('[data-nett-prikk="' + navn + '"]');
    function lys(n) {
      for (var i = 0; i < prikker.length; i++) {
        var paa = i === n, f = paa ? 'var(--lissom-brown)' : 'var(--border-subtle)';
        prikker[i].style.width = (paa ? 34 : 18) + 'px';
        prikker[i].style.backgroundImage = 'linear-gradient(' + f + ', ' + f + ')';
      }
    }
    function til(n) {
      nr = (n % antall + antall) % antall;
      if (rolig) { tegn(nr); lys(nr); return; }
      el.setAttribute('data-toner', '');
      setTimeout(function () { tegn(nr); lys(nr); el.removeAttribute('data-toner'); }, 800);
    }
    function neste() {
      klokke = setTimeout(function () {
        if (!pause && !laast && !d.hidden) til(nr + 1);
        neste();
      }, opphold);
    }
    for (var i = 0; i < prikker.length; i++) {
      prikker[i].addEventListener('click', function () { laast = true; til(parseInt(this.getAttribute('data-nr'), 10) || 0); });
    }
    el.addEventListener('mouseenter', function () { pause = true; });
    el.addEventListener('mouseleave', function () { pause = false; });
    el.addEventListener('touchstart', function () { pause = true; }, { passive: true });
    el.addEventListener('touchend', function () { pause = false; }, { passive: true });
    el.addEventListener('touchcancel', function () { pause = false; }, { passive: true });
    if (!rolig) neste();
  }

  var rot = window.lissomRot || [];
  felt('rot', rot.length, 6000, function (n) {
    var r = rot[n], el = d.querySelector('[data-nett-rot="rot"]');
    var q = function (s) { return el.querySelector('[data-rot="' + s + '"]'); };
    var bilde = q('bilde'); bilde.src = r.bilde; bilde.alt = r.alt;
    q('stikktittel').textContent = r.stikktittel;
    q('tittel').textContent = r.tittel;
    q('tekst').textContent = r.tekst; q('tekst').style.display = r.tekst ? '' : 'none';
    var logo = q('logo'); logo.style.display = r.logo ? 'block' : 'none';
    logo.style.backgroundImage = r.logo ? 'url("' + r.logo + '")' : ''; logo.setAttribute('aria-label', 'Logoen til ' + r.stikktittel);
    var u = q('undertekst'); u.textContent = r.undertekst || ''; u.style.display = r.undertekst ? 'block' : 'none';
    // Knappene sto her. De hoerte til Events og Medlemskap, som ikke er i
    // feltet lenger — eieren, 20. september 2026: «kan du soerge for at det
    // kun er referansekunder i denne». En kunde har en lenke, ikke knapper.
    var l = q('lenke'); l.style.display = r.lenke ? 'inline-block' : 'none'; l.href = r.lenke || '#'; l.textContent = r.lenkeTekst || '';
  });

  var but = d.querySelectorAll('[data-nett-rot="but"] > [data-but]');
  felt('but', but.length, 6000, function (n) {
    for (var i = 0; i < but.length; i++) { if (i === n) but[i].removeAttribute('hidden'); else but[i].setAttribute('hidden', ''); }
  });

  /* ── Bildene paa kurssida ───────────────────────────────────────────── */
  // app/nett/sider/kursside.php legger bildene oppaa hverandre med
  // «data-nett-karusell=<sekunder>» paa ramma, og har gjort det siden
  // serversidene kom. Men ingenting leste attributtet: bildene byttet
  // aldri, fordi appen ikke tar over kurssida — den aapnes foerst naar
  // noen trykker paa en dato. Maalt 21. september 2026 paa
  // /kurs/handbygging: samme bilde etter 16 sekunder.
  //
  // Da eieren samme dag ba om «Dette kan du lage» — ett kurs som viser
  // bilde og tekst fra de andre — var byttet selve poenget. Samme regler
  // som feltene over: stopper naar musa eller fingeren er over, og staar
  // i ro for den som har bedt om mindre bevegelse. Toningen ligger alt
  // paa hvert bilde (transition: opacity 1.2s).
  var kar = d.querySelectorAll('[data-nett-karusell]');
  for (var ki = 0; ki < kar.length; ki++) {
    (function (ramme) {
      var bilder = [];
      for (var j = 0; j < ramme.children.length; j++) {
        if (ramme.children[j].style && ramme.children[j].style.backgroundImage) bilder.push(ramme.children[j]);
      }
      if (bilder.length < 2 || rolig) return;
      var sek = parseInt(ramme.getAttribute('data-nett-karusell'), 10) || 5;
      var nr = 0, pause = false;
      ramme.addEventListener('mouseenter', function () { pause = true; });
      ramme.addEventListener('mouseleave', function () { pause = false; });
      ramme.addEventListener('touchstart', function () { pause = true; }, { passive: true });
      ramme.addEventListener('touchend', function () { pause = false; }, { passive: true });
      ramme.addEventListener('touchcancel', function () { pause = false; }, { passive: true });
      setInterval(function () {
        if (pause || d.hidden) return;
        nr = (nr + 1) % bilder.length;
        for (var i = 0; i < bilder.length; i++) bilder[i].style.opacity = i === nr ? '1' : '0';
      }, Math.max(2, sek) * 1000);
    })(kar[ki]);
  }

  /* ── Appen, i bakgrunnen ────────────────────────────────────────────── */
  // «Book», «Min side» og kassa er appen (lissom-2108.html). Den hentes
  // naar sida er ferdig lest og nettleseren har ro, saa den ligger i
  // bufferen naar noen trykker — og ikke foer: som <link rel="prefetch"> i
  // hodet tok den baandbredde fra bildene (maalt 15. september 2026: heroen
  // paa PC 1,9 s senere). Ikke paa spare-data eller treg linje.
  function hentApp() {
    try {
      var c = navigator.connection || {};
      if (c.saveData || /2g/.test(c.effectiveType || '')) return;
    } catch (e) {}
    var l = d.createElement('link'); l.rel = 'prefetch'; l.href = '/booking'; l.as = 'document';
    d.head.appendChild(l);
  }
  window.addEventListener('load', function () {
    if ('requestIdleCallback' in window) requestIdleCallback(hentApp, { timeout: 8000 }); else setTimeout(hentApp, 4000);
  });

  /* ── Samtykke og maaling ────────────────────────────────────────────── */
  // Ingen maaling foer noen har sagt ja — samme noekkel («lissom-analyse»)
  // og samme rekkefoelge (consent default → update → config) som i appen.
  function samtykke() { try { return localStorage.getItem('lissom-analyse') || ''; } catch (e) { return 'nei'; } }
  var felt = ['ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage'];
  var sett = function (v) { var o = {}; for (var i = 0; i < felt.length; i++) o[felt[i]] = v; return o; };
  var samtykkeSendt = false;
  function maal() {
    var m = window.lissomMaal || {};
    var ga = /^G-[A-Z0-9]{6,20}$/i.test(m.ga || '') ? m.ga : '';
    var gtm = /^GTM-[A-Z0-9]{4,12}$/i.test(m.gtm || '') ? m.gtm.toUpperCase() : '';
    var meta = /^\d{15,16}$/.test(m.meta || '') ? m.meta : '';
    if (!ga && !gtm && !meta) return;
    if (samtykke() !== 'ja') return;
    window.dataLayer = window.dataLayer || [];
    if (typeof window.gtag !== 'function') { window.gtag = function () { window.dataLayer.push(arguments); }; }
    if (samtykkeSendt) {
      // Sa nei og saa ja igjen paa samme side: taggene er lastet alt, bare
      // samtykket skal skrus paa igjen.
      window.gtag('consent', 'update', sett('granted'));
      try { window['ga-disable-' + ga] = false; } catch (e) {}
      try { if (meta && typeof window.fbq === 'function') window.fbq('consent', 'grant'); } catch (e) {}
      return;
    }
    samtykkeSendt = true;
    window.gtag('consent', 'default', sett('denied'));
    window.gtag('consent', 'update', sett('granted'));
    if (ga) {
      var s = d.createElement('script'); s.async = true;
      s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ga);
      d.head.appendChild(s);
      window.gtag('js', new Date());
      window.gtag('config', ga, { anonymize_ip: true });
    }
    if (gtm) {
      window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
      var g = d.createElement('script'); g.async = true;
      g.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(gtm);
      d.head.appendChild(g);
    }
    // Meta-pikselen (Facebook og Instagram), som metaLast() i appen. Ingen
    // «revoke» foer init: lastet foerst etter «ja», og et «revoke» i koeen
    // foer skriptet er lastet holder alt tilbake — ogsaa «grant» (maalt
    // 16. september 2026). Serversidene har ingen kunde aa kjenne igjen,
    // saa init gaar uten opplysninger.
    if (meta) {
      try {
        if (typeof window.fbq !== 'function') {
          var n = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
          if (!window._fbq) window._fbq = n;
          n.push = n; n.loaded = true; n.version = '2.0'; n.queue = [];
          window.fbq = n;
          var f = d.createElement('script'); f.async = true;
          f.src = 'https://connect.facebook.net/en_US/fbevents.js';
          d.head.appendChild(f);
        }
        window.fbq('init', meta);
        window.fbq('consent', 'grant');
        window.fbq('track', 'PageView');
      } catch (e) {}
    }
  }
  // Personvern: «Ditt svar paa besoeksmaaling» — staar bare naar noen har
  // svart, og sier hva de svarte. «Endre svaret mitt» nullstiller, som
  // angreSamtykke() i appen, og boksen kommer opp igjen.
  var endre = d.querySelector('[data-nett-handling="endreAnalyse"]');
  if (endre) {
    var blokk = endre.parentElement, svar = samtykke();
    var m0 = window.lissomMaal || {};
    if (!svar || !(m0.ga || m0.gtm || m0.meta)) { blokk.style.display = 'none'; }
    else {
      var p = blokk.querySelector('p');
      if (p) p.textContent = svar === 'ja'
        ? 'Du har sagt ja til at vi måler besøket. Vil du ombestemme deg, stopper målingen med en gang du trykker under.'
        : 'Du har sagt nei, og ingenting blir målt. Trykker du under, kan du svare på nytt.';
      endre.addEventListener('click', function () {
        try { localStorage.removeItem('lissom-analyse'); } catch (e) {}
        try { window['ga-disable-' + (m0.ga || '')] = true; } catch (e) {}
        // Og si fra til Google, som gaAv() i appen: «ga-disable» stopper
        // Analytics, men Tag Manager og Ads leser samtykket.
        try { if (typeof window.gtag === 'function' && samtykkeSendt) window.gtag('consent', 'update', sett('denied')); } catch (e) {}
        try { if (typeof window.fbq === 'function' && samtykkeSendt) window.fbq('consent', 'revoke'); } catch (e) {}
        blokk.style.display = 'none';
        var b2 = d.querySelector('[data-nett-samtykke]'); if (b2) b2.removeAttribute('hidden');
      });
    }
  }

  var boks = d.querySelector('[data-nett-samtykke]');
  if (boks) {
    var m = window.lissomMaal || {};
    if (samtykke() === '' && (m.ga || m.gtm || m.meta)) boks.removeAttribute('hidden');
    var svar = function (v) { try { localStorage.setItem('lissom-analyse', v); } catch (e) {} boks.setAttribute('hidden', ''); if (v === 'ja') maal(); };
    var ja = boks.querySelector('[data-nett-samtykke-ja]'), nei = boks.querySelector('[data-nett-samtykke-nei]');
    if (ja) ja.addEventListener('click', function () { svar('ja'); });
    if (nei) nei.addEventListener('click', function () { svar('nei'); });
  }
  maal();
})();

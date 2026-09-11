/**
 * Lager de aapne guidene under /nyttig-info/<adresse> som ferdige sider.
 *
 * Eieren, 11. september 2026, SEO-instruksen: «Publiser de åpne guidene med
 * egne URL-er under /nyttig-info/<slug>, med H1 = tittel og meta-beskrivelse
 * per guide. Disse fanger nasjonale søk. Intern lenking: hver guide lenker
 * til relevant kurs («Vil du lære dette i praksis? Se dreiekurs»).»
 *
 * ── Hvordan ───────────────────────────────────────────────────────────
 *
 * Lista over guidene staar i lissom-2108.html (Component.GUIDER): adresse,
 * navn, tittel, meta og hvilken HTML-fil guiden kom som. Den leses herfra,
 * saa kortene paa Nyttig info, sidekartet, llms.txt og sidene alltid sier
 * det samme.
 *
 * Hver guide er den samme HTML-en som PDF-en i Verkstedet ble laget av
 * (doc-page, handbok.css). Her blir den en vanlig side: <doc-page> pakkes ut
 * til en <article>, bunnteksten og de tomme bildeplassene tas bort, og sida
 * faar hode (tittel, meta, canonical, deling, JSON-LD), tilbake-pille,
 * kurspille og bunn — som vilkar.html, som ogsaa er en egen fil.
 *
 * Skriver:
 *   guider/<adresse>.html    sidene (serveres av .htaccess paa /nyttig-info/<adresse>)
 *   guider/guider.json       lista, til api/sitemap.php og api/llms.php
 *
 * Kjoeres lokalt naar en guide er ny eller endret:
 *
 *     node bin/guidepakke.mjs "…/Maler Lissom 2" "…/Nye dokumenter 2"
 *
 * Fila for hver guide letes opp i mappene i den rekkefoelgen de er oppgitt.
 */

import fs from 'fs';
import path from 'path';

const ROT = path.resolve(import.meta.dirname, '..');
const kilde = fs.readFileSync(path.join(ROT, 'lissom-2108.html'), 'utf8');
const mapper = process.argv.slice(2);
if (!mapper.length) {
  console.error('Oppgi mappene guidene ligger i.');
  process.exit(1);
}

/** Klipper ut et balansert [...] som starter etter «fra» — som i seokart.mjs. */
function balansert(tekst, fra) {
  let d = 0;
  for (let i = fra; i < tekst.length; i++) {
    const c = tekst[i];
    if (c === '[' || c === '{') d++;
    if (c === ']' || c === '}') { d--; if (d === 0) return tekst.slice(fra, i + 1); }
  }
  throw new Error('Ubalansert');
}
const i0 = kilde.indexOf('static get GUIDER()');
if (i0 < 0) throw new Error('Fant ikke «static get GUIDER()» i lissom-2108.html');
const GUIDER = eval('(' + balansert(kilde, kilde.indexOf('[', kilde.indexOf('return', i0))) + ')');

const NFC = (s) => s.normalize('NFC');
const finn = (fil) => {
  for (const m of mapper) {
    const treff = fs.readdirSync(m).find(n => NFC(n) === NFC(fil));
    if (treff) return path.join(m, treff);
  }
  return null;
};
const e = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

// handbok.css fra den foerste mappa som har den — det er den nyeste.
const cssFil = mapper.map(m => path.join(m, 'handbok.css')).find(fs.existsSync);
if (!cssFil) throw new Error('Fant ikke handbok.css i mappene');
const handbokCss = fs.readFileSync(cssFil, 'utf8')
  // «body» i handbok.css gjelder dokumentet; her er det artikkelen.
  .replace(/(^|\n)body\{/g, '$1.guide{')
  // Sidene har ikke doc-page; regelen der er uten mening her.
  .replace(/(^|\n)doc-page\{[^}]*\}/g, '');

const ut = path.join(ROT, 'guider');
fs.mkdirSync(ut, { recursive: true });

const liste = [];
for (const g of GUIDER) {
  const fil = finn(g.kilde);
  if (!fil) { console.warn('Mangler: ' + g.kilde); continue; }
  let html = fs.readFileSync(fil, 'utf8');

  // Innholdet mellom <doc-page …> og </doc-page>.
  const a = html.indexOf('<doc-page');
  const b = html.indexOf('</doc-page>');
  if (a < 0 || b < 0) { console.warn('Ingen <doc-page> i ' + g.kilde); continue; }
  let kropp = html.slice(html.indexOf('>', a) + 1, b);
  kropp = kropp
    .replace(/<div slot="footer"[\s\S]*?<\/div>\s*/g, '')                    // bunnteksten paa hvert ark
    .replace(/<figure[^>]*>\s*<image-slot[\s\S]*?<\/figure>\s*/g, '')          // tomme bildeplasser med bildetekst
    .replace(/<image-slot[\s\S]*?<\/image-slot>\s*/g, '')
    .replace(/<image-slot[^>]*\/?>\s*/g, '')
    .replace(/<div class="figgrid">\s*<\/div>\s*/g, '')                       // tomme rutenett etter det
    .replace(/src="assets\/mark-cup\.svg"/g, 'src="/mark-cup.svg"')
    .replace(/<script[\s\S]*?<\/script>/g, '')
    .trim();

  // Overskriften er guidens egen h1. Ingressen er «lede» i omslaget.
  const h1 = (kropp.match(/<h1[^>]*>([\s\S]*?)<\/h1>/) || [, g.navn])[1].replace(/<[^>]+>/g, '').trim();
  const url = 'https://lissom.no/nyttig-info/' + g.slug;
  const erFaq = /Kapittel|vanlige spørsmål/i.test(h1 + g.navn) && g.slug === 'vanlige-sporsmal';

  // Strukturerte data: artikkel fra verkstedet — og FAQPage der spoersmaalene
  // er selve innholdet.
  const graf = [{
    '@type': 'Article', '@id': url + '#artikkel', headline: h1, description: g.meta, url,
    inLanguage: 'nb-NO', isAccessibleForFree: true,
    author: { '@type': 'Organization', name: 'Lissom Keramikk & Håndverk', url: 'https://lissom.no/' },
    publisher: { '@id': 'https://lissom.no/#verksted' },
    about: ['keramikk', 'leire', 'glasur', 'brenning'],
  }];
  if (erFaq) {
    const sp = [...kropp.matchAll(/<h2 class="kap">([\s\S]*?)<\/h2>([\s\S]*?)(?=<span class="kapnr">|<h2 class="kap">|$)/g)]
      .map(m => ({ q: m[1].replace(/<[^>]+>/g, '').trim(), a: m[2].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 600) }))
      .filter(x => x.q && x.a);
    if (sp.length) {
      graf.push({ '@type': 'FAQPage', '@id': url + '#faq',
        mainEntity: sp.map(x => ({ '@type': 'Question', name: x.q, acceptedAnswer: { '@type': 'Answer', text: x.a } })) });
    }
  }
  const ld = JSON.stringify({ '@context': 'https://schema.org', '@graph': graf }).replace(/<\//g, '<\\/');

  const side = `<!DOCTYPE html>
<html lang="nb">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- Laget av bin/guidepakke.mjs fra «${e(g.kilde)}». Rediger kilden, ikke denne. -->
<title>${e(g.tittel)}</title>
<meta name="description" content="${e(g.meta)}">
<meta name="robots" content="index,follow">
<link rel="canonical" href="${url}">
<meta property="og:type" content="article">
<meta property="og:site_name" content="Lissom Keramikk &amp; Håndverk">
<meta property="og:locale" content="nb_NO">
<meta property="og:url" content="${url}">
<meta property="og:title" content="${e(g.tittel)}">
<meta property="og:description" content="${e(g.meta)}">
<meta property="og:image" content="https://lissom.no/delingsbilde.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="675">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<meta name="theme-color" content="#FFCF38">
<link href="https://fonts.googleapis.com/css2?family=Bitter:wght@400;600;700;800&family=Alegreya+Sans:ital,wght@0,400;0,500;0,700;0,800;1,400&display=swap" rel="stylesheet">
<script type="application/ld+json">${ld}</script>
<style>
${handbokCss}
/* Sida rundt guiden. Samme grep som vilkar.html: en egen fil, uavhengig av
   designsystem-bundelen, saa den er lett og alltid oppe. */
html{background:#FBF6EE}
body{margin:0;background:#FBF6EE;font:400 15px/1.6 "Alegreya Sans",sans-serif;color:#2E1002}
.ramme{max-width:760px;margin:0 auto;padding:32px 20px 80px}
.guide{padding:0}
.rad{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:0 0 26px}
.pille{display:inline-flex;align-items:center;gap:8px;border:2px solid #4D1D12;border-radius:999px;padding:10px 20px;font:700 13px/1.2 "Alegreya Sans",sans-serif;letter-spacing:.06em;text-transform:uppercase;text-decoration:none;color:#4D1D12;background:transparent}
.pille.gul{background:#FFCF38}
.kurs{margin:40px 0 0;padding:26px 28px;background:#fff;border:1px solid #E8DBC8;border-radius:14px;display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between}
.kurs p{margin:0;font-family:"Bitter",serif;font-weight:700;font-size:20px;color:#4D1D12;max-width:none}
footer{margin-top:56px;padding-top:20px;border-top:1px solid #E8DBC8;font-size:13px;color:#6F5D4C;letter-spacing:.04em}
footer a{color:#4D1D12}
table{display:block;overflow-x:auto}
@media (max-width:560px){.ramme{padding:20px 14px 60px}.cover{padding:30px 22px}h1{font-size:32px}.tipgrid,.figgrid{grid-template-columns:1fr}.toc ol{column-count:1}}
</style>
</head>
<body>
<main class="ramme">
<div class="rad"><a class="pille" href="/nyttig-info">← Nyttig info</a></div>
<article class="guide">
${kropp}
</article>
<div class="kurs"><p>Vil du lære dette i praksis?</p><a class="pille gul" href="/kurs/dreiekurs">Se dreiekurs</a></div>
<footer>Lissom Keramikk &amp; Håndverk AS · Nordre Løkkevei 15, 3120 Nøtterøy · <a href="mailto:post@lissom.no">post@lissom.no</a> · <a href="/">lissom.no</a></footer>
</main>
</body>
</html>
`;
  fs.writeFileSync(path.join(ut, g.slug + '.html'), side);
  const ord = kropp.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().split(' ').length;
  liste.push({ slug: g.slug, navn: g.navn, om: g.om, tittel: g.tittel, meta: g.meta, h1, ord });
  console.log('guide  ' + g.slug + '  (' + ord + ' ord' + (erFaq ? ', FAQPage' : '') + ')');
}

fs.writeFileSync(path.join(ut, 'guider.json'), JSON.stringify({
  laget: new Date().toISOString().slice(0, 10),
  om: 'Laget av bin/guidepakke.mjs fra Component.GUIDER i lissom-2108.html. Rediger der, ikke her.',
  guider: liste,
}, null, 2) + '\n');
console.log(liste.length + ' guider skrevet til guider/');

/**
 * Pakker dokumentene fra verkstedet til db/dokumenter/, klare til import.
 *
 * Eieren, 11. september 2026: haandboekene og de 71 keramikkmalene i mappa
 * «Lissom opplasting» skal inn i kortene under Verkstedet — haandboekene i
 * hvert sitt kort, og hver mal som sitt eget kort under «Keramikk maler».
 *
 * Claude har ikke tilgang til webhotellet, og admin-opplastingen flater ut
 * mapper (65 filer som alle heter «Mal.pdf»). Derfor denne veien: filene
 * legges i repoet under db/dokumenter/ med et manifest som sier hvor hver
 * fil hoerer hjemme, deploy-jobben legger dem ved siden av app-koden, og
 * «⚙ Kjør oppdateringer» i admin importerer dem (Dokumenter::importer).
 *
 * Kjoeres lokalt, én gang, paa maskinen der kildemappa ligger:
 *
 *     node bin/dokumentpakke.mjs "C:/…/Lissom opplasting"
 *
 * Det som skjer med hver mal:
 *   Resultatbilde.*     → bilde.jpg, skalert ned til maks 1200 px (148 MB
 *                         ble 15 MB; kortet trenger ikke mer). En som
 *                         egentlig er WebP kopieres som den er.
 *   Monteringsguide.html→ Monteringsguide.pdf, via Chrome uten vindu. Chrome
 *                         legger bildene inn i PDF-en slik de er, og med
 *                         2 MB-bilder ble 57 guider 134 MB. Derfor skrives
 *                         den ut fra en kopi der bildene er skalert til
 *                         maks 1000 px — paa papir er det mer enn nok.
 *   alle andre PDF/bilder → urørt, med filnavnet som navn («Mal»,
 *                         «Mal - liten», «Original med videolenke» …)
 *   bilder/steg-NN.jpg  → steg-NN.jpg, urørt, som «Steg N»
 *   annet (svg)         → hoppes over og meldes; appen tar ikke imot det
 *
 * Mappenavnene i repoet er uten æøå og mellomrom — FTP og webhotell er ikke
 * til aa stole paa med annet. Navnet slik det skal staa paa kortet ligger i
 * manifestet.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, statSync, copyFileSync, writeFileSync, rmSync, cpSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const kilde = process.argv[2];
if (!kilde || !existsSync(kilde)) {
  console.error('Oppgi mappa «Lissom opplasting» som første argument.');
  process.exit(1);
}

const rot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const ut  = join(rot, 'db', 'dokumenter');
const maler = join(kilde, 'Maler Lissom');

const CHROME = [
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].find(existsSync);

// Haandboekene → kortet de hoerer hjemme i (slug fra migrasjon 154).
const HAANDBOEKER = [
  ['Glasurhåndbok for keramikere.pdf',              'dekorasjon'],
  ['Håndbok i dekorative teknikker.pdf',            'dekorasjon'],
  ['Håndbok i engober, pigmenter og oksider.pdf',   'engober'],
  ['Håndbok i keramikkbrenning.pdf',                'brenning'],
  ['Håndbok i lagvis glasering.pdf',                'glassering'],
];

// Gruppene i den rekkefoelgen de skal staa. De 20 foerste har nummer i
// mappenavnet; resten staar med gruppenavnet under.
const GRUPPER = [
  ['Maler 1 til 20',   'mal'],
  ['Maler 20 til 55',  'mal-20-55'],
  ['Maler 55 til 85',  'mal-55-85'],
  ['Maler 85 til 100', 'mal-85-100'],
  ['Ekstra maler',     'mal-ekstra'],
];

const slug = (s) => s.toLowerCase()
  .replace(/æ/g, 'ae').replace(/ø/g, 'o').replace(/å/g, 'a')
  .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

const skalerBilde = (inn, utfil, maks = 1200) => {
  // System.Drawing finnes paa alle Windows-maskiner; ingen installasjon.
  const ps = `
    Add-Type -AssemblyName System.Drawing
    $img = [System.Drawing.Image]::FromFile('${inn.replace(/'/g, "''")}')
    $maks = ${maks}
    $s = [Math]::Min(1, $maks / [Math]::Max($img.Width, $img.Height))
    $w = [int]($img.Width * $s); $h = [int]($img.Height * $s)
    $bmp = New-Object System.Drawing.Bitmap $w, $h
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.InterpolationMode = 'HighQualityBicubic'
    $g.DrawImage($img, 0, 0, $w, $h)
    $codec = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders() | Where-Object { $_.MimeType -eq 'image/jpeg' }
    $p = New-Object System.Drawing.Imaging.EncoderParameters 1
    $p.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter ([System.Drawing.Imaging.Encoder]::Quality, 82L)
    $bmp.Save('${utfil.replace(/'/g, "''")}', $codec, $p)
    $g.Dispose(); $bmp.Dispose(); $img.Dispose()
  `;
  execFileSync('powershell', ['-NoProfile', '-NonInteractive', '-Command', ps], { stdio: 'pipe' });
};

const tilPdf = (html, pdf) => {
  // En kopi av malmappa med smaa bilder, og det den deler med de andre
  // (assets/, doc-page.js) to nivaaer opp — slik guiden peker paa dem.
  const kildeMappe = dirname(html);
  const tmp = join(tmpdir(), 'lissom-guide', 'g', 'm');
  rmSync(join(tmpdir(), 'lissom-guide'), { recursive: true, force: true });
  mkdirSync(tmp, { recursive: true });
  cpSync(join(maler, 'assets'), join(tmpdir(), 'lissom-guide', 'assets'), { recursive: true });
  copyFileSync(join(maler, 'doc-page.js'), join(tmpdir(), 'lissom-guide', 'doc-page.js'));
  const kopier = (fra, til) => {
    for (const n of readdirSync(fra)) {
      const p = join(fra, n);
      if (statSync(p).isDirectory()) { mkdirSync(join(til, n), { recursive: true }); kopier(p, join(til, n)); continue; }
      if (/.(jpe?g|png)$/i.test(n)) {
        try { skalerBilde(p, join(til, n), 1000); continue; } catch (e) { /* kopieres under */ }
      }
      copyFileSync(p, join(til, n));
    }
  };
  kopier(kildeMappe, tmp);
  html = join(tmp, 'Monteringsguide.html');
  execFileSync(CHROME, [
    '--headless=new', '--disable-gpu', '--no-pdf-header-footer',
    '--virtual-time-budget=10000',
    '--print-to-pdf=' + pdf,
    'file:///' + html.replace(/\\/g, '/'),
  ], { stdio: 'pipe' });
};

rmSync(ut, { recursive: true, force: true });
mkdirSync(ut, { recursive: true });

const manifest = { versjon: 1, laget: new Date().toISOString().slice(0, 10), dokumenter: [], maler: [] };

// ── Haandboekene ────────────────────────────────────────────────────────
for (const [fil, kort] of HAANDBOEKER) {
  const inn = join(kilde, fil);
  if (!existsSync(inn)) { console.warn('Mangler: ' + fil); continue; }
  const rel = `haandboker/${kort}/${slug(fil.replace(/\.pdf$/i, ''))}.pdf`;
  mkdirSync(dirname(join(ut, rel)), { recursive: true });
  copyFileSync(inn, join(ut, rel));
  manifest.dokumenter.push({ kort, fil: rel, navn: fil.replace(/\.pdf$/i, '') });
  console.log('håndbok  ' + fil);
}

// ── Malene ──────────────────────────────────────────────────────────────
let sortering = 0;
for (const [gruppe, prefiks] of GRUPPER) {
  const gm = join(maler, gruppe);
  if (!existsSync(gm)) { console.warn('Mangler gruppe: ' + gruppe); continue; }
  const mapper = readdirSync(gm).filter(n => statSync(join(gm, n)).isDirectory()).sort();

  for (const mappe of mapper) {
    const inn = join(gm, mappe);
    const m = mappe.match(/^(\d+)\s*-\s*(.+)$/);
    const nummer = m ? parseInt(m[1], 10) : null;
    const navn = (m ? m[2] : mappe).trim();
    const malSlug = nummer !== null
      ? `${prefiks}-${String(nummer).padStart(2, '0')}-${slug(navn)}`
      : `${prefiks}-${slug(navn)}`;
    const relMappe = `maler/${malSlug}`;
    mkdirSync(join(ut, relMappe), { recursive: true });

    const mal = {
      slug: malSlug, navn,
      under: nummer !== null ? 'Mal ' + String(nummer).padStart(2, '0') : gruppe,
      sortering: ++sortering,
      bilde: null, dokumenter: [],
    };

    const res = readdirSync(inn).find(n => /^Resultatbilde\.(jpe?g|png|webp)$/i.test(n));
    if (res) {
      try {
        skalerBilde(join(inn, res), join(ut, relMappe, 'bilde.jpg'));
        mal.bilde = relMappe + '/bilde.jpg';
      } catch (e) {
        // System.Drawing leser ikke WebP, og ett av bildene er WebP med
        // .png-endelse. Det er 1000 px fra foer; kopieres som det er.
        copyFileSync(join(inn, res), join(ut, relMappe, 'bilde.webp'));
        mal.bilde = relMappe + '/bilde.webp';
        console.warn('  ' + mappe + ': bildet kopiert uskalert (WebP)');
      }
    }
    // Alle dokumentene i mappa, i navnerekkefolge — «Mal» og «Mal - liten»
    // foerst, saa «Monteringsguide», saa resten.
    const filer = readdirSync(inn).filter(n => n !== res && statSync(join(inn, n)).isFile()).sort();
    for (const f of filer) {
      const m2 = f.match(/^(.+).(pdf|jpe?g|png|webp|html)$/i);
      if (!m2) { console.warn('  hopper over ' + mappe + '/' + f); continue; }
      const [, stamme, endelse] = m2;
      if (endelse.toLowerCase() === 'html') {
        if (!/^Monteringsguide$/i.test(stamme)) { console.warn('  hopper over ' + mappe + '/' + f); continue; }
        tilPdf(join(inn, f), join(ut, relMappe, 'Monteringsguide.pdf'));
        mal.dokumenter.push({ fil: relMappe + '/Monteringsguide.pdf', navn: 'Monteringsguide' });
        continue;
      }
      const utnavn = slug(stamme) + '.' + endelse.toLowerCase();
      copyFileSync(join(inn, f), join(ut, relMappe, utnavn));
      mal.dokumenter.push({ fil: relMappe + '/' + utnavn, navn: stamme.trim() });
    }
    const steg = join(inn, 'bilder');
    if (existsSync(steg)) {
      for (const b of readdirSync(steg).filter(n => /^steg-\d+\.jpe?g$/i.test(n)).sort()) {
        const nr = parseInt(b.match(/\d+/)[0], 10);
        copyFileSync(join(steg, b), join(ut, relMappe, b.toLowerCase()));
        mal.dokumenter.push({ fil: relMappe + '/' + b.toLowerCase(), navn: 'Steg ' + nr });
      }
    }
    manifest.maler.push(mal);
    console.log('mal      ' + malSlug + '  (' + mal.dokumenter.length + ')');
  }
}

writeFileSync(join(ut, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log(`\n${manifest.dokumenter.length} håndbøker, ${manifest.maler.length} maler → ${ut}`);

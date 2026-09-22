/**
 * Passer paa autolasteren i app/bootstrap.php.
 *
 * Fram til 22. september 2026 lastet hver eneste foresporsel alle 34
 * klassefilene — 764 kB PHP for aa svare paa noe som helst. Naa lastes en
 * klasse naar den brukes, og kartet fra klassenavn til fil staar skrevet i
 * bootstrap.php.
 *
 * Et skrevet kart kan komme ut av takt med disken. Derfor kontrolleres:
 *
 *   1. Hver klasse i app/lib/ staar i kartet, og peker paa riktig fil.
 *   2. Kartet peker ikke paa filer eller klasser som ikke finnes.
 *   3. Filene som definerer globale FUNKSJONER lastes fortsatt med en gang —
 *      ingen autolaster finner dem, for den leter etter klassenavn.
 *   4. Hver «Klasse::»-bruk i app/ og api/ kan finnes: i kartet, blant de
 *      ivrige filene, i app/nett/ (som lastes for seg), eller i PHP selv.
 */

import fs from 'fs';
import path from 'path';

const ROT = path.resolve(import.meta.dirname, '..');
let feil = 0;
const si = (ok, t) => { if (!ok) feil++; console.log((ok ? '  OK  ' : '  FEIL') + '  ' + t); };

const boot = fs.readFileSync(path.join(ROT, 'app/bootstrap.php'), 'utf8');

// ── Kartet slik det staar i bootstrap ────────────────────────────────────
const blokk = boot.slice(boot.indexOf('spl_autoload_register'), boot.indexOf('});', boot.indexOf('spl_autoload_register')));
const kartet = Object.fromEntries([...blokk.matchAll(/'([A-Za-z_][A-Za-z0-9_]*)' => '([a-z0-9_]+\.php)'/g)].map(m => [m[1], m[2]]));
si(Object.keys(kartet).length > 20, Object.keys(kartet).length + ' klasser i kartet');

// ── Klassene som faktisk ligger i app/lib ────────────────────────────────
const FUNKSJONSFILER = ['auth.php', 'foresporsel.php', 'logg.php', 'nett.php'];
const paaDisk = {};
const funksjoner = {};
for (const navn of fs.readdirSync(path.join(ROT, 'app/lib')).filter(n => n.endsWith('.php'))) {
  const kode = fs.readFileSync(path.join(ROT, 'app/lib', navn), 'utf8');
  for (const m of kode.matchAll(/^(?:final |abstract )?(?:class|interface|enum|trait) ([A-Za-z_][A-Za-z0-9_]*)/gm)) {
    (FUNKSJONSFILER.includes(navn) ? funksjoner : paaDisk)[m[1]] = navn;
  }
  const g = [...kode.matchAll(/^function ([a-z_][a-z0-9_]*)/gm)].map(m => m[1]);
  if (g.length && !FUNKSJONSFILER.includes(navn)) {
    si(false, navn + ' har globale funksjoner (' + g.join(', ') + ') og kan ikke autolastes');
  }
}

const mangler = Object.keys(paaDisk).filter(k => kartet[k] !== paaDisk[k]);
si(mangler.length === 0, 'hver klasse i app/lib staar riktig i kartet'
   + (mangler.length ? ' — feil: ' + mangler.map(k => k + ' (disk: ' + paaDisk[k] + ', kart: ' + (kartet[k] || 'mangler') + ')').join(', ') : ''));

const overflodige = Object.keys(kartet).filter(k => !paaDisk[k]);
si(overflodige.length === 0, 'kartet peker ikke paa klasser som ikke finnes'
   + (overflodige.length ? ' — ' + overflodige.join(', ') : ''));

for (const f of FUNKSJONSFILER) {
  si(boot.includes("require APP_DIR . '/lib/" + f + "';") || boot.includes("require_once APP_DIR . '/lib/" + f + "';"),
     f + ' lastes med en gang (globale funksjoner)');
}

// ── Hver Klasse:: som brukes, maa kunne finnes ───────────────────────────
// app/config.php lastes med en gang av bootstrap, og har baade klassen
// Config og funksjonen normaliser_telefon().
const nettKlasser = {};
for (const m of fs.readFileSync(path.join(ROT, 'app/config.php'), 'utf8')
    .matchAll(/^(?:final |abstract )?(?:class|interface|enum|trait) ([A-Za-z_][A-Za-z0-9_]*)/gm)) {
  funksjoner[m[1]] = 'config.php';
}
si(boot.includes("require APP_DIR . '/config.php';"), 'config.php lastes med en gang');
for (const navn of fs.existsSync(path.join(ROT, 'app/nett')) ? fs.readdirSync(path.join(ROT, 'app/nett')).filter(n => n.endsWith('.php')) : []) {
  for (const m of fs.readFileSync(path.join(ROT, 'app/nett', navn), 'utf8')
      .matchAll(/^(?:final |abstract )?(?:class|interface|enum|trait) ([A-Za-z_][A-Za-z0-9_]*)/gm)) {
    nettKlasser[m[1]] = navn;
  }
}
const INNEBYGD = new Set(['DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateInterval', 'DatePeriod',
  'PDO', 'PDOException', 'PDOStatement', 'Throwable', 'Exception', 'RuntimeException', 'InvalidArgumentException',
  'JsonException', 'Error', 'TypeError', 'ValueError', 'ArrayObject', 'Closure', 'Generator', 'IntlDateFormatter',
  'NumberFormatter', 'ZipArchive', 'DOMDocument', 'SimpleXMLElement', 'CurlHandle', 'finfo', 'Imagick',
  'LogicException', 'LengthException', 'OutOfRangeException', 'UnexpectedValueException', 'DomainException',
  'Normalizer', 'Collator', 'Random', 'Stringable', 'Countable', 'Iterator', 'IteratorAggregate', 'ArrayAccess',
  'JsonSerializable', 'Traversable', 'Serializable', 'WeakMap', 'SplFileObject', 'SplQueue', 'SplStack',
  'ReflectionClass', 'ReflectionMethod', 'Attribute', 'ParseError', 'ArithmeticError', 'DivisionByZeroError', 'ArgumentCountError']);
const brukte = new Set();
const gaa = (mappe) => {
  for (const n of fs.readdirSync(mappe, { withFileTypes: true })) {
    const s = path.join(mappe, n.name);
    if (n.isDirectory()) { gaa(s); continue; }
    if (!n.name.endsWith('.php')) continue;
    const kode = fs.readFileSync(s, 'utf8');
    for (const m of kode.matchAll(/(?<![\w$>:\\])([A-Z][A-Za-z0-9_]*)::/g)) brukte.add(m[1]);
    for (const m of kode.matchAll(/\bnew\s+([A-Z][A-Za-z0-9_]*)\s*\(/g)) brukte.add(m[1]);
    for (const m of kode.matchAll(/\bcatch\s*\(\s*([A-Z][A-Za-z0-9_]*)/g)) brukte.add(m[1]);
  }
};
gaa(path.join(ROT, 'app'));
gaa(path.join(ROT, 'api'));
const ukjente = [...brukte].filter(k =>
  !kartet[k] && !paaDisk[k] && !funksjoner[k] && !nettKlasser[k] && !INNEBYGD.has(k) && k !== 'self' && k !== 'static' && k !== 'parent');
si(ukjente.length === 0, brukte.size + ' klasser brukes med «::», alle kan finnes'
   + (ukjente.length ? ' — ukjente: ' + ukjente.join(', ') : ''));

console.log(feil ? '\n' + feil + ' feil.' : '\nAutolasteren er i takt med filene.');
process.exit(feil ? 1 : 0);

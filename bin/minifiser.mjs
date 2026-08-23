// Fjerner kommentarer og mellomrom fra de to genererte skriptene.
//
//   cd <et sted med terser>  &&  node bin/minifiser.mjs
//
// support.js og ds-bundle.js er generert og skal ikke redigeres. De er
// heller ikke minifisert — til sammen 171 kB, hvorav en tredjedel er
// kommentarer og innrykk. Nettsida laster .min.js-utgavene; kildene ligger
// ved siden av og er fortsatt det man leser.
//
// Navn dopes IKKE om. dc-runtime leser komponentene sine som tekst, og et
// omdopt funksjonsnavn ville brutt det. Det koster noen kilobyte og er verdt
// det.
//
// Kjor dette paa nytt naar en av kildene endres.
import { minify } from 'terser';
import fs from 'fs';
for (const f of ['support.js', 'ds-bundle.js']) {
  const kode = fs.readFileSync('/home/user/lissom/' + f, 'utf8');
  // Bare mellomrom og kommentarer. Ingen omdoping av navn: dc-runtime
  // leser komponentene sine som tekst, og et omdopt navn ville brutt det.
  const trygg = await minify(kode, { compress: false, mangle: false, format: { comments: false } });
  console.log(f, (kode.length/1024).toFixed(1), 'kB ->', (trygg.code.length/1024).toFixed(1), 'kB');
  fs.writeFileSync('/home/user/lissom/' + f.replace('.js', '.min.js'), trygg.code);
}
import { minify } from 'terser';
import fs from 'fs';
for (const f of ['support.js', 'ds-bundle.js']) {
  const kode = fs.readFileSync('/home/user/lissom/' + f, 'utf8');
  // Bare mellomrom og kommentarer. Ingen omdoping av navn: dc-runtime
  // leser komponentene sine som tekst, og et omdopt navn ville brutt det.
  const trygg = await minify(kode, { compress: false, mangle: false, format: { comments: false } });
  console.log(f, (kode.length/1024).toFixed(1), 'kB ->', (trygg.code.length/1024).toFixed(1), 'kB');
  fs.writeFileSync('/home/user/lissom/' + f.replace('.js', '.min.js'), trygg.code);
}

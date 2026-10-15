import assert from 'node:assert/strict';
import fs from 'node:fs';

// Kjør de faktiske metodene fra kursappen med kontrollerte kursdata.
const kilde = fs.readFileSync(new URL('../lissom-2108.html', import.meta.url), 'utf8').replace(/\r\n/g, '\n');
const metoder = kilde.match(/  bookPrisDetaljer\(\) \{[\s\S]*?\n  \}\n\n  bookSum\(\) \{[\s\S]*?\n  \}\n\n  medlemsplaner\(/);
assert.ok(metoder, 'Fant prisberegningen i kursappen');
const Klasse = new Function(`return class Kurs { ${metoder[0].replace(/\n\n  medlemsplaner\($/, '')} }`)();

function kurs({ innlogget = false, medlemsrabatt = 0, antall = 1, gruppe = 0, prisOre = 159000, fraPris = false } = {}) {
  const c = new Klasse();
  c.state = {
    innlogget, medlemsrabatt, bOktId: 7,
    valgtKurs: { title: 'Kreativ formiddag', tema: 'Håndbygging', price: 'kr. 1 590,-' },
    katalog: [{ tittel: 'Kreativ formiddag', fraPris, prisFraOre: fraPris ? 135000 : null,
      datoer: [{ oktId: 7, prisOre }] }],
  };
  c.valgtAntall = () => antall;
  c.prisAv = () => 1590;
  c.rabattFor = () => gruppe;
  c.popVelgerBetaling = () => false;
  return c;
}

const kroner = c => c.bookSum().replace(/\s/g, ' ');
assert.equal(kroner(kurs({ medlemsrabatt: 20 })), 'kr. 1 590,-', 'utlogget ser full pris');
assert.equal(kroner(kurs({ innlogget: true })), 'kr. 1 590,-', 'innlogget uten aktiv rabatt ser full pris');
assert.equal(kroner(kurs({ innlogget: true, medlemsrabatt: 20 })), 'kr. 1 272,-', 'medlem får 20 %');
assert.equal(kroner(kurs({ innlogget: true, medlemsrabatt: 20, antall: 2, gruppe: 10 })),
  'kr. 2 703,-', 'medlemsrabatt gjelder egen plass, grupperabatt den andre');
assert.equal(kroner(kurs({ innlogget: true, medlemsrabatt: 20, prisOre: 200000 })),
  'kr. 1 600,-', 'valgt datos pris brukes i betalingssammendraget');
assert.equal(kroner(kurs({ innlogget: true, medlemsrabatt: 20, fraPris: true })),
  'fra kr. 1 350,-', 'fra-pris får ikke medlemsrabatt');

console.log('Medlemspris i kursbestilling: OK');

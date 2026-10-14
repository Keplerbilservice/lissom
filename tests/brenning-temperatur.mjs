import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const les=fil=>readFileSync(new URL(`../${fil}`,import.meta.url),'utf8');
const skjerm=les('admin-ny/arbeidsoppgaver.js');
const api=les('api/admin/verkstedet.php');
const kalender=les('api/admin/kalender.php');
const migrasjon=les('db/migrations/278_brenning_temperatur.sql');

assert.match(skjerm,/\['1220','1220 °C'\],\['1250','1250 °C'\]/);
assert.match(skjerm,/velg:v=>v\.slag==='glasurbrann'/);
assert.match(api,/in_array\(\$temperatur, \[1220, 1250\], true\)/);
assert.match(api,/'temperatur' => \$slag === 'glasurbrann' \? \$temperatur : null/);
assert.match(kalender,/\$br\['temperatur'\].*' °C'/s);
assert.match(migrasjon,/ADD COLUMN temperatur SMALLINT UNSIGNED NULL/);

console.log('OK: glasurbrann kan lagres og vises med 1220 °C eller 1250 °C.');

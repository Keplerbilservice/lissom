import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const les=fil=>readFileSync(new URL(`../${fil}`,import.meta.url),'utf8');
const side=les('lissom-2108.html');
const lett=les('lissom-2108-uten-admin.html');
const api=les('api/ovn.php');

for(const html of [side,lett]){
  assert.doesNotMatch(html,/ovnGlasurNaa/);
  assert.match(html,/<option value="1220">1220 °C<\/option>/);
  assert.match(html,/<option value="1250">1250 °C<\/option>/);
}
assert.match(side,/ovnGlasurVelg: e =>/);
assert.match(api,/in_array\(\$temperatur, \[1220, 1250\], true\)/);
assert.match(api,/'temperatur' => \$slag === 'glasurbrann'/);
assert.match(api,/\$rad\['tomt_slag'\] = \(string\) \$forrige\['slag'\]/);
assert.match(side,/Råbrann er tømt/);

console.log('OK: hurtigknappen for glasurbrann velger 1220 eller 1250 °C.');

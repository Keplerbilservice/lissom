import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const les=fil=>readFileSync(new URL(`../${fil}`,import.meta.url),'utf8');
const side=les('lissom-2108.html');
const lett=les('lissom-2108-uten-admin.html');
const api=les('api/ovn.php');

for(const html of [side,lett]){
  assert.doesNotMatch(html,/ovnGlasurNaa/);
  assert.match(html,/>Glasur 1220<\/button>/);
  assert.match(html,/>Glasur 1250<\/button>/);
}
assert.match(side,/ovnGlasur1220Naa: \(\) => this\.ovnKall\('glasurbrann', 1220\)/);
assert.match(side,/ovnGlasur1250Naa: \(\) => this\.ovnKall\('glasurbrann', 1250\)/);
assert.match(api,/in_array\(\$temperatur, \[1220, 1250\], true\)/);
assert.match(api,/'temperatur' => \$slag === 'glasurbrann'/);

console.log('OK: hurtigknappen for glasurbrann velger 1220 eller 1250 °C.');

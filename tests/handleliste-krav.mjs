import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
// «Krev inn med Vipps» og «Send bestilling» i handlelista mot den falske Vippsen (kontrolløren 09.10.2026).
// Testdataene lages og ryddes av tests/handleliste-krav.php selv (egne servere). Ingen ekte Vipps, e-post eller SMS.
let ut;
try {
  ut = execFileSync('php', ['tests/handleliste-krav.php'], { encoding: 'utf8' }).trim();
} catch (e) {
  console.log(String(e.stdout || '') + String(e.stderr || ''));
  throw e;
}
console.log(ut);
assert.match(ut, /(\d+) av \1 handleliste-krav-kontroller bestått/);

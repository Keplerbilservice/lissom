import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
// Vipps-krav fra «Start kurset» (bølge 2) mot den falske Vippsen og testserveren.
// Testdataene lages og ryddes av tests/kursstart-krav.php selv. Ingen ekte Vipps, e-post eller SMS.
let ut;
try {
  ut = execFileSync('php', ['tests/kursstart-krav.php'], { encoding: 'utf8' }).trim();
} catch (e) {
  console.log(String(e.stdout || '') + String(e.stderr || ''));
  throw e;
}
console.log(ut);
assert.match(ut, /(\d+) av \1 kursstart-krav-kontroller bestått/);

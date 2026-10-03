import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
// «nydatoer» og «nydato» i kurs.php (kalenderplanen, bølge 3) mot testserveren.
// Testdataene lages og ryddes av tests/kalender-gjenta.php selv. Ingen e-post eller SMS.
let ut;
try {
  ut = execFileSync('php', ['tests/kalender-gjenta.php'], { encoding: 'utf8' }).trim();
} catch (e) {
  console.log(String(e.stdout || '') + String(e.stderr || ''));
  throw e;
}
console.log(ut);
assert.match(ut, /(\d+) av \1 kalender-gjenta-kontroller bestått/);

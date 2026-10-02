import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
// Frys og fast trekk mot den falske Vippsen (eieren, 2. oktober 2026).
// Testmedlemmene lages og ryddes av tests/frys-trekk.php selv.
const ut = execFileSync('php', ['tests/frys-trekk.php'], { encoding: 'utf8' }).trim();
console.log(ut);
assert.match(ut, /(\d+) av \1 frys-trekk-kontroller bestått/);

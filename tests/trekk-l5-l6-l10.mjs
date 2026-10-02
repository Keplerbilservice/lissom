import{execFileSync}from'node:child_process';
import assert from'node:assert/strict';
// L-5, L-6, L-10 og trekkdag den 1. Testmedlemmet lages og ryddes her.
const fixture=(m,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',m,JSON.stringify(s||{})],{encoding:'utf8'}));
const s=fixture('seed');
try{const out=execFileSync('php',['tests/trekk-l5-l6-l10.php',JSON.stringify(s)],{encoding:'utf8'}).trim();console.log(out);assert.match(out,/(\d+) av \1 trekkontroller/);}finally{fixture('cleanup',s);}

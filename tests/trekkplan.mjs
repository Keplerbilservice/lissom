import{execFileSync}from'node:child_process';
import assert from'node:assert/strict';
const fixture=(m,s)=>JSON.parse(execFileSync('php',['tests/nettleser/henting-fixture.php',m,JSON.stringify(s||{})],{encoding:'utf8'}));
const s=fixture('seed');
try{const out=execFileSync('php',['tests/trekkplan.php',JSON.stringify(s)],{encoding:'utf8'}).trim();assert.match(out,/^28 trekkplankontroller bestått$/);console.log(out);}finally{fixture('cleanup',s);}

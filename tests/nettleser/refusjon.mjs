import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
const HERE=path.dirname(fileURLToPath(import.meta.url));
const ROOT=path.resolve(HERE,'../..');
const require=createRequire(import.meta.url);
let chromium;
for(const base of [ROOT,path.resolve(ROOT,'../lissom')]) {
  try { ({chromium}=require(require.resolve('playwright',{paths:[base]}))); break; } catch {}
}
if(!chromium) throw new Error('Playwright mangler');
const fixture=(mode,id) => JSON.parse(execFileSync('php',[path.join(HERE,'refusjon-fixture.php'),mode,...(id?[String(id)]:[])],{cwd:ROOT,encoding:'utf8'}));
const ADR=process.env.E2E_REFUND_ADRESSE || 'http://lokal.lissom.no:8160';
const STUB=process.env.E2E_REFUND_VIPPS || 'http://127.0.0.1:8165';
assert.equal(new URL(ADR).hostname,'lokal.lissom.no');
const browser=await chromium.launch({args:['--host-resolver-rules=MAP lokal.lissom.no 127.0.0.1']});
try {
  for(const [name,width,height] of [['PC',1358,900],['Nettbrett',820,1180],['Mobil',390,844]]) {
    const seed=fixture('seed');
    const context=await browser.newContext({viewport:{width,height},isMobile:width<600,hasTouch:width<900});
    try {
      await context.addCookies([{name:'lissom_sesjon',value:seed.token,domain:'lokal.lissom.no',path:'/',httpOnly:true}]);
      await context.addInitScript(() => localStorage.setItem('lissom-samtykke','nei'));
      const page=await context.newPage();
      const errors=[];page.on('pageerror',e=>errors.push(e.message));
      page.on('dialog',d=>d.accept());
      let lost=false;const requests=[];
      await page.route('**/api/admin/betalinger.php',async route=>{
        if(route.request().method()!=='POST') return route.continue();
        const data=route.request().postDataJSON();
        if(data.referanse!==seed.ref) return route.continue();
        requests.push(data);
        if(!lost) {
          // APIRequestContext bruker OS-DNS, ikke Chromiums resolverregel.
          const response=await route.fetch({url:route.request().url().replace('lokal.lissom.no','127.0.0.1'),
            headers:{...route.request().headers(),host:new URL(ADR).host}});
          assert.equal(response.status(),200,await response.text());
          lost=true; await route.abort('failed');
        } else await route.continue();
      });
      const open=async()=>{
        await page.goto(ADR+'/admin/okonomi',{waitUntil:'networkidle',timeout:45000});
        const person=page.getByText(seed.name,{exact:true}).first();
        await person.waitFor({timeout:20000});
        const card=person.locator('xpath=ancestor::div[.//button[contains(normalize-space(.),"Refunder")]][1]');
        await card.getByRole('button',{name:'Refunder …',exact:true}).click();
        await card.getByPlaceholder('Tomt = alt som gjenstår').fill('20');
        await card.getByRole('button',{name:'Refunder',exact:true}).click();
      };
      await open();
      await page.getByText('Fikk ikke svar fra serveren. Prøv igjen.',{exact:true}).waitFor({timeout:15000});
      assert.equal(fixture('inspect',seed.payment).refunded,2000);
      await open();
      await page.getByText('kr. 20,- er refundert',{exact:true}).waitFor({timeout:15000});
      const state=fixture('inspect',seed.payment);
      assert.equal(state.refunded,2000);
      assert.equal(state.operations.length,1);
      assert.equal(requests.length,2);
      assert.equal(requests[0].operasjonId,requests[1].operasjonId);
      const ledger=await fetch(STUB+'/ledger').then(r=>r.json());
      const actual=ledger.filter(x=>x.ref===seed.ref);
      assert.equal(actual.length,1);
      assert.equal(actual[0].body.modificationAmount.value,2000);
      assert.equal(errors.length,0,errors.join('\n'));
      console.log('OK '+name+': ekte dialog, servercommit, tapt svar, reload og samme delrefusjon ga bare én Vipps-operasjon.');
      if(process.env.E2E_REFUND_BILDER) await page.screenshot({path:path.join(process.env.E2E_REFUND_BILDER,'refusjon-'+name+'.png'),fullPage:true});
    } finally { await context.close(); fixture('cleanup',seed.payment); }
  }
} finally { await browser.close(); }

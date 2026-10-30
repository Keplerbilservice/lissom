// Lissom Kasse på iPad (eieren «ok, bygg det» 08.10.2026, skissen Rm1Casf4CpVv8i5zwyfecX).
// Ny kasse etter eierens godkjente design 30.10.2026: brun topp med «Dagens oppgjør», valgene Paint on Pots, Keramikk,
// Gavekort og Leire, «Har betalt» på kurset, fire betalingsknapper (Vipps-nummer, Kontant, Gavekort, Natura), Betalt-skjermen
// med Kunde og Kjøpet, «Dagens salg» med «Angre» og stripa uten nett.
// Egen innlogging (rollen «kasse»), PIN per person, låser seg etter 5 minutter uten bruk.
// All regning skjer på serveren (api/kasse/kasse.php, app/lib/kasse.php): skjermen sender hva som er valgt
// og beløpet den viste, og ingenting registreres hvis det ikke stemmer.
const rot=document.getElementById('kasse');
const meldinger=document.getElementById('meldinger');
let data=null;      // «I dag» fra serveren
let person=null;    // den som står i kassa
let salg=null;      // kunden som betaler nå
let ferdig=null;    // Betalt-skjermen (til «I dag» trykkes)
let laasMs=5*60*1000;
let laasTimer=null;
let pollTimer=null;

function el(tag,attrs={},...barn){const n=document.createElement(tag);for(const [k,v] of Object.entries(attrs)){if(v===undefined||v===null||v===false)continue;if(k==='text')n.textContent=v;else if(k.startsWith('on'))n.addEventListener(k.slice(2),v);else n.setAttribute(k,v===true?'':String(v));}for(const c of barn.flat(Infinity)){if(c===null||c===undefined||c===false)continue;n.append(c instanceof Node?c:document.createTextNode(String(c)));}return n;}
const kr=o=>(o<0?'−':'')+String(Math.round(Math.abs(o)/100)).replace(/\B(?=(\d{3})+(?!\d))/g,' ')+' kr';
const uuid=()=>{if(crypto.randomUUID)return crypto.randomUUID();const b=crypto.getRandomValues(new Uint8Array(16));b[6]=(b[6]&15)|64;b[8]=(b[8]&63)|128;const h=[...b].map(x=>x.toString(16).padStart(2,'0')).join('');return `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`;};
function melding(tekst,ms=4500){if(!tekst)return;const p=el('p',{text:tekst});meldinger.append(p);setTimeout(()=>p.remove(),ms);}
const pille=(tekst,fn,klasse='')=>el('button',{type:'button',class:'k-pille '+klasse,text:tekst,onclick:fn});
const stopPoll=()=>{if(pollTimer){clearTimeout(pollTimer);pollTimer=null;}};

// ── Uten nett (eieren 30.10.2026) ────────────────────────────────────
// Gul stripe under toppen når nettet er borte eller et kall feiler fordi nettet er nede. Betalingsknappene er grå mens
// stripa vises, og den forsvinner av seg selv når nettet er tilbake (sjekkes hvert 5. sekund).
const INGEN_NETT='Ingen nett – skriv salget på papir og legg det inn senere.';
const nettStripe=el('div',{class:'k-nett',role:'alert',hidden:true,text:INGEN_NETT});
let nettNede=false,nettTimer=null;
function settNett(oppe){
 if(oppe===!nettNede)return;
 nettNede=!oppe;nettStripe.hidden=oppe;
 clearTimeout(nettTimer);nettTimer=null;
 if(!oppe)nettTimer=setTimeout(sjekkNett,5000);
 if(salg&&!salg.vent)tegnKurv();
}
async function sjekkNett(){nettTimer=null;try{const r=await fetch('/api/kasse/pin.php',{credentials:'same-origin',cache:'no-store'});if(r.status<500){settNett(true);return;}}catch(e){}if(nettNede)nettTimer=setTimeout(sjekkNett,5000);}
addEventListener('offline',()=>settNett(false));
addEventListener('online',()=>sjekkNett());

async function kall(fil,body){
 let r;
 try{r=await fetch('/api/kasse/'+fil,{credentials:'same-origin',cache:'no-store',...(body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{})});}
 catch(e){settNett(false);throw Error(INGEN_NETT);}
 settNett(true);
 let d;try{d=await r.json();}catch{throw Error('Serveren svarte ikke som forventet. Prøv igjen.');}
 if(r.status===423){stopPoll();visPin();throw Object.assign(Error(''),{stille:true});}
 if(r.status===401){stopPoll();visInnlogging();throw Object.assign(Error(''),{stille:true});}
 if(!r.ok||d.ok===false)throw Object.assign(Error(d.feil||'Kunne ikke hente opplysningene.'),{status:r.status,data:d});
 return d;
}
const feil=e=>{if(!e.stille)melding(e.message);};

// ── Låsen: 5 minutter uten bruk ──────────────────────────────────────
function aktiv(){if(!person)return;clearTimeout(laasTimer);laasTimer=setTimeout(laas,laasMs);}
document.addEventListener('pointerdown',aktiv,{passive:true});
document.addEventListener('keydown',aktiv);
async function laas(){clearTimeout(laasTimer);stopPoll();stoppListe();document.querySelectorAll('.k-ark').forEach(a=>a.remove());person=null;salg=null;ferdig=null;try{await kall('pin.php',{handling:'laas'});}catch(e){}visPin();}

// «✕ Lukk» (eieren 09.10.2026): kassa åpnet fra ny admin (fra=ny-admin) eller av en innlogget admin går tilbake til /ny-admin,
// til I dag eller siden i tilbake=. iPad-kassen (kassekontoen, uten fra=ny-admin) får ikke knappen og er som før.
const sok=new URLSearchParams(location.search);
const tilbakeSide=(s=>/^[a-z]{2,20}$/.test(s)?s:'idag')(sok.get('tilbake')||'');
let fraNyAdmin=sok.get('fra')==='ny-admin';
const lukkKnapp=()=>fraNyAdmin?pille('✕ Lukk',lukkKassa,'k-lukk'):null;
async function lukkKassa(){
 if(ikkeNaa())return;
 // Uferdig salg i kurven: spør først (samme ark som ellers i kassa).
 if(salg&&(harVarer(salg)||salg.bookingId||salg.pop.size>0)){
  const ok=await new Promise(svar=>{const a=ark('Det ligger varer i kurven',lukk=>[el('div',{class:'k-rad-knapper'},pille('Lukk likevel',()=>{lukk();svar(true);},'fylt'),pille('Avbryt',()=>{lukk();svar(false);}))]);a.addEventListener('click',ev=>{if(ev.target===a)svar(false);});});
  if(!ok||ikkeNaa())return;
 }
 stopPoll();stoppListe();
 location.href='/admin#'+tilbakeSide;
}
function topp(tittel,...hoyre){return el('header',{class:'k-topp'},el('span',{class:'k-tittel',text:tittel}),el('div',{class:'k-valg'},...hoyre,lukkKnapp()));}
// Toppen (eieren 30.10.2026): navnet på den som står i kassa, en synlig gul «Dagens oppgjør» og menyen ⋯ med «Lås».
const personValg=()=>person?[el('span',{class:'k-person',text:person.navn}),el('button',{type:'button',class:'k-pille k-oppgjorknapp',text:'Dagens oppgjør',onclick:visOppgjor}),el('button',{type:'button',class:'k-pille k-meny','aria-label':'Meny',text:'⋯',onclick:meny})]:[];

// ── Start ────────────────────────────────────────────────────────────
async function start(){
 try{const s=await kall('pin.php');if(s.admin)fraNyAdmin=true;laasMs=(s.laasMinutter||5)*60*1000;person=s.person;if(person){aktiv();await visIdag();}else visPin();}
 catch(e){if(e.stille)return;if(e.status===404){visInnlogging('Denne kontoen har ikke tilgang til kassa.');return;}visBeskjed(e.message);}
}
function visBeskjed(tekst){rot.replaceChildren(topp('Lissom Kasse'),nettStripe,el('div',{class:'k-midt'},el('h1',{text:'Lissom Kasse'}),el('p',{text:tekst})));}

function visInnlogging(feilTekst=''){
 person=null;clearTimeout(laasTimer);
 const bruker=el('input',{name:'brukernavn',autocomplete:'username',autocapitalize:'none',required:true});
 const passord=el('input',{name:'passord',type:'password',autocomplete:'current-password',required:true});
 const f=el('p',{class:'k-feil',role:'alert',text:feilTekst,hidden:!feilTekst});
 const knapp=el('button',{class:'k-stor',type:'submit',text:'Logg inn',style:'max-width:360px'});
 rot.replaceChildren(topp('Lissom Kasse',el('small',{text:'iPad i verkstedet'})),nettStripe,el('form',{class:'k-midt',onsubmit:async ev=>{ev.preventDefault();knapp.disabled=true;f.hidden=true;try{const r=await fetch('/api/logg-inn.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({brukernavn:bruker.value,passord:passord.value})});const d=await r.json().catch(()=>({}));if(!r.ok||d.ok===false)throw Error(d.feil||'Feil brukernavn eller passord.');if(!d.erKasse&&!d.erAdmin)throw Error('Denne kontoen har ikke tilgang til kassa.');await start();}catch(e){f.textContent=e.message;f.hidden=false;}finally{knapp.disabled=false;}}},
  el('h1',{text:'Lissom Kasse'}),el('label',{class:'k-felt'},el('span',{text:'Brukernavn'}),bruker),el('label',{class:'k-felt'},el('span',{text:'Passord'}),passord),f,knapp));
}

// ── PIN ──────────────────────────────────────────────────────────────
function visPin(){
 person=null;salg=null;ferdig=null;kursVist=null;clearTimeout(laasTimer);stopPoll();stoppListe();document.querySelectorAll('.k-ark').forEach(a=>a.remove());
 let pin='';let opptatt=false;
 const prikker=el('div',{class:'k-prikker','aria-hidden':'true'});
 const f=el('p',{class:'k-feil',role:'alert',hidden:true});
 const tegn=()=>prikker.replaceChildren(...[0,1,2,3].map(i=>el('i',{class:i<pin.length?'':'t'})));
 const tast=async s=>{if(opptatt)return;if(s==='⌫'){pin=pin.slice(0,-1);tegn();return;}if(pin.length>=4)return;pin+=s;tegn();f.hidden=true;
  if(pin.length===4){opptatt=true;try{const r=await kall('pin.php',{handling:'pin',pin});person=r.person;aktiv();await visIdag();}catch(e){if(e.stille)return;pin='';tegn();f.textContent=e.message;f.hidden=false;}finally{opptatt=false;}}};
 tegn();
 rot.replaceChildren(topp('Lissom Kasse',el('small',{text:'iPad i verkstedet'})),nettStripe,el('div',{class:'k-midt'},el('h1',{text:'Hvem står i kassa?'}),prikker,f,
  el('div',{class:'k-pin'},[1,2,3,4,5,6,7,8,9,'',0,'⌫'].map(x=>x===''?el('span'):el('button',{type:'button',text:String(x),'aria-label':x==='⌫'?'Slett':String(x),onclick:()=>tast(String(x))})))));
}

// ── I dag: valgene til venstre, kurven fast til høyre ────────────────
// Venstre (eieren 30.10.2026): Kontantkunde/+ Ny kunde, Dagens kurs (alle dagens økter; borte når det ikke er noen),
// fire store valg (Paint on Pots, Keramikk, Gavekort, Leire) og «Skriv beløp» nederst.
// Trykk på et kurs: flaten byttes ut med dem som skylder og «Har betalt». Bare venstre flate ruller; lista hentes på nytt hvert 15. sekund.
let venstreEl=null,kurvEl=null,kursVist=null,listeTimer=null,opptatt=false;
let valgt='pop',filter='Alt',sokTekst='';
const VALG=[['pop','Paint on Pots'],['keramikk','Keramikk'],['gavekort','Gavekort'],['leire','Leire']];
const stoppListe=()=>{if(listeTimer){clearTimeout(listeTimer);listeTimer=null;}};
const kort=(a,...barn)=>el('button',{type:'button',class:'k-k'+(a.class?' '+a.class:'')+(a.valgt?' valgt':''),disabled:a.disabled,'aria-pressed':a.valgt?'true':undefined,'aria-label':a.label,onclick:a.onclick},...barn);
const utenAntall=t=>String(t).replace(/ × \d+$/,'');
const sett=(n,...barn)=>n.replaceChildren(...barn.flat().filter(x=>x!==null&&x!==undefined&&x!==false));
async function visIdag(){
 stopPoll();stoppListe();
 try{data=await kall('kasse.php');}catch(e){if(!e.stille)visBeskjed(e.message);return;}
 person=data.person;laasMs=(data.laasMinutter||5)*60*1000;
 venstreEl=el('div',{class:'k-sone'});kurvEl=el('section',{class:'k-kurvsone','aria-label':'Kurven'});
 rot.replaceChildren(topp('Lissom Kasse · '+data.dato,...personValg()),nettStripe,el('div',{class:'k-a'},venstreEl,kurvEl));
 if(kursVist){try{kursVist=await kall('kasse.php?okt='+kursVist.oktId);}catch(e){kursVist=null;}}
 tegnVenstre();await visSalg();lytt();
 await taFraAdmin();
}
// «Ta betalt» i ny admin (eieren 09.10.2026): /kasse?booking=<id> legger påmeldingen i kurven med det som står igjen,
// uansett dato, når kassa er låst opp. Er kassa alt åpen, kommer bookingen som en melding (NA.tilKassa i ny-admin/felles.js),
// så et salg som er i gang ikke forsvinner. Alt betalt, Vipps pågår eller ikke funnet: beskjed, og ingenting i kurven.
let fraAdmin=(()=>{const n=Number(new URLSearchParams(location.search).get('booking'));return Number.isInteger(n)&&n>0?n:0;})();
if(fraAdmin){try{const q=new URLSearchParams(location.search);q.delete('booking');const t=q.toString();history.replaceState(null,'',location.pathname+(t?'?'+t:'')+location.hash);}catch(e){}}
addEventListener('message',ev=>{if(ev.origin!==location.origin)return;const n=Number(ev.data&&ev.data.kasseBooking);if(!Number.isInteger(n)||n<=0)return;fraAdmin=n;if(person&&venstreEl&&venstreEl.isConnected)taFraAdmin();});
async function taFraAdmin(){
 const id=fraAdmin;if(!id||!person)return;fraAdmin=0;
 if(ikkeNaa())return;
 let r;
 try{r=await kall('kasse.php?booking='+id);}catch(e){if(e.stille){fraAdmin=id;return;}ark(e.message,lukk=>[el('div',{class:'k-rad-knapper'},pille('Lukk',lukk,'fylt'))]);return;}
 // Ligger det varer i kurven, spør først (eieren 09.10.2026): «Ta dem med» eller «Fjern dem». Tom kurv: rett inn.
 if(salg&&harVarer(salg)){
  const valg=await new Promise(svar=>{const a=ark('Det ligger varer i kurven',lukk=>[el('div',{class:'k-rad-knapper'},pille('Ta dem med',()=>{lukk();svar('med');}),pille('Fjern dem',()=>{lukk();svar('fjern');}))]);a.addEventListener('click',ev=>{if(ev.target===a)svar(null);});});
  if(!valg||ikkeNaa())return;
  if(valg==='fjern'){stopPoll();salg=null;}
 }
 const k=r.idag?data.kurs.find(x=>x.oktId===r.oktId):null;
 if(k)await apneKurs(k);
 await nyttSalg({bookingId:r.bookingId,navn:r.navn,oktId:r.oktId});
}
const harVarer=s=>s.varer.size>0||s.fritt.length>0||s.gavekort.length>0||s.popUten.size>0||!!s.timepakke;
function lytt(){stoppListe();listeTimer=setTimeout(async()=>{listeTimer=null;if(!person||!venstreEl||!venstreEl.isConnected)return;try{if(kursVist)kursVist=await kall('kasse.php?okt='+kursVist.oktId);else data=await kall('kasse.php');tegnVenstre();}catch(e){if(e.stille)return;if(e.status===404){kursVist=null;tegnVenstre();}}lytt();},15000);}
async function oppdaterListe(){try{if(kursVist)kursVist=await kall('kasse.php?okt='+kursVist.oktId);data=await kall('kasse.php');}catch(e){if(e.stille)return;if(e.status===404)kursVist=null;}tegnVenstre();}

// Paint on Pots: prisnivåene fra PopPris::nivaer(), samme som booking på nett. Flisen viser navn, pris og gjenstandene.
const nivaaKort=n=>kort({class:'pris',onclick:()=>leggTilNivaa(n)},el('b',{text:n.navn}),el('span',{class:'k-pris',text:n.pris}),n.gjenstander?el('small',{text:n.gjenstander}):null);
function prisliste(){
 if(!data.nivaer.length)return[];
 return[el('h3',{text:'Paint on Pots · prislisten'}),el('div',{class:'k-g3'},data.nivaer.map(nivaaKort))]; // Bare kategoriene på prislisten (eieren 08.10.2026)
}
// Keramikk og Leire: bilde, navn og pris, og antallet i kurven på bildet. Et stykke som er solgt (her eller på nett),
// eller som ligger i kurven til siste stykke, er grått med «Solgt» og kan ikke trykkes.
const iKurven=id=>salg?(salg.varer.get(id)||0):0;
const solgt=v=>v.lager!==null&&v.lager-iKurven(v.id)<=0;
function vareKort(v){
 const n=iKurven(v.id);const ut=solgt(v);
 return kort({class:'vare'+(ut?' solgt':''),disabled:ut,label:v.tittel,onclick:()=>leggTilVare(v)},
  el('span',{class:'k-bilde'},v.bilde?el('img',{src:v.bilde,alt:'',loading:'lazy'}):null,n>0?el('span',{class:'k-antall',text:String(n)}):null,ut?el('span',{class:'k-merke solgt',text:'Solgt'}):null),
  el('b',{text:v.tittel}),el('span',{class:'k-pris',text:v.pris}));
}
let sokEl=null,vareGrid=null;
function tegnVarer(){
 if(!vareGrid)return;
 const q=sokTekst.trim().toLowerCase();
 const liste=data.varer.filter(v=>v.keramikk&&(filter==='Alt'||v.kategori===filter)&&(!q||v.tittel.toLowerCase().includes(q)));
 vareGrid.replaceChildren(...liste.map(vareKort));
}
function keramikk(){
 if(!sokEl)sokEl=el('input',{type:'search',class:'k-sok','aria-label':'Søk',placeholder:'Søk',autocomplete:'off',oninput:()=>{sokTekst=sokEl.value;tegnVarer();}});
 sokEl.value=sokTekst;
 vareGrid=el('div',{class:'k-g4 k-varer'});tegnVarer();
 return[sokEl,el('div',{class:'k-valg-rad k-filtre'},['Alt',...(data.filtre||[])].map(f=>el('button',{type:'button',class:'k-pille'+(filter===f?' v':''),'aria-pressed':filter===f?'true':'false',text:f,onclick:()=>{filter=f;tegnVenstre();}}))),vareGrid];
}
function leire(){vareGrid=null;return[el('div',{class:'k-g4 k-varer'},data.varer.filter(v=>v.leire).map(vareKort))];}
function gavekortValg(){vareGrid=null;return[el('div',{class:'k-g3'},kort({class:'pris',onclick:()=>belopArk('Gavekort',b=>endreKurv(()=>{salg.gavekort.push(b);salg.sist='gavekort:'+(salg.gavekort.length-1);}))},el('b',{text:'Gavekort'}),el('small',{text:'Velg beløp'})))];}
function valgInnhold(){
 if(valgt==='keramikk')return keramikk();
 vareGrid=null;
 if(valgt==='gavekort')return gavekortValg();
 if(valgt==='leire')return leire();
 return data.nivaer.length?[el('div',{class:'k-g3'},data.nivaer.map(nivaaKort))]:[];
}
const personKort=(k,r,betalt)=>kort({class:'kurs',valgt:!!salg&&salg.bookingId===r.bookingId,onclick:()=>nyttSalg({bookingId:r.bookingId,navn:r.navn,oktId:k.oktId})},el('b',{text:r.navn}),el('small',{text:r.pop?`${r.antall} ${r.antall===1?'person':'personer'}`:r.info}),
 betalt?el('span',{class:'k-merke betalt',text:'Betalt'}):r.pop&&r.depositumOre?el('span',{class:'k-merke dep',text:r.depositum+' depositum'}):el('span',{class:'k-merke skylder',text:r.pille.tekst}));
function tegnVenstre(){
 if(!venstreEl)return;
 if(ferdig){tegnFerdigVenstre();return;}
 const y=venstreEl.scrollTop;const v=[];const fokus=sokEl&&document.activeElement===sokEl;
 if(kursVist){const k=kursVist;vareGrid=null;
  v.push(el('div',{class:'k-sone-topp'},pille('Tilbake',()=>{kursVist=null;tegnVenstre();venstreEl.scrollTop=0;}),el('h3',{text:`${k.tittel} ${k.kl} · ${k.skalBetale} skal betale`})));
  if(k.rader.length)v.push(el('div',{class:'k-g4'},k.rader.map(r=>personKort(k,r,false))));
  else if(!(k.betalte||[]).length)v.push(el('p',{class:'k-tom',text:'Alle har betalt.'}));
  // «Har betalt» (eieren 30.10.2026): de på økta som har betalt alt, med grønt «Betalt».
  if((k.betalte||[]).length)v.push(el('h3',{text:'Har betalt'}),el('div',{class:'k-g4'},k.betalte.map(r=>personKort(k,r,true))));
  if(k.pop)v.push(...prisliste());
 }else{
  v.push(el('div',{class:'k-g2'},kort({class:'kunde',valgt:!!salg&&salg.kontantkunde,onclick:kontantkunde},el('b',{text:'Kontantkunde'}),el('small',{text:'Salg uten navn'})),kort({class:'kunde',onclick:nyKundeArk},el('b',{text:'+ Ny kunde'}),el('small',{text:'Navn og telefon'}))));
  // Ingen økter i dag: hele seksjonen er borte, og valgene under blir større.
  if(data.kurs.length){
   v.push(el('h3',{text:'Dagens kurs'}));
   v.push(el('div',{class:'k-g4'},data.kurs.map(k=>kort({class:'kurs',valgt:!!salg&&salg.oktId===k.oktId,onclick:()=>apneKurs(k)},el('small',{text:k.kl}),el('b',{text:k.tittel}),k.skalBetale>0?el('span',{class:'k-merke skylder',text:k.skalBetale+' skal betale'}):el('span',{class:'k-merke betalt',text:'Alle har betalt'})))));
  }
  v.push(el('div',{class:'k-velg'+(data.kurs.length?'':' stor')},VALG.map(([id,navn])=>el('button',{type:'button',class:'k-velgknapp'+(valgt===id?' valgt':''),'aria-pressed':valgt===id?'true':'false',text:navn,onclick:()=>{valgt=id;tegnVenstre();}}))));
  v.push(...valgInnhold());
  v.push(el('div',{class:'k-bunn'},pille('Skriv beløp',skrivBelopArk,'k-skrivbelop')));
 }
 venstreEl.replaceChildren(...v);venstreEl.scrollTop=y;
 if(fokus&&sokEl.isConnected){sokEl.focus();}
}
async function apneKurs(k){if(ikkeNaa())return;kursVist={...k,rader:[],betalte:[]};tegnVenstre();venstreEl.scrollTop=0;try{kursVist=await kall('kasse.php?okt='+k.oktId);}catch(e){feil(e);kursVist=null;}tegnVenstre();lytt();}

function ark(tittel,innhold,klasse=''){const lukk=()=>a.remove();const a=el('div',{class:'k-ark '+klasse,onclick:ev=>{if(ev.target===a)lukk();}},el('div',{},el('h2',{text:tittel}),...innhold(lukk)));document.body.append(a);return a;}
// Beløpet vises stort mens det tastes (eieren 30.10.2026).
function belopArk(tittel,ok,ekstra,sjekk){
 const stor=el('div',{class:'k-belop-stor','aria-hidden':'true',text:kr(0)});
 const inn=el('input',{type:'text',inputmode:'decimal',autocomplete:'off',oninput:()=>{const t=inn.value.replace(/\s|kr|,-/g,'').replace(',','.');const n=Number(t);stor.textContent=kr(isFinite(n)&&n>0?Math.round(n*100):0);}});
 const f=el('p',{class:'k-feil',role:'alert',hidden:true});
 ark(tittel,lukk=>[ekstra?ekstra(lukk):null,el('form',{class:'k-skjema',onsubmit:ev=>{ev.preventDefault();const t=inn.value.replace(/\s|kr|,-/g,'').replace(',','.');if(!t||!isFinite(Number(t))||Number(t)<=0){f.textContent='Skriv inn et beløp over null.';f.hidden=false;return;}const m=sjekk&&sjekk();if(m){f.textContent=m;f.hidden=false;return;}lukk();ok(t);}},
  stor,el('label',{class:'k-felt'},el('span',{text:'Beløp i kroner'}),inn),f,el('div',{class:'k-rad-knapper'},pille('Avbryt',lukk),el('button',{type:'submit',class:'k-pille fylt',text:'Legg til'})))]);
 inn.focus();
}
// Timepakke ligger under «Skriv beløp» som et fast valg (eieren 08.10.2026).
// Hva beløpet gjelder velges først (eieren 24.10.2026): kurs og kunst uten mva, brukskunst og leire med mva (KasseKurv::FRITT_SLAG).
const FRITT_SLAG=[['kurs','Kurs','uten mva'],['kunst','Kunst','uten mva'],['brukskunst','Brukskunst','med mva'],['leire','Leire','med mva']];
function skrivBelopArk(){if(ikkeNaa())return;let slag=null;belopArk('Skriv beløp',b=>endreKurv(()=>{salg.fritt.push({belop:b,slag});salg.sist='fri:'+(salg.fritt.length-1);}),lukk=>{const g=el('div',{class:'k-g2'});const tegn=()=>g.replaceChildren(...FRITT_SLAG.map(([k,n,m])=>kort({valgt:slag===k,onclick:()=>{slag=k;tegn();}},el('b',{text:n}),el('small',{text:m}))));tegn();return el('div',{},g,el('div',{class:'k-g2'},kort({onclick:()=>{lukk();endreKurv(()=>{salg.timepakke=true;salg.sist='timepakke';});}},el('b',{text:'Timepakke'}),el('small',{text:data.timepakke.timer+' timer · '+data.timepakke.pris}))));},()=>slag?'':'Velg hva beløpet gjelder.');}
function leggTilVare(v){endreKurv(()=>{const n=salg.varer.get(v.id)||0;if(v.lager!==null&&n>=v.lager){melding('Ikke flere på lager.');return;}salg.varer.set(v.id,n+1);salg.sist='vare:'+v.id;});}
function nyKundeArk(){
 if(ikkeNaa())return;
 const navn=el('input',{name:'navn',autocomplete:'off',autocapitalize:'words',required:true});
 const tlf=el('input',{name:'telefon',type:'tel',inputmode:'tel',autocomplete:'off'});
 const f=el('p',{class:'k-feil',role:'alert',hidden:true});
 let valg=null;const valgEl=el('div',{class:'k-valg-rad'});
 const tegnValg=()=>valgEl.replaceChildren(...data.ledigeKurs.map(k=>el('button',{type:'button',class:'k-pille'+(valg===k.oktId?' v':''),'aria-pressed':valg===k.oktId?'true':'false',onclick:()=>{valg=k.oktId;tegnValg();}},`${k.tittel} ${k.kl} · ${k.ledige} ${k.ledige===1?'ledig':'ledige'}`)),el('button',{type:'button',class:'k-pille'+(valg===0?' v':''),'aria-pressed':valg===0?'true':'false',text:'Bare kjøp',onclick:()=>{valg=0;tegnValg();}}));
 tegnValg();
 ark('Ny kunde',lukk=>[el('form',{class:'k-skjema',onsubmit:async ev=>{ev.preventDefault();if(opptatt)return;f.hidden=true;
   if(!navn.value.trim()){f.textContent='Skriv inn navnet.';f.hidden=false;return;}
   if(valg===null){f.textContent='Velg kurs eller «Bare kjøp».';f.hidden=false;return;}
   opptatt=true;try{const r=await kall('kasse.php',{handling:'nyKunde',navn:navn.value,telefon:tlf.value,oktId:valg||0});lukk();
    if(r.bookingId){await nyttSalg({bookingId:r.bookingId,navn:r.navn,oktId:valg});oppdaterListe();}else nyttSalg({betaler:r.betaler,navn:r.navn});}
   catch(e){if(!e.stille){f.textContent=e.message;f.hidden=false;}}finally{opptatt=false;}}},
  el('label',{class:'k-felt'},el('span',{text:'Navn'}),navn),el('label',{class:'k-felt'},el('span',{text:'Telefon'}),tlf),
  el('h3',{text:'Knytt til dagens kurs'}),valgEl,f,el('div',{class:'k-rad-knapper'},pille('Avbryt',lukk),el('button',{type:'submit',class:'k-pille fylt',text:'Videre'})))]);
 navn.focus();
}

// ── Salget ───────────────────────────────────────────────────────────
// «sist»: linja som sist ble lagt i kurven. Den er gul med «LAGT TIL» til neste endring (eieren 30.10.2026).
function tomtSalg(){return{betaler:null,navn:null,bookingId:null,oktId:null,person:null,pop:new Map(),popUten:new Map(),popGjester:0,varer:new Map(),fritt:[],gavekort:[],timepakke:false,priser:new Map(),rabatt:null,regnet:null,feil:'',kontantkunde:false,nokler:{},noklerSig:null,forventet:null,betalt:new Set(),betalinger:[],koder:[],sist:null};}
function start0(){if(!salg){salg=tomtSalg();salg.kontantkunde=true;tegnVenstre();}}
// Mens det registreres kan verken kurven eller kunden byttes (kontrolløren 08.10.2026).
const laast=()=>!!salg&&(salg.vent||salg.betalt.size>0);
const ikkeNaa=()=>{if(laast()){melding('Gjør ferdig betalingen først.');return true;}if(ferdig){ferdig=null;tegnVenstre();tegnKurv();}return false;};
function kontantkunde(){if(ikkeNaa())return;const g=salg;salg=tomtSalg();salg.kontantkunde=true;if(g)beholdVarer(g);tegnVenstre();visSalg();}
// Varene som alt er i kurven, blir med når kunden velges etterpå.
function beholdVarer(g){salg.varer=g.varer;salg.fritt=g.fritt;salg.gavekort=g.gavekort;salg.timepakke=g.timepakke;salg.popUten=g.popUten;salg.popGjester=g.popGjester;for(const [k,v] of g.priser)if(!k.startsWith('booking:'))salg.priser.set(k,v);salg.rabatt=g.rabatt;}
async function nyttSalg(fra){
 if(ikkeNaa())return;
 const g=salg;salg=tomtSalg();if(g)beholdVarer(g);salg.navn=fra.navn;salg.oktId=fra.oktId||null;
 if(fra.betaler){salg.betaler=fra.betaler;tegnVenstre();await visSalg();return;}
 salg.betaler={bookingId:fra.bookingId};salg.bookingId=fra.bookingId;
 const s=salg;
 try{await hentPerson(s);}catch(e){feil(e);if(salg===s)salg=null;}
 tegnVenstre();await visSalg();
}
async function hentPerson(s=salg){
 const p=await kall('kasse.php',{handling:'person',bookingId:s.bookingId});s.person=p;s.navn=p.navn;
 if(p.pop){s.pop=new Map();for(const l of p.lagret||[])s.pop.set(l.nokkel,{nivaaId:l.nivaaId,gjenstand:l.gjenstand,antall:l.antall});if(s.popUten.size){for(const [id,n] of s.popUten){const k=id+'|';const q=s.pop.get(k)||{nivaaId:id,gjenstand:'',antall:0};const pris=s.priser.get('pop:'+id);s.priser.delete('pop:'+id);if(pris!==undefined&&!q.antall)s.priser.set('booking:'+s.bookingId+':'+k,pris);q.antall+=n;s.pop.set(k,q);}s.popUten=new Map();s.popGjester=0;}}
}
const kurvTilServer=(s=salg)=>({bookingId:s.bookingId||undefined,pop:[...s.pop.values()].filter(p=>p.antall>0).map(p=>({nivaaId:p.nivaaId,gjenstand:p.gjenstand,antall:p.antall})),popGjester:s.popUten.size?s.popGjester:undefined,popUten:[...s.popUten].map(([nivaaId,antall])=>({nivaaId,antall})),varer:[...s.varer].map(([id,antall])=>({id,antall})),fritt:s.fritt,gavekort:s.gavekort,timepakke:s.timepakke||undefined,priser:s.priser.size?Object.fromEntries(s.priser):undefined,rabatt:s.rabatt||undefined});
const betalerTilServer=(s=salg)=>s.kontantkunde||!s.betaler?{}:s.betaler;
const tomKurv=(s=salg)=>!s.bookingId&&!s.popUten.size&&!s.varer.size&&!s.fritt.length&&!s.gavekort.length&&!s.timepakke;

let regnNr=0;
async function regn(s=salg){
 const nr=++regnNr;
 if(tomKurv(s)){s.regnet=null;s.feil='';return;}
 try{const d=await kall('kasse.php',{handling:'regn',kurv:kurvTilServer(s),betaler:betalerTilServer(s)});if(nr!==regnNr)return;s.regnet=d;s.feil='';}
 catch(e){if(nr!==regnNr||e.stille)return;s.regnet=null;s.feil=e.message;}
}
// Alt som endrer kurven går hit. Er betalingen i gang (registrerer), gjøres ingenting.
// Sier serveren nei til endringen (for eksempel timepakke uten medlem, rabatt over summen), går kurven tilbake og feilen vises.
const bilde=s=>({pop:new Map([...s.pop].map(([k,v])=>[k,{...v}])),popUten:new Map(s.popUten),popGjester:s.popGjester,varer:new Map(s.varer),fritt:[...s.fritt],gavekort:[...s.gavekort],timepakke:s.timepakke,priser:new Map(s.priser),rabatt:s.rabatt,sist:s.sist});
async function endreKurv(fn){
 if(ikkeNaa())return;
 start0();const s=salg;const for0=bilde(s);s.sist=null;fn();stopPoll();await regn(s);
 if(s===salg&&s.feil&&s.regnet===null&&!tomKurv(s)){const f=s.feil;Object.assign(s,for0);await regn(s);if(!s.feil)melding(f);}
 tegnKurv();
 if(valgt==='keramikk'||valgt==='leire')tegnVenstre();
}
function leggTilNivaa(n){endreKurv(()=>{if(salg.person&&salg.person.pop){const k=n.id+'|';const p=salg.pop.get(k)||{nivaaId:n.id,gjenstand:'',antall:0};p.antall++;salg.pop.set(k,p);salg.sist='booking:'+salg.bookingId+':'+k;}else{salg.popUten.set(n.id,(salg.popUten.get(n.id)||0)+1);salg.popGjester=Math.max(1,salg.popGjester);salg.sist='pop:'+n.id;}});}
function endreAntall(l,d){const nokkel=l.nokkel;endreKurv(()=>{
 const [slag,id,k]=nokkel.split(':');const tom=()=>salg.priser.delete(nokkel);
 if(d>0)salg.sist=nokkel;
 // En pris som gjelder linjesummen (enhetene har ulik pris), gjelder ikke lenger når antallet endres.
 if(!l.perStk)tom();
 if(slag==='vare'){const v=data.varer.find(x=>x.id===+id);const n=(salg.varer.get(+id)||0)+d;if(d>0&&v&&v.lager!==null&&n>v.lager){melding('Ikke flere på lager.');return;}if(n>0)salg.varer.set(+id,n);else{salg.varer.delete(+id);tom();}}
 else if(slag==='pop'){const n=(salg.popUten.get(+id)||0)+d;if(n>0)salg.popUten.set(+id,n);else{salg.popUten.delete(+id);tom();if(!salg.popUten.size)salg.popGjester=0;}}
 else if(slag==='booking'&&k!==undefined){const p=salg.pop.get(k);if(p){p.antall=Math.max(0,p.antall+d);if(!p.antall)tom();}}
});}
async function visSalg(){
 stopPoll();
 if(salg)await regn(salg);
 tegnKurv();
}

// ── Kurven (alltid synlig til høyre) ─────────────────────────────────
// Eierens regler (08.10.2026): endret pris og rabatt kan ikke brukes på samme kjøp, og prosent bare når ingenting er betalt.
const RABATT_GITT='Rabatt er gitt – fjern den for å endre pris.';
const PRIS_ENDRET='Prisen er endret – rabatt kan ikke gis i tillegg.';
const PROSENT_BETALT='Prosent kan bare gis når hele beløpet betales nå.';
const linjeNokkel=(d,l)=>l.nokkel||(l.fri!==undefined?'fri:'+l.fri:d.type==='gavekort'?d.id:d.type==='timepakke'?'timepakke':'');
// betalt: linja hører til en påmelding som er gjort opp («Har betalt»): + og − virker, men prisen endres ikke.
function kurvLinje(d,l,betalt=false){
 const tekst=l.antall!==undefined&&!/^booking:\d+$/.test(l.nokkel||'')?utenAntall(l.tekst):l.tekst;
 const sist=!!salg.sist&&linjeNokkel(d,l)===salg.sist;
 const venstre=el('span',{class:'k-ltekst'},tekst,sist?el('span',{class:'k-lagttil',text:'LAGT TIL'}):null,l.fraOre!==undefined?el('small',{class:'k-endret',text:'Pris endret fra '+kr(l.perStk?l.fraEnhetOre:l.fraOre)}):null);
 const klasse='k-linje'+(sist?' sist':'');
 if(l.rabatt)return el('div',{class:klasse+' rabatt'},venstre,el('button',{type:'button',class:'k-prisknapp',onclick:rabattArk,text:l.kr}));
 if(l.nokkel){
  const stepper=/^booking:\d+$/.test(l.nokkel)?null:el('span',{class:'k-stepper'},el('button',{type:'button','aria-label':'Færre '+tekst,text:'−',onclick:()=>endreAntall(l,-1)}),el('span',{text:String(l.antall)}),el('button',{type:'button','aria-label':'Flere '+tekst,text:'+',onclick:()=>endreAntall(l,1)}));
  if(betalt)return el('div',{class:klasse},venstre,stepper,el('b',{text:l.kr}));
  return el('div',{class:klasse},venstre,stepper,el('button',{type:'button',class:'k-prisknapp','aria-label':'Endre prisen på '+tekst,disabled:!!salg.rabatt,title:salg.rabatt?RABATT_GITT:undefined,onclick:()=>prisArk(l,tekst),text:l.kr}));
 }
 let fjern=null;
 if(d.type==='gavekort')fjern=()=>salg.gavekort.splice(+d.id.split(':')[1],1);
 else if(d.type==='timepakke')fjern=()=>{salg.timepakke=false;};
 else if(l.fri!==undefined)fjern=()=>salg.fritt.splice(l.fri,1);
 const endre=!betalt&&l.tekst==='Betalt ved booking'&&salg.person&&salg.person.kanEndre?pille('Endre',endreDepositum):null;
 return el('div',{class:klasse},venstre,endre,fjern?el('button',{type:'button',class:'k-minus','aria-label':'Ta bort '+tekst,text:'−',onclick:()=>endreKurv(fjern)}):null,el('b',{text:l.kr}));
}
function tegnKurv(){
 if(!kurvEl)return;
 if(ferdig){tegnFerdigHoyre();return;}
 if(!salg){kurvEl.replaceChildren(el('p',{class:'k-tom',text:'Velg fra listen.'}));return;}
 const r=salg.regnet;const linjer=[];
 if(r&&r.betalt)for(const l of r.betalt.linjer)linjer.push(kurvLinje({type:'booking',id:r.betalt.id},l,true));
 if(r)for(const d of r.deler)for(const l of d.linjer)linjer.push(kurvLinje(d,l));
 const kan=!!r&&r.deler.length>0&&!salg.feil&&!laast()&&!nettNede;
 const hvem=salg.navn?salg.navn+(salg.person?' · '+salg.person.tittel:''):'Kontantkunde';
 const prisEndret=!!r&&r.prisEndret&&!salg.rabatt;
 sett(kurvEl,
  el('div',{class:'k-hvem'},el('span',{text:hvem}),pille('Avbryt',avbrytSalg)),
  el('div',{class:'k-linjer'},linjer.length?linjer:el('p',{class:'k-tom',text:'Velg fra listen.'})),
  el('button',{type:'button',class:'k-pille k-rabattknapp'+(salg.rabatt?' v':''),disabled:!r||!r.deler.length||prisEndret,onclick:rabattArk,text:'Rabatt på kjøpet'}),
  prisEndret?el('p',{class:'k-tom k-regel',text:PRIS_ENDRET}):salg.rabatt?el('p',{class:'k-tom k-regel',text:RABATT_GITT}):null,
  el('div',{class:'k-sum'},el('span',{text:'Totalt'}),el('span',{text:r?r.sum:kr(0)})),
  salg.feil?el('p',{class:'k-feil',role:'alert',text:salg.feil}):null,
  // Fire store betalingsknapper, to og to (eieren 30.10.2026). Vipps-QR og «Del betalingen» vises ikke lenger på iPaden.
  el('div',{class:'k-betal4'},
   kort({disabled:!kan,onclick:vippsNummer},el('b',{text:'Vipps-nummer'})),
   kort({disabled:!kan,onclick:()=>registrer('Kontant','kontant')},el('b',{text:'Kontant'})),
   kort({disabled:!kan,onclick:gavekortBetal},el('b',{text:'Gavekort'})),
   kort({disabled:!kan,onclick:natura},el('b',{text:'Natura'}))));
}
function avbrytSalg(){if(ikkeNaa())return;stopPoll();salg=null;tegnVenstre();tegnKurv();}
function endreDepositum(){
 if(ikkeNaa())return;const s=salg;const p=s.person;
 ark('Endre: betalte de ved booking?',lukk=>[el('p',{class:'k-tom',text:'Når du har lagt inn bookingen selv, eller den ikke ble betalt'}),el('div',{class:'k-rad-knapper'},pille('Ja, '+kr(p.vedBookingOre),lukk,'valgt'),pille('Betalte ikke',async ev=>{const k=ev.currentTarget;k.disabled=true;try{await kall('kasse.php',{handling:'betalteIkke',bookingId:s.bookingId});lukk();await hentPerson(s);if(salg===s)visSalg();}catch(e){feil(e);k.disabled=false;}},'fylt'))]);
}
function prisArk(l,tekst){
 if(ikkeNaa())return;if(salg.rabatt){melding(RABATT_GITT);return;}
 const perStk=!!l.perStk;
 const inn=el('input',{type:'text',inputmode:'decimal',autocomplete:'off'});
 const f=el('p',{class:'k-feil',role:'alert',hidden:true});
 ark(tekst,lukk=>[el('form',{class:'k-skjema',onsubmit:ev=>{ev.preventDefault();const t=inn.value.replace(/\s|kr|,-/g,'').replace(',','.');if(t===''||!isFinite(Number(t))||Number(t)<0){f.textContent='Prisen må være 0 kroner eller mer.';f.hidden=false;return;}lukk();endreKurv(()=>salg.priser.set(l.nokkel,t));}},
  el('p',{class:'k-tom',text:'Nå: '+kr(perStk?l.enhetOre:l.ore)+(perStk&&l.antall>1?' per stykk':'')}),
  el('label',{class:'k-felt'},el('span',{text:'Ny pris i kroner'+(perStk&&l.antall>1?' per stykk':'')}),inn),f,
  el('div',{class:'k-rad-knapper'},pille('Avbryt',lukk),salg.priser.has(l.nokkel)?pille('Opprinnelig pris',()=>{lukk();endreKurv(()=>salg.priser.delete(l.nokkel));}):null,el('button',{type:'submit',class:'k-pille fylt',text:'Endre pris'})))]);
 inn.focus();
}
// «Rabatt på kjøpet»: prosent eller kroner, valgfritt hvorfor. Serveren sjekker reglene og at rabatten ikke er større enn summen.
function rabattArk(){
 if(ikkeNaa())return;
 const rg=salg.regnet;if(rg&&rg.prisEndret&&!salg.rabatt){melding(PRIS_ENDRET);return;}
 const prosentKan=!rg||rg.prosentKan!==false;
 const r=salg.rabatt;let type=r?(r.prosent!==undefined?'prosent':'kr'):(prosentKan?null:'kr');
 const inn=el('input',{type:'text',inputmode:'decimal',autocomplete:'off',value:r?String(r.prosent??r.kr):''});
 const hvorfor=el('input',{type:'text',autocomplete:'off',maxlength:'191',value:r?r.hvorfor||'':''});
 const f=el('p',{class:'k-feil',role:'alert',hidden:true});
 const typeEl=el('div',{class:'k-valg-rad'});const tegnType=()=>typeEl.replaceChildren(...[['prosent','Prosent'],['kr','Kroner']].map(([v,t])=>el('button',{type:'button',class:'k-pille'+(type===v?' v':''),disabled:v==='prosent'&&!prosentKan,'aria-pressed':type===v?'true':'false',text:t,onclick:()=>{type=v;tegnType();}})));tegnType();
 ark('Rabatt på hele kjøpet',lukk=>[el('form',{class:'k-skjema',onsubmit:ev=>{ev.preventDefault();const t=inn.value.replace(/\s|kr|%|,-/g,'').replace(',','.');
   if(!type){f.textContent='Velg prosent eller kroner.';f.hidden=false;return;}
   if(!t||!isFinite(Number(t))||Number(t)<=0||(type==='prosent'&&Number(t)>100)){f.textContent=type==='prosent'?'Rabatten må være mellom 0 og 100 %.':'Skriv inn en rabatt over null.';f.hidden=false;return;}
   lukk();endreKurv(()=>{salg.rabatt=type==='prosent'?{prosent:t,hvorfor:hvorfor.value.trim()}:{kr:t,hvorfor:hvorfor.value.trim()};});}},
  typeEl,prosentKan?null:el('p',{class:'k-tom k-regel',text:PROSENT_BETALT}),el('label',{class:'k-felt'},el('span',{text:'Rabatt'}),inn),
  el('div',{class:'k-valg-rad'},[10,20,50].map(p=>el('button',{type:'button',class:'k-pille',disabled:!prosentKan,text:p+' %',onclick:()=>{type='prosent';tegnType();inn.value=String(p);}}))),
  el('label',{class:'k-felt'},el('span',{text:'Hvorfor (valgfritt)'}),hvorfor),f,
  el('div',{class:'k-rad-knapper'},pille('Avbryt',lukk),r?pille('Fjern rabatt',()=>{lukk();endreKurv(()=>{salg.rabatt=null;});}):null,el('button',{type:'submit',class:'k-pille fylt',text:'Legg til rabatt'})))]);
}

// ── Betaling ─────────────────────────────────────────────────────────
// Nøklene (én per del, med delens faste id) beholdes så lenge kurven og betaleren er de samme.
// Da kjenner serveren igjen et nytt forsøk, og registrerer aldri det samme to ganger (kontrolløren 08.10.2026).
// Alt under er knyttet til salget det gjelder (s), ikke til det som står i kassa når svaret kommer.
function forbered(s){
 const sig=JSON.stringify([kurvTilServer(s),betalerTilServer(s)]);
 if(s.noklerSig!==sig){s.nokler={};s.noklerSig=sig;}
 for(const d of s.regnet.deler)if(!s.nokler[d.id])s.nokler[d.id]=uuid();
 if(!s.betalt.size)s.forventet=Object.fromEntries(s.regnet.deler.map(d=>[d.id,d.sumOre]));
}
const body=s=>({kurv:kurvTilServer(s),betaler:betalerTilServer(s),nokler:s.nokler,forventet:s.forventet});
async function registrer(maate,tekst,ekstra={}){
 const s=salg;if(!s||!s.regnet||s.vent||nettNede)return;forbered(s);stopPoll();s.vent=true;
 kurvEl.replaceChildren(el('p',{class:'k-tom',text:'Registrerer …'}));
 try{const d=await kall('kasse.php',{handling:'betal',maate,...ekstra,...body(s)});s.vent=false;visFerdig(s,{sum:s.regnet.sum,tekst,valg:d.kvitteringValg,koder:(d.gavekort||[]).concat(s.koder),endringer:d.endringer||[],kvittering:d.kvittering});}
 catch(e){s.vent=false;if(e.stille)return;if(salg===s)tegnKurv();melding(e.message);}
}
// Vipps-nummer: kunden har betalt med den løse QR-koden på disken. Spør først om pengene er kommet inn (eieren 30.10.2026).
function vippsNummer(){
 if(!salg||!salg.regnet||nettNede)return;const sum=salg.regnet.sum;
 ark(`Har du sett at ${sum} er kommet inn på Vipps?`,lukk=>[el('div',{class:'k-rad-knapper'},pille('Avbryt',lukk),pille('Ja, registrer',()=>{lukk();registrer('Vipps','med Vipps-nummer');},'fylt'))]);
}
// Gavekort: hele beløpet trekkes fra kortet. Dekker kortet ikke alt, avviser serveren betalingen (midlertidig, eieren avgjør).
function gavekortBetal(){
 if(!salg||!salg.regnet||nettNede)return;
 const kode=el('input',{name:'kode',type:'text',autocomplete:'off',autocapitalize:'characters'});
 const f=el('p',{class:'k-feil',role:'alert',hidden:true});
 ark('Gavekort',lukk=>[el('form',{class:'k-skjema',onsubmit:ev=>{ev.preventDefault();if(!kode.value.trim()){f.textContent='Fant ikke gavekortet. Sjekk koden — den kan være brukt opp eller gått ut på dato.';f.hidden=false;return;}lukk();registrer('Gavekort','med gavekort',{kode:kode.value.trim()});}},
  el('p',{class:'k-tom',text:salg.regnet.sum}),el('label',{class:'k-felt'},el('span',{text:'Gavekortkode'}),kode),f,
  el('div',{class:'k-rad-knapper'},pille('Avbryt',lukk),el('button',{type:'submit',class:'k-pille fylt',text:'Registrer'})))]);
 kode.focus();
}
// Natura registrerer ingenting (eieren 30.10.2026, på moro).
const NATURA='Haha, din perverse fjert! Dette er notert og delt på NRK nyheter';
function natura(){melding(NATURA,5000);}

// ── Betalt-skjermen ──────────────────────────────────────────────────
// Til høyre: ✓, «Betalt [sum] [måte]», «Send kvittering» (bare når det trykkes) og den store gule «I dag».
// Til venstre: «KUNDE» og «KJØPET» med varene, inkl./uten mva, totalen og mva-en (regnet på serveren).
function visFerdig(s,{sum,tekst,valg,koder,endringer,kvittering}){
 stopPoll();
 const info={sum,tekst,valg:valg||{},koder:koder||[],endringer:endringer||[],kvittering:kvittering||null,betaler:betalerTilServer(s),kunde:s.kontantkunde||!s.navn?'Kontant':s.navn,sendt:false,skjema:false};
 if(salg===s){salg=null;ferdig=info;tegnVenstre();tegnKurv();}
 else melding(`Betalt ${sum} ${tekst}`);
 oppdaterListe();
}
function tegnFerdigHoyre(){
 const f=ferdig;const v=f.valg;
 const kvittering=()=>{
  if(!v.smsMulig||f.sendt)return null;
  if(!f.skjema)return el('button',{type:'button',class:'k-pille k-sendkv',text:'Send kvittering',onclick:()=>{f.skjema=true;tegnFerdigHoyre();}});
  const tlf=el('input',{name:'telefon',type:'tel',inputmode:'tel',autocomplete:'off',value:v.telefon||''});
  const knapp=el('button',{type:'submit',class:'k-pille fylt',text:'Send'});
  setTimeout(()=>tlf.focus(),0);
  return el('form',{class:'k-skjema k-kvskjema',onsubmit:async ev=>{ev.preventDefault();knapp.disabled=true;try{const d=await kall('kasse.php',{handling:'kvittering',kanal:'sms',telefon:tlf.value,betalinger:v.betalinger||[],betaler:f.betaler});melding(d.beskjed);f.sendt=true;f.skjema=false;if(ferdig===f)tegnFerdigHoyre();}catch(e){feil(e);knapp.disabled=false;}}},
   el('label',{class:'k-felt'},el('span',{text:'Telefon'}),tlf),el('div',{class:'k-rad-knapper'},pille('Avbryt',()=>{f.skjema=false;tegnFerdigHoyre();}),knapp));
 };
 kurvEl.replaceChildren(el('div',{class:'k-ferdig'},el('div',{class:'k-hake','aria-hidden':'true',text:'✓'}),el('div',{class:'k-betalt-tekst',text:`Betalt ${f.sum} ${f.tekst}`}),
  f.koder.map(k=>el('p',{style:'font-size:20px;margin:0'},'Gavekort '+k.belop+': ',el('b',{text:k.kode}))),
  f.endringer.map(t=>el('p',{class:'k-tom',text:t})),
  kvittering()),
  el('button',{type:'button',class:'k-stor k-idag',text:'I dag',onclick:()=>{ferdig=null;tegnVenstre();tegnKurv();}}));
}
function tegnFerdigVenstre(){
 const f=ferdig;const k=f.kvittering;vareGrid=null;
 venstreEl.replaceChildren(
  el('div',{class:'k-flis'},el('h3',{text:'KUNDE'}),el('div',{class:'k-flis-navn',text:f.kunde})),
  k?el('div',{class:'k-flis'},el('h3',{text:'KJØPET'}),
   el('div',{class:'k-kjop'},k.linjer.map(l=>el('div',{class:'k-kjop-linje'},el('span',{class:'k-ltekst'},l.navn,l.mva?el('small',{text:l.mva}):null),el('span',{class:'k-kjop-antall',text:l.vare?String(l.antall):''}),el('b',{text:l.kr})))),
   el('div',{class:'k-kjop-sum'},el('span',{text:'Totalt'}),el('span',{},el('b',{text:k.total}),k.mvaOre>0?el('small',{text:'mva '+k.mva}):null))):null);
}
function oppgjorTabell(o){
 const e=o.endringer||[];
 return el('div',{},el('table',{class:'k-tabell'},el('tbody',{},o.rader.map(r=>el('tr',{},el('td',{text:r.navn}),el('td',{text:r.kr}))),el('tr',{class:'total'},el('td',{text:'Totalt i dag'}),el('td',{text:o.total})))),
  el('div',{class:'k-rad',style:'margin-top:10px'},el('span',{},'Kontant i kassa nå',el('small',{text:'Telles ved stenging'})),el('b',{text:o.kontant})),
  e.length?el('h3',{text:'Endret pris og rabatt'}):null,
  e.map(x=>el('div',{class:'k-rad'},el('span',{},`${x.kl} · ${x.kunde||'Kontantkunde'}`,el('small',{text:[x.fra+' → '+x.til,...x.endringer,x.hvorfor,x.hvem].filter(Boolean).join(' · ')})),el('b',{text:'−'+kr(x.trukketOre)}))));
}
// «Dagens salg» (eieren 30.10.2026): klokkeslett, kunde, beløp, måte og «Angre». Angrede salg står igjen, med hvem og når.
function dagensSalg(o,tegnPaaNytt){
 return el('div',{class:'k-salg'},(o.salg||[]).map(x=>el('div',{class:'k-rad k-salgrad'+(x.angret?' angret':'')},
  el('span',{},`${x.kl} · ${x.kunde||'Kontantkunde'}`,el('small',{text:x.angret?['Angret',x.angret.kl,x.angret.hvem].filter(Boolean).join(' · '):x.maate})),
  el('b',{text:x.sum}),
  x.kanAngre?pille('Angre',()=>angreSalg(x,tegnPaaNytt)):x.angret?el('span',{class:'k-merke angret',text:'Angret'}):null)));
}
function angreSalg(x,tegnPaaNytt){
 ark(`Angre salget på ${x.sum}?`,lukk=>[el('div',{class:'k-rad-knapper'},pille('Avbryt',lukk),pille('Ja, angre',async ev=>{const k=ev.currentTarget;k.disabled=true;try{const d=await kall('kasse.php',{handling:'angre',salgId:x.id});lukk();tegnPaaNytt(d.oppgjor);oppdaterListe();}catch(e){feil(e);k.disabled=false;}},'fylt'))]);
}
async function visOppgjor(){
 let o;try{o=await kall('kasse.php',{handling:'oppgjor'});}catch(e){feil(e);return;}
 const innhold=el('div',{class:'k-oppgjor'});
 const tegn=o2=>{sett(innhold,el('div',{},oppgjorTabell(o2)),el('div',{},el('h3',{text:'Dagens salg'}),dagensSalg(o2,tegn)));};
 tegn(o);
 ark('Dagens oppgjør',lukk=>[innhold,el('div',{class:'k-rad-knapper'},pille('Lukk',lukk,'fylt'))],'bred');
}
// Menyen ⋯ øverst til høyre: Lås («Dagens oppgjør» er en egen knapp i toppen).
function meny(){ark(person?person.navn:'Lissom Kasse',lukk=>[el('div',{class:'k-g2'},kort({onclick:()=>{lukk();laas();}},el('b',{text:'Lås'}))),el('div',{class:'k-rad-knapper'},pille('Lukk',lukk,'fylt'))]);}

start();

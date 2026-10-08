// Lissom Kasse på iPad (eieren «ok, bygg det» 08.10.2026, skissen Rm1Casf4CpVv8i5zwyfecX).
// Egen innlogging (rollen «kasse»), PIN per person, låser seg etter 5 minutter uten bruk.
// All regning skjer på serveren (api/kasse/kasse.php, app/lib/kasse.php): skjermen sender hva som er valgt
// og beløpet den viste, og ingenting registreres hvis det ikke stemmer.
const rot=document.getElementById('kasse');
const meldinger=document.getElementById('meldinger');
let data=null;      // «I dag» fra serveren
let person=null;    // den som står i kassa
let salg=null;      // kunden som betaler nå
let laasMs=5*60*1000;
let laasTimer=null;
let pollTimer=null;

function el(tag,attrs={},...barn){const n=document.createElement(tag);for(const [k,v] of Object.entries(attrs)){if(v===undefined||v===null||v===false)continue;if(k==='text')n.textContent=v;else if(k.startsWith('on'))n.addEventListener(k.slice(2),v);else n.setAttribute(k,v===true?'':String(v));}for(const c of barn.flat(Infinity)){if(c===null||c===undefined||c===false)continue;n.append(c instanceof Node?c:document.createTextNode(String(c)));}return n;}
const kr=o=>(o<0?'−':'')+String(Math.round(Math.abs(o)/100)).replace(/\B(?=(\d{3})+(?!\d))/g,' ')+' kr';
const uuid=()=>{if(crypto.randomUUID)return crypto.randomUUID();const b=crypto.getRandomValues(new Uint8Array(16));b[6]=(b[6]&15)|64;b[8]=(b[8]&63)|128;const h=[...b].map(x=>x.toString(16).padStart(2,'0')).join('');return `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`;};
function melding(tekst){if(!tekst)return;const p=el('p',{text:tekst});meldinger.append(p);setTimeout(()=>p.remove(),4500);}
const pille=(tekst,fn,klasse='')=>el('button',{type:'button',class:'k-pille '+klasse,text:tekst,onclick:fn});
const stopPoll=()=>{if(pollTimer){clearTimeout(pollTimer);pollTimer=null;}};

async function kall(fil,body){
 const r=await fetch('/api/kasse/'+fil,{credentials:'same-origin',cache:'no-store',...(body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{})});
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
async function laas(){clearTimeout(laasTimer);stopPoll();stoppListe();document.querySelectorAll('.k-ark').forEach(a=>a.remove());person=null;salg=null;try{await kall('pin.php',{handling:'laas'});}catch(e){}visPin();}

function topp(tittel,...hoyre){return el('header',{class:'k-topp'},el('span',{class:'k-tittel',text:tittel}),el('div',{class:'k-valg'},...hoyre));}
// «Dagens oppgjør» og «Lås» ligger i menyen ⋯ (eieren 08.10.2026).
const personValg=()=>person?[el('span',{text:person.navn}),el('button',{type:'button',class:'k-pille k-meny','aria-label':'Meny',text:'⋯',onclick:meny})]:[];

// ── Start ────────────────────────────────────────────────────────────
async function start(){
 try{const s=await kall('pin.php');laasMs=(s.laasMinutter||5)*60*1000;person=s.person;if(person){aktiv();await visIdag();}else visPin();}
 catch(e){if(e.stille)return;if(e.status===404){visInnlogging('Denne kontoen har ikke tilgang til kassa.');return;}visBeskjed(e.message);}
}
function visBeskjed(tekst){rot.replaceChildren(topp('Lissom Kasse'),el('div',{class:'k-midt'},el('h1',{text:'Lissom Kasse'}),el('p',{text:tekst})));}

function visInnlogging(feilTekst=''){
 person=null;clearTimeout(laasTimer);
 const bruker=el('input',{name:'brukernavn',autocomplete:'username',autocapitalize:'none',required:true});
 const passord=el('input',{name:'passord',type:'password',autocomplete:'current-password',required:true});
 const f=el('p',{class:'k-feil',role:'alert',text:feilTekst,hidden:!feilTekst});
 const knapp=el('button',{class:'k-stor',type:'submit',text:'Logg inn',style:'max-width:360px'});
 rot.replaceChildren(topp('Lissom Kasse',el('small',{text:'iPad i verkstedet'})),el('form',{class:'k-midt',onsubmit:async ev=>{ev.preventDefault();knapp.disabled=true;f.hidden=true;try{const r=await fetch('/api/logg-inn.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({brukernavn:bruker.value,passord:passord.value})});const d=await r.json().catch(()=>({}));if(!r.ok||d.ok===false)throw Error(d.feil||'Feil brukernavn eller passord.');if(!d.erKasse&&!d.erAdmin)throw Error('Denne kontoen har ikke tilgang til kassa.');await start();}catch(e){f.textContent=e.message;f.hidden=false;}finally{knapp.disabled=false;}}},
  el('h1',{text:'Lissom Kasse'}),el('label',{class:'k-felt'},el('span',{text:'Brukernavn'}),bruker),el('label',{class:'k-felt'},el('span',{text:'Passord'}),passord),f,knapp));
}

// ── PIN ──────────────────────────────────────────────────────────────
function visPin(){
 person=null;salg=null;kursVist=null;clearTimeout(laasTimer);stopPoll();stoppListe();document.querySelectorAll('.k-ark').forEach(a=>a.remove());
 let pin='';let opptatt=false;
 const prikker=el('div',{class:'k-prikker','aria-hidden':'true'});
 const f=el('p',{class:'k-feil',role:'alert',hidden:true});
 const tegn=()=>prikker.replaceChildren(...[0,1,2,3].map(i=>el('i',{class:i<pin.length?'':'t'})));
 const tast=async s=>{if(opptatt)return;if(s==='⌫'){pin=pin.slice(0,-1);tegn();return;}if(pin.length>=4)return;pin+=s;tegn();f.hidden=true;
  if(pin.length===4){opptatt=true;try{const r=await kall('pin.php',{handling:'pin',pin});person=r.person;aktiv();await visIdag();}catch(e){if(e.stille)return;pin='';tegn();f.textContent=e.message;f.hidden=false;}finally{opptatt=false;}}};
 tegn();
 rot.replaceChildren(topp('Lissom Kasse',el('small',{text:'iPad i verkstedet'})),el('div',{class:'k-midt'},el('h1',{text:'Hvem står i kassa?'}),prikker,f,
  el('div',{class:'k-pin'},[1,2,3,4,5,6,7,8,9,'',0,'⌫'].map(x=>x===''?el('span'):el('button',{type:'button',text:String(x),'aria-label':x==='⌫'?'Slett':String(x),onclick:()=>tast(String(x))})))));
}

// ── I dag (oppsett A, eieren 08.10.2026): valgene til venstre, kurven fast til høyre ──
// Venstre: Kontantkunde/+ Ny kunde, Dagens kurs (fire i bredden, etter klokkeslett), Paint on Pots-prislisten og Annet salg.
// Trykk på et kurs: flaten byttes ut med dem som ikke har betalt. Bare venstre flate ruller; lista hentes på nytt hvert 15. sekund.
let venstreEl=null,kurvEl=null,kursVist=null,listeTimer=null,opptatt=false;
const stoppListe=()=>{if(listeTimer){clearTimeout(listeTimer);listeTimer=null;}};
const kort=(a,...barn)=>el('button',{type:'button',class:'k-k'+(a.class?' '+a.class:'')+(a.valgt?' valgt':''),disabled:a.disabled,'aria-pressed':a.valgt?'true':undefined,onclick:a.onclick},...barn);
const utenAntall=t=>String(t).replace(/ × \d+$/,'');
const sett=(n,...barn)=>n.replaceChildren(...barn.flat().filter(x=>x!==null&&x!==undefined&&x!==false));
async function visIdag(){
 stopPoll();stoppListe();
 try{data=await kall('kasse.php');}catch(e){if(!e.stille)visBeskjed(e.message);return;}
 person=data.person;laasMs=(data.laasMinutter||5)*60*1000;
 venstreEl=el('div',{class:'k-sone'});kurvEl=el('section',{class:'k-kurvsone','aria-label':'Kurven'});
 rot.replaceChildren(topp('Lissom Kasse · '+data.dato,...personValg()),el('div',{class:'k-a'},venstreEl,kurvEl));
 if(kursVist){try{kursVist=await kall('kasse.php?okt='+kursVist.oktId);}catch(e){kursVist=null;}}
 tegnVenstre();await visSalg();lytt();
}
function lytt(){stoppListe();listeTimer=setTimeout(async()=>{listeTimer=null;if(!person||!venstreEl||!venstreEl.isConnected)return;try{if(kursVist)kursVist=await kall('kasse.php?okt='+kursVist.oktId);else data=await kall('kasse.php');tegnVenstre();}catch(e){if(e.stille)return;if(e.status===404){kursVist=null;tegnVenstre();}}lytt();},15000);}
async function oppdaterListe(){try{if(kursVist)kursVist=await kall('kasse.php?okt='+kursVist.oktId);data=await kall('kasse.php');}catch(e){if(e.stille)return;if(e.status===404)kursVist=null;}tegnVenstre();}

function prisliste(){
 if(!data.nivaer.length)return[];
 return[el('h3',{text:'Paint on Pots · prislisten'}),el('div',{class:'k-g4'},data.nivaer.map(n=>kort({class:'pris',onclick:()=>leggTilNivaa(n)},el('b',{text:n.navn}),el('small',{text:n.pris}))))]; // Bare kategoriene på prislisten (eieren 08.10.2026)
}
function tegnVenstre(){
 if(!venstreEl)return;const y=venstreEl.scrollTop;const v=[];
 if(kursVist){const k=kursVist;
  v.push(el('div',{class:'k-sone-topp'},pille('Tilbake',()=>{kursVist=null;tegnVenstre();venstreEl.scrollTop=0;}),el('h3',{text:`${k.tittel} ${k.kl} · ${k.skalBetale} skal betale`})));
  v.push(k.rader.length?el('div',{class:'k-g4'},k.rader.map(r=>kort({class:'kurs',valgt:!!salg&&salg.bookingId===r.bookingId,onclick:()=>nyttSalg({bookingId:r.bookingId,navn:r.navn,oktId:k.oktId})},el('b',{text:r.navn}),el('small',{text:r.pop?`${r.antall} ${r.antall===1?'person':'personer'}`:r.info}),r.pop&&r.depositumOre?el('span',{class:'k-merke dep',text:r.depositum+' depositum'}):el('span',{class:'k-merke skylder',text:r.pille.tekst})))):el('p',{class:'k-tom',text:'Alle har betalt.'}));
  if(k.pop)v.push(...prisliste());
 }else{
  v.push(el('div',{class:'k-g2'},kort({class:'kunde',valgt:!!salg&&salg.kontantkunde,onclick:kontantkunde},el('b',{text:'Kontantkunde'}),el('small',{text:'Salg uten navn'})),kort({class:'kunde',onclick:nyKundeArk},el('b',{text:'+ Ny kunde'}),el('small',{text:'Navn og telefon'}))));
  v.push(el('h3',{text:'Dagens kurs'}));
  v.push(data.kurs.length?el('div',{class:'k-g4'},data.kurs.map(k=>kort({class:'kurs',valgt:!!salg&&salg.oktId===k.oktId,onclick:()=>apneKurs(k)},el('small',{text:k.kl}),el('b',{text:k.tittel}),el('span',{class:'k-merke skylder',text:k.skalBetale+' skal betale'})))):el('p',{class:'k-tom',text:'Ingen påmeldte i dag.'}));
  v.push(...prisliste());
  v.push(el('h3',{text:'Annet salg'}),el('div',{class:'k-g3'},kort({onclick:vareArk},el('b',{text:'Varer'}),el('small',{text:'Butikken'})),kort({onclick:()=>belopArk('Gavekort',b=>endreKurv(()=>salg.gavekort.push(b)))},el('b',{text:'Gavekort'}),el('small',{text:'Velg beløp'})),kort({onclick:skrivBelopArk},el('b',{text:'Skriv beløp'}),el('small',{text:'Timepakke og annet'}))));
 }
 venstreEl.replaceChildren(...v);venstreEl.scrollTop=y;
}
async function apneKurs(k){kursVist={...k,rader:[]};tegnVenstre();venstreEl.scrollTop=0;try{kursVist=await kall('kasse.php?okt='+k.oktId);}catch(e){feil(e);kursVist=null;}tegnVenstre();lytt();}

function ark(tittel,innhold){const lukk=()=>a.remove();const a=el('div',{class:'k-ark',onclick:ev=>{if(ev.target===a)lukk();}},el('div',{},el('h2',{text:tittel}),...innhold(lukk)));document.body.append(a);return a;}
function belopArk(tittel,ok,ekstra){
 const inn=el('input',{type:'text',inputmode:'decimal',autocomplete:'off'});
 const f=el('p',{class:'k-feil',role:'alert',hidden:true});
 ark(tittel,lukk=>[ekstra?ekstra(lukk):null,el('form',{class:'k-skjema',onsubmit:ev=>{ev.preventDefault();const t=inn.value.replace(/\s|kr|,-/g,'').replace(',','.');if(!t||!isFinite(Number(t))||Number(t)<=0){f.textContent='Skriv inn et beløp over null.';f.hidden=false;return;}lukk();ok(t);}},
  el('label',{class:'k-felt'},el('span',{text:'Beløp i kroner'}),inn),f,el('div',{class:'k-rad-knapper'},pille('Avbryt',lukk),el('button',{type:'submit',class:'k-pille fylt',text:'Legg til'})))]);
 inn.focus();
}
// Timepakke ligger under «Skriv beløp» som et fast valg (eieren 08.10.2026).
function skrivBelopArk(){belopArk('Skriv beløp',b=>endreKurv(()=>salg.fritt.push(b)),lukk=>el('div',{class:'k-g2'},kort({onclick:()=>{lukk();endreKurv(()=>{salg.timepakke=true;});}},el('b',{text:'Timepakke'}),el('small',{text:data.timepakke.timer+' timer · '+data.timepakke.pris}))));}
function vareArk(){
 ark('Varer',lukk=>[el('div',{class:'k-g3 k-ark-rull'},data.varer.map(p=>kort({disabled:p.utsolgt,onclick:()=>{endreKurv(()=>{const n=salg.varer.get(p.id)||0;if(p.lager!==null&&n>=p.lager){melding('Ikke flere på lager.');return;}salg.varer.set(p.id,n+1);});melding(p.tittel);}},el('b',{text:p.tittel}),el('small',{text:p.pris})))),el('div',{class:'k-rad-knapper'},pille('Lukk',lukk,'fylt'))]);
}
function nyKundeArk(){
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
function tomtSalg(){return{betaler:null,navn:null,bookingId:null,oktId:null,person:null,pop:new Map(),popUten:new Map(),popGjester:0,varer:new Map(),fritt:[],gavekort:[],timepakke:false,priser:new Map(),rabatt:null,regnet:null,feil:'',kontantkunde:false,nokler:{},noklerSig:null,forventet:null,betalt:new Set(),betalinger:[],koder:[]};}
function start0(){if(!salg){salg=tomtSalg();salg.kontantkunde=true;tegnVenstre();}}
function kontantkunde(){if(salg&&salg.betalt.size){melding('Gjør ferdig betalingen først.');return;}const g=salg;salg=tomtSalg();salg.kontantkunde=true;if(g)beholdVarer(g);tegnVenstre();visSalg();}
// Varene som alt er i kurven, blir med når kunden velges etterpå.
function beholdVarer(g){salg.varer=g.varer;salg.fritt=g.fritt;salg.gavekort=g.gavekort;salg.popUten=g.popUten;salg.popGjester=g.popGjester;for(const [k,v] of g.priser)if(!k.startsWith('booking:'))salg.priser.set(k,v);}
async function nyttSalg(fra){
 if(salg&&salg.betalt.size){melding('Gjør ferdig betalingen først.');return;}
 const g=salg;salg=tomtSalg();if(g)beholdVarer(g);salg.navn=fra.navn;salg.oktId=fra.oktId||null;
 if(fra.betaler){salg.betaler=fra.betaler;tegnVenstre();await visSalg();return;}
 salg.betaler={bookingId:fra.bookingId};salg.bookingId=fra.bookingId;
 try{await hentPerson();}catch(e){feil(e);salg=null;}
 tegnVenstre();await visSalg();
}
async function hentPerson(){
 const p=await kall('kasse.php',{handling:'person',bookingId:salg.bookingId});salg.person=p;salg.navn=p.navn;
 if(p.pop){salg.pop=new Map();for(const l of p.lagret||[])salg.pop.set(l.nokkel,{nivaaId:l.nivaaId,gjenstand:l.gjenstand,antall:l.antall});if(salg.popUten.size){for(const [id,n] of salg.popUten){const k=id+'|';const q=salg.pop.get(k)||{nivaaId:id,gjenstand:'',antall:0};q.antall+=n;salg.pop.set(k,q);}salg.popUten=new Map();salg.popGjester=0;}}
}
const kurvTilServer=()=>({bookingId:salg.bookingId||undefined,pop:[...salg.pop.values()].filter(p=>p.antall>0).map(p=>({nivaaId:p.nivaaId,gjenstand:p.gjenstand,antall:p.antall})),popGjester:salg.popUten.size?salg.popGjester:undefined,popUten:[...salg.popUten].map(([nivaaId,antall])=>({nivaaId,antall})),varer:[...salg.varer].map(([id,antall])=>({id,antall})),fritt:salg.fritt,gavekort:salg.gavekort,timepakke:salg.timepakke||undefined,priser:salg.priser.size?Object.fromEntries(salg.priser):undefined,rabatt:salg.rabatt||undefined});
const betalerTilServer=()=>salg.kontantkunde||!salg.betaler?{}:salg.betaler;
const tomKurv=()=>!salg.bookingId&&!salg.popUten.size&&!salg.varer.size&&!salg.fritt.length&&!salg.gavekort.length&&!salg.timepakke;

let regnNr=0;
async function regn(){
 const nr=++regnNr;
 if(tomKurv()){salg.regnet=null;salg.feil='';return;}
 try{const d=await kall('kasse.php',{handling:'regn',kurv:kurvTilServer(),betaler:betalerTilServer()});if(nr!==regnNr)return;salg.regnet=d;salg.feil='';}
 catch(e){if(nr!==regnNr||e.stille)return;salg.regnet=null;salg.feil=e.message;}
}
// Alt som endrer kurven går hit. Er en del alt betalt med Vipps, gjøres ingenting før betalingen er ferdig.
// Sier serveren nei til endringen (for eksempel timepakke uten medlem, rabatt over summen), går kurven tilbake og feilen vises.
const bilde=s=>({pop:new Map([...s.pop].map(([k,v])=>[k,{...v}])),popUten:new Map(s.popUten),popGjester:s.popGjester,varer:new Map(s.varer),fritt:[...s.fritt],gavekort:[...s.gavekort],timepakke:s.timepakke,priser:new Map(s.priser),rabatt:s.rabatt});
async function endreKurv(fn){
 if(salg&&salg.betalt.size){melding('Gjør ferdig betalingen først.');return;}
 start0();const s=salg;const for0=bilde(s);fn();stopPoll();await regn();
 if(s===salg&&s.feil&&s.regnet===null&&!tomKurv()){const f=s.feil;Object.assign(s,for0);await regn();if(!s.feil)melding(f);}
 tegnKurv();
}
function leggTilNivaa(n){endreKurv(()=>{if(salg.person&&salg.person.pop){const k=n.id+'|';const p=salg.pop.get(k)||{nivaaId:n.id,gjenstand:'',antall:0};p.antall++;salg.pop.set(k,p);}else{salg.popUten.set(n.id,(salg.popUten.get(n.id)||0)+1);salg.popGjester=Math.max(1,salg.popGjester);}});}
function endreAntall(nokkel,d){endreKurv(()=>{
 const [slag,id,k]=nokkel.split(':');const tom=()=>salg.priser.delete(nokkel);
 if(slag==='vare'){const v=data.varer.find(x=>x.id===+id);const n=(salg.varer.get(+id)||0)+d;if(d>0&&v&&v.lager!==null&&n>v.lager){melding('Ikke flere på lager.');return;}if(n>0)salg.varer.set(+id,n);else{salg.varer.delete(+id);tom();}}
 else if(slag==='pop'){const n=(salg.popUten.get(+id)||0)+d;if(n>0)salg.popUten.set(+id,n);else{salg.popUten.delete(+id);tom();if(!salg.popUten.size)salg.popGjester=0;}}
 else if(slag==='booking'&&k!==undefined){const p=salg.pop.get(k);if(p){p.antall=Math.max(0,p.antall+d);if(!p.antall)tom();}}
});}
async function visSalg(){
 stopPoll();
 if(salg)await regn();
 tegnKurv();
}

// ── Kurven (alltid synlig til høyre) ─────────────────────────────────
function kurvLinje(d,l,fri){
 const tekst=l.antall!==undefined&&!/^booking:\d+$/.test(l.nokkel||'')?utenAntall(l.tekst):l.tekst;
 const venstre=el('span',{class:'k-ltekst'},tekst,l.fraOre!==undefined?el('small',{class:'k-endret',text:'Pris endret fra '+kr(l.fraEnhetOre??l.fraOre)}):null);
 if(l.rabatt)return el('div',{class:'k-linje rabatt'},venstre,el('button',{type:'button',class:'k-prisknapp',onclick:rabattArk,text:l.kr}));
 if(l.nokkel){
  const stepper=/^booking:\d+$/.test(l.nokkel)?null:el('span',{class:'k-stepper'},el('button',{type:'button','aria-label':'Færre '+tekst,text:'−',onclick:()=>endreAntall(l.nokkel,-1)}),el('span',{text:String(l.antall)}),el('button',{type:'button','aria-label':'Flere '+tekst,text:'+',onclick:()=>endreAntall(l.nokkel,1)}));
  return el('div',{class:'k-linje'},venstre,stepper,el('button',{type:'button',class:'k-prisknapp','aria-label':'Endre prisen på '+tekst,onclick:()=>prisArk(l,tekst),text:l.kr}));
 }
 let fjern=null;
 if(d.type==='gavekort')fjern=()=>salg.gavekort.splice(+d.id.split(':')[1],1);
 else if(d.type==='timepakke')fjern=()=>{salg.timepakke=false;};
 else if(d.id==='ordre')fjern=()=>salg.fritt.splice(fri,1);
 const endre=l.tekst==='Betalt ved booking'&&salg.person&&salg.person.kanEndre?pille('Endre',endreDepositum):null;
 return el('div',{class:'k-linje'},venstre,endre,fjern?el('button',{type:'button',class:'k-minus','aria-label':'Ta bort '+tekst,text:'−',onclick:()=>endreKurv(fjern)}):null,el('b',{text:l.kr}));
}
function tegnKurv(){
 if(!kurvEl)return;
 if(!salg){kurvEl.replaceChildren(el('p',{class:'k-tom',text:'Velg fra listen.'}));return;}
 const r=salg.regnet;const linjer=[];
 if(r)for(const d of r.deler){let fri=0;for(const l of d.linjer)linjer.push(kurvLinje(d,l,l.nokkel?0:(d.id==='ordre'?fri++:0)));}
 const kan=!!r&&r.deler.length>0&&!salg.feil&&!opptatt;
 const hvem=salg.navn?salg.navn+(salg.person?' · '+salg.person.tittel:''):'Kontantkunde';
 sett(kurvEl,
  el('div',{class:'k-hvem'},el('span',{text:hvem}),pille('Avbryt',avbrytSalg)),
  el('div',{class:'k-linjer'},linjer.length?linjer:el('p',{class:'k-tom',text:'Velg fra listen.'})),
  el('button',{type:'button',class:'k-pille k-rabattknapp'+(salg.rabatt?' v':''),disabled:!r||!r.deler.length,onclick:rabattArk,text:'Rabatt på kjøpet'}),
  el('div',{class:'k-sum'},el('span',{text:'Totalt'}),el('span',{text:r?r.sum:kr(0)})),
  salg.feil?el('p',{class:'k-feil',role:'alert',text:salg.feil}):null,
  el('div',{class:'k-betal2'},
   kort({disabled:!kan,onclick:()=>qrStart()},el('b',{text:'Vipps'}),el('small',{text:'QR-kode'})),
   kort({disabled:!kan,onclick:()=>registrer('Kontant','kontant')},el('b',{text:'Kontant'}))),
  el('button',{type:'button',class:'k-pille k-flere',disabled:!kan,onclick:flereValg,text:'Flere valg'}));
}
function avbrytSalg(){if(salg&&salg.betalt.size){melding('Gjør ferdig betalingen først.');return;}stopPoll();salg=null;tegnVenstre();tegnKurv();}
function endreDepositum(){
 const p=salg.person;
 ark('Endre: betalte de ved booking?',lukk=>[el('p',{class:'k-tom',text:'Når du har lagt inn bookingen selv, eller den ikke ble betalt'}),el('div',{class:'k-rad-knapper'},pille('Ja, '+kr(p.vedBookingOre),lukk,'valgt'),pille('Betalte ikke',async ev=>{const k=ev.currentTarget;k.disabled=true;try{await kall('kasse.php',{handling:'betalteIkke',bookingId:salg.bookingId});lukk();await hentPerson();visSalg();}catch(e){feil(e);k.disabled=false;}},'fylt'))]);
}
function prisArk(l,tekst){
 const perStk=!/^booking:\d+$/.test(l.nokkel);
 const inn=el('input',{type:'text',inputmode:'decimal',autocomplete:'off'});
 const f=el('p',{class:'k-feil',role:'alert',hidden:true});
 ark(tekst,lukk=>[el('form',{class:'k-skjema',onsubmit:ev=>{ev.preventDefault();const t=inn.value.replace(/\s|kr|,-/g,'').replace(',','.');if(t===''||!isFinite(Number(t))||Number(t)<0){f.textContent='Prisen må være 0 kroner eller mer.';f.hidden=false;return;}lukk();endreKurv(()=>salg.priser.set(l.nokkel,t));}},
  el('p',{class:'k-tom',text:'Nå: '+kr(perStk?l.enhetOre:l.ore)+(perStk&&l.antall>1?' per stykk':'')}),
  el('label',{class:'k-felt'},el('span',{text:'Ny pris i kroner'+(perStk&&l.antall>1?' per stykk':'')}),inn),f,
  el('div',{class:'k-rad-knapper'},pille('Avbryt',lukk),salg.priser.has(l.nokkel)?pille('Opprinnelig pris',()=>{lukk();endreKurv(()=>salg.priser.delete(l.nokkel));}):null,el('button',{type:'submit',class:'k-pille fylt',text:'Endre pris'})))]);
 inn.focus();
}
// «Rabatt på kjøpet»: prosent eller kroner, valgfritt hvorfor. Serveren sjekker at rabatten ikke er større enn summen.
function rabattArk(){
 const r=salg.rabatt;let type=r?(r.prosent!==undefined?'prosent':'kr'):null;
 const inn=el('input',{type:'text',inputmode:'decimal',autocomplete:'off',value:r?String(r.prosent??r.kr):''});
 const hvorfor=el('input',{type:'text',autocomplete:'off',maxlength:'191',value:r?r.hvorfor||'':''});
 const f=el('p',{class:'k-feil',role:'alert',hidden:true});
 const typeEl=el('div',{class:'k-valg-rad'});const tegnType=()=>typeEl.replaceChildren(...[['prosent','Prosent'],['kr','Kroner']].map(([v,t])=>el('button',{type:'button',class:'k-pille'+(type===v?' v':''),'aria-pressed':type===v?'true':'false',text:t,onclick:()=>{type=v;tegnType();}})));tegnType();
 ark('Rabatt på hele kjøpet',lukk=>[el('form',{class:'k-skjema',onsubmit:ev=>{ev.preventDefault();const t=inn.value.replace(/\s|kr|%|,-/g,'').replace(',','.');
   if(!type){f.textContent='Velg prosent eller kroner.';f.hidden=false;return;}
   if(!t||!isFinite(Number(t))||Number(t)<=0||(type==='prosent'&&Number(t)>100)){f.textContent=type==='prosent'?'Rabatten må være mellom 0 og 100 %.':'Skriv inn en rabatt over null.';f.hidden=false;return;}
   lukk();endreKurv(()=>{salg.rabatt=type==='prosent'?{prosent:t,hvorfor:hvorfor.value.trim()}:{kr:t,hvorfor:hvorfor.value.trim()};});}},
  typeEl,el('label',{class:'k-felt'},el('span',{text:'Rabatt'}),inn),
  el('div',{class:'k-valg-rad'},[10,20,50].map(p=>el('button',{type:'button',class:'k-pille',text:p+' %',onclick:()=>{type='prosent';tegnType();inn.value=String(p);}}))),
  el('label',{class:'k-felt'},el('span',{text:'Hvorfor (valgfritt)'}),hvorfor),f,
  el('div',{class:'k-rad-knapper'},pille('Avbryt',lukk),r?pille('Fjern rabatt',()=>{lukk();endreKurv(()=>{salg.rabatt=null;});}):null,el('button',{type:'submit',class:'k-pille fylt',text:'Legg til rabatt'})))]);
}

// ── Betaling ─────────────────────────────────────────────────────────
// Nøklene (én per del, med delens faste id) beholdes så lenge kurven og betaleren er de samme — også etter «Tilbake».
// Da kjenner serveren igjen en QR-kode som venter, og stopper den før kontanten tas (kontrolløren 08.10.2026).
function forbered(){
 const sig=JSON.stringify([kurvTilServer(),betalerTilServer()]);
 if(salg.noklerSig!==sig){salg.nokler={};salg.noklerSig=sig;}
 for(const d of salg.regnet.deler)if(!salg.nokler[d.id])salg.nokler[d.id]=uuid();
 if(!salg.betalt.size)salg.forventet=Object.fromEntries(salg.regnet.deler.map(d=>[d.id,d.sumOre]));
}
const body=()=>({kurv:kurvTilServer(),betaler:betalerTilServer(),nokler:salg.nokler,forventet:salg.forventet});
async function registrer(maate,tekst,deler){
 if(opptatt||!salg||!salg.regnet)return;forbered();stopPoll();opptatt=true;
 kurvEl.replaceChildren(el('p',{class:'k-tom',text:'Registrerer …'}));
 try{const d=await kall('kasse.php',deler?{handling:'delt',deler,...body()}:{handling:'betal',maate,...body()});opptatt=false;visFerdig({sum:salg.regnet.sum,tekst,valg:d.kvitteringValg,koder:(d.gavekort||[]).concat(salg.koder),endringer:d.endringer||[]});}
 catch(e){opptatt=false;if(e.stille)return;tegnKurv();melding(e.message);}
}
function flereValg(){
 ark('Flere valg',lukk=>[el('div',{class:'k-g2'},
  kort({onclick:()=>{lukk();deltArk();}},el('b',{text:'Del betalingen'}),el('small',{text:'To eller flere betaler'})),
  kort({onclick:()=>{lukk();registrer('Faktura','med faktura');}},el('b',{text:'Faktura'})),
  kort({onclick:()=>{lukk();registrer('Vipps','med Vipps-nummer');}},el('b',{text:'Vipps-nummer'}),el('small',{text:'Betalt på annen måte'}))),
  el('div',{class:'k-rad-knapper'},pille('Lukk',lukk,'fylt'))]);
}
function deltArk(){
 const f=(navn)=>el('input',{name:navn,type:'text',inputmode:navn!=='kode'?'decimal':undefined,autocomplete:'off'});
 const k=f('kontant'),v=f('vipps'),g=f('gavekort'),kode=f('kode');
 ark('Del betalingen',lukk=>[el('form',{class:'k-skjema',onsubmit:ev=>{ev.preventDefault();const deler=[['Kontant',k.value],['Vipps',v.value],['Gavekort',g.value]].filter(([,b])=>b.trim()!==''&&Number(b.replace(',','.'))>0).map(([maate,belop])=>({maate,belop,...(maate==='Gavekort'?{kode:kode.value}:{})}));
   if(deler.length<2){melding('Fyll inn minst to deler. Bruk vanlig betalingsregistrering for én betalingsmåte.');return;}lukk();registrer('','med delt betaling',deler);}},
  el('p',{class:'k-tom',text:salg.regnet?salg.regnet.sum:''}),
  el('label',{class:'k-felt'},el('span',{text:'Kontant i kroner'}),k),el('label',{class:'k-felt'},el('span',{text:'Mottatt Vipps i kroner'}),v),el('label',{class:'k-felt'},el('span',{text:'Gavekort i kroner'}),g),el('label',{class:'k-felt'},el('span',{text:'Gavekortkode'}),kode),
  el('div',{class:'k-rad-knapper'},pille('Avbryt',lukk),el('button',{type:'submit',class:'k-pille fylt',text:'Registrer'})))]);
}
// Vipps-QR i kurven: én QR per del, neste når den forrige er betalt.
function qrStart(){if(!salg||!salg.regnet)return;forbered();const deler=salg.regnet.deler.map(d=>d.id);qr(Math.max(0,deler.findIndex(id=>!salg.betalt.has(id))),deler);}
async function qr(i,deler){
 stopPoll();const id=deler[i];const n=deler.length;
 const omraade=el('div',{class:'k-qrsone'},el('p',{text:'Henter QR-kode …'}));
 sett(kurvEl,el('div',{class:'k-hvem'},el('span',{text:salg.navn||'Kontantkunde'})),el('div',{class:'k-sum'},el('span',{text:'Totalt'}),el('span',{text:salg.regnet.sum})),omraade,salg.betalt.size?null:pille('Tilbake',()=>{stopPoll();tegnKurv();}));
 let d;
 try{d=await kall('kasse.php',{handling:'qr',del:id,...body()});}
 catch(e){if(e.stille)return;if(e.status===410)salg.nokler[id]=uuid();omraade.replaceChildren(el('p',{class:'k-feil',role:'alert',text:e.message}));return;}
 if(d.betalt){if(d.betalingId)salg.betalinger.push(d.betalingId);await delBetalt(i,deler);return;}
 const status=el('p',{style:'font-weight:700',text:'Venter på Vipps …'});
 omraade.replaceChildren(...[n>1?el('p',{class:'k-tom',text:`Vipps ${i+1} av ${n} · ${d.belop}`}):null,el('img',{class:'k-qr',src:d.qr,alt:'QR-kode for betaling med Vipps'}),status].filter(Boolean));
 let runder=0;
 const sjekk=async()=>{pollTimer=null;if(!omraade.isConnected)return;try{const s=await kall('kasse.php',{handling:'status',poll:d.poll});if(s.betalt){if(s.kode)salg.koder.push({kode:s.kode,belop:d.belop});if(s.betalingId)salg.betalinger.push(s.betalingId);await delBetalt(i,deler);return;}if(['avbrutt','feilet'].includes(s.status)){salg.nokler[id]=uuid();status.textContent='Betalingen ble avbrutt i Vipps. Trykk «Vipps» for en ny QR-kode.';return;}}catch(e){if(e.stille)return;status.textContent=e.message;}
  if(++runder<220)pollTimer=setTimeout(sjekk,3000);else status.textContent='Ingen bekreftelse ennå. Sjekk Penger i admin før du tar betalt på nytt.';};
 pollTimer=setTimeout(sjekk,3000);
}
async function delBetalt(i,deler){
 salg.betalt.add(deler[i]);
 const neste=deler.findIndex(id=>!salg.betalt.has(id));
 if(neste>=0){await qr(neste,deler);return;}
 let valg={epost:false,sms:false,betalinger:salg.betalinger};
 try{valg=await kall('kasse.php',{handling:'kvitteringValg',betaler:betalerTilServer(),betalinger:salg.betalinger});}catch(e){}
 visFerdig({sum:salg.regnet.sum,tekst:'med Vipps',valg,koder:salg.koder,endringer:[]});
}

// ── Ferdig ───────────────────────────────────────────────────────────
function visFerdig({sum,tekst,valg,koder,endringer}){
 stopPoll();
 const betalinger=(valg&&valg.betalinger)||[];const betaler=salg?betalerTilServer():{};
 const kvittering=async(kanal,knapp)=>{knapp.disabled=true;try{const d=await kall('kasse.php',{handling:'kvittering',kanal,betalinger,betaler});melding(d.beskjed);}catch(e){feil(e);knapp.disabled=false;}};
 const knapper=[];
 if(valg&&valg.sms)knapper.push(kort({onclick:ev=>kvittering('sms',ev.currentTarget)},el('b',{text:'Kvittering på SMS'})));
 if(valg&&valg.epost)knapper.push(kort({onclick:ev=>kvittering('epost',ev.currentTarget)},el('b',{text:'Kvittering på e-post'})));
 kurvEl.replaceChildren(el('div',{class:'k-ferdig'},el('div',{class:'k-hake','aria-hidden':'true',text:'✓'}),el('div',{style:'font-size:24px;font-weight:800',text:`Betalt ${sum} ${tekst}`}),
  (koder||[]).map(k=>el('p',{style:'font-size:20px;margin:0'},'Gavekort '+k.belop+': ',el('b',{text:k.kode}))),
  (endringer||[]).map(t=>el('p',{class:'k-tom',text:t})),
  knapper.length?el('div',{class:'k-g2'},knapper):null,
  el('button',{type:'button',class:'k-stor',text:'I dag',onclick:()=>{tegnKurv();}})));
 salg=null;oppdaterListe();
}
function oppgjorTabell(o){
 const e=o.endringer||[];
 return el('div',{},el('table',{class:'k-tabell'},el('tbody',{},o.rader.map(r=>el('tr',{},el('td',{text:r.navn}),el('td',{text:r.kr}))),el('tr',{class:'total'},el('td',{text:'Totalt i dag'}),el('td',{text:o.total})))),
  el('div',{class:'k-rad',style:'margin-top:10px'},el('span',{},'Kontant i kassa nå',el('small',{text:'Telles ved stenging'})),el('b',{text:o.kontant})),
  e.length?el('h3',{text:'Endret pris og rabatt'}):null,
  e.map(x=>el('div',{class:'k-rad'},el('span',{},`${x.kl} · ${x.kunde||'Kontantkunde'}`,el('small',{text:[x.fra+' → '+x.til,...x.endringer,x.hvorfor,x.hvem].filter(Boolean).join(' · ')})),el('b',{text:'−'+kr(x.trukketOre)}))));
}
async function visOppgjor(){
 try{const o=await kall('kasse.php',{handling:'oppgjor'});ark('Dagens oppgjør',lukk=>[oppgjorTabell(o),el('div',{class:'k-rad-knapper'},pille('Lukk',lukk,'fylt'))]);}catch(e){feil(e);}
}
// Menyen ⋯ øverst til høyre: Dagens oppgjør og Lås.
function meny(){ark(person?person.navn:'Lissom Kasse',lukk=>[el('div',{class:'k-g2'},kort({onclick:()=>{lukk();visOppgjor();}},el('b',{text:'Dagens oppgjør'})),kort({onclick:()=>{lukk();laas();}},el('b',{text:'Lås'}))),el('div',{class:'k-rad-knapper'},pille('Lukk',lukk,'fylt'))]);}

start();

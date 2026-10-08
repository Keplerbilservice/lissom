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
async function laas(){clearTimeout(laasTimer);stopPoll();person=null;salg=null;try{await kall('pin.php',{handling:'laas'});}catch(e){}visPin();}

function topp(tittel,...hoyre){return el('header',{class:'k-topp'},el('span',{class:'k-tittel',text:tittel}),el('div',{class:'k-valg'},...hoyre));}
const personValg=()=>person?[el('span',{text:person.navn}),pille('Dagens oppgjør',visOppgjor),pille('Lås',laas,'fylt')]:[];

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
 person=null;salg=null;clearTimeout(laasTimer);stopPoll();
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

// ── I dag ────────────────────────────────────────────────────────────
async function visIdag(){
 stopPoll();salg=null;
 try{data=await kall('kasse.php');}catch(e){if(!e.stille)visBeskjed(e.message);return;}
 person=data.person;laasMs=(data.laasMinutter||5)*60*1000;
 const venstre=[];
 for(const g of data.grupper){venstre.push(el('h3',{text:g.tittel}));for(const r of g.rader)venstre.push(el('button',{type:'button',class:'k-rad',onclick:()=>nyttSalg({bookingId:r.bookingId,navn:r.navn})},el('span',{},r.navn,el('small',{text:r.info})),el('span',{class:'k-merke '+r.pille.tone,text:r.pille.tekst})));}
 if(data.inne.length){venstre.push(el('h3',{text:'Medlemmer inne'}));for(const m of data.inne)venstre.push(el('button',{type:'button',class:'k-rad',onclick:()=>nyttSalg({medlemId:m.medlemId,navn:m.navn})},el('span',{},m.navn,el('small',{text:m.info})),el('span',{class:'k-merke inne',text:'Inne'})));}
 if(!venstre.length)venstre.push(el('p',{class:'k-tom',text:'Ingen påmeldte i dag.'}));
 rot.replaceChildren(topp('Lissom Kasse · '+data.dato,...personValg()),el('div',{class:'k-innhold'},el('div',{class:'k-kol'},venstre),el('div',{class:'k-kol'},el('h3',{text:'Selg'}),fliser(true))));
}

// Varer med fast pris, gavekort, timepakke og «Skriv beløp». Trykk legger i kurven (og starter et salg uten navn fra «I dag»).
function fliser(medPop){
 const v=[];
 if(medPop&&data.nivaer.length)v.push(el('button',{type:'button',class:'k-vare bred',onclick:()=>{start0();salg.popGjester=Math.max(1,salg.popGjester);visSalg();}},'🎨 Paint on Pots uten booking',el('small',{text:'Gjester som bare kommer innom'})));
 for(const p of data.varer)v.push(el('button',{type:'button',class:'k-vare',disabled:p.utsolgt,onclick:()=>{start0();const n=salg.varer.get(p.id)||0;if(p.lager!==null&&n>=p.lager){melding('Ikke flere på lager.');return;}salg.varer.set(p.id,n+1);visSalg();}},p.tittel,el('small',{text:p.pris})));
 v.push(el('button',{type:'button',class:'k-vare',onclick:()=>belopArk('Gavekort',b=>{start0();salg.gavekort.push(b);visSalg();})},'Gavekort',el('small',{text:'Velg beløp'})));
 v.push(el('button',{type:'button',class:'k-vare',onclick:()=>{start0();salg.timepakke=true;visSalg();}},'Timepakke',el('small',{text:data.timepakke.timer+' timer'})));
 v.push(el('button',{type:'button',class:'k-vare',onclick:()=>belopArk('Annet',b=>{start0();salg.fritt.push(b);visSalg();})},'Annet',el('small',{text:'Skriv beløp'})));
 return el('div',{class:'k-varer'},v);
}

function belopArk(tittel,ok){
 const inn=el('input',{type:'text',inputmode:'decimal',autocomplete:'off'});
 const f=el('p',{class:'k-feil',role:'alert',hidden:true});
 const lukk=()=>ark.remove();
 const ark=el('div',{class:'k-ark',onclick:ev=>{if(ev.target===ark)lukk();}},el('form',{onsubmit:ev=>{ev.preventDefault();const t=inn.value.replace(/\s|kr|,-/g,'').replace(',','.');if(!t||!isFinite(Number(t))||Number(t)<=0){f.textContent='Skriv inn et beløp over null.';f.hidden=false;return;}lukk();ok(t);}},
  el('div',{},el('h2',{text:tittel}),el('label',{class:'k-felt'},el('span',{text:'Beløp i kroner'}),inn),f,el('div',{class:'k-rad-knapper'},pille('Avbryt',lukk),el('button',{type:'submit',class:'k-pille fylt',text:'Legg til'})))));
 document.body.append(ark);inn.focus();
}

// ── Salget ───────────────────────────────────────────────────────────
function tomtSalg(){return{betaler:null,navn:null,bookingId:null,person:null,pop:new Map(),popUten:new Map(),popGjester:0,varer:new Map(),fritt:[],gavekort:[],timepakke:false,regnet:null,feil:'',endre:false,kontantkunde:false,nokler:{},noklerSig:null,forventet:null,betalt:new Set(),betalinger:[],koder:[]};}
function start0(){if(!salg)salg=tomtSalg();}
async function nyttSalg(fra){
 salg=tomtSalg();salg.navn=fra.navn;
 if(fra.medlemId){salg.betaler={medlemId:fra.medlemId};visSalg();return;}
 salg.betaler={bookingId:fra.bookingId};salg.bookingId=fra.bookingId;
 try{await hentPerson();}catch(e){feil(e);salg=null;return;}
 visSalg();
}
async function hentPerson(){
 const p=await kall('kasse.php',{handling:'person',bookingId:salg.bookingId});salg.person=p;salg.navn=p.navn;
 if(p.pop){salg.pop=new Map();for(const l of p.lagret||[]){const n=data.nivaer.find(x=>x.id===l.nivaaId);salg.pop.set(l.nokkel,{nivaaId:l.nivaaId,gjenstand:l.gjenstand,antall:l.antall,navn:l.nivaa,prisOre:n?n.prisOre:0});}}
}
const kurvTilServer=()=>({bookingId:salg.bookingId||undefined,pop:[...salg.pop.values()].filter(p=>p.antall>0).map(p=>({nivaaId:p.nivaaId,gjenstand:p.gjenstand,antall:p.antall})),popGjester:salg.popUten.size?salg.popGjester:undefined,popUten:[...salg.popUten].map(([nivaaId,antall])=>({nivaaId,antall})),varer:[...salg.varer].map(([id,antall])=>({id,antall})),fritt:salg.fritt,gavekort:salg.gavekort,timepakke:salg.timepakke||undefined});
const betalerTilServer=()=>salg.kontantkunde||!salg.betaler?{}:salg.betaler;
const tomKurv=()=>!salg.bookingId&&!salg.popUten.size&&!salg.varer.size&&!salg.fritt.length&&!salg.gavekort.length&&!salg.timepakke;

let regnNr=0;
async function regn(){
 const nr=++regnNr;
 if(tomKurv()){salg.regnet=null;salg.feil='';return;}
 try{const d=await kall('kasse.php',{handling:'regn',kurv:kurvTilServer(),betaler:betalerTilServer()});if(nr!==regnNr)return;salg.regnet=d;salg.feil='';}
 catch(e){if(nr!==regnNr||e.stille)return;salg.regnet=null;salg.feil=e.message;}
}

function leggTilNivaa(n){
 if(salg.person&&salg.person.pop){const k=n.id+'|';const p=salg.pop.get(k)||{nivaaId:n.id,gjenstand:'',antall:0,navn:n.navn,prisOre:n.prisOre};p.antall++;salg.pop.set(k,p);}
 else salg.popUten.set(n.id,(salg.popUten.get(n.id)||0)+1);
 visSalg();
}

async function visSalg(){
 stopPoll();
 await regn();
 if(!salg)return;
 const pop=salg.person&&salg.person.pop;const popUten=!pop&&salg.popGjester>0;
 const linjer=[];
 const linje=(tekst,ore,minus)=>el('div',{class:'k-linje'},minus?el('button',{type:'button',class:'k-minus','aria-label':'Ta bort '+tekst,text:'−',onclick:()=>{minus();visSalg();}}):null,el('span',{text:tekst}),el('b',{text:kr(ore)}));
 const dBooking=salg.regnet?.deler.find(d=>d.type==='booking');
 if(salg.bookingId&&!pop&&dBooking)for(const l of dBooking.linjer)linjer.push(linje(l.tekst,l.ore));
 if(pop){for(const [k,p] of salg.pop)if(p.antall>0)linjer.push(linje(p.navn+(p.gjenstand?' · '+p.gjenstand:'')+' × '+p.antall,p.antall*p.prisOre,()=>{p.antall--;}));
  const b=dBooking?.linjer.find(l=>l.tekst==='Betalt ved booking');if(b)linjer.push(linje(b.tekst,b.ore));}
 for(const [id,n] of salg.popUten){const nv=data.nivaer.find(x=>x.id===id);if(nv)linjer.push(linje('Paint on Pots · '+nv.navn+' × '+n,n*nv.prisOre,()=>{n>1?salg.popUten.set(id,n-1):salg.popUten.delete(id);}));}
 for(const [id,n] of salg.varer){const v=data.varer.find(x=>x.id===id);if(v)linjer.push(linje(v.tittel+(n>1?' × '+n:''),n*v.prisOre,()=>{n>1?salg.varer.set(id,n-1):salg.varer.delete(id);}));}
 salg.fritt.forEach((b,i)=>linjer.push(linje('Annet',Math.round(Number(b)*100),()=>{salg.fritt.splice(i,1);})));
 salg.gavekort.forEach((b,i)=>linjer.push(linje('Gavekort',Math.round(Number(b)*100),()=>{salg.gavekort.splice(i,1);})));
 if(salg.timepakke)linjer.push(linje('Timepakke · '+data.timepakke.timer+' timer',data.timepakke.prisOre,()=>{salg.timepakke=false;}));

 const venstre=[];
 if(pop){const p=salg.person;venstre.push(el('h3',{text:`${p.navn} · ${p.antall} ${p.antall===1?'person':'personer'}`}));
  venstre.push(el('div',{class:'k-rad'},el('span',{},'Betalt ved booking',p.perPersonOre&&p.vedBookingOre===p.depositumOre?el('small',{text:`${kr(p.perPersonOre)} × ${p.antall} ${p.antall===1?'person':'personer'}`}):null),el('span',{style:'display:flex;gap:8px;align-items:center'},el('b',{text:kr(-p.vedBookingOre)}),p.kanEndre?pille('Endre',()=>{salg.endre=!salg.endre;visSalg();}):null)));
  if(salg.endre&&p.kanEndre)venstre.push(el('div',{class:'k-rad v'},el('span',{},'Endre: betalte de ved booking?',el('small',{text:'Når du har lagt inn bookingen selv, eller den ikke ble betalt'})),el('span',{style:'display:flex;gap:6px;flex-wrap:wrap'},pille('Ja, '+kr(p.vedBookingOre),()=>{salg.endre=false;visSalg();},'valgt'),pille('Betalte ikke',async ev=>{const k=ev.currentTarget;k.disabled=true;try{await kall('kasse.php',{handling:'betalteIkke',bookingId:salg.bookingId});salg.endre=false;await hentPerson();visSalg();}catch(e){feil(e);k.disabled=false;}},'fylt'))));
 }else if(popUten){venstre.push(el('h3',{text:'Paint on Pots uten booking'}),el('div',{class:'k-stepper'},el('button',{type:'button','aria-label':'Færre personer',text:'−',onclick:()=>{if(salg.popGjester>1){salg.popGjester--;visSalg();}}}),el('span',{text:salg.popGjester+' '+(salg.popGjester===1?'person':'personer')}),el('button',{type:'button','aria-label':'Flere personer',text:'+',onclick:()=>{if(salg.popGjester<50){salg.popGjester++;visSalg();}}})));}
 else venstre.push(el('h3',{text:salg.navn||'Denne kunden'}));
 if(salg.bookingId&&!pop&&!dBooking&&!salg.feil)venstre.push(el('p',{class:'k-tom',text:'Betalt'}));
 const sum=salg.regnet?salg.regnet.sum:kr(0);
 const kanBetale=!!salg.regnet&&salg.regnet.deler.length>0&&!salg.feil;
 venstre.push(el('div',{class:'k-kurv'},linjer.length?linjer:el('p',{class:'k-tom',text:'Velg fra listen.'}),el('div',{class:'k-sum'},el('span',{text:'Å betale'}),el('span',{text:sum})),salg.feil?el('p',{class:'k-feil',role:'alert',text:salg.feil}):null,
  el('button',{type:'button',class:'k-stor',disabled:!kanBetale,text:'Ta betalt '+sum,onclick:visBetaling}),el('button',{type:'button',class:'k-stor rolig',text:'Avbryt',onclick:visIdag})));

 const hoyre=[];
 if(pop||popUten){hoyre.push(el('h3',{text:'Trykk på nivået for hver gjenstand'}),el('div',{class:'k-varer to'},data.nivaer.map(n=>el('button',{type:'button',class:'k-vare',onclick:()=>leggTilNivaa(n)},n.navn+' · '+n.pris,el('small',{text:n.gjenstander})))));} // Bare kategoriene på prislisten (eieren 08.10.2026)
 else hoyre.push(el('h3',{text:'Selg'}),fliser(!salg.bookingId));
 const tittel='Lissom Kasse · '+(salg.navn||'Kontantkunde')+(pop||popUten?' · Paint on Pots':'');
 rot.replaceChildren(topp(tittel,...personValg()),el('div',{class:'k-innhold'},el('div',{class:'k-kol'},venstre),el('div',{class:'k-kol'},hoyre)));
}

// ── Betaling ─────────────────────────────────────────────────────────
// Nøklene (én per del, med delens faste id) beholdes så lenge kurven og betaleren er de samme — også etter «Tilbake».
// Da kjenner serveren igjen en QR-kode som venter, og stopper den før kontanten tas (kontrolløren 08.10.2026).
function visBetaling(){
 if(!salg.regnet)return;
 const sig=JSON.stringify([kurvTilServer(),betalerTilServer()]);
 if(salg.noklerSig!==sig){salg.nokler={};salg.noklerSig=sig;}
 for(const d of salg.regnet.deler)if(!salg.nokler[d.id])salg.nokler[d.id]=uuid();
 if(!salg.betalt.size)salg.forventet=Object.fromEntries(salg.regnet.deler.map(d=>[d.id,d.sumOre]));
 let valgt=null;let opptatt=false;
 const deler=salg.regnet.deler.map(d=>d.id);const n=deler.length;
 const omraade=el('div',{class:'k-midt',style:'padding:0;flex:0'});
 const body=()=>({kurv:kurvTilServer(),betaler:betalerTilServer(),nokler:salg.nokler,forventet:salg.forventet});
 const ferdigMed=(tekst,svar)=>visFerdig({sum:salg.regnet.sum,tekst,valg:svar.kvitteringValg,koder:(svar.gavekort||[]).concat(salg.koder)});
 const registrer=async(maate,tekst)=>{if(opptatt)return;opptatt=true;omraade.replaceChildren(el('p',{text:'Registrerer …'}));try{const d=await kall('kasse.php',{handling:'betal',maate,...body()});ferdigMed(tekst,d);}catch(e){if(e.stille)return;omraade.replaceChildren(el('p',{class:'k-feil',role:'alert',text:e.message}));}finally{opptatt=false;}};
 const maate=(navn,liten,fn,id)=>el('button',{type:'button',class:'k-maate'+(valgt===id?' v':''),onclick:fn},navn,el('small',{text:liten}));

 async function qr(i){
  stopPoll();valgt='vipps';tegn();
  const id=deler[i];
  omraade.replaceChildren(el('p',{text:'Henter QR-kode …'}));
  let d;
  try{d=await kall('kasse.php',{handling:'qr',del:id,...body()});}
  catch(e){if(e.stille)return;if(e.status===410)salg.nokler[id]=uuid();omraade.replaceChildren(el('p',{class:'k-feil',role:'alert',text:e.message}));return;}
  if(d.betalt){if(d.betalingId)salg.betalinger.push(d.betalingId);await delBetalt(i);return;}
  const status=el('p',{style:'font-weight:700',text:'Venter på Vipps …'});
  omraade.replaceChildren(...[n>1?el('p',{class:'k-tom',text:`Vipps ${i+1} av ${n} · ${d.belop}`}):null,el('img',{class:'k-qr',src:d.qr,alt:'QR-kode for betaling med Vipps'}),status].filter(Boolean));
  let runder=0;
  const sjekk=async()=>{pollTimer=null;if(valgt!=='vipps')return;try{const s=await kall('kasse.php',{handling:'status',poll:d.poll});if(s.betalt){if(s.kode)salg.koder.push({kode:s.kode,belop:d.belop});if(s.betalingId)salg.betalinger.push(s.betalingId);await delBetalt(i);return;}if(['avbrutt','feilet'].includes(s.status)){salg.nokler[id]=uuid();status.textContent='Betalingen ble avbrutt i Vipps. Trykk «Vipps» for en ny QR-kode.';return;}}catch(e){if(e.stille)return;status.textContent=e.message;}
   if(++runder<220)pollTimer=setTimeout(sjekk,3000);else status.textContent='Ingen bekreftelse ennå. Sjekk Penger i admin før du tar betalt på nytt.';};
  pollTimer=setTimeout(sjekk,3000);
 }
 async function delBetalt(i){
  salg.betalt.add(deler[i]);
  const neste=deler.findIndex(id=>!salg.betalt.has(id));
  if(neste>=0){await qr(neste);return;}
  let valg={epost:false,sms:false,betalinger:salg.betalinger};
  try{valg=await kall('kasse.php',{handling:'kvitteringValg',betaler:betalerTilServer(),betalinger:salg.betalinger});}catch(e){}
  visFerdig({sum:salg.regnet.sum,tekst:'med Vipps',valg,koder:salg.koder});
 }

 function tegn(){
  const betalerPiller=[];
  if(salg.navn)betalerPiller.push(el('button',{type:'button',class:'k-pille'+(!salg.kontantkunde?' valgt':''),text:salg.navn,disabled:salg.betalt.size>0,onclick:async()=>{if(!salg.kontantkunde)return;salg.kontantkunde=false;await regn();if(salg.feil){melding(salg.feil);}visBetaling();}}));
  betalerPiller.push(el('button',{type:'button',class:'k-pille'+(salg.kontantkunde||!salg.navn?' valgt':''),text:'👤 Kontantkunde',disabled:salg.betalt.size>0,onclick:async()=>{if(salg.kontantkunde||!salg.navn)return;salg.kontantkunde=true;await regn();if(salg.feil){melding(salg.feil);salg.kontantkunde=false;await regn();}visBetaling();}}));
  const under=[];
  if(valgt==='annen')under.push(el('div',{style:'display:flex;gap:10px;flex-wrap:wrap;justify-content:center'},pille('Vipps-nummer',()=>registrer('Vipps','med Vipps-nummer'),'fylt'),pille('Faktura',()=>registrer('Faktura','med faktura'),'fylt')));
  if(valgt==='delt'){
   const f=(navn,type='text')=>el('input',{name:navn,type,inputmode:type==='text'&&navn!=='kode'?'decimal':undefined,autocomplete:'off'});
   const k=f('kontant'),v=f('vipps'),g=f('gavekort'),kode=f('kode');
   under.push(el('form',{class:'k-midt',style:'padding:0',onsubmit:async ev=>{ev.preventDefault();if(opptatt)return;const deler=[['Kontant',k.value],['Vipps',v.value],['Gavekort',g.value]].filter(([,b])=>b.trim()!==''&&Number(b.replace(',','.'))>0).map(([maate,belop])=>({maate,belop,...(maate==='Gavekort'?{kode:kode.value}:{})}));
     if(deler.length<2){melding('Fyll inn minst to deler. Bruk vanlig betalingsregistrering for én betalingsmåte.');return;}
     opptatt=true;try{const d=await kall('kasse.php',{handling:'delt',deler,...body()});ferdigMed('med delt betaling',d);}catch(e){feil(e);}finally{opptatt=false;}}},
    el('label',{class:'k-felt'},el('span',{text:'Kontant i kroner'}),k),el('label',{class:'k-felt'},el('span',{text:'Mottatt Vipps i kroner'}),v),el('label',{class:'k-felt'},el('span',{text:'Gavekort i kroner'}),g),el('label',{class:'k-felt'},el('span',{text:'Gavekortkode'}),kode),el('button',{type:'submit',class:'k-stor',style:'max-width:360px',text:'Registrer'})));
  }
  rot.replaceChildren(topp('Lissom Kasse · '+salg.regnet.sum,...personValg()),el('div',{class:'k-midt'},
   el('div',{style:'display:flex;gap:8px;flex-wrap:wrap;justify-content:center'},betalerPiller),
   el('div',{class:'k-maater'},
    maate('Vipps','Kunden skanner QR',()=>{if(salg.betalt.size>0&&valgt==='vipps')return;qr(Math.max(0,deler.findIndex(id=>!salg.betalt.has(id))));},'vipps'),
    maate('Kontant','Registreres med en gang',()=>{if(salg.betalt.size>0)return;valgt='kontant';tegn();registrer('Kontant','kontant');},'kontant'),
    maate('Del betalingen','To eller flere betaler',()=>{if(salg.betalt.size>0)return;stopPoll();valgt='delt';tegn();},'delt'),
    maate('Betalt på annen måte','Vipps-nummer, faktura',()=>{if(salg.betalt.size>0)return;stopPoll();valgt='annen';tegn();},'annen')),
   under,omraade,
   salg.betalt.size?null:pille('Tilbake',()=>{stopPoll();visSalg();})));
 }
 tegn();
}

// ── Ferdig ───────────────────────────────────────────────────────────
async function visFerdig({sum,tekst,valg,koder}){
 stopPoll();
 const betalinger=(valg&&valg.betalinger)||[];const betaler=salg?betalerTilServer():{};
 const kvittering=async(kanal,knapp)=>{knapp.disabled=true;try{const d=await kall('kasse.php',{handling:'kvittering',kanal,betalinger,betaler});melding(d.beskjed);}catch(e){feil(e);knapp.disabled=false;}};
 const knapper=[];
 if(valg&&valg.sms)knapper.push(el('button',{type:'button',class:'k-maate',text:'Kvittering på SMS',onclick:ev=>kvittering('sms',ev.currentTarget)}));
 if(valg&&valg.epost)knapper.push(el('button',{type:'button',class:'k-maate',text:'Kvittering på e-post',onclick:ev=>kvittering('epost',ev.currentTarget)}));
 const oppgjor=el('div',{},el('p',{class:'k-tom',text:'Henter …'}));
 rot.replaceChildren(topp('Lissom Kasse',...personValg()),el('div',{class:'k-innhold'},
  el('div',{class:'k-kol k-midt'},el('div',{class:'k-hake','aria-hidden':'true',text:'✓'}),el('div',{style:'font-size:26px;font-weight:800',text:`Betalt ${sum} ${tekst}`}),
   (koder||[]).map(k=>el('p',{style:'font-size:20px;margin:0'},'Gavekort '+k.belop+': ',el('b',{text:k.kode}))),
   knapper.length?el('div',{class:'k-maater'},knapper):null,
   el('button',{type:'button',class:'k-stor',style:'max-width:360px',text:'I dag',onclick:visIdag})),
  el('div',{class:'k-kol'},el('h3',{text:'Dagens oppgjør'}),oppgjor)));
 salg=null;
 try{oppgjor.replaceChildren(oppgjorTabell(await kall('kasse.php',{handling:'oppgjor'})));}catch(e){feil(e);}
}
function oppgjorTabell(o){
 return el('div',{},el('table',{class:'k-tabell'},el('tbody',{},o.rader.map(r=>el('tr',{},el('td',{text:r.navn}),el('td',{text:r.kr}))),el('tr',{class:'total'},el('td',{text:'Totalt i dag'}),el('td',{text:o.total})))),
  el('div',{class:'k-rad',style:'margin-top:10px'},el('span',{},'Kontant i kassa nå',el('small',{text:'Telles ved stenging'})),el('b',{text:o.kontant})));
}
async function visOppgjor(){
 try{const o=await kall('kasse.php',{handling:'oppgjor'});const lukk=()=>ark.remove();const ark=el('div',{class:'k-ark',onclick:ev=>{if(ev.target===ark)lukk();}},el('div',{},el('h2',{text:'Dagens oppgjør'}),oppgjorTabell(o),el('div',{class:'k-rad-knapper'},pille('Lukk',lukk,'fylt'))));document.body.append(ark);}catch(e){feil(e);}
}

start();

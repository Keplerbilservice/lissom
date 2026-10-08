// «Start kurset» i tre steg (kalenderplanen, bølge 2; skissen godkjent 3. oktober 2026): Velkommen · Praktisk · Etter kurset.
// Åpnes fra økt-arket (gul knapp i arkhodet og Kursdagen-fanen) når bryteren «Vis/kursstart3» står på. Av = kursstarten som før.
// Tekstene er de fem kortene under «Rediger kursstart» (api/admin/kursstart.php). Endepunktene:
// Deltakerne, QR-koden og e-poststatusen: kursstart3.php (KursstartKrav). Kontant = kursbetaling.php handling=registrer, som før.
// Avkrysningene i Praktisk lagres ikke: de lever bare i nettleseren mens siden er åpen.
// Eieren, 7. oktober 2026 (skissen MWs11co3…): nytt første steg «Deltakerne» med «Møtt», e-post og mobil rett i lista
// (pamelding.php handling=kontakt), «+ Legg til deltaker» og betaling som i Velkommen. «Møtt» = status som ikke er
// «ikke_mott» (pamelding.php handling=status); kursbevis går bare til betalte som har møtt. Etter kurset: «Kursbevis sendes til X av Y».
import {el,api,button,badge,sheet,confirm,toast} from './ui.js';

const STEG=['Deltakerne','Velkommen','Praktisk','Etter kurset'];
const SISTE=STEG.length-1;
const sagtFor=new Map();
// Statusen før «ikke møtt», så et feiltrykk på «Møtt» setter den tilbake slik den var.
const forMott=new Map();
const gyldigEpost=t=>/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(t||'').trim());

export async function startKurs(e,o){
 const id=Number(e.oktId||e.id);
 let d,tekster;
 try{[tekster,d]=await Promise.all([api('kursstart.php'),api('kursstart3.php?okt='+id)]);}catch(err){toast(err.message);return;}
 const kort=nr=>(tekster.kort||[]).find(k=>k.nr===nr&&k.paa);
 if(!sagtFor.has(id))sagtFor.set(id,{});
 const sagt=sagtFor.get(id);
 let steg=0,endret=false,lukket=false,teller=null;
 const feil=el('p',{class:'notice error ks-feil',role:'alert',hidden:true});
 const stegRad=el('div',{class:'ks-steg',role:'group','aria-label':'Steg'});
 const innhold=el('div',{class:'ks-innhold','aria-live':'polite'});
 const fot=el('div',{class:'ks-fot'});
 const s=sheet(e.tittel,el('div',{},el('p',{class:'muted ks-naar',text:`Start kurset · ${o.naar||e.dato||''}`}),stegRad,feil,innhold,fot));
 s.dlg.classList.add('ks');
 const lukk=()=>{if(lukket)return;lukket=true;clearInterval(teller);if(endret)o.refresh?.();};
 s.dlg.addEventListener('close',lukk);
 // Feilen står øverst; på mobil kan knappen du trykket være langt nede, så den rulles fram.
 const visFeil=t=>{feil.textContent=t||'';feil.hidden=!t;if(t&&feil.scrollIntoView)feil.scrollIntoView({block:'nearest'});};

 async function hent(){try{d=await api('kursstart3.php?okt='+id);}catch(err){visFeil(err.message);}}
 const ubetalte=()=>d.deltakere.filter(p=>p.status==='Ikke betalt'&&p.skyldigOre>0);
 // Krav som venter: hent statusen på nytt med jevne mellomrom, så «Betalt» kommer av seg selv.
 function folgMed(){clearInterval(teller);if(!d.deltakere.some(p=>p.krav==='venter'||p.krav==='opprettet'))return;
  teller=setInterval(async()=>{if(lukket||steg>1)return;await hent();tegn();if(!d.deltakere.some(p=>p.krav==='venter'||p.krav==='opprettet'))clearInterval(teller);},6000);}

 // Én betaling om gangen: et ekstra trykk (dobbelttrykk på «Registrer» havnet på «Kontant» i raden under) åpner ingenting nytt
 // før den første er ferdig og lista er tegnet på nytt (brukertesten 3. oktober 2026).
 let opptatt=false;
 // «Vis QR-kode» (eieren, 3. oktober 2026): en Vipps-betaling med QR for det som står igjen, stort på skjermen. Kunden skanner.
 // Venter det alt en QR-kode, vises den samme. Mens koden vises, hentes statusen hvert tredje sekund; «Betalt» kommer av seg selv.
 async function qr(p,knapp){if(opptatt)return;opptatt=true;knapp.disabled=true;visFeil('');let r=null;
  try{r=await api('kursstart3.php',{handling:'qr',bookingId:p.bookingId});endret=true;}
  catch(err){visFeil(err.message);}
  await hent();opptatt=false;tegn();folgMed();
  if(r?.qr)visQr(p,r);}
 function visQr(p,r){
  const tilstand=el('div',{class:'ks-qr-status','aria-live':'polite'},badge('Venter på Vipps','warn'));
  const v=sheet(p.navn,el('div',{class:'ks-qr'},el('p',{class:'stat',text:r.belop}),
   el('img',{src:r.qr,alt:'QR-kode for betaling med Vipps'}),tilstand));
  let ferdig=false,t=null;
  const stopp=()=>{ferdig=true;clearTimeout(t);};
  v.dlg.addEventListener('close',stopp);
  async function sjekk(){if(ferdig||lukket||!v.dlg.isConnected)return;await hent();if(ferdig)return;tegn();
   const n=d.deltakere.find(x=>x.bookingId===p.bookingId);
   // Borte fra lista = avbestilt mens koden sto oppe (lista tar ikke med avbestilte). Ikke «Betalt».
   if(!n){stopp();tilstand.replaceChildren(el('p',{class:'muted',text:'Påmeldingen er avbestilt.'}));t=setTimeout(()=>{if(v.dlg.isConnected)v.close();},1500);return;}
   if(n.status==='Betalt'||n.skyldigOre===0){stopp();tilstand.replaceChildren(badge('Betalt','good'));t=setTimeout(()=>{if(v.dlg.isConnected)v.close();},1500);return;}
   if(n.krav!=='venter'&&n.krav!=='opprettet'){stopp();tilstand.replaceChildren(el('p',{class:'muted',text:'QR-koden er utløpt. Trykk «Vis QR-kode» på nytt.'}));return;}
   t=setTimeout(sjekk,3000);}
  t=setTimeout(sjekk,3000);}
 async function kontant(p,knapp){if(opptatt)return;opptatt=true;knapp.disabled=true;visFeil('');
  try{
   if(!await confirm('Registrer betalingen?','Registrer bare penger som faktisk er mottatt. Dette føres i regnskapet og oppdaterer påmeldingens betalingsstatus.','Registrer')){knapp.disabled=false;return;}
   try{const r=await api('kursbetaling.php',{handling:'registrer',bookingId:p.bookingId,maate:'Kontant'});endret=true;toast(r.beskjed||'Registrert.');}
   catch(err){visFeil(err.message);}
   await hent();
  }finally{opptatt=false;}
  tegn();}
 // «+ Noen kom uten påmelding»: «Legg til deltaker» fra økt-arket (pamelding.php legg-til), og lista hentes på nytt.
 function leggTil(){if(!o.leggTil)return;o.leggTil(async r=>{endret=true;toast(r?.beskjed||'Lagret.');await hent();tegn();});}

 const erBetalt=p=>p.status==='Betalt'||(p.status==='Ikke betalt'&&p.skyldigOre===0);
 // Betalingsknappene, de samme i Deltakerne og Velkommen.
 function betaling(p){
  const venter=p.krav==='venter'||p.krav==='opprettet';
  if(erBetalt(p))return badge('Betalt','good');
  if(p.status!=='Ikke betalt')return badge(p.status);
  const kontantKnapp=el('button',{type:'button',class:'button kal-liten',text:'Kontant',onclick:ev=>kontant(p,ev.currentTarget)});
  // «Send Vipps-krav» er slått av (eieren, 3. oktober 2026): bare «Vis QR-kode» og «Kontant». QR-koden trenger ikke mobilnummer.
  return el('div',{class:'kal-rad ks-betal'},
   venter?badge('Venter på Vipps','warn'):null,
   el('button',{type:'button',class:'button primary kal-liten',text:'Vis QR-kode',onclick:ev=>qr(p,ev.currentTarget)}),
   kontantKnapp);
 }

 // ── Steg 1 «Deltakerne» ─────────────────────────────────────────────
 const harEpost=p=>gyldigEpost(p.epost);
 const sum=el('div',{class:'ks-sum'});
 function tegnSum(){const n=d.deltakere.length,mott=d.deltakere.filter(p=>p.mott).length,mangler=d.deltakere.filter(p=>!harEpost(p)).length;
  sum.replaceChildren(el('span',{class:'badge',text:`${n} påmeldte`}),badge(`${mott} møtt`,'good'),mangler?badge(`${mangler} mangler e-post`,'warn'):badge('Alle har e-post','good'));}
 // «Møtt» av = status «ikke_mott»; på igjen = statusen den hadde (husket her, ellers forStatus fra serveren).
 async function settMott(p,boks){if(opptatt){boks.checked=!boks.checked;return;}opptatt=true;boks.disabled=true;visFeil('');
  const mott=boks.checked;
  if(!mott)forMott.set(p.bookingId,p.statusKode);
  const status=mott?(forMott.get(p.bookingId)||p.forStatus||'reservert'):'ikke_mott';
  try{await api('pamelding.php',{handling:'status',id:p.bookingId,status});endret=true;if(mott)forMott.delete(p.bookingId);}
  catch(err){visFeil(err.message);}
  await hent();opptatt=false;tegn();folgMed();}
 // E-post og mobil lagres når feltet forlates (change), én om gangen, med pamelding.php handling=kontakt som «Kontaktinfo».
 function kontaktFelt(p,felt,navn,type,melding,oppdater){
  const id=`ks-${felt}-${p.bookingId}`;
  const inp=el('input',{id,type,name:felt,autocomplete:'off',inputmode:type==='tel'?'tel':'email',value:p[felt]||''});
  inp.addEventListener('change',async()=>{const v=inp.value.trim();if(v===(p[felt]||''))return;
   melding.className='ks-lagret';melding.textContent='';
   try{await api('pamelding.php',{handling:'kontakt',id:p.bookingId,[felt]:v});p[felt]=v;endret=true;melding.textContent='Lagret.';melding.classList.add('ok');oppdater();}
   catch(err){melding.textContent=err.message;melding.classList.add('feil');}});
  return el('div',{class:'ks-felt'},el('label',{for:id,text:navn}),inp);
 }
 function deltakerKort(p){
  const melding=el('p',{class:'ks-lagret','aria-live':'polite'});
  const hint=el('p',{class:'ks-hint',text:'Trengs for kursbevis'});
  const kort=el('div',{class:'ks-del','data-booking':p.bookingId});
  const oppdater=()=>{const mangler=!harEpost(p);kort.classList.toggle('mangel',mangler);hint.hidden=!mangler;tegnSum();};
  const kanMott=['betalt','reservert','ikke_mott'].includes(p.statusKode);
  const boks=el('input',{type:'checkbox',id:`ks-mott-${p.bookingId}`,checked:!!p.mott,onchange:ev=>settMott(p,ev.currentTarget)});
  kort.append(...[
   el('div',{class:'ks-del-topp'},
    el('div',{class:'kal-info'},el('b',{},p.navn,p.ny?el('span',{class:'kal-m kal-m-ny',text:'ny'}):null),
     el('small',{text:[`${p.antall} ${p.antall===1?'plass':'plasser'}`,p.merknad?'✎ '+p.merknad:''].filter(Boolean).join(' · ')})),
    kanMott?el('label',{class:'ks-mott'},boks,el('span',{text:'Møtt'})):null),
   el('div',{class:'ks-del-status'},erBetalt(p)?badge('Betalt','good'):p.status==='Ikke betalt'?badge('Ikke betalt','warn'):badge(p.status)),
   el('div',{class:'ks-to'},kontaktFelt(p,'epost','E-post','email',melding,oppdater),kontaktFelt(p,'telefon','Mobil','tel',melding,oppdater)),
   hint,melding,
   p.status==='Ikke betalt'&&!erBetalt(p)?betaling(p):null].filter(Boolean));
  oppdater();
  return kort;
 }
 function stegDeltakere(){tegnSum();
  return [sum,
   o.leggTil?el('button',{type:'button',class:'button primary kal-liten ks-leggtil',text:'+ Legg til deltaker',onclick:leggTil}):null,
   d.deltakere.length?el('div',{class:'ks-liste'},d.deltakere.map(deltakerKort)):el('p',{class:'muted',text:'Ingen påmeldte ennå.'})];}

 function deltaker(p){
  const hoyre=betaling(p);
  return el('div',{class:'kal-delt','data-booking':p.bookingId},
   el('div',{class:'kal-info'},el('b',{},p.navn,p.ny?el('span',{class:'kal-m kal-m-ny',text:'ny'}):null),el('small',{text:[p.merknad?'✎ '+p.merknad:'',p.antall>1?`${p.antall} plasser`:'',p.status==='Ikke betalt'&&p.skyldigOre>0?p.skyldig:''].filter(Boolean).join(' · ')})),
   hoyre);
 }

 function steg1(){const u=ubetalte().length,k2=kort(2);
  return [el('p',{class:'ks-si',text:`«Hei og velkommen til ${d.okt?.tittel||e.tittel}! Så hyggelig at dere kom.»`}),
   k2?el('p',{class:'ks-si ks-si-2',text:k2.tekst}):null,
   el('div',{class:'ks-topp'},el('b',{text:`${d.deltakere.length} påmeldt`}),u?badge(`${u} har ikke betalt`,'warn'):badge('Alle har betalt','good')),
   d.deltakere.length?el('div',{class:'ks-liste'},d.deltakere.map(deltaker)):el('p',{class:'muted',text:'Ingen påmeldte ennå.'}),
   o.leggTil?el('button',{type:'button',class:'button kal-liten ks-leggtil',text:'+ Noen kom uten påmelding',onclick:leggTil}):null];}

 function steg2(){const k1=kort(1),k3=kort(3);
  const linjer=k1?String(k1.tekst||'').split(/\n+/).map(x=>x.trim()).filter(Boolean):[];
  return [el('p',{class:'ks-si',text:'Fortell dette før dere setter i gang:'}),
   linjer.length?el('div',{class:'ks-punkter'},linjer.map((l,i)=>{const n='k1-'+i;
    const boks=el('input',{type:'checkbox',checked:!!sagt[n],onchange:ev=>{sagt[n]=ev.currentTarget.checked;rad.classList.toggle('ok',sagt[n]);}});
    const rad=el('label',{class:`ks-punkt${sagt[n]?' ok':''}`},boks,el('span',{text:l}));return rad;})):el('p',{class:'muted',text:'Ingen punkter.'}),
   k3?el('div',{class:'ks-kort'},el('b',{text:k3.tittel}),el('p',{text:k3.tekst})):null];}

 // Kursbeviset går med «Be om en anmeldelse» (bin/cron.php anmeldelser) til betalte, ikke trukket tilbake, med e-post.
 function kursbevis(){
  const mott=d.deltakere.filter(p=>p.mott);
  const faar=mott.filter(p=>p.statusKode==='betalt'&&!p.bevisSperret&&harEpost(p));
  const mangler=mott.filter(p=>!harEpost(p));
  const an=(d.eposter||[]).find(m=>m.navn==='Google-anmeldelse');
  const naar=String(an?.status||'').startsWith('Sendt')?'Sendt sammen med spørsmålet om anmeldelse.'
   :an?.status==='Planlagt'?'Sendes neste dag kl. 10 sammen med spørsmålet om anmeldelse.'
   :`Sendes ikke nå: «Google-anmeldelse» står som «${an?.status||'Slått av'}».`;
  // Kursbevis på SMS (eieren 8. oktober 2026, migrasjon 259): null = SMS er ikke satt opp.
  const smsKan=mott.filter(p=>p.statusKode==='betalt'&&!p.bevisSperret&&p.harTlf);
  const smsDel=d.kursbevisSms==null?[]:[el('p',{class:'ks-kanaler',text:`E-post: ${faar.length} · SMS: ${d.kursbevisSms?smsKan.length:0}`}),
   el('label',{class:'ks-bryter'},el('input',{type:'checkbox',checked:!!d.kursbevisSms,onchange:async ev=>{const inp=ev.currentTarget,paa=inp.checked;inp.disabled=true;try{await api('kursstart3.php',{handling:'kursbevis-sms',okt:id,paa:paa?'ja':'nei'});d.kursbevisSms=paa;tegn();}catch(e){inp.checked=!paa;toast(e.message);}finally{inp.disabled=false;}}}),' Send også på SMS')];
  return [el('div',{class:'ks-kort ks-bevis'},el('b',{text:`Kursbevis sendes til ${faar.length} av ${mott.length}`}),el('p',{class:'muted',text:naar}),...smsDel),
   mangler.length?el('div',{class:'ks-kort ks-mangler'},el('b',{text:'Mangler e-post'}),
    el('ul',{class:'ks-navn'},mangler.map(p=>el('li',{text:p.navn}))),
    el('button',{type:'button',class:'button kal-liten',text:'Legg inn e-post',onclick:()=>{steg=0;visFeil('');tegn();document.getElementById('ks-epost-'+mangler[0].bookingId)?.focus();}})):null];
 }

 function steg3(){const k4=kort(4),k5=kort(5);
  return [kursbevis(),el('p',{class:'ks-si',text:'Fortell dette når kurset er ferdig:'}),
   [k4,k5].filter(Boolean).map(k=>el('div',{class:'ks-kort'},el('b',{text:k.tittel}),el('p',{text:k.tekst}))),
   el('h3',{class:'ks-h',text:'E-poster som går av seg selv'}),
   el('div',{class:'ks-eposter'},(d.eposter||[]).map(m=>el('div',{class:'ks-epost'},el('span',{},el('b',{text:m.navn}),el('small',{text:m.naar})),badge(m.status,m.tone||''))))];}

 function tegn(){if(lukket)return;
  // Lista tegnes på nytt (betaling, «Møtt», statusen fra Vipps): feltet du står i beholder fokus og det du har skrevet.
  const a=document.activeElement,fokus=a&&innhold.contains(a)&&a.id?{id:a.id,v:a.tagName==='INPUT'&&a.type!=='checkbox'?a.value:null}:null;
  stegRad.replaceChildren(...STEG.map((n,i)=>el('button',{type:'button','aria-current':i===steg?'step':'false',class:i<steg?'ferdig':null,onclick:()=>{steg=i;visFeil('');tegn();}},el('span',{text:i<steg?'✓':String(i+1)}),n)));
  innhold.replaceChildren(...[[stegDeltakere,steg1,steg2,steg3][steg]()].flat(Infinity).filter(Boolean));
  if(fokus){const n=document.getElementById(fokus.id);if(n&&innhold.contains(n)){if(fokus.v!==null)n.value=fokus.v;n.focus();}}
  const u=ubetalte().length;
  fot.replaceChildren(
   el('button',{type:'button',class:'button',text:'Tilbake',style:steg===0?'visibility:hidden':null,onclick:()=>{steg--;visFeil('');tegn();}}),
   steg<SISTE?el('button',{type:'button',class:'button primary',text:steg<=1&&u?`Videre (${u} ubetalt)`:'Videre',onclick:()=>{steg++;visFeil('');tegn();}})
    :el('button',{type:'button',class:'button ks-ferdig',text:'Kurset er ferdig',onclick:()=>s.close()}));
 }
 // Sveip mellom stegene på mobil.
 let x0=null;
 // Ikke fra et felt: der flytter fingeren markøren i teksten.
 s.dlg.addEventListener('touchstart',ev=>{x0=ev.target.closest?.('input')?null:ev.touches[0].clientX;},{passive:true});
 s.dlg.addEventListener('touchend',ev=>{if(x0===null)return;const dx=ev.changedTouches[0].clientX-x0;x0=null;if(dx<-60&&steg<SISTE){steg++;tegn();}else if(dx>60&&steg>0){steg--;tegn();}},{passive:true});
 tegn();folgMed();
 return s;
}

// Markedsføring: forsiden som kort (eieren 08.10). Henter tilbake det gamle admin hadde på adminmarked:
// kurs som fylles tregt (kursboost), SEO- og GEO-poeng med «Fyll inn og lagre» og «Optimaliser for GEO»,
// bryterne samlet (samme innhold.php-nøkler som Synlighet), analyse, innboks og utkast, og Oppsett.
// Ingen nye API-er: marked.php, innhold.php, ai.php, maaling.php og den offentlige kurs.php (katalogen).
import {boostPackage} from './kursboost.js';
import {SIDER,seoScore,geoScore,geoForslag} from './seogeo.js';
import {el,api,button,link,badge,card,form,field,confirm,toast} from './ui.js';

const stolpe=pst=>el('span',{class:'kilde-stolpe','aria-hidden':'true'},el('span',{style:`width:${Math.max(2,Math.min(100,Math.round(pst)))}%`}));
const json=raw=>{if(!raw)return {};try{return JSON.parse(raw)||{};}catch{return null;}};
// Samme brytere og samme standardverdi som Synlighet (innhold-og-kurs.js). Kursboost har ingen bryter.
const BRYTERE=[['Vis/autosvar','Svar automatisk på kommentarer',false],['Vis/salgsuke','Kampanje på forsiden',true],['Banner/pa','Banner under toppbildet',true]];

export function marketingScreens({refresh,save,act,draft,head,item,lenker}){
 async function home(){
  const [d,inn,kart]=await Promise.all([api('marked.php'),api('innhold.php'),api('innhold.php?fil=seo-kart')]);
  const innhold=inn.innhold||{};const ferdig=kart.sider||{};
  const newDraft=()=>form('Lag utkast',[field('handling','Hva vil du lage?','text',{options:[['artikkel','Artikkel'],['sosialt','Innlegg på SoMe'],['nyhetsbrev','Nyhetsbrev']]}),field('emne','Artikkelens emne'),field('om','Hva skal innlegget handle om?'),field('kanal','Kanal','text',{options:['Instagram','Facebook','TikTok','LinkedIn']}),field('form','Innleggstype','text',{options:[['innlegg','Innlegg'],['story','Story'],['reels','Reels'],['karusell','Karusell']]}),field('slag','Nyhetsbrev','text',{options:[['maned','Månedens nyhetsbrev'],['host','Høst'],['jul','Jul'],['medlem','Medlemsbrev']]})],{},async v=>{if(!await confirm('Lag AI-utkast?','Lag et utkast med det eksisterende AI-oppsettet. Forbruk belastes innenfor kostnadstaket. Teksten kontrolleres før publisering.','Lag utkast'))throw Error('Avbrutt.');await save('ai.php',v);});
  function wordEdit(r={}){form(r.id?'Endre søkeord':'Nytt søkeord',[field('ord','Søkeord','text',{required:true}),field('maalside','Siden som skal svare'),field('notat','Notat','textarea')],r,v=>save('marked.php',{handling:'sokeord',id:r.id||0,...v}));}

  // Kurs som fylles tregt: samme liste og samme kursboost som gamle admin (ai.php, handling kursboost).
  const boost=async k=>{if(!await confirm('Lag markedsføring?',`Lag en kursboost for «${k.tittel}»: artikkel, innlegg, nyhetsbrev og melding til medlemmer. Dette bruker AI-budsjettet. Ingenting publiseres før du har kontrollert det.`,'Lag markedsføring'))return;try{toast('AI-en skriver. Dette tar gjerne et halvt minutt.');const r=await api('ai.php',{handling:'kursboost',kursId:k.kursId});refresh();if(r.id)await boostPackage(r.id,refresh);}catch(e){toast(e.message);}};
  const tomme=d.kursTomme||[];
  const kursKort=card('Kurs som fylles tregt',tomme.length?el('div',{class:'list'},tomme.map(k=>el('div',{class:'marked-rad'},el('div',{},el('strong',{text:k.tittel}),el('small',{class:'muted',text:`${k.dato} · ${k.ledige} av ${k.kapasitet} ledig · om ${k.dager} dager`}),stolpe(k.andel)),button('Lag markedsføring',()=>boost(k),'primary')))):el('p',{class:'empty',text:'Ingen kurs fylles tregt nå.'}));

  // SEO og GEO: poengene regnes som i gamle admin. SEO teller ferdigteksten der eieren ikke har skrevet selv; GEO bare det som er lagret.
  const seoLagret=id=>json(innhold['SEO/'+id]);const geoLagret=id=>json(innhold['GEO/'+id]);
  const rader=SIDER.map(s=>{const sl=seoLagret(s.id),gl=geoLagret(s.id);return {s,seo:seoScore({...(ferdig[s.id]||{}),...(sl||{})}),geo:geoScore(gl||{}),feil:sl===null||gl===null};});
  const snitt=k=>Math.round(rader.reduce((a,r)=>a+r[k],0)/Math.max(1,rader.length));
  const fyllSeo=async()=>{const endringer={};for(const s of SIDER){const fra=seoLagret(s.id);if(fra===null||!ferdig[s.id])continue;const ny={...ferdig[s.id],...fra};if(JSON.stringify(ny)!==JSON.stringify(fra))endringer['SEO/'+s.id]=JSON.stringify(ny);}const n=Object.keys(endringer).length;if(!n){toast('Alt er alt fylt ut. Ingen tomme felt igjen.');return;}if(!await confirm('Fyll inn og lagre?',`Fyll de tomme SEO-feltene på ${n} sider med de ferdigskrevne tekstene, og publiser dem. Tekst du selv har skrevet blir stående.`,'Fyll inn og lagre'))return;try{await save('innhold.php',{endringer});toast(n+' sider er fylt inn og lagret.');}catch(e){toast(e.message);}};
  const fyllGeo=async()=>{let katalog=[];try{const r=await fetch('/api/kurs.php',{credentials:'same-origin',cache:'no-store'});katalog=(await r.json()).kurs||[];}catch{katalog=[];}const endringer={};for(const s of SIDER){const fra=geoLagret(s.id);if(fra===null)continue;const ny={...geoForslag(s,katalog),...fra};if(JSON.stringify(ny)!==JSON.stringify(fra))endringer['GEO/'+s.id]=JSON.stringify(ny);}const n=Object.keys(endringer).length;if(!n){toast('Alt er alt fylt ut. Ingen tomme felt igjen.');return;}if(!await confirm('Optimaliser for GEO?',`Fyll spørsmål, kort svar, fakta og hvem det passer for på ${n} sider, med prisene og datoene fra kursene, og publiser dem. Tekst du selv har skrevet blir stående.`,'Optimaliser for GEO'))return;try{await save('innhold.php',{endringer});toast(n+' sider er optimalisert for GEO.');}catch(e){toast(e.message);}};
  const seoKort=el('section',{class:'card'},el('div',{class:'card-head'},el('h2',{text:'SEO og GEO'})),el('div',{class:'marked-poeng'},el('div',{},el('small',{class:'muted',text:'SEO snitt'}),el('p',{class:'stat',text:snitt('seo')+'/100'})),el('div',{},el('small',{class:'muted',text:'GEO snitt'}),el('p',{class:'stat',text:snitt('geo')+'/100'}))),el('div',{class:'actions'},button('Fyll inn og lagre',fyllSeo,'primary'),button('Optimaliser for GEO',fyllGeo,'primary'),link('SEO per side','#seo'),link('GEO per side','#geo')),el('div',{class:'list marked-sider'},rader.map(r=>el('div',{class:'marked-side'},el('strong',{text:r.s.navn}),el('span',{class:'num',text:'SEO '+r.seo}),stolpe(r.seo),el('span',{class:'num',text:'GEO '+r.geo}),stolpe(r.geo)))),rader.some(r=>r.feil)?el('p',{class:'notice',text:'Noen lagrede søketekster må kontrolleres under SEO eller GEO før de kan fylles inn.'}):null);

  // Brytere: samme innhold.php-nøkler og samme standard som Synlighet.
  const bryterKort=card('Brytere',el('div',{class:'list'},BRYTERE.map(([key,name,fallback])=>{const on=innhold[key]===undefined?fallback:innhold[key]==='ja';return el('div',{class:'row'},el('div',{},el('strong',{text:name}),el('p',{class:'muted',text:on?'På':'Av'})),el('div',{class:'actions'},badge(on?'Vises':'Skjult',on?'good':''),button(on?'Slå av':'Slå på',()=>act('innhold.php',{endringer:{[key]:on?'nei':'ja'}},`${on?'Slå av':'Slå på'} «${name}». Endringen gjelder straks på nettsiden.`,on?'Slå av':'Slå på'))));})));

  // Analyse: besøk ligger hos Google; det vi vet selv er bookingene og hvor kjøpene kom fra.
  const a=d.analyse||{};const mest=a.mestBookede||[];const toppM=Math.max(1,...mest.map(x=>x.plasser));const kilder=a.kilder||[];const toppK=Math.max(1,...kilder.map(x=>x.antall));
  const analyseKort=card('Analyse',a.mangler?el('p',{class:'notice',text:a.mangler}):el('div',{},el('p',{text:'Besøkstall, mest leste sider og hvor folk kommer fra ligger i Google Analytics. Måle-ID-en er '+(a.gaId||'')+'.'}),el('div',{class:'actions'},el('a',{class:'button',href:'https://analytics.google.com/',target:'_blank',rel:'noopener',text:'Google Analytics'}))),el('h3',{text:'Mest bookede kurs siste år'}),mest.length?el('div',{class:'list'},mest.map(x=>el('div',{class:'marked-tall'},el('span',{text:x.tittel}),el('span',{class:'num',text:x.plasser+' plasser'}),stolpe(x.plasser/toppM*100)))):el('p',{class:'empty',text:'Ingen bookinger ennå.'}),el('h3',{text:'Hvor kjøpene kom fra, siste 30 dager'}),el('div',{class:'list'},kilder.map(x=>el('div',{class:'marked-tall'},el('span',{text:x.navn}),el('span',{class:'num',text:String(x.antall)}),stolpe(x.antall/toppK*100)))));

  // Innboks og utkast som venter. Hele raden er klikkbar.
  const rad=(tittel,tekst,onclick)=>el('button',{type:'button',class:'marked-klikk',onclick},el('strong',{text:tittel}),el('small',{class:'muted',text:tekst}));
  const innboksKort=card('Innboks og utkast',el('a',{class:'marked-klikk',href:'#innboks'},el('strong',{text:'Innboks'}),el('small',{class:'muted',text:(d.innboksVenter||0)+' kommentarer venter på svar'})),el('h3',{text:'Utkast som venter'}),(d.utkast||[]).length?el('div',{class:'list'},(d.utkast||[]).map(r=>rad(r.tittel,r.type,()=>draft(r.id)))):el('p',{class:'empty',text:'Ingen utkast.'}),el('h3',{text:'Godkjent og klart til bruk'}),(d.godkjente||[]).length?el('div',{class:'list'},(d.godkjente||[]).map(r=>rad(r.tittel,r.type,()=>draft(r.id)))):el('p',{class:'empty',text:'Ingen godkjente utkast.'}));

  // Oppsett: eget skjermbilde under Markedsføring. Hele kortet er klikkbart.
  const s=d.innstillinger||{};
  const oppsettKort=el('a',{class:'card marked-flis',href:'#markedoppsett'},el('h2',{text:'Oppsett'}),el('p',{class:'muted',text:'Google Analytics, Tag Manager, Meta-piksel og kjøpsmåling.'}),el('div',{class:'actions'},badge(s.gaId?'Google Analytics':'Google Analytics mangler',s.gaId?'good':'warn'),badge(s.metaPiksel?'Meta-piksel':'Meta-piksel mangler',s.metaPiksel?'good':'warn')));

  return el('div',{},head('Markedsføring','Kurs som trenger hjelp, søk, brytere og tall — med kontroll før noe går ut.',[button('Lag utkast',newDraft,'primary'),link('Kampanjer','#kampanjer')]),lenker(),el('div',{class:'grid marked-grid'},kursKort,seoKort,bryterKort,analyseKort,innboksKort,oppsettKort),el('div',{class:'card',style:'margin-top:22px'},el('div',{class:'card-head'},el('h2',{text:'Søkeord'}),button('Nytt søkeord',()=>wordEdit())),el('div',{class:'list'},(d.sokeord||[]).map(r=>item(r.ord,[r.maalside,r.notat].filter(Boolean).join(' · '),[button('Endre',()=>wordEdit(r)),button('Slett',()=>act('marked.php',{handling:'sokeord',id:r.id,fjern:true},`Ta ${r.ord} ut av søkeordlisten.`,'Slett'))])))));
 }

 // Markedsføring › Oppsett: måle-ID-ene (marked.php, innstilling) og kjøpsmålingen (maaling.php).
 async function setup(){
  const [d,m]=await Promise.all([api('marked.php'),api('maaling.php')]);const s=d.innstillinger||{};
  const endre=()=>form('Måling og kostnadstak',[field('gaId','Google Analytics måle-ID'),field('gtmId','Google Tag Manager'),field('metaPiksel','Meta piksel-ID'),field('aiTak','Månedlig AI-kostnadstak i kroner','number',{min:0}),field('googleBedrift','Google bedriftsprofil','url')],s,async v=>{if(!await confirm('Endre markedsoppsettet?','Oppdater måling og kostnadstak for nettsiden.','Lagre oppsett'))throw Error('Avbrutt.');await save('marked.php',{handling:'innstilling',...v});});
  const verdi=(navn,v)=>el('div',{class:'row'},el('div',{},el('strong',{text:navn}),el('p',{class:'muted',text:v||'Ikke lagt inn'})),badge(v?'Lagt inn':'Mangler',v?'good':'warn'));
  return el('div',{},head('Oppsett','Måling og koblinger for markedsføringen.',[button('Endre oppsett',endre,'primary'),link('Markedsføring','#marked')]),el('div',{class:'grid'},card('Måling på nettsiden',el('div',{class:'list'},verdi('Google Analytics måle-ID',s.gaId),verdi('Google Tag Manager',s.gtmId),verdi('Meta piksel-ID',s.metaPiksel),verdi('Google bedriftsprofil',s.googleBedrift))),el('a',{class:'card marked-flis',href:'#maaling'},el('h2',{text:'Kjøpsmåling'}),el('p',{class:'muted',text:`Siste 30 dager: ${m.medSporing30||0} betalinger med samtykke av ${m.betalte30||0} betalte.`}),el('div',{class:'actions'},badge(m.harGaSecret?'Google-nøkkel lagret':'Google-nøkkel mangler',m.harGaSecret?'good':'warn'),badge(m.harMetaToken?'Meta-token lagret':'Meta-token mangler',m.harMetaToken?'good':'warn')))));
 }
 return {home,setup};
}

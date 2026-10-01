import { el, hent, feilboks, lasting } from './kjerne.js';

const iso = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
const dato = s => new Date(s+'T12:00:00');
const flytt = (s,n) => { const d=dato(s); d.setDate(d.getDate()+n); return iso(d); };
const oslo = () => new Intl.DateTimeFormat('sv-SE',{timeZone:'Europe/Oslo'}).format(new Date());
const navn = s => dato(s).toLocaleDateString('nb-NO',{weekday:'long',day:'numeric',month:'long'});
let valgt=oslo(), visning='uke', sok='', type='', holder='';

/** Samme lesedata og samme redigering som den etablerte kalenderen. */
export async function tegnKalender(main) {
  const seksjon=el('section',{class:'k', 'aria-label':'Kalender'});
  main.insertBefore(seksjon,main.children[1]||null);
  let runde=0;
  async function tegn() {
    const denne=++runde;
    const d=dato(valgt), fra=iso(new Date(d.getFullYear(),d.getMonth(),1)), til=iso(new Date(d.getFullYear(),d.getMonth()+1,0));
    seksjon.replaceChildren(lasting());
    const svar=await hent(`/api/admin/kalender.php?fra=${fra}&til=${til}`);
    if(denne!==runde || !seksjon.isConnected)return;
    if(svar.status!==200 || !Array.isArray(svar.d.hendelser)){seksjon.replaceChildren(feilboks('Fikk ikke hentet kalenderen.',tegn));return;}
    const alle=svar.d.hendelser;
    const knapp=(tekst,handling,aktiv=false)=>el('button',{class:'pille'+(aktiv?' f':''),type:'button',tekst,onclick:handling});
    const anker=el('input',{type:'date',value:valgt,'aria-label':'Velg kalenderdato',onchange:ev=>{if(ev.target.value){valgt=ev.target.value;tegn();}}});
    const felt=el('input',{type:'search',value:sok,placeholder:'Søk kurs eller kursholder','aria-label':'Søk i kalender',oninput:ev=>{sok=ev.target.value;innhold();}});
    const velger=(label,verdi,valg,endret)=>el('select',{'aria-label':label,onchange:ev=>{endret(ev.target.value);innhold();}},valg.map(([v,n])=>el('option',{value:v,selected:v===verdi,tekst:n})));
    const typer=[...new Set(alle.map(e=>e.type).filter(Boolean))].sort();
    const holdere=[...new Set(alle.map(e=>e.holder).filter(Boolean))].sort();
    const filter=el('div',{class:'pl kal-filter'},felt,
      velger('Hendelsestype',type,[['','Alle hendelser'],...typer.map(t=>[t,t])],v=>type=v),
      velger('Kursholder',holder,[['','Alle kursholdere'],...holdere.map(t=>[t,t])],v=>holder=v));
    const resultat=el('div',{'aria-live':'polite'});
    seksjon.replaceChildren(el('h2',{tekst:'Kalender · '+d.toLocaleDateString('nb-NO',{month:'long',year:'numeric'})}),el('div',{class:'pl'},
      knapp('Forrige',()=>{valgt=visning==='maaned'?iso(new Date(d.getFullYear(),d.getMonth()-1,1)):flytt(valgt,visning==='uke'?-7:-1);tegn();}),
      knapp('I dag',()=>{valgt=oslo();tegn();}),knapp('Neste',()=>{valgt=visning==='maaned'?iso(new Date(d.getFullYear(),d.getMonth()+1,1)):flytt(valgt,visning==='uke'?7:1);tegn();}),anker),
      el('div',{class:'pl',style:'margin:12px 0'},[['maaned','Måned'],['uke','Uke'],['dag','Dag']].map(([v,n])=>knapp(n,()=>{visning=v;tegn();},v===visning))),filter,resultat,
      el('div',{class:'pl',style:'margin-top:16px'},el('a',{class:'pille',href:`/admin/kalender?maaned=${valgt.slice(0,7)}`,tekst:'Planlegg og rediger'}),el('a',{class:'pille',href:'/admin/kurs?apne=nydato',tekst:'Ny kursdato'})));
    function innhold() {
      const ord=sok.toLocaleLowerCase('nb-NO').trim().split(/\s+/).filter(Boolean);
      const hendelser=alle.filter(e=>(!type||e.type===type)&&(!holder||e.holder===holder)&&ord.every(o=>`${e.tittel} ${e.holder||''}`.toLocaleLowerCase('nb-NO').includes(o)));
      const start=visning==='maaned'?fra:visning==='uke'?flytt(valgt,-((dato(valgt).getDay()+6)%7)):valgt;
      const ant=visning==='maaned'?dato(til).getDate():visning==='uke'?7:1;
      const dager=Array.from({length:ant},(_,i)=>flytt(start,i));
      const grid=el('div',{class:'kal-dager '+(visning==='maaned'?'kal-maaned':'')});
      let totalt=0;
      if(visning==='maaned')for(let i=0;i<(dato(fra).getDay()+6)%7;i++)grid.append(el('div',{class:'kal-tom','aria-hidden':'true'}));
      for(const dag of dager){
        const rader=hendelser.filter(e=>e.dato===dag).sort((a,b)=>String(a.tid).localeCompare(String(b.tid)));
        totalt+=rader.length;
        grid.append(el('section',{class:'kal-dag'+(dag===oslo()?' kal-idag':''),'data-kal-dato':dag},
          el('h3',{tekst:navn(dag)}),
          Object.prototype.hasOwnProperty.call(svar.d.stengte||{},dag)?el('p',{class:'tag u',tekst:'Stengt'}):null,
          rader.length?rader.map(e=>el('a',{class:'kal-hendelse',href:`/admin/kalender?dato=${dag}`},
            el('strong',{tekst:`${e.tid||''}${e.slutt?'–'+e.slutt:''} ${e.tittel}`}),
            el('span',{tekst:[e.holder,e.samling,e.avlyst?'Avlyst':null,e.stengt?'Stengt':null,e.publisert===false?'Utkast':null].filter(Boolean).join(' · ')}),
            el('span',{tekst:Number(e.kap)>0?`${e.pameldt||0}/${e.kap} påmeldt · ${(e.venteliste||[]).length} på venteliste`:e.type})))
            :el('p',{class:'dempet',tekst:'Ingen hendelser'})));
      }
      resultat.replaceChildren(el('p',{class:'dempet',tekst:`${totalt} hendelser i valgt visning`}),grid);
    }
    innhold();
  }
  await tegn();
}

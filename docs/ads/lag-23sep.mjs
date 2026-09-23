import fs from 'fs';
const H='Row Type,Action,Ad group status,Keyword status,Ad status,Level,Campaign ID,Campaign,Ad group ID,Ad group,Ad group type,Keyword,Negative keyword,Type,Ad type,Headline 1,Headline 2,Headline 3,Headline 4,Headline 5,Headline 6,Headline 7,Headline 8,Headline 9,Headline 10,Description 1,Description 2,Description 3,Description 4,Path 1,Path 2,Final URL'.split(',');
const C='24249319399', N='Lissom søk';
const rows=[];
const row=o=>rows.push(H.map(h=>o[h]??''));
row({'Row Type':'Ad group',Action:'Add','Ad group status':'Enabled','Campaign ID':C,Campaign:N,'Ad group':'Keramikkurs','Ad group type':'Standard'});
for(const k of ['keramikkurs','keramikk kurs','kurs i keramikk','keramikk kurs tønsberg','keramikkurs tønsberg','keramikk kurs vestfold','keramikkurs vestfold','keramikk tønsberg','keramikk kurs nybegynner'])
 row({'Row Type':'Keyword',Action:'Add','Keyword status':'Enabled','Campaign ID':C,Campaign:N,'Ad group':'Keramikkurs',Keyword:k,Type:'Phrase match'});
for(const k of ['keramikkurs','keramikk kurs tønsberg','keramikk kurs vestfold','keramikkurs vestfold','keramikk kurs nybegynner'])
 row({'Row Type':'Keyword',Action:'Edit','Keyword status':'Paused','Campaign ID':C,Campaign:N,'Ad group':'Dreiekurs',Keyword:k,Type:'Phrase match'});
row({'Row Type':'Keyword',Action:'Edit','Keyword status':'Paused','Campaign ID':C,Campaign:N,'Ad group':'Paint on Pots',Keyword:'paint on pots',Type:'Phrase match'});
for(const k of ['male på keramikk','male keramikk','mal din egen keramikk','male på keramikk tønsberg'])
 row({'Row Type':'Keyword',Action:'Add','Keyword status':'Enabled','Campaign ID':C,Campaign:N,'Ad group':'Paint on Pots',Keyword:k,Type:'Phrase match'});
for(const k of ['sagene','shots and pots','liv i leire','kjærgaard','keramikk kollektivet','selvherdende','retreat','fredrikstad','sarpsborg','moss','østfold','halden','larvik keramikk butikk'].slice(0,7))
 row({'Row Type':'Negative keyword',Action:'Add','Keyword status':'Enabled',Level:'Campaign','Campaign ID':C,Campaign:N,'Negative keyword':k,Type:'Phrase match'});
const hl=['Keramikkurs i Tønsberg','Keramikkurs på Teie','Nybegynnere velkommen','Dreiekurs og håndbygging','Leire og brenning inkludert','Book med Vipps i dag','Små grupper, god veiledning','Kveldskurs og helgekurs','Lag din egen keramikk','Keramikkurs i Vestfold'];
const ds=['Keramikkurs for nybegynnere på Teie ved Tønsberg. Leire, verktøy og brenning er med.','Dreiekurs over to kvelder eller håndbygging på én kveld. Små grupper og god veiledning.','Kveldskurs i uka og helgekurs hver måned. Se ledige datoer og book plassen med Vipps.','Fem minutter fra Tønsberg sentrum med gratis parkering. Nordre Løkkevei 15 på Teie.'];
hl.forEach(h=>{if(h.length>30)throw h}); ds.forEach(d=>{if(d.length>90)throw d+' '+d.length});
const ad={'Row Type':'Ad',Action:'Add','Ad status':'Enabled','Campaign ID':C,Campaign:N,'Ad group':'Keramikkurs','Ad type':'Responsive search ad','Path 1':'keramikkurs','Path 2':'tonsberg','Final URL':'https://lissom.no/kurs'};
hl.forEach((h,i)=>ad['Headline '+(i+1)]=h); ds.forEach((d,i)=>ad['Description '+(i+1)]=d); row(ad);
const q=v=>/[",]/.test(v)?'"'+v.replace(/"/g,'""')+'"':v;
fs.writeFileSync('lissom-sok-23sep.csv','﻿'+[H,...rows].map(r=>r.map(q).join(',')).join('\n'));
console.log(rows.length,'rader', ds.map(d=>d.length));

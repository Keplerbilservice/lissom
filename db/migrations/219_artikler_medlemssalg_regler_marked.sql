-- Tre artikler som kladd: Egen nettbutikk for medlemmer, Regler for salg av keramikk i Norge, og Fysisk utsalg og markeder.
--
-- Eieren, 25. september 2026: satsing på å bli Norges største keramikk-kurssenter.
-- Medlemsfordeler og salg av egne arbeider — unikt for Lissom i Norge.
--
-- Tekstene ble godkjent av eieren («GO – legg inn som kladd») før innlegging.
-- Ingenting vises for publikum før eieren publiserer fra admin (Nyheter → status).
--
-- Formatet er det nettsida tegner (parseInnhold): «# » er mellomtittel,
-- «## » undertittel, «- » punkt, tom linje skiller avsnitt. Bolken
-- «# Ofte stilte spørsmål» med «## spoersmaal» under blir FAQ-data for
-- Google — se Robottekst::artikkelFaq().
--
-- «INSERT IGNORE»: tittel og slug er unike. Kjøres migrasjonen en gang
-- til, eller har verkstedet alt en artikkel med samme navn, røres ingenting.

INSERT IGNORE INTO articles (tittel, kategori, slug, fokus_ord, dato, ingress, innhold, kilde, status, sortering) VALUES
(
  'Selg keramikken din på nett: egen butikk og 0 % provisjon for medlemmer',
  'Medlemskap',
  'selg-keramikken-din-pa-nett-medlem',
  'selge egen keramikk nettbutikk',
  '25. september 2026',
  'Å lage keramikk er givende, men hyllene hjemme fylles fort opp. Som medlem hos Lissom får du din egen profil og nettbutikk på lissom.no, der du styrer varene selv og får betalt rett på din egen Vipps.',
  '# Problemet alle hobbykeramikere kjenner på

Når du først har lært å dreie eller bygge med leire, skjer det raskt: kjøkkenskapene blir fulle, venner og familie har fått kopper til jul og bursdag, og vinduskarmene bugner av skåler, vaser og fat. Du har lyst til å fortsette å skape, men har ikke plass til alt selv.

Mange drømmer om å selge arbeidene sine, men terskelen er høy: å sette opp en egen nettbutikk krever domene, teknisk oppsett, betalingsavtaler og månedlige abonnementskostnader som spiser opp inntektene.

# Løsningen: Din egen butikk på lissom.no

Hos Lissom Keramikk har vi bygget en løsning rett inn i nettsiden vår. Alle medlemmer med et løpende medlemskap får sin egen salgsside og kan legge ut ferdige arbeider direkte for salg:

- Egen profil og varer: Du laster opp bilder av koppen, fatet eller skålen din, setter prisen selv og skriver en kort beskrivelse.
- Direkte oppgjør med Vipps: Betalingen går direkte fra kjøperen til ditt eget Vipps-nummer.
- 0 % provisjon: Lissom tar ingenting av salgssummen. Hver eneste krone du selger for er din egen.
- Etablert trafikk: Varene dine vises for tusenvis av besøkende som allerede er på jakt etter håndlaget keramikk i nettbutikken vår.

# Slik fungerer det i praksis

1. Logg inn på Min side: Under fanen «Selg» legger du inn tittel, beskrivelse, pris og bilde av produktet ditt.
2. Kort godkjenning: Verkstedet godkjenner at varen har et godt bilde og faller inn under godkjente kategorier, før den legges ut.
3. Kunden handler: Når en kunde trykker på varen din i butikken, ser de bildet, beskrivelsen og Vipps-nummeret ditt. Kjøpet avtales og betales direkte til deg.
4. Overlevering: Kunden henter varen i verkstedet på Teie eller får den tilsendt etter avtale.

# Fra hobby til ekstra inntekt

Denne ordningen er unik i Norge, og gir deg som medlem muligheten til å finansiere hobbyen din. Mange medlemmer opplever at salget dekker både leiren de bruker og deler av medlemskontingenten.

Vil du bli en del av verkstedfellesskapet? Les mer om medlemskapene våre og meld deg inn på lissom.no/medlemskap.

# Ofte stilte spørsmål

## Hvem kan selge keramikk på nettsiden?

Alle medlemmer med et løpende medlemskap (Mini 15, Basis 30 eller Årsmedlemskap). Engangsmedlemskapet Prøv Lissom gir ikke tilgang til salg.

## Tar Lissom gebyr eller provisjon av salget?

Nei. Hele salgsbeløpet går uavkortet til deg via Vipps.

## Må jeg ha et registrert foretak for å selge?

Nei, så lenge du selger som hobby innenfor skatteetatens hobbygrenser trenger du ikke enkeltpersonforetak.

## Hvor mange produkter kan jeg ha ute samtidig?

Hvert medlem kan ha opptil 20 aktive produkter i butikken samtidig.',
  'ai', 'kladd', 22
),
(
  'Selge egen keramikk i Norge: regler for hobby, skatt, MVA og Mattilsynet',
  'Keramikk',
  'selge-egen-keramikk-regler-matsikkerhet',
  'selge keramikk regler norge',
  '25. september 2026',
  'Hva kreves for å selge hjemmelaget keramikk lovlig i Norge? Her er den komplette guiden om skattefrie hobbyinntekter, MVA-grensen på 50 000 kroner, og Mattilsynets krav til matsikkerhet.',
  '# Drømmen om å selge egne arbeider

Keramikk er mer populært enn noen gang, og etterspørselen etter unike, håndlagde kopper, skåler og fat er enorm. Mange som starter med keramikk som hobby lurer raskt på: Når må jeg betale skatt? Hva er reglene for MVA? Og hvilke krav stilles til kopper som folk skal drikke av?

Her er en ryddig oversikt over regelverket for deg som vil selge keramikk i Norge.

# 1. Hobby eller næring? (Skatteetaten)

Det finnes ingen fast kronegrense for når en aktivitet går fra å være hobby til å bli næringsvirksomhet. Skatteetaten vurderer tre ting: omfang, varighet og om formålet er å gå med overskudd.

- Hobby (skattefritt): Hvis du selger litt keramikk til venner, bekjente eller gjennom et lokalt utsalg for å dekke kostnader til leire, glasur og verkstedleie, regnes dette som hobby. Eventuelt lite overskudd er skattefritt.
- Næringsvirksomhet: Hvis salget er jevnt, har et visst omfang og er egnet til å gi overskudd over tid, må du registrere et enkeltpersonforetak (ENK) eller AS og skatte av overskuddet.

# 2. MVA-grensen på 50 000 kroner

Uansett om du ser på virksomheten som hobby eller bedrift, gjelder Merverdiavgiftsloven:

- Når den samlede omsetningen din overstiger 50 000 kroner i løpet av en 12-månedersperiode, har du plikt til å registrere deg i Merverdiavgiftsregisteret.
- Etter registrering må du legge 25 % MVA på salgene dine og levere MVA-meldinger.
- Inntil du når 50 000 kroner, selger du helt uten MVA.

# 3. Mattilsynet og matsikkerhet (Viktig for kopper og tallerkener!)

Lager du gjenstander som skal være i kontakt med mat og drikke (brukskeramikk), regnes dette som matkontaktmaterialer. Her gjelder klare regler som beskytter forbrukeren:

- Matsikker glasur: Du må kun benytte glasurer som er merket «food safe» eller «matsikker» fra anerkjente leverandører.
- Riktig brenning: Leiren og glasuren må brennes til korrekt modningstemperatur. En underbrent glasur kan lekke tungmetaller som bly eller kadmium ut i sure væsker (som kaffe, sitron og eddik).
- Samsvarserklæring: Yrkesmessige produsenter skal ha dokumentasjon på at materialene er trygge.
- Registreringsplikt: Produserer du jevnlig brukskeramikk for salg, skal virksomheten registreres hos Mattilsynet (dette er gratis via Mattilsynets nettsider).

Pyntegjenstander, blomsterpotter, lysestaker og skulpturer er unntatt fra matkontaktreglene.

# 4. Trygge rammer hos Lissom

I verkstedet hos Lissom Keramikk på Teie brenner vi utelukkende med høykvalitets steingods og godkjente, matsikre glasurer. Vi har nøyaktige brennekurver og profesjonelle ovner, slik at du som medlem kan være trygg på at koppene og fatene du lager holder høyeste standard for matsikkerhet og holdbarhet.

# Ofte stilte spørsmål

## Kan jeg selge keramikk på julemarked uten eget firma?

Ja. Privatsalg av hobbyarbeider på markeder krever ikke organisasjonsnummer, så lenge omsetningen er beskjeden og du holder deg under MVA-grensen.

## Hva skjer hvis en kopp lekker eller glasuren krakelerer?

Krakelert glasur er ikke matsikker fordi bakterier og fuktighet kan trenge inn i sprekkene over tid. Slike gjenstander bør merkes som dekorasjon, ikke som brukskeramikk.

## Hvordan bør jeg ta betalt?

Vipps er den enkleste og tryggeste betalingsmåten for hobbysalg i Norge.',
  'ai', 'kladd', 23
),
(
  'Fra verksted til butikkhylle: fysisk utsalg, åpent hus og markeder for medlemmer',
  'Medlemskap',
  'fysisk-utsalg-apent-hus-markeder-medlem',
  'keramikkutsalg markeder medlemmer',
  '25. september 2026',
  'Det er én ting å selge på nettet – noe helt annet er å se folk holde koppen din i hendene og kjenne på tyngden. Slik hjelper Lissom sine medlemmer ut i fysiske butikker, markeder og salgsutstillinger.',
  '# Magien ved å oppleve keramikk i virkeligheten

Keramikk er en sanselig kunstform. Et bilde på en skjerm kan vise fargen og formen, men det kan aldri formidle følelsen av en myk, matt glasur mot fingertuppene, balansen i en hank, eller den lune varmen fra en kopp som sitter perfekt i håndflaten.

Derfor er det fysiske møtet mellom kjøper og keramikk så utrolig viktig. Hos Lissom på Teie legger vi til rette for at medlemmene våre skal få vise frem og selge arbeidene sine på flere fysiske arenaer.

# 1. Det fysiske butikkutsalget på Teie

I tilknytning til verkstedet vårt i Nordre Løkkevei 15 har vi et lyst og innbydende utsalg. Her stiller vi ut håndlaget keramikk fra verkstedet.

Som medlem har du mulighet til å få dine unike produkter plassert på hyllene i butikken. Hit kommer naboer, tilreisende fra Tønsberg og omegn, og kursdeltakere som vil kjøpe gaver med ekte sjel og lokal forankring.

# 2. Åpent hus og salgsdager i verkstedet

Gjennom året arrangerer vi «Åpent hus» hvor dørene slås opp på vidt gap for publikum. Dette er festdager i verkstedet:

- Medlemmer rigger egne små stands eller utstillinger rundt langbordene.
- Vi byr på god kaffe og hyggelig prat med nysgjerrige besøkende.
- Det demonstreres dreiing og håndbygging, og folk får se hvor gjenstandene faktisk blir til.
- Stemningen er uformell, varm og full av skaperglede.

# 3. Felles deltakelse på eksterne markeder

Å stå helt alene på et stort marked kan være både dyrt og skummelt for en fersk keramiker: du må leie bord, ordne telt, stå vakt hele helgen og markedsføre deg selv.

Lissom stiller jevnlig på lokale markeder – som det populære julemarkedet på Mellom Rød Gård på Tjøme. Her deltar verkstedet med en stor felles stand hvor medlemmenes produkter er med. Du får profesjonell eksponering og salg uten å måtte bære hele markedsrigget alene.

# Et støttende fellesskap

Det beste med å være medlem hos Lissom er at du aldri står alene. Du er en del av et inkluderende miljø der vi deler erfaringer om prissetting, signaturstempler, innpakning og hvordan du best formidler historien bak hvert enkelt arbeid.

Bli med i Norges koseligste verkstedfellesskap! Les om medlemskapene våre og meld deg inn på lissom.no/medlemskap.

# Ofte stilte spørsmål

## Hvordan bestemmes prisen på produktene i butikken?

Du som produsent bestemmer salgsprisen på dine egne produkter helt selv.

## Hva koster det å stille ut på Åpent hus?

Åpent hus og felles arrangementer i verkstedet er inkludert for aktive medlemmer.

## Hvordan merkes produktene?

Hvert produkt merkes med medlemmets navn eller stempel, slik at kunden vet nøyaktig hvem som har formet det.',
  'ai', 'kladd', 24
);

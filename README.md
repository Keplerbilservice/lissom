# Lissom Keramikk — klikkbar prototype

Komplett prototype av lissom.no med kundesider, Min side (medlem), admin (PC og mobil) og mobilvisning.

## Kjøre lokalt
Åpne `Lissom nettside.dc.html` i en nettleser, eller start en enkel server (`npx serve .`).
`Mobilvisning.dc.html` viser siden i 390 px mobilramme.

## GitHub Pages
Repoet er klart for Pages: Settings → Pages → Deploy from branch → main / root.
`index.html` videresender til hovedfilen.

## Struktur
- `Lissom nettside.dc.html` — hele nettsiden: markup (template) + logikk (script nederst i filen). All demodata ligger her.
- `support.js` — runtime som rendrer templaten (React-basert).
- `_ds/` — Lissom designsystem (tokens + komponentbundle: Button, CourseCard, NavBar, Dialog m.m.).
- `komprimert/` — bilder i nettstørrelse. `assets/` — logo og grafikk.

## Til utvikleren — hva som er demo
- Betaling: Vipps-flyten er simulert (ePayment for engangskjøp, Recurring for medlemskap er intensjonen).
- Booking: i dag bruker Lissom Understory (lissom-kurs/-events/-medlemskap.understory.io).
- Data: kurs, medlemmer, ordrer og innstillinger er hardkodet i hovedfilen — trenger backend/DB i ekte løsning.
- SEO-titler/beskrivelser ligger i admin → SEO og i `SEO_DEFAULTS` i koden.

Kontakt: monica@lissom.no · lissom.no
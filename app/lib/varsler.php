<?php
/**
 * Varsling på e-post og SMS.
 *
 * Ingenting sendes direkte fra et endepunkt. Alt legges i kø i `notifications`,
 * og bin/cron.php tømmer køen. Det gir tre ting: kunden venter aldri på at SMTP
 * svarer, et mislykket forsøk kan gjentas, og du kan alltid slå opp om en
 * kvittering faktisk gikk ut.
 */

declare(strict_types=1);

final class Varsel
{
    /**
     * Legger en e-post i kø.
     *
     * «$egenHtml» er ferdig oppsett fra Oppsett::epost — et nyhetsbrev med
     * bilde, overskrift og knapp. Uten det bygges HTML-en av teksten som
     * for. Signaturen legges paa i begge tilfeller, ett sted, saa den ikke
     * mangler i det ene og staar i det andre.
     */
    public static function epost(string $mottaker, string $emne, string $tekst, ?string $refType = null, ?int $refId = null, string $gruppe = 'system', ?string $egenHtml = null): int
    {
        $mottaker = trim($mottaker);
        if (!filter_var($mottaker, FILTER_VALIDATE_EMAIL)) {
            logg('Hoppet over e-post til ugyldig adresse', ['mottaker' => $mottaker]);
            return 0;
        }
        [$tekst, $html] = self::medSignatur($tekst, $gruppe, $egenHtml);
        return self::iKo('epost', $mottaker, $emne, $tekst, null, $refType, $refId, $html);
    }

    /** Legger en SMS i kø. Returnerer 0 hvis SMS ikke kan sendes. */
    public static function sms(string $mottaker, string $tekst, ?string $refType = null, ?int $refId = null): int
    {
        $tlf = normaliser_telefon($mottaker);
        if ($tlf === '') {
            logg('Hoppet over SMS til ugyldig nummer', ['mottaker' => $mottaker]);
            return 0;
        }
        // Uten leverandor legger vi den ikke i ko i det hele tatt. For gjorde
        // vi det: fem mislykkede forsok, deretter «feilet», og en advarsel i
        // admin om at varsler ikke kom fram — for noe som aldri kunne sendes.
        if (!self::smsMulig()) {
            logg('Hoppet over SMS — ingen leverandør satt opp', ['til' => $tlf]);
            return 0;
        }
        return self::iKo('sms', $tlf, null, $tekst, null, $refType, $refId);
    }

    /**
     * Kan vi i det hele tatt sende SMS?
     *
     * Svaret avgjor om en SMS-mal gaar ut som den er, eller om den maa finne
     * en annen vei fram. Det er oppsettet som spor — ikke om forrige melding
     * gikk bra.
     */
    public static function smsMulig(): bool
    {
        $lev = mb_strtolower((string) Config::hent('sms_leverandor', 'sveve'));
        if ($lev === 'gatewayapi') {
            return trim((string) Config::hent('gatewayapi_token', '')) !== '';
        }
        return trim((string) Config::hent('sveve_bruker', '')) !== ''
            && trim((string) Config::hent('sveve_passord', '')) !== '';
    }

    /**
     * Adressene som skal ha beskjed naar noe krever et menneske.
     *
     * Eieren, 9. september 2026: «la oss forholde oss til det som staar i
     * admin, jeg vil at det kun er monica eller post@lissom.no som skal faa
     * eposter, gjelder hele systemet.» Han valgte post@lissom.no.
     *
     * For gikk de interne varslene til ALLE med rollen «admin» i basen. Da
     * avgjorde rollelista hvem som fikk e-post, og en ny administrator fikk
     * dem uten at noen hadde bestemt det. Naa er det avsenderoppsettet under
     * Innstillinger → Varsler som gjelder: svaradressen om den er fylt ut,
     * ellers avsenderadressen. Begge to staar i admin og kan endres der.
     *
     * Staar «admin_eposter» i secrets.php, er det fortsatt den som gjelder —
     * den som satte opp serveren beholder kontrollen. Den settes ingen steder
     * i koden.
     *
     * @return list<string>
     */
    public static function adminEposter(): array
    {
        $fra = Config::hent('admin_eposter', []);
        $liste = is_array($fra) ? $fra : [];

        if ($liste === []) {
            // Én adresse: den som staar i admin (post@lissom.no).
            //
            // Historikken: 9. september ble rolleoppslaget (alle med rolle
            // admin i basen) tatt bort, fordi en ny administrator begynte aa
            // faa e-post uten at noen hadde bestemt det. 13. september kom
            // det tilbake — eieren ville varsles selv. 14. september, med
            // bilde av tre like e-poster i innboksen (to til post@, én til
            // Monica): «Det holder med å sende 1 stk epost til
            // post@lissom.no.» Saa: bare adressen i admin. Skal flere ha
            // dem, settes «admin_eposter» i secrets.php — den lista gaar
            // foran, som foer.
            $liste = [(string) Config::hent('epost_svar_til', (string) Config::hent('epost_fra', 'post@lissom.no'))];
        }

        // Samme adresse skal telle som én, ogsaa naar den staar med ulik
        // skrivemaate. «Monica@lissom.no» og «monica@lissom.no» er samme
        // postkasse — de sto som to, og da kom hvert varsel dobbelt.
        $rene = [];
        $sett = [];
        foreach ($liste as $e) {
            $e = trim((string) $e);
            $noekkel = mb_strtolower($e);
            if (filter_var($e, FILTER_VALIDATE_EMAIL) && !isset($sett[$noekkel])) {
                $sett[$noekkel] = true;
                $rene[] = $e;
            }
        }
        return $rene;
    }

    /**
     * Beskjed til verkstedet om noe som maa tas for haand.
     *
     * Brukes der en melding ellers ville stoppet: kunden har ikke e-post, SMS
     * er ikke satt opp, eller beskjeden gjelder driften og ikke kunden.
     * Returnerer antall adresser som fikk den.
     */
    public static function tilAdmin(string $emne, string $tekst, ?string $refType = null, ?int $refId = null): int
    {
        // ── Den samme beskjeden to ganger ─────────────────────────────
        //
        // Sperren malTilAdmin() har hatt siden 17. september 2026 gjaldt
        // bare beskjeder av en mal. De som lages i koden — «Varsel maa
        // sendes for haand …» — gikk utenom, og kunne komme to ganger naar
        // det samme kallet kom to ganger. Eieren, 16. september: «sperr
        // dobbel e-post uansett aarsak». Da gjelder den her ogsaa: er baade
        // emnet og teksten det samme som noe vi alt har lagt i koen de siste
        // femten minuttene, er det en dublett.
        if (DB::en(
            "SELECT id FROM notifications
              WHERE kanal = 'epost' AND emne = :e AND tekst = :t
                AND status IN ('ko', 'sendt')
                AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)
              LIMIT 1",
            ['e' => mb_substr($emne, 0, 191), 't' => $tekst]
        ) !== null) {
            logg('Hoppet over en helt lik beskjed til admin', ['emne' => $emne]);
            return 0;
        }

        $antall = 0;
        foreach (self::adminEposter() as $adresse) {
            // «intern» staar ikke i noen gruppe, og faar derfor ingen
            // signatur. Dette er beskjeder til verkstedet om egen drift.
            if (self::epost($adresse, $emne, $tekst, $refType, $refId, 'intern') > 0) {
                $antall++;
            }
        }
        if ($antall === 0) {
            logg_feil('Fant ingen adresse å varsle admin på: ' . $emne);
        }
        return $antall;
    }

    /**
     * Beskjed til verkstedet, av en mal.
     *
     * Eieren, 1. september: «hvorfor kan ikke alle vaere redigerbare?» — og
     * paa spoersmaal om de fire interne ogsaa skulle med: «ja, alle 29».
     *
     * De gaar til Monica selv, ikke til en kunde, og skal derfor ikke ha
     * signaturen under seg. Gruppa «intern» staar utenfor GRUPPER nettopp
     * derfor. Den kan ikke lagres paa malen — kolonna er en enum uten
     * «intern» — saa den settes her, ved sending. Da oppfoerer de seg
     * noeyaktig som da teksten sto i koden.
     */
    public static function malTilAdmin(string $malNavn, array $felter = [], ?string $refType = null, ?int $refId = null): int
    {
        $mal = self::hentMal($malNavn);
        if ($mal === null) {
            return 0;
        }
        $emne  = self::flett((string) ($mal['emne'] ?? ''), $felter);
        $tekst = self::flett((string) $mal['tekst'], $felter);

        // ── Én beskjed per hendelse ───────────────────────────────────
        //
        // Eieren, 16. september 2026, med to like «Gave løst inn: 1 ekstra
        // time» i innboksen samme minutt: «sperr dobbel e-post uansett
        // årsak».
        //
        // Det var to ting som kunne gi to: flere adresser i «admin_eposter»
        // som alle havner i samme innboks, og det samme kallet to ganger.
        // Begge er stengt her.
        //
        // Koen selv kunne det ikke: tomKo() tar raden med én UPDATE som bare
        // treffer om den er urort, foer den sendes. Det ble maalt 16.
        // september — én innloesning gav én rad.
        //
        // Samme regel som eieren satte 14. september 2026, med bilde av tre
        // like e-poster: «Det holder med å sende 1 stk epost til
        // post@lissom.no.»
        $adresser = self::adminEposter();
        if ($adresser === []) {
            logg_feil('Fant ingen adresse å varsle admin på: ' . $emne);
            return 0;
        }

        // Er beskjeden om en hendelse vi kan kjenne igjen — en gave, en
        // ordre, en soeknad — gaar den ut én gang for den hendelsen. Ligger
        // den alt i koen eller er sendt, er det ingenting mer aa gjore.
        if ($refType !== null && $refId !== null && DB::verdi(
            "SELECT id FROM notifications
              WHERE emne = :e AND ref_type = :t AND ref_id = :i
                AND status IN ('ko', 'sendt')
              LIMIT 1",
            ['e' => mb_substr($emne, 0, 191), 't' => $refType, 'i' => $refId]
        ) !== null) {
            logg('Hoppet over dobbel beskjed til admin', ['emne' => $emne, 'ref' => $refType . '#' . $refId]);
            return 0;
        }

        // ── Den samme beskjeden to ganger ─────────────────────────────
        //
        // Eieren, 17. september 2026, med to like «Ny vare til godkjenning —
        // Eirin har lagt ut «Skål» til kr. 400» i innboksen, samme minutt.
        //
        // Sperren over gaar paa hendelsen: samme «ref_type#ref_id». La
        // medlemmet ut den samme varen to ganger — et dobbelttrykk paa
        // «Legg ut» — ble det to rader i «member_sales», to ulike id-er, og
        // dermed to beskjeder som slapp gjennom. For den som leser innboksen
        // er de to helt like.
        //
        // «uansett aarsak», sa eieren 16. september. Da er det innholdet som
        // maa avgjore: er bade emnet og teksten den samme som noe vi alt har
        // sendt de siste femten minuttene, er det en dublett. Ulike
        // hendelser med samme emne staar fortsatt hver for seg — teksten
        // navngir varen, gaven eller ordren.
        if (DB::en(
            "SELECT id FROM notifications
              WHERE kanal = 'epost' AND emne = :e AND tekst = :t
                AND status IN ('ko', 'sendt')
                AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)
              LIMIT 1",
            ['e' => mb_substr($emne, 0, 191), 't' => $tekst]
        ) !== null) {
            logg('Hoppet over en helt lik beskjed til admin', ['emne' => $emne]);
            return 0;
        }

        // Én adresse: den foerste. Staar det flere i «admin_eposter», er det
        // fortsatt én beskjed per hendelse — det er det eieren ba om.
        if (self::epost($adresser[0], $emne, $tekst, $refType, $refId, 'intern') > 0) {
            return 1;
        }
        logg_feil('Fikk ikke lagt beskjed til admin i kø: ' . $emne);
        return 0;
    }

    /**
     * Malen, eller null med en linje i loggen.
     *
     * Sto inne i mal() og kunne bare naas derfra. Naar en beskjed til
     * verkstedet ogsaa skal komme av en mal, trenger begge det samme
     * oppslaget — og en mal som er slaatt av skal vaere slaatt av begge veier.
     *
     * @return array<string,mixed>|null
     */
    private static function hentMal(string $malNavn): ?array
    {
        $mal = DB::en('SELECT * FROM notification_templates WHERE navn = :n AND aktiv = 1', ['n' => $malNavn]);
        if ($mal === null) {
            logg_feil("Varselmal «{$malNavn}» finnes ikke eller er slått av");
            return null;
        }
        return $mal;
    }

    /**
     * Sender en av malene fra `notification_templates`, med {navn}-plassholdere
     * fylt ut. Malen bestemmer selv om det blir e-post, SMS eller begge.
     *
     * @param array<string,string> $felter
     */
    public static function mal(string $malNavn, array $mottaker, array $felter = [], ?string $refType = null, ?int $refId = null, ?string $egenHtml = null): void
    {
        // «$egenHtml» er en ferdig HTML-utgave av meldingen (medlemsinvitasjonen
        // etter kurset, app/epost/fortsett.html). Tekstdelen er fortsatt
        // malen; HTML-en gaar i stedet for den automatiske omskrivinga.
        $mal = self::hentMal($malNavn);
        if ($mal === null) {
            return;
        }

        // ── Bare fornavnet til kunden ─────────────────────────────────
        //
        // Eieren, 18. september 2026: «Bruk kun fornavn, det gjør du i alle
        // maler forøvrig.» Det ble rettet i én mal. 25. september kom
        // bekreftelsen til ham med «Hei Joakim Andre Væthe-Larsen!»: «jeg vil
        // kun at eposter bruker fornavnet mitt … sørg for å fikse det
        // permanent».
        //
        // Derfor her, der alle malene flettes, og ikke i hver mal: {navn} er
        // fornavnet i alt som går til en kunde, også i en mal som skrives om
        // under Maler. Beskjedene til verkstedet (intern_) beholder hele
        // navnet — der må det gå an å se hvem det er.
        if (!str_starts_with($malNavn, 'intern_') && trim((string) ($felter['navn'] ?? '')) !== '') {
            $forste = (string) (preg_split('/\s+/u', trim((string) $felter['navn']))[0] ?? '');
            $felter['navn'] = $forste;
            $felter['fornavn'] = $felter['fornavn'] ?? $forste;
        }

        $emne = self::flett((string) ($mal['emne'] ?? ''), $felter);
        $tekst = self::flett((string) $mal['tekst'], $felter);
        $kanal = (string) $mal['kanal'];
        // Hvilken gruppe malen hoerer til avgjor om signaturen skal med.
        // Kolonna kom med migrasjon 062; er den ikke kjort, staar alt som
        // «system» — og da oppfoerer det seg som for.
        $gruppe = (string) ($mal['gruppe'] ?? 'system');

        $viaEpost = false;
        if ($kanal === 'epost' || $kanal === 'epost_sms') {
            if (!empty($mottaker['epost'])) {
                [$epostTekst, $epostHtml] = self::medSignatur($tekst, $gruppe, $egenHtml);
                self::iKo('epost', (string) $mottaker['epost'], $emne, $epostTekst, $malNavn, $refType, $refId, $epostHtml);
                $viaEpost = true;
            }
        }

        $viaSms = false;
        if ($kanal === 'sms' || $kanal === 'epost_sms') {
            if (!empty($mottaker['telefon']) && self::smsMulig()) {
                $tlf = normaliser_telefon((string) $mottaker['telefon']);
                if ($tlf !== '') {
                    self::iKo('sms', $tlf, null, self::tilSmsTekst($tekst), $malNavn, $refType, $refId);
                    $viaSms = true;
                }
            }
        }

        // En mal som bare finnes som SMS naar ingen SMS kan sendes: da er
        // e-post bedre enn ingenting. Kunden skal faa beskjeden, ikke vente
        // paa at oppsettet blir ferdig.
        if (!$viaEpost && !$viaSms && $kanal === 'sms' && !empty($mottaker['epost'])) {
            [$reserveTekst, $reserveHtml] = self::medSignatur($tekst, $gruppe);
            self::iKo(
                'epost',
                (string) $mottaker['epost'],
                $emne !== '' ? $emne : 'Beskjed fra Lissom Keramikk',
                $reserveTekst,
                $malNavn,
                $refType,
                $refId,
                $reserveHtml
            );
            $viaEpost = true;
        }

        if ($viaEpost || $viaSms) {
            return;
        }

        // ── En SMS til verkstedet selv, uten SMS ──────────────────────
        //
        // Eieren, 20. september 2026: «admin faar fortsatt to epostvarsler».
        //
        // Ved en innmelding gaar det én e-post til verkstedet
        // («Nytt medlem: …») og én SMS til admin-numrene («intern_nytt_
        // medlem_sms»). Er SMS ikke satt opp, kom SMS-en hit — og ble til
        // e-post nummer to: «Varsel maa sendes for haand: Nytt medlem», med
        // den samme beskjeden en gang til, til den samme innboksen. Den ba
        // verkstedet sende en SMS til seg selv.
        //
        // Beskjeden til verkstedet er alt gitt paa e-post. En intern SMS som
        // ikke kan gaa, skal bare loggfoeres — ikke bli en e-post til.
        if (str_starts_with($malNavn, 'intern_')) {
            logg('Intern SMS kunne ikke sendes — e-posten er alt sendt', ['mal' => $malNavn]);
            return;
        }

        // Ingen vei fram. «Plass ledig paa venteliste» og «keramikken er
        // ferdig brent» finnes bare som SMS, og uten leverandor ville de
        // stanset her uten at noen visste det. Da skal et menneske faa vite
        // hvem som venter paa beskjed — med nummeret, slik at hun kan ringe.
        self::tilAdmin(
            'Varsel må sendes for hånd: ' . ($emne !== '' ? $emne : $malNavn),
            "Dette varselet nådde ingen automatisk, og må tas for hånd.\n\n"
            . 'Mottaker: ' . (string) ($mottaker['navn'] ?? '(uten navn)') . "\n"
            . 'Telefon: ' . (!empty($mottaker['telefon']) ? (string) $mottaker['telefon'] : '(ikke registrert)') . "\n"
            . 'E-post: ' . (!empty($mottaker['epost']) ? (string) $mottaker['epost'] : '(ikke registrert)') . "\n\n"
            . "Meldingen som skulle gått ut:\n\n" . $tekst . "\n\n"
            . (self::smsMulig()
                ? 'Grunnen er at mottakeren mangler kontaktopplysninger.'
                : 'Grunnen er at SMS ikke er satt opp. Legges nøklene inn i secrets.php, går slike varsler ut av seg selv igjen.'),
            $refType,
            $refId
        );
    }

    /** @param array<string,string> $felter */
    public static function flett(string $tekst, array $felter): string
    {
        foreach ($felter as $nokkel => $verdi) {
            $tekst = str_replace('{' . $nokkel . '}', (string) $verdi, $tekst);
        }
        // Plassholdere vi ikke har verdi for fjernes, så kunden slipper å lese «{navn}».
        $tekst = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '', $tekst) ?? $tekst;
        // Et tomt felt på egen linje («{betaling}» når alt er betalt, eller
        // «{kursinfo}» på et kurs uten samlinger) skal ikke bli et hull.
        return preg_replace("/\n[ \t]*\n(?:[ \t]*\n)+/", "\n\n", $tekst) ?? $tekst;
    }

    /** SMS tåler ikke HTML, og lange meldinger koster flere segmenter. */
    private static function tilSmsTekst(string $tekst): string
    {
        $ren = trim(html_entity_decode(strip_tags($tekst), ENT_QUOTES, 'UTF-8'));
        $ren = preg_replace("/\n{3,}/", "\n\n", $ren) ?? $ren;
        return mb_substr($ren, 0, 900);
    }

    private static function iKo(
        string $kanal,
        string $mottaker,
        ?string $emne,
        string $tekst,
        ?string $mal = null,
        ?string $refType = null,
        ?int $refId = null,
        ?string $html = null
    ): int {
        $rad = [
            'mal'      => $mal,
            'kanal'    => $kanal,
            'mottaker' => mb_substr($mottaker, 0, 191),
            'emne'     => $emne === null ? null : mb_substr($emne, 0, 191),
            'tekst'    => $tekst,
            'ref_type' => $refType,
            'ref_id'   => $refId,
        ];
        // Kolonna kom med migrasjon 062. Er den ikke kjort, skal meldingen
        // gaa ut som ren tekst — ikke stanse.
        if ($html !== null && DB::harKolonne('notifications', 'html')) {
            $rad['html'] = $html;
        }
        return DB::settInn('notifications', $rad);
    }

    // ── Signaturen ────────────────────────────────────────────────────────
    //
    // Fire grupper, satt paa malen: system, ordre, kurs og nyhetsbrev. Hver
    // av dem kan skrus av for seg under Innstillinger -> E-post og varsler.
    // En gruppe som ikke er en av de fire — «intern», beskjeder til
    // verkstedet selv — faar aldri signatur.
    private const GRUPPER = ['system', 'ordre', 'kurs', 'nyhetsbrev'];

    /**
     * Legger signaturen paa en melding, og lager HTML-utgaven som foelger med.
     *
     * E-postene har vaert ren tekst. En HTML-signatur i en ren-tekst-melding
     * blir en klump med taggkode hos mottakeren, saa naar signaturen er paa,
     * sendes begge deler: teksten med signaturen skrevet ut i ren tekst, og
     * HTML-en med signaturen slik den er tegnet. De sier det samme.
     *
     * @return array{0: string, 1: ?string} [tekst, html]
     */
    private static function medSignatur(string $tekst, string $gruppe, ?string $egenHtml = null): array
    {
        // Et ferdig oppsett skal ut selv om signaturen er slaatt av for
        // gruppa — ellers ville et nyhetsbrev med bilde blitt sendt som ren
        // tekst, og bildet forsvunnet.
        $kropp = $egenHtml !== null && trim($egenHtml) !== ''
            ? $egenHtml : self::tekstSomHtml($tekst);

        if (!in_array($gruppe, self::GRUPPER, true)) {
            return [$tekst, $egenHtml];
        }
        $signatur = trim((string) Config::hent('epost_signatur', ''));
        if ($signatur === '') {
            return [$tekst, $egenHtml];
        }
        if ((string) Config::hent('epost_signatur_' . $gruppe, '1') !== '1') {
            return [$tekst, $egenHtml];
        }

        $ren = self::signaturSomTekst($signatur);
        return [
            $ren === '' ? $tekst : $tekst . "\n\n-- \n" . $ren,
            $kropp . '<div style="margin-top:28px;">' . $signatur . '</div>',
        ];
    }

    /**
     * Meldingsteksten som HTML.
     *
     * Teksten er skrevet som tekst, av et menneske, i en mal. Den skal vises
     * som den staar — derfor rommes den inn foerst, saa en < i «kl. 18 < 20»
     * ikke blir borte, og linjeskiftene beholdes.
     */
    private static function tekstSomHtml(string $tekst): string
    {
        $trygg = htmlspecialchars($tekst, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:15px;'
            . 'line-height:1.6;color:#4D1D12;white-space:pre-wrap;">' . $trygg . '</div>';
    }

    /**
     * Signaturen skrevet ut som ren tekst, til tekstdelen av meldingen.
     *
     * Den som leser e-post som ren tekst skal faa de samme opplysningene —
     * navn, telefon, adresse — ikke et hull der signaturen sto.
     *
     * Aapen fordi admin viser den samme utregningen: eieren skal se hva den
     * som leser ren tekst faktisk faar, og det maa vaere det samme svaret
     * som utsendingen bruker — ikke en etterlikning i nettleseren.
     */
    public static function signaturSomTekst(string $html): string
    {
        $t = preg_replace('~<(br|/div|/p|/tr|/td|/h[1-6])[^>]*>~i', "\n", $html) ?? $html;
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', $t);
        $linjer = [];
        foreach (explode("\n", $t) as $l) {
            $l = trim(preg_replace('/[ \t]+/', ' ', $l) ?? $l);
            if ($l === '') {
                continue;
            }
            // «·» mellom to lenker staar som egen linje naar taggene tas
            // bort. Alene betyr den ingenting — den hoerer til linja foer.
            if (preg_match('/^[·|\-–—]+$/u', $l) === 1) {
                if ($linjer !== []) {
                    $linjer[count($linjer) - 1] .= ' · ';
                }
                continue;
            }
            if ($linjer !== [] && str_ends_with($linjer[count($linjer) - 1], ' · ')) {
                $linjer[count($linjer) - 1] .= $l;
                continue;
            }
            $linjer[] = $l;
        }
        return implode("\n", $linjer);
    }
}

/**
 * Selve sendingen. Kalles kun fra cron.
 *
 * E-post går via SMTP hos Domene.no, der SPF og DKIM for lissom.no allerede er
 * satt opp riktig siden e-posten hører hjemme hos samme leverandør. Vil du
 * senere bytte til Resend eller Brevo, er det denne klassen som endres — resten
 * av koden merker ingenting.
 */
final class Utsending
{
    /** Tømmer køen. Returnerer [sendt, feilet]. */
    /**
     * Siste grunn til at en utsending feilet, satt av sendSmtp() og sendSms().
     *
     * «Leverandoren svarte med feil» hjelper ingen. Serverens eget svar —
     * «550 relaying denied», «535 authentication failed» — sier hva som er
     * galt, og det er det som skal staa i koen.
     */
    private static string $sisteFeil = '';

    public static function tomKo(int $maks = 50): array
    {
        $rader = DB::alle(
            "SELECT * FROM notifications
              WHERE status = 'ko'
                AND send_etter <= UTC_TIMESTAMP()
                AND forsok < 5
              ORDER BY id
              LIMIT {$maks}"
        );

        $sendt = 0;
        $feilet = 0;

        foreach ($rader as $n) {
            // ── Ta raden foer den sendes ──────────────────────────────
            //
            // Koen toemmes fra to kanter: cron hvert femte minutt, og
            // Tikk etter hver nettforespoersel. Begge leste «status = ko»
            // og sendte — og satte «sendt» foerst etterpaa. Overlappet de,
            // gikk samme e-post to ganger. Eieren, 14. september 2026, med
            // bilde fra innboksen: «Fått 3 stk mail av samme» — to til
            // post@, én til Monica.
            //
            // Raden tas med én UPDATE som bare treffer om den fortsatt er
            // urort: forsok telles opp, og send_etter flyttes ti minutter
            // fram, saa ingen annen plukker den mens den sendes. Treffer
            // UPDATE-en null rader, har en annen alt tatt den. Gaar
            // sendinga galt, legger merkFeilet() den tilbake med ny
            // ventetid, som foer.
            $krav = DB::oppdater('notifications', [
                'forsok'     => (int) $n['forsok'] + 1,
                'send_etter' => gmdate('Y-m-d H:i:s', time() + 600),
            ], ['id' => $n['id'], 'status' => 'ko', 'forsok' => (int) $n['forsok']]);
            if ($krav !== 1) {
                continue;
            }
            $n['forsok'] = (int) $n['forsok'] + 1;

            try {
                self::$sisteFeil = '';
                $ok = $n['kanal'] === 'sms'
                    ? self::sendSms((string) $n['mottaker'], (string) $n['tekst'])
                    : self::sendEpost(
                        (string) $n['mottaker'],
                        (string) ($n['emne'] ?? ''),
                        (string) $n['tekst'],
                        // Kolonna kom med migrasjon 062. Er den ikke der, er
                        // meldingen ren tekst, som den alltid har vaert.
                        isset($n['html']) && trim((string) $n['html']) !== '' ? (string) $n['html'] : null,
                        (int) $n['id']
                    );

                if ($ok) {
                    // forsok er alt telt opp da raden ble tatt.
                    DB::oppdater('notifications', [
                        'status'   => 'sendt',
                        'sendt_at' => gmdate('Y-m-d H:i:s'),
                    ], ['id' => $n['id']]);
                    $sendt++;
                } else {
                    self::merkFeilet($n, self::$sisteFeil !== '' ? self::$sisteFeil : 'Leverandøren svarte med feil');
                    $feilet++;
                }
            } catch (Throwable $e) {
                self::merkFeilet($n, $e->getMessage());
                $feilet++;
            }

            // Delte webhotell har sendegrense per time. Litt pust mellom hver.
            usleep(200_000);
        }

        return [$sendt, $feilet];
    }

    private static function merkFeilet(array $n, string $grunn): void
    {
        // Forsoket er alt telt opp da raden ble tatt i tomKo().
        $forsok = (int) $n['forsok'];
        DB::oppdater('notifications', [
            // Under fem forsøk: legg tilbake i køen med økende ventetid.
            'status'      => $forsok >= 5 ? 'feilet' : 'ko',
            'forsok'      => $forsok,
            'feilmelding' => mb_substr($grunn, 0, 255),
            'send_etter'  => gmdate('Y-m-d H:i:s', time() + (60 * (2 ** $forsok))),
        ], ['id' => $n['id']]);
        logg_feil('Varsel feilet', new RuntimeException($grunn . ' (varsel ' . $n['id'] . ')'));
    }

    /**
     * Testmiljoeet sender ingenting.
     *
     * test.lissom.no kjoerer mot en kopi av basen — med ekte medlemmer,
     * ekte adresser og SMTP-oppsettet fra content_blocks. Hver paaminnelse
     * og hvert «ferdig brent» ville gaatt til ekte folk fra en testside.
     * Derfor: er miljoeet noe annet enn produksjon, loggfoeres meldinga og
     * regnes som sendt. Skal testen faktisk sende, settes
     * send_i_test => true i secrets.php — og da vet man hva man gjoer.
     */
    private static function holdtTilbake(string $slag, string $til, string $hva): bool
    {
        if (!Config::erUtvikling() || Config::hent('send_i_test', false)) {
            return false;
        }
        error_log('Lissom test: ' . $slag . ' til ' . $til . ' holdt tilbake (' . $hva . ')');
        return true;
    }

    private static function sendEpost(string $til, string $emne, string $tekst, ?string $html = null, int $varselId = 0): bool
    {
        if (self::holdtTilbake('e-post', $til, $emne)) {
            return true;
        }
        // Avsenderadressen gaar bade i From-headeren og i konvolutten
        // (MAIL FROM / «-f»). Er den ikke en adresse, er den ikke noe vi skal
        // sende med — da er standardadressen bedre enn en halv linje.
        $fra = trim((string) Config::hent('epost_fra', 'post@lissom.no'));
        if (!filter_var($fra, FILTER_VALIDATE_EMAIL)) {
            $fra = 'post@lissom.no';
        }
        $fraNavn = Config::hent('epost_fra_navn', 'Lissom Keramikk');
        $svarTil = Config::hent('epost_svar_til', $fra);

        $headere = [
            'From'                      => sprintf('=?UTF-8?B?%s?= <%s>', base64_encode((string) $fraNavn), $fra),
            'Reply-To'                  => (string) $svarTil,
            'MIME-Version'              => '1.0',
            'Content-Type'              => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer'                  => 'lissom.no',
        ];

        // ── Én identitet per varsel ───────────────────────────────────
        //
        // Eieren, 22. september 2026, om «Ny paamelding»: «admin faar to
        // eposter» — meldt mange ganger. Koen viste én rad, sendt én gang.
        // Meldingene gikk ut UTEN Message-ID, saa mottakersystemet lagde en
        // ny for hver kopi som kom fram (videresending, POP-henting, kopi i
        // «Sendt» naar avsender og mottaker er samme konto). To kopier av
        // samme melding sto da som to ulike e-poster.
        //
        // Med en fast Message-ID per rad i koen ser Gmail, Outlook og
        // webmail at det er den samme, og viser den én gang. Og en melding
        // uten Message-ID er i seg selv et spam-signal.
        if ($varselId > 0) {
            $domene = parse_url(Config::nettsted(), PHP_URL_HOST) ?: 'lissom.no';
            $headere['Message-ID'] = '<varsel-' . $varselId . '.'
                . substr(hash('sha256', $varselId . '|' . $til . '|' . $emne), 0, 12) . '@' . $domene . '>';
        }

        // Ingen linjeskift i en headerverdi.
        //
        // Verdiene under kommer fra Varsler → oppsett, som eieren fyller ut.
        // Et linjeskift midt i «Svar til»-adressen ville blitt en ekte
        // headerlinje naar de settes sammen lenger nede — «Bcc: en@annen.no»
        // gaar like fint som noe annet. trim() i skjemaet tar bare enden av
        // strengen, ikke midten. Her klippes de vekk ett sted, saa det
        // gjelder ogsaa de headerne vi maatte legge til senere.
        $headere = array_map(
            static fn(string $v): string => str_replace(["\r", "\n"], '', $v),
            $headere
        );

        $emneKodet = '=?UTF-8?B?' . base64_encode($emne) . '?=';
        $kropp = str_replace("\r\n", "\n", $tekst);

        // To utgaver av samme melding naar det finnes en HTML-utgave.
        // Mottakeren — eller programmet hens — velger. Den som leser ren
        // tekst faar teksten, ikke en klump med taggkode.
        //
        // Kroppen bygges med rene linjeskift. sendSmtp() gjor dem om til
        // CRLF for den gaar paa traaden; gjorde vi det her, ville de blitt
        // gjort om to ganger.
        if ($html !== null && trim($html) !== '') {
            $grense = 'lissom-' . bin2hex(random_bytes(12));
            $headere['Content-Type'] = 'multipart/alternative; boundary="' . $grense . '"';
            unset($headere['Content-Transfer-Encoding']);
            $htmlDel = "Content-Type: text/html; charset=UTF-8\n"
                . "Content-Transfer-Encoding: 8bit\n\n"
                . str_replace("\r\n", "\n", $html) . "\n\n";

            // ── Logoen ligger i e-posten, ikke paa nettet ─────────────
            //
            // Eieren, 25. september 2026: «lissom logoen i eposten vises
            // heller ikke». Signaturen hentet den fra lissom.no, og Outlook
            // tok bildet ut av meldingen — igjen sto en tom lenke. Mange
            // e-postprogrammer blokkerer bilder fra nettet til mottakeren
            // trykker «Last ned bilder».
            //
            // Naa sendes logoen med i selve meldingen (multipart/related),
            // og signaturen peker paa den. Det er den samme fila, lagt i
            // app/epost/ saa den foelger koden ut.
            $logoFil = APP_DIR . '/epost/signatur-logo.png';
            if (str_contains($html, 'lissom-signatur-logo.png') && is_file($logoFil)) {
                $cid = 'signatur-logo@lissom.no';
                $medCid = (string) preg_replace('#src="[^"]*lissom-signatur-logo\.png"#', 'src="cid:' . $cid . '"', $html);
                $rel = 'lissom-rel-' . bin2hex(random_bytes(12));
                $htmlDel = "Content-Type: multipart/related; boundary=\"{$rel}\"\n\n"
                    . "--{$rel}\n"
                    . "Content-Type: text/html; charset=UTF-8\n"
                    . "Content-Transfer-Encoding: 8bit\n\n"
                    . str_replace("\r\n", "\n", $medCid) . "\n\n"
                    . "--{$rel}\n"
                    . "Content-Type: image/png; name=\"lissom-logo.png\"\n"
                    . "Content-Transfer-Encoding: base64\n"
                    . "Content-ID: <{$cid}>\n"
                    . "Content-Disposition: inline; filename=\"lissom-logo.png\"\n\n"
                    . chunk_split(base64_encode((string) file_get_contents($logoFil)), 76, "\n") . "\n"
                    . "--{$rel}--\n\n";
            }

            $kropp = "--{$grense}\n"
                . "Content-Type: text/plain; charset=UTF-8\n"
                . "Content-Transfer-Encoding: 8bit\n\n"
                . $kropp . "\n\n"
                . "--{$grense}\n"
                . $htmlDel
                . "--{$grense}--\n";
        }

        // SMTP naar det er satt opp, ellers serverens egen mail(). SMTP er aa
        // foretrekke: da vet vi om det gikk galt, og hvorfor. mail() svarer
        // bare true naar meldingen er levert til koen paa serveren — den kan
        // fortsatt bli avvist av mottakeren uten at vi far vite noe.
        if (trim((string) Config::hent('smtp_vert', '')) !== '') {
            return self::sendSmtp($til, $emneKodet, $kropp, $headere, (string) $fra);
        }

        // Serverens egen e-post. mail() svarer bare true eller false, og
        // grunnen staar i PHP-ens siste advarsel — «sendmail: not found»,
        // «Could not execute mail delivery program», eller hva serveren nna
        // sier. Uten dette blir «Leverandoren svarte med feil» staaende i
        // koen, og det hjelper ingen: eieren ser en rad som feilet uten aa
        // faa vite hvorfor.
        //
        // Funnet 13. september 2026. Eieren: «Naar noen melder seg paa kurs,
        // saa fikk jeg epost for, det gjor jeg ikke lenger.» Koen sa bare
        // «Leverandoren svarte med feil» paa hver eneste rad — $sisteFeil ble
        // satt av sendSmtp() og sendSms(), men aldri her.
        $foer = error_get_last();
        $ok = @mail($til, $emneKodet, $kropp, $headere, '-f' . $fra);
        if (!$ok) {
            $etter = error_get_last();
            $sagt = ($etter !== null && $etter !== $foer)
                ? trim((string) ($etter['message'] ?? ''))
                : '';
            self::$sisteFeil = 'SMTP er ikke satt opp, og serverens egen e-post tok ikke imot meldingen'
                . ($sagt !== '' ? ' — ' . $sagt : '');
            logg('E-post: serverens egen sending feilet', ['til' => $til, 'feil' => $sagt]);
        }
        return $ok;
    }

    /**
     * Enkel SMTP-klient. Ingen avhengigheter — webhotellet har ikke Composer.
     *
     * Stotter STARTTLS og AUTH LOGIN, som er det Domene.no og de fleste andre
     * bruker. Kaster ikke: feilen legges i feilloggen og meldingen blir
     * liggende i koen, som proever igjen.
     *
     * @param array<string,string> $headere
     */
    private static function sendSmtp(string $til, string $emne, string $kropp, array $headere, string $fra): bool
    {
        $vert    = (string) Config::hent('smtp_vert', '');
        $port    = (int) Config::hent('smtp_port', 587);
        $bruker  = (string) Config::hent('smtp_bruker', '');
        $passord = (string) Config::hent('smtp_passord', '');
        $sikker  = (string) Config::hent('smtp_sikkerhet', 'starttls'); // starttls | ssl | ingen

        $adresse = ($sikker === 'ssl' ? 'ssl://' : '') . $vert . ':' . $port;
        $sokk = @stream_socket_client($adresse, $eno, $estr, 15);
        if (!$sokk) {
            self::$sisteFeil = 'Fikk ikke kontakt med ' . $vert . ':' . $port . ' — ' . $estr;
            logg('SMTP: fikk ikke kontakt', ['vert' => $vert, 'feil' => $estr]);
            return false;
        }
        stream_set_timeout($sokk, 15);

        $les = static function () use ($sokk): string {
            $ut = '';
            while (($linje = fgets($sokk, 515)) !== false) {
                $ut .= $linje;
                // Siste linje i et svar har mellomrom paa plass fire, ikke bindestrek.
                if (strlen($linje) < 4 || $linje[3] !== '-') {
                    break;
                }
            }
            return $ut;
        };
        $si = static function (string $kommando) use ($sokk, $les): string {
            fwrite($sokk, $kommando . "\r\n");
            return $les();
        };
        $ok = static fn(string $svar, string $kode): bool => str_starts_with(trim($svar), $kode);

        $velkomst = $les();
        if (!$ok($velkomst, '220')) {
            self::$sisteFeil = 'Serveren svarte uventet ved oppkobling: ' . trim($velkomst);
            logg('SMTP: uventet velkomst', ['svar' => trim($velkomst)]);
            fclose($sokk);
            return false;
        }

        $navn = parse_url(Config::nettsted(), PHP_URL_HOST) ?: 'lissom.no';
        $evner = $si('EHLO ' . $navn);

        if ($sikker === 'starttls') {
            if (!$ok($si('STARTTLS'), '220')
                || !stream_socket_enable_crypto($sokk, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                self::$sisteFeil = 'STARTTLS feilet mot ' . $vert . '. Prøv smtp_sikkerhet => \'ssl\' og'
                    . ' smtp_port => 465. Holder det fram, kan sertifikatet gjelde et annet navn enn '
                    . $vert . '.';
                logg('SMTP: STARTTLS feilet', ['vert' => $vert]);
                fclose($sokk);
                return false;
            }
            $evner = $si('EHLO ' . $navn);
        }

        if ($bruker !== '') {
            // Serveren forteller i EHLO-svaret hvilke maater den godtar. Noen
            // tilbyr bare PLAIN, andre bare LOGIN.
            $harPlain = stripos($evner, 'PLAIN') !== false;
            $harLogin = stripos($evner, 'LOGIN') !== false;

            if ($harLogin || !$harPlain) {
                $si('AUTH LOGIN');
                $si(base64_encode($bruker));
                $svarAuth = $si(base64_encode($passord));
            } else {
                $svarAuth = $si('AUTH PLAIN ' . base64_encode("\0" . $bruker . "\0" . $passord));
            }

            if (!$ok($svarAuth, '235')) {
                // Serverens eget svar sier mer enn vi kan gjette. «535» betyr
                // som regel feil passord, «534» at kontoen krever noe mer.
                // 535 betyr som regel feil passord — men like ofte at man
                // staar mot feil server. Hos mange webhotell er utgaaende
                // server mail.<domenet>, ikke leverandorens egen smtp-adresse.
                self::$sisteFeil = 'Innloggingen ble avvist av ' . $vert . ': ' . trim($svarAuth)
                    . ' — sjekk smtp_bruker og smtp_passord, og at smtp_vert er riktig server'
                    . ' (står under kontodetaljer for e-postkontoen, ofte mail.dittdomene.no).';
                logg('SMTP: innlogging avvist', ['vert' => $vert, 'bruker' => $bruker, 'svar' => trim($svarAuth)]);
                fclose($sokk);
                return false;
            }
        }

        if (!$ok($si('MAIL FROM:<' . $fra . '>'), '250')) {
            self::$sisteFeil = 'Avsenderadressen ' . $fra . ' ble avvist. Den må være en konto du har hos leverandøren.';
            logg('SMTP: avsenderadressen ble avvist', ['fra' => $fra]);
            fclose($sokk);
            return false;
        }
        if (!$ok($si('RCPT TO:<' . $til . '>'), '250')) {
            self::$sisteFeil = 'Mottakeren ' . $til . ' ble avvist av serveren.';
            logg('SMTP: mottakeren ble avvist', ['til' => $til]);
            fclose($sokk);
            return false;
        }
        if (!$ok($si('DATA'), '354')) {
            fclose($sokk);
            return false;
        }

        $linjer = ['To: ' . $til, 'Subject: ' . $emne, 'Date: ' . gmdate('r')];
        foreach ($headere as $navnH => $verdi) {
            $linjer[] = $navnH . ': ' . $verdi;
        }
        // En enslig prikk avslutter meldingen, saa en prikk forst i en linje
        // maa dobles. Ellers ville teksten kunne kutte seg selv av.
        $tekst = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $kropp));

        $svar = $si(implode("\r\n", $linjer) . "\r\n\r\n" . $tekst . "\r\n.");
        $godtatt = $ok($svar, '250');
        if (!$godtatt) {
            self::$sisteFeil = 'Meldingen ble avvist: ' . trim($svar);
            logg('SMTP: meldingen ble avvist', ['til' => $til, 'svar' => trim($svar)]);
        }

        $si('QUIT');
        fclose($sokk);
        return $godtatt;
    }

    /**
     * SMS via Sveve. Norsk leverandør, ingen månedsavgift, betaling per melding.
     * Avsender vises som «Lissom» i stedet for et nummer.
     */
    /**
     * SMS. Leverandoren velges med sms_leverandor i secrets.php.
     *
     * Begge tar betalt per melding uten maanedsavgift, og begge er enkle
     * HTTP-kall. Aa bytte er derfor en linje i oppsettet, ikke en jobb —
     * poenget her er at prisen ikke skal laase oss til noen.
     */
    private static function sendSms(string $til, string $tekst): bool
    {
        if (self::holdtTilbake('SMS', $til, mb_substr($tekst, 0, 40))) {
            return true;
        }
        $leverandor = mb_strtolower((string) Config::hent('sms_leverandor', 'sveve'));

        return match ($leverandor) {
            'gatewayapi' => self::smsGatewayApi($til, $tekst),
            default      => self::smsSveve($til, $tekst),
        };
    }

    /** Sveve.no — norsk, betaling per melding. */
    private static function smsSveve(string $til, string $tekst): bool
    {
        $bruker = (string) Config::hent('sveve_bruker', '');
        $passord = (string) Config::hent('sveve_passord', '');

        if ($bruker === '' || $passord === '') {
            self::$sisteFeil = 'Sveve-noklene mangler i secrets.php.';
            logg('SMS ikke sendt — Sveve-nøkler mangler i secrets.php', ['til' => $til]);
            return false;
        }

        $svar = http_post_form('https://sveve.no/SMS/SendMessage', [
            'user'  => $bruker,
            'passwd'=> $passord,
            'to'    => $til,
            'msg'   => $tekst,
            'from'  => (string) Config::hent('sms_avsender', 'Lissom'),
            'f'     => 'json',
        ]);

        if ($svar['status'] !== 200) {
            self::$sisteFeil = 'Sveve svarte HTTP ' . $svar['status'] . '.';
            return false;
        }
        $d = json_decode($svar['kropp'], true);
        $ok = (int) ($d['response']['msgOkCount'] ?? 0) > 0;
        if (!$ok) {
            $grunn = (string) ($d['response']['errors'][0]['errorMessage'] ?? $svar['kropp']);
            self::$sisteFeil = 'Sveve avviste meldingen: ' . mb_substr($grunn, 0, 180);
            logg_feil('Sveve avviste SMS: ' . $svar['kropp']);
        }
        return $ok;
    }

    /**
     * GatewayAPI — dansk, ofte rimeligere til norske numre, ingen
     * maanedsavgift. Autentisering med en token som Bearer.
     */
    private static function smsGatewayApi(string $til, string $tekst): bool
    {
        $token = (string) Config::hent('gatewayapi_token', '');
        if ($token === '') {
            self::$sisteFeil = 'gatewayapi_token mangler i secrets.php.';
            logg('SMS ikke sendt — GatewayAPI-token mangler', ['til' => $til]);
            return false;
        }

        // Numrene sendes uten pluss, som E.164-siffer.
        $nummer = ltrim(normaliser_telefon($til), '+');
        if ($nummer === '') {
            self::$sisteFeil = 'Nummeret kunne ikke tolkes.';
            return false;
        }

        $svar = http_post_json('https://gatewayapi.com/rest/mtsms', [
            'sender'     => (string) Config::hent('sms_avsender', 'Lissom'),
            'message'    => $tekst,
            'recipients' => [['msisdn' => (int) $nummer]],
        ], ['Authorization: Bearer ' . $token]);

        if ($svar['status'] >= 200 && $svar['status'] < 300) {
            return true;
        }

        $grunn = (string) ($svar['json']['message'] ?? $svar['kropp']);
        self::$sisteFeil = 'GatewayAPI avviste meldingen (HTTP ' . $svar['status'] . '): ' . mb_substr($grunn, 0, 160);
        logg_feil('GatewayAPI avviste SMS: ' . $svar['kropp']);
        return false;
    }
}

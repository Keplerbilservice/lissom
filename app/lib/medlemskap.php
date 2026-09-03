<?php
/**
 * Medlemskap og manedstrekk.
 *
 * Medlemskapet ble gjort opp direkte med verkstedet. «Fornyes 1. september»
 * sto som fast tekst paa Min side — en paastand om et trekk som ikke fantes.
 *
 * Naa: kunden godkjenner en avtale i Vipps én gang, og avtalen belastes hver
 * maaned av cron. Hvert trekk blir en helt vanlig rad i payments, saa det
 * dukker opp i omsetningen og i kjopshistorikken som alt annet.
 *
 * Trekket kjores av cron og ikke av et sidevisning: ellers ville det avhengt
 * av at noen tilfeldigvis var innom nettsiden den dagen.
 */

declare(strict_types=1);

final class Medlemskap
{
    /** Sa mange dager for forfall ber vi Vipps om trekket. */
    private const VARSEL_DAGER = 3;

    /**
     * Hvilken utgave av medlemsvilkaarene som gjelder naa.
     *
     * Lagres sammen med samtykket ved innmelding. Uten den vet vi at noen
     * huket av, men ikke hva de huket av PAA — og vilkaar som kan endres uten
     * spor er ikke verdt mye den dagen noen er uenig.
     *
     * Datoen er den teksten sist ble endret. Endres vilkaarene, settes denne
     * opp samtidig, saa en rad fra i fjor peker paa teksten som gjaldt i fjor.
     */
    public const VILKAAR_VERSJON = '2026-09-03';

    /** @return array<string,mixed>|null */
    public static function plan(string $navn): ?array
    {
        return DB::en('SELECT * FROM membership_plans WHERE navn = :n AND aktiv = 1', ['n' => $navn]);
    }

    /**
     * Alle tabellene som peker paa et medlem, lest av basen selv.
     *
     * Lista skrives ikke for haand. Den som legger til en tabell med
     * «member_id» neste gang skal ikke trenge aa huske hverken
     * sammenslaaingen av dubletter eller nullstillingen — begge leser denne.
     *
     * @return list<array{tabell:string,kolonne:string}>
     */
    public static function pekere(): array
    {
        $ut = [];
        foreach (DB::alle(
            "SELECT table_name AS t, column_name AS k
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND column_name IN ('member_id', 'registrert_av')
                AND table_name <> 'members'
           ORDER BY table_name, column_name"
        ) as $r) {
            $t = (string) $r['t'];
            $k = (string) $r['k'];
            // Navnene kommer fra basen, ikke fra en forespoersel — men de
            // settes inn i SQL som identifikatorer, saa de sjekkes likevel.
            if (preg_match('/^[a-z_]+$/', $t) && preg_match('/^[a-z_]+$/', $k)) {
                $ut[] = ['tabell' => $t, 'kolonne' => $k];
            }
        }
        return $ut;
    }

    /** @return list<array<string,mixed>> */
    public static function planer(): array
    {
        return DB::alle('SELECT * FROM membership_plans WHERE aktiv = 1 ORDER BY sortering');
    }

    /**
     * Punktlista paa kortet, ett punkt per linje.
     *
     * Verkstedet skriver den i et vanlig tekstfelt. Tomme linjer og
     * kulepunkter de har skrevet selv fjernes — kortet setter sin egen prikk.
     *
     * @return list<string>
     */
    /**
     * Maa dette medlemskapet ha fast trekk?
     *
     * Aarsmedlemskapet bindes i tolv maaneder, og da er det avtalen som er
     * hele grunnlaget. De andre lar medlemmet velge selv.
     *
     * Kolonna kom med migrasjon 081. Er den ikke kjort, krever ingen plan
     * fast trekk — altsaa som for.
     *
     * @param array<string,mixed> $plan
     */
    public static function kreverFastTrekk(array $plan): bool
    {
        return (int) ($plan['krever_fast_trekk'] ?? 0) === 1;
    }

    public static function punkter(?string $raa): array
    {
        $ut = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $raa) ?: [] as $linje) {
            $linje = trim((string) preg_replace('/^[\s\-\*\x{2022}\x{00b7}]+/u', '', $linje));
            if ($linje !== '') {
                $ut[] = $linje;
            }
        }
        return $ut;
    }

    /**
     * Hvor mange timer i maaneden dette medlemmet har.
     *
     * «timer_per_mnd» paa medlemmet ble aldri fylt ut noe sted — den ble bare
     * lest. Alle sto derfor med NULL, som betyr fri tilgang, og Min side viste
     * ingen timeoversikt til noen. Poenget med et 30-timers medlemskap er
     * nettopp de tretti timene.
     *
     * Planen bestemmer, medlemsraden overstyrer: setter verkstedet et eget
     * timetall paa én person, gjelder det foran planen. NULL fra planen (Fri
     * tilgang) betyr fortsatt ingen grense.
     */
    public static function timerFor(array $medlem): ?int
    {
        if ($medlem['timer_per_mnd'] !== null) {
            return (int) $medlem['timer_per_mnd'];
        }
        $type = trim((string) ($medlem['medlemskap_type'] ?? ''));
        if ($type === '') {
            return null;
        }
        $plan = self::plan($type);
        return $plan === null || $plan['timer'] === null ? null : (int) $plan['timer'];
    }

    /**
     * Har medlemmet betalt for medlemskapet sitt?
     *
     * Skjermen viste «Fast trekk» eller «Gjor opp selv» — det er
     * betalingsMAATEN, ikke betalingen. Eieren, 2. september: «jeg kan ikke se
     * paa min side paa et medlem om det er betalt for medlemskapet eller
     * ikke». Tallene laa i basen hele tida; ingen slo dem opp.
     *
     * Regelen staar her og ikke i skjermene fordi tre steder spor om den:
     * medlemslista, kortet paa Oversikt og medlemsruta. Sto den tre steder,
     * kunne de svart hver sitt om den samme personen.
     *
     * @param array      $medlem  raden fra members
     * @param array|null $avtale  nyeste subscriptions-rad, eller null
     * @param array|null $siste   nyeste betalte medlemskapsbetaling, eller null
     * @param array|null $trekk   nyeste trekk paa avtalen uansett utfall, eller null
     * @return array{tilstand:string,tekst:string,forfalt:bool}
     *
     * tilstand er én av:
     *   fri         medlemmet skal ikke betale (haken i admin)
     *   betalt      det er gjort opp for perioden som loper
     *   bestilt     trekket er bedt om, men pengene har ikke flyttet seg enda
     *               — Vipps krever forvarsel, saa det tar noen dager
     *   forfalt     perioden er ute og det er ikke betalt
     *   venter      meldt inn, men foerste betaling er ikke kommet enda
     *   ingen       ikke medlem — ingenting aa betale for
     */
    public static function betalingsstatus(array $medlem, ?array $avtale, ?array $siste, ?array $trekk = null): array
    {
        // To forskjellige spoersmaal, og de ble blandet:
        //
        //   forfalt      — pengene skulle vaert her, og er det ikke. Roedt.
        //   utestaaende  — pengene er ikke inne. Kan vaere helt i orden
        //                  (trekket er bestilt, forfallet er ikke naadd), men
        //                  verkstedet skal likevel se det.
        //
        // Eieren, 2. september: «verken hun eller Eirin kommer opp i kortet
        // ikke betalt paa oversikten, og det maa de jo, helt til pengene er
        // inne». Eirin sto med et trekk som ikke var forfalt enda, og falt
        // dermed ut av tellingen — enda ingen krone hadde kommet.
        //
        // «utestaaende» folger «forfalt» naar den ikke settes: det som er
        // forfalt er alltid ogsaa utestaaende.
        $ut = static fn(string $t, string $tekst, bool $forfalt = false, ?bool $ute = null): array
            => ['tilstand' => $t, 'tekst' => $tekst, 'forfalt' => $forfalt,
                'utestaaende' => $ute === null ? $forfalt : $ute];

        // Haken gaar foran alt. Et gratismedlem skal aldri lyse roedt.
        if (!empty($medlem['betaler_ikke'])) {
            $grunn = trim((string) ($medlem['betaler_ikke_grunn'] ?? ''));
            return $ut('fri', $grunn !== '' ? 'Fri — ' . $grunn : 'Betaler ikke');
        }

        $status = (string) ($medlem['status'] ?? 'ingen');
        if (!in_array($status, ['prove', 'aktiv', 'pause'], true)) {
            return $ut('ingen', '');
        }

        $idag = gmdate('Y-m-d');
        $kort = static fn(string $d): string => Booking::norskDatoKort($d . ' 12:00:00');

        // ── Fast trekk i Vipps ──────────────────────────────────────────
        //
        // Her er det Vipps som trekker, og «neste_trekk» er fasiten paa om
        // perioden er dekket. Har dagen passert uten at «siste_trekk» fulgte
        // etter, gikk trekket ikke gjennom.
        //
        // Statusen maa vaere med. Et forsok som ble staaende paa «venter»
        // har ogsaa en avtale-id hos Vipps, men ingenting trekkes paa den —
        // den ble aldri godkjent. Merket sa likevel «Fast trekk». Eieren,
        // 3. september, om Eirin: «hun staar oppfort med fast trekk, men
        // ikke trukket». Se loepende().
        $fastTrekk = $avtale !== null
            && (string) ($avtale['status'] ?? '') === 'aktiv'
            && trim((string) ($avtale['vipps_agreement_id'] ?? '')) !== '';
        if ($fastTrekk) {
            $neste = (string) ($avtale['neste_trekk'] ?? '');
            $sist  = (string) ($avtale['siste_trekk'] ?? '');

            // ── Trekket, slik det faktisk gikk ──────────────────────────
            //
            // «siste_trekk» settes i det trekket BES OM. Vipps krever at
            // kunden varsles for et fast trekk, saa forfallet ligger noen
            // dager fram — og i mellomtida har ingen penger flyttet seg.
            // Leste vi bare den datoen, sto det «Betalt» om noe som bare var
            // bestilt, og det ble staaende ogsaa om trekket senere feilet.
            $tstatus = $trekk === null ? '' : (string) $trekk['status'];
            if ($tstatus === 'feilet' || $tstatus === 'avbrutt') {
                return $ut('forfalt', 'Trekket gikk ikke — prøvd '
                    . $kort(substr((string) $trekk['created_at'], 0, 10)), true);
            }
            if ($tstatus === 'opprettet' || $tstatus === 'venter') {
                return $ut('bestilt', 'Trekket er bestilt '
                    . $kort(substr((string) $trekk['created_at'], 0, 10))
                    . ' · venter på Vipps', false, true);
            }
            if ($tstatus === 'betalt' || $tstatus === 'delvis_refundert') {
                return $ut('betalt', 'Trukket '
                    . $kort(substr((string) $trekk['created_at'], 0, 10))
                    . ($neste !== '' ? ' · neste ' . $kort($neste) : ''));
            }

            // Ingen trekk aa se paa enda. Da er datoene alt vi har.
            if ($neste !== '' && $neste < $idag) {
                return $ut('forfalt', 'Skulle vært trukket ' . $kort($neste), true);
            }
            if ($sist !== '') {
                return $ut('betalt', 'Trukket ' . $kort($sist)
                    . ($neste !== '' ? ' · neste ' . $kort($neste) : ''));
            }
            // Avtalen er godkjent i Vipps, men ingen krone har flyttet seg.
            // Ikke roedt — det er ikke noe galt — men det skal telles.
            return $ut('venter',
                $neste !== '' ? 'Trekkes ' . $kort($neste) : 'Venter på første trekk',
                false, true);
        }

        // ── Gjor opp selv ───────────────────────────────────────────────
        //
        // Ingen avtale aa spore. Da er den siste registrerte betalingen det
        // eneste vi har — den som huker av i Kassa skriver den inn.
        if ($siste === null) {
            // ── Hvorfor er det ikke betalt? ─────────────────────────────
            //
            // Eieren, 2. september, om et medlem som sto som ubetalt: «denne
            // staar som ubetalt, mens eposten du sendte meg sier dette ...
            // Betaling: gjor opp selv».
            //
            // De to sier ikke det samme. «Gjor opp selv» er MAATEN — hun
            // betaler én periode om gangen i Vipps i stedet for fast trekk.
            // «Ikke betalt» er at pengene ikke er kommet. Begge kan vaere
            // sanne samtidig, og det er nettopp det som er tilfellet her.
            //
            // Innmeldingen oppretter betalingen i Vipps med det samme. Ligger
            // den og henger paa «venter», rakk hun aldri aa fullfore den — og
            // det er noe helt annet enn at ingen har begynt. Merket sa «Ikke
            // betalt ennaa» i begge tilfeller, altsaa det samme som merket
            // over det, og ingenting om hva som faktisk skjedde.
            $tstatus = $trekk === null ? '' : (string) $trekk['status'];
            if ($tstatus === 'opprettet' || $tstatus === 'venter') {
                return $ut('venter', 'Betalingen ble startet i Vipps '
                    . $kort(substr((string) $trekk['created_at'], 0, 10))
                    . ', men aldri fullført', true);
            }
            if ($tstatus === 'feilet' || $tstatus === 'avbrutt') {
                return $ut('venter', 'Betalingen gikk ikke gjennom '
                    . $kort(substr((string) $trekk['created_at'], 0, 10)), true);
            }
            $start = trim((string) ($medlem['start_dato'] ?? ''));
            return $ut('venter', $start !== ''
                ? 'Ingen betaling registrert · medlem siden ' . $kort($start)
                : 'Ingen betaling registrert', true);
        }
        $betaltDen = substr((string) $siste['created_at'], 0, 10);

        // Proveperioden betales én gang og loper til slutt_dato. Da er det
        // ikke noe mer aa betale, og den skal ikke forfalle hver maaned.
        $plan = self::plan((string) ($medlem['medlemskap_type'] ?? ''));
        if ($plan !== null && (int) ($plan['engangs'] ?? 0) === 1) {
            return $ut('betalt', 'Betalt ' . $kort($betaltDen));
        }

        // Loepende medlemskap: betalingen dekker én maaned fram.
        $dekkerTil = gmdate('Y-m-d', strtotime($betaltDen . ' +1 month'));
        if ($dekkerTil < $idag) {
            return $ut('forfalt', 'Forfalt ' . $kort($dekkerTil)
                . ' · sist betalt ' . $kort($betaltDen), true);
        }
        return $ut('betalt', 'Betalt ' . $kort($betaltDen) . ' · neste ' . $kort($dekkerTil));
    }

    /**
     * Siste trekk per avtale — uansett hvordan det gikk.
     *
     * sisteBetalinger() under teller bare det som ER betalt. Til «har hun
     * betalt?» trengs ogsaa det som er BESTILT og ikke gjort opp enda, og det
     * som feilet. «subscriptions.siste_trekk» duger ikke: den settes i det
     * trekket bes om, ikke naar pengene kommer.
     *
     * Uten dette sa admin «BETALT · Trukket 2. september» i de tre-fire
     * dagene mellom bestilling og oppgjor — og fortsatte aa si det om trekket
     * senere feilet. Det er den samme forvekslingen medlemmet Eirin ble
     * utsatt for, bakt inn i verkstedets egen oversikt.
     *
     * @param int[] $abonnementIder
     * @return array<int,array<string,mixed>>
     */
    public static function sisteTrekk(array $abonnementIder): array
    {
        if ($abonnementIder === []) {
            return [];
        }
        $inn = implode(',', array_map('intval', $abonnementIder));
        $ut = [];
        foreach (DB::alle(
            "SELECT p.subscription_id, p.status, p.created_at, p.belop_ore
               FROM payments p
               JOIN (SELECT subscription_id, MAX(id) AS siste
                       FROM payments
                      WHERE formal = 'medlemskap'
                        AND annullert_at IS NULL
                        AND subscription_id IN ({$inn})
                   GROUP BY subscription_id) n ON n.siste = p.id"
        ) as $r) {
            $ut[(int) $r['subscription_id']] = $r;
        }
        return $ut;
    }

    /**
     * Siste betalte medlemskapsbetaling, per medlem.
     *
     * Ett oppslag for hele lista. Ett per medlem ville blitt fem hundre
     * sporringer paa medlemsskjermen.
     *
     * @param int[] $medlemIder
     * @return array<int,array<string,mixed>>
     */
    public static function sisteBetalinger(array $medlemIder): array
    {
        if ($medlemIder === []) {
            return [];
        }
        $inn = implode(',', array_map('intval', $medlemIder));
        $ut = [];
        foreach (DB::alle(
            "SELECT p.member_id, p.created_at, p.belop_ore, p.maate, p.type
               FROM payments p
               JOIN (SELECT member_id, MAX(id) AS siste
                       FROM payments
                      WHERE formal = 'medlemskap'
                        AND status IN ('betalt','delvis_refundert')
                        AND annullert_at IS NULL
                        AND member_id IN ({$inn})
                   GROUP BY member_id) n ON n.siste = p.id"
        ) as $r) {
            $ut[(int) $r['member_id']] = $r;
        }
        return $ut;
    }

    /**
     * Avtalen et medlem har i spill naa, eller null.
     *
     * «venter» er med: en avtale kunden nettopp er sendt til Vipps for aa
     * godkjenne, er den avtalen vi skal sporre Vipps om. Godkjenner hun i
     * appen uten aa komme tilbake til nettsida, er det denne raden Min side
     * finner naar hun trykker «sjekk».
     *
     * Merk: «i spill» er ikke det samme som «loeper». Til pris, plan og
     * «fast trekk» skal bare en avtale som faktisk trekker telle — se
     * loepende().
     */
    public static function avtale(int $medlemId): ?array
    {
        return DB::en(
            "SELECT * FROM subscriptions
              WHERE member_id = :m AND status IN ('venter','aktiv')
              ORDER BY id DESC LIMIT 1",
            ['m' => $medlemId]
        );
    }

    /**
     * Avtalen som faktisk loeper, eller null.
     *
     * ── Et forsok som aldri ble godkjent er ingen avtale ──────────────
     *
     * «venter» settes naar vi sender kunden til Vipps. Godkjenner hun, blir
     * raden «aktiv» — det skjer i oppdaterFraVipps(), etter at vi har spurt
     * Vipps. Snur hun i doera, blir raden staaende paa «venter» for alltid.
     *
     * Den raden ble likevel lest som medlemmets avtale: den bestemte prisen
     * hun sto som skyldig, og hvilket medlemskap hun sto paa. Eirin forsokte
     * fast trekk, godkjente aldri, og satt igjen med en rad som fortsatte aa
     * si «Basis 30» og prisen paa den — ogsaa etter at medlemskapet hennes
     * ble byttet.
     *
     * Eieren, 4. september: «jeg byttet medlemskap for eirin, men det endrer
     * ikke pris», og «hun fikk jo aldri betalt eller har aldri godkjent saa
     * nullstill denne».
     */
    public static function loepende(int $medlemId): ?array
    {
        return DB::en(
            "SELECT * FROM subscriptions
              WHERE member_id = :m AND status = 'aktiv'
              ORDER BY id DESC LIMIT 1",
            ['m' => $medlemId]
        );
    }

    /**
     * Starter en avtale i Vipps og lagrer den som «venter».
     *
     * Den blir ikke aktiv her. Det skjer forst naar kunden har godkjent i
     * Vipps og vi har spurt Vipps om status — vi stoler ikke paa at kunden
     * kom tilbake til riktig side.
     *
     * @return array{url:string,id:int}
     */
    /**
     * Et paagaaende innmeldingsforsoek paa den samme planen, eller null.
     *
     * Eieren, 2. september: e-posten «Nytt medlem» kom to ganger, i det samme
     * minuttet. api/bli-medlem.php hadde ingen vakt mot at det samme forsoeket
     * kom to ganger — vakta under slaar bare til paa en avtale som ER aktiv,
     * og en avtale som staar «venter» stopper ingenting. Andre gang lagde
     * derfor en avtale til i Vipps, en soknadsrad til, og alle varslene om
     * igjen. To avtaler er verre enn to e-poster: det er to trekk.
     *
     * Vinduet er kort med vilje. Fem minutter dekker et dobbeltklikk og en
     * tilbakeknapp fra Vipps. Lenger, og en som virkelig vil proeve paa nytt
     * ville sittet fast med en adresse som kanskje er utloept hos Vipps.
     *
     * Planen er med i oppslaget: bytter man medlemskap i mellomtida, er det
     * et annet forsoek, og da skal det opprettes paa nytt.
     *
     * @return array<string,mixed>|null
     */
    public static function paagaaendeForsok(int $medlemId, string $planNavn, bool $medAvtale): ?array
    {
        if (!DB::harKolonne('subscriptions', 'vipps_url')) {
            return null;
        }
        // ── Betalingsmaaten maa vaere med i oppslaget ────────────────
        //
        // Eieren, 3. september: «Eirin forsokte aa betale med vanlig vipps,
        // men hun fikk kun alternativet fast trekk. selv om hun valgte noe
        // annet i losningen vaart».
        //
        // Vakta sa bare medlem + plan + «venter» + under fem minutter. Den
        // sa ikke HVA slags forsok det var. Da traff en engangsbetaling
        // avtaleforsoket fra minuttet for, og fikk avtalens adresse tilbake:
        //
        //   1. hun trykker med «Fast trekk» — som sto forhaandsvalgt —
        //      og en avtale opprettes i Vipps
        //   2. hun gaar tilbake, velger «Betal i Vipps», trykker igjen
        //   3. startEngangs() finner avtalen fra punkt 1 og sender henne
        //      til den samme fast-trekk-skjermen
        //
        // I fem minutter kunne hun ikke komme til vanlig Vipps uansett hva
        // hun valgte. Skillet staar i «vipps_agreement_id»: en avtale har
        // en, en engangsbetaling har NULL — se startEngangs().
        $avtaleLedd = $medAvtale
            ? 'AND vipps_agreement_id IS NOT NULL'
            : 'AND vipps_agreement_id IS NULL';
        return DB::en(
            "SELECT * FROM subscriptions
              WHERE member_id = :m AND plan = :p AND status = 'venter'
                {$avtaleLedd}
                AND vipps_url IS NOT NULL AND vipps_url <> ''
                AND created_at >= (UTC_TIMESTAMP() - INTERVAL 5 MINUTE)
           ORDER BY id DESC LIMIT 1",
            ['m' => $medlemId, 'p' => $planNavn]
        );
    }

    /**
     * Rydder bort forsoeket hun gikk fra da hun byttet betalingsmaate.
     *
     * Uten dette blir raden fra punkt 1 over liggende som «venter», med en
     * ekte avtale-id hos Vipps. Skjermen hun forlot er fortsatt gyldig:
     * godkjenner hun den senere — en fane som sto aapen, en lenke i
     * historikken — har hun et loepende trekk hun ikke ba om, ved siden av
     * engangsbetalingen hun faktisk valgte.
     *
     * Bare forsoek som staar «venter» roeres. En avtale som er aktiv er et
     * medlemskap, og det sies opp fra Min side — ikke her.
     *
     * Feiler Vipps, skal det ikke stoppe betalingen hun holder paa med. Da
     * er raden merket stoppet hos oss, og loggen sier hva som ikke gikk.
     */
    private static function avlysMotsattForsok(int $medlemId, string $planNavn, bool $medAvtale): void
    {
        $motsatt = self::paagaaendeForsok($medlemId, $planNavn, !$medAvtale);
        if ($motsatt === null) {
            return;
        }

        $avtaleId = trim((string) ($motsatt['vipps_agreement_id'] ?? ''));
        if ($avtaleId !== '') {
            try {
                Vipps::stoppAvtale($avtaleId);
            } catch (Throwable $e) {
                logg_feil('Fikk ikke stoppet forlatt avtaleforsøk ' . $avtaleId, $e);
            }
        } else {
            // En engangsbetaling. Referansen ligger paa betalingsraden.
            $ref = (string) DB::verdi(
                'SELECT vipps_reference FROM payments
                  WHERE subscription_id = :s ORDER BY id DESC LIMIT 1',
                ['s' => (int) $motsatt['id']]
            );
            if ($ref !== '') {
                try {
                    Vipps::avbryt($ref);
                } catch (Throwable $e) {
                    logg_feil('Fikk ikke avbrutt forlatt betalingsforsøk ' . $ref, $e);
                }
                DB::oppdater('payments', ['status' => 'avbrutt'],
                    ['vipps_reference' => $ref]);
            }
        }

        DB::oppdater('subscriptions', ['status' => 'stoppet'], ['id' => (int) $motsatt['id']]);
    }

    /** Lagrer adressen forsoeket godkjennes paa, om kolonna finnes. */
    private static function husk(int $abonnementId, string $url): void
    {
        if ($url !== '' && DB::harKolonne('subscriptions', 'vipps_url')) {
            DB::oppdater('subscriptions', ['vipps_url' => mb_substr($url, 0, 500)], ['id' => $abonnementId]);
        }
    }

    public static function startAvtale(array $medlem, string $planNavn): array
    {
        $plan = self::plan($planNavn);
        if ($plan === null) {
            throw new RuntimeException('Ukjent medlemskap.');
        }

        // Har medlemmet en avtale fra for, skal den ikke bli staaende ved
        // siden av den nye. Da ville de blitt trukket to ganger.
        $fra = self::avtale((int) $medlem['id']);
        if ($fra !== null && $fra['status'] === 'aktiv') {
            throw new RuntimeException('Du har alt et medlemskap. Si det opp først, eller bytt fra Min side.');
        }

        // Det samme forsoeket to ganger skal gi den samme avtalen, ikke to.
        $igjen = self::paagaaendeForsok((int) $medlem['id'], $planNavn, true);
        if ($igjen !== null) {
            return ['url' => (string) $igjen['vipps_url'], 'id' => (int) $igjen['id'],
                    'gjentakelse' => true];
        }
        // Byttet hun fra «Betal i Vipps» til fast trekk, skal den forlatte
        // betalingen ikke bli staaende og kunne gjennomfores i tillegg.
        self::avlysMotsattForsok((int) $medlem['id'], $planNavn, true);

        $vipps = Vipps::opprettAvtale(
            $planNavn,
            (int) $plan['pris_ore'],
            'Medlemskap hos Lissom Keramikk — ' . $planNavn,
            Config::nettsted() . '/api/vipps-avtale-retur.php',
            $medlem['telefon'] ?? null
        );

        if ($vipps['avtaleId'] === '' || $vipps['url'] === '') {
            throw new RuntimeException('Vipps ga ikke noen avtale tilbake.');
        }

        $binding = (int) $plan['binding_mnd'];

        $id = DB::settInn('subscriptions', [
            'member_id'          => (int) $medlem['id'],
            'plan'               => $planNavn,
            'pris_ore'           => (int) $plan['pris_ore'],
            'vipps_agreement_id' => $vipps['avtaleId'],
            'status'             => 'venter',
            'binding_til'        => $binding > 0
                ? (new DateTimeImmutable('now'))->modify('+' . $binding . ' months')->format('Y-m-d')
                : null,
        ]);

        self::husk((int) $id, (string) $vipps['url']);
        return ['url' => $vipps['url'], 'id' => $id, 'gjentakelse' => false];
    }

    /**
     * Medlemskap uten fast trekk: én betaling i Vipps for forste periode.
     *
     * Eieren: «de skal ha fast trekk eller haandtere selv». Velger medlemmet
     * aa haandtere selv, skal det likevel betales med det samme — det er bare
     * de senere periodene verkstedet krever inn for haand.
     *
     * Raden i «subscriptions» faar ingen avtale-id og ingen trekkdato. Da
     * rorer oppdaterFraVipps() den ikke, og tilTrekk() henter den aldri — det
     * kommer altsaa ingen automatiske trekk. Medlemskapet blir aktivt naar
     * betalingen er i havn, i Booking::markerBetalt().
     *
     * @return array{url:string,id:int}
     */
    public static function startEngangs(array $medlem, string $planNavn): array
    {
        $plan = self::plan($planNavn);
        if ($plan === null) {
            throw new RuntimeException('Ukjent medlemskap.');
        }
        $fra = self::avtale((int) $medlem['id']);
        if ($fra !== null && $fra['status'] === 'aktiv') {
            throw new RuntimeException('Du har alt et medlemskap. Si det opp først, eller bytt fra Min side.');
        }

        // Samme vakt som i startAvtale(): det samme forsoeket to ganger skal
        // gi den samme betalingen, ikke to.
        $igjen = self::paagaaendeForsok((int) $medlem['id'], $planNavn, false);
        if ($igjen !== null) {
            return ['url' => (string) $igjen['vipps_url'], 'id' => (int) $igjen['id'],
                    'gjentakelse' => true];
        }
        // Og avtaleforsoeket hun gikk fra da hun valgte vanlig Vipps.
        self::avlysMotsattForsok((int) $medlem['id'], $planNavn, false);

        $binding = (int) $plan['binding_mnd'];
        $id = DB::settInn('subscriptions', [
            'member_id'          => (int) $medlem['id'],
            'plan'               => $planNavn,
            'pris_ore'           => (int) $plan['pris_ore'],
            // NULL, ikke tom streng.
            //
            // Kolonna har UNIQUE KEY uq_subs_agreement. To rader med '' er
            // to like verdier, og den andre avvises:
            //
            //   SQLSTATE[23000]: Integrity constraint violation: 1062
            //   Duplicate entry '' for key 'uq_subs_agreement'
            //
            // NULL teller ikke som en verdi i en unik noekkel, saa flere
            // rader kan staa uten avtale — som er nettopp det som gjelder
            // her: en engangsbetaling har ingen avtale i Vipps.
            //
            // Feilen slo inn fra og med den ANDRE gangen noen betalte paa
            // denne maaten. Den forste raden gikk gjennom og ble staaende;
            // alle etter den traff den. Eieren, 2. september, da han provde
            // aa betale for medlemskapet sitt.
            //
            // Alle stedene som leser kolonna taaler NULL fra for: de gjor
            // enten «if ($avtale['vipps_agreement_id'])» eller
            // «trim((string) ($p['vipps_agreement_id'] ?? ''))».
            //
            // Migrasjon 125 setter den ene raden som alt staar med '' til
            // NULL, saa den slutter aa sperre.
            'vipps_agreement_id' => null,
            'status'             => 'venter',
            'binding_til'        => $binding > 0
                ? (new DateTimeImmutable('now'))->modify('+' . $binding . ' months')->format('Y-m-d')
                : null,
        ]);

        $referanse = Vipps::nyReferanse('MED');
        $betalingId = DB::settInn('payments', [
            'vipps_reference' => $referanse,
            'type'            => 'epayment',
            'formal'          => 'medlemskap',
            'member_id'       => (int) $medlem['id'],
            'subscription_id' => $id,
            'belop_ore'       => (int) $plan['pris_ore'],
            'status'          => 'opprettet',
            // Denne manglet, og «payments.idempotency_key» er NOT NULL uten
            // standardverdi. Innsettingen kastet derfor hver eneste gang:
            // hele veien for medlemskap som betales én gang — «Prov Lissom»,
            // og alle som valgte «ordner selv» — endte i en databasefeil for
            // Vipps i det hele tatt ble kontaktet. Alle de andre stedene som
            // oppretter en betaling setter noekkelen; dette var det ene som
            // ikke gjorde det.
            'idempotency_key' => Vipps::uuid(),
        ]);

        try {
            $betaling = Vipps::opprettBetaling(
                $referanse,
                (int) $plan['pris_ore'],
                'Medlemskap hos Lissom — ' . $planNavn,
                Config::nettsted() . '/api/betaling-retur.php?ref=' . rawurlencode($referanse),
                $medlem['telefon'] ?? null
            );
        } catch (Throwable $e) {
            // Betalingen kom aldri i gang. Da skal det ikke ligge igjen et
            // halvt medlemskap som ser ut som om noen venter paa aa betale.
            DB::oppdater('payments', ['status' => 'feilet'], ['id' => $betalingId]);
            DB::kjor('DELETE FROM subscriptions WHERE id = :i', ['i' => $id]);
            logg_feil('Fikk ikke startet medlemsbetaling for medlem ' . $medlem['id'], $e);
            throw new RuntimeException('Fikk ikke startet betalingen. Prøv igjen om litt.');
        }

        DB::oppdater('payments', ['status' => 'venter'], ['id' => $betalingId]);
        self::husk((int) $id, (string) $betaling['url']);
        return ['url' => $betaling['url'], 'id' => $id, 'gjentakelse' => false];
    }

    /**
     * Slaar paa et medlemskap som er betalt med én betaling.
     *
     * Kalles fra Booking::markerBetalt(). Ingen trekkdato settes — det er
     * nettopp poenget med denne maaten: verkstedet krever inn de neste
     * periodene selv.
     */
    public static function betaltEngangs(int $abonnementId): void
    {
        $a = DB::en('SELECT * FROM subscriptions WHERE id = :i', ['i' => $abonnementId]);
        if ($a === null || $a['status'] === 'aktiv') {
            return;
        }
        DB::oppdater('subscriptions', ['status' => 'aktiv', 'neste_trekk' => null], ['id' => $abonnementId]);
        DB::oppdater('members', [
            'status'          => 'aktiv',
            'medlemskap_type' => (string) $a['plan'],
            'start_dato'      => DB::verdi('SELECT start_dato FROM members WHERE id = :m', ['m' => (int) $a['member_id']])
                                  ?: gmdate('Y-m-d'),
        ], ['id' => (int) $a['member_id']]);
    }

    /**
     * Spor Vipps om status og setter avtalen deretter.
     *
     * Kalles bade naar kunden kommer tilbake og fra cron. Den er trygg aa
     * kjore flere ganger.
     */
    public static function oppdaterFraVipps(array $avtale): string
    {
        $id = (string) $avtale['vipps_agreement_id'];
        if ($id === '') {
            return (string) $avtale['status'];
        }

        try {
            $svar = Vipps::hentAvtale($id);
        } catch (Throwable $e) {
            logg_feil('Fikk ikke hentet avtale ' . $id, $e);
            return (string) $avtale['status'];
        }

        $vippsStatus = strtoupper((string) ($svar['status'] ?? ''));

        $ny = match ($vippsStatus) {
            'ACTIVE'  => 'aktiv',
            'STOPPED' => 'stoppet',
            'EXPIRED' => 'utlopt',
            'PENDING' => 'venter',
            default   => 'avslaatt',
        };

        $endring = ['status' => $ny];

        // Ligger det en soknad til behandling, holdes trekket igjen.
        //
        // Soknaden oppretter avtalen med det samme, saa ingen kommer inn uten
        // aa ha betalingen paa plass. Men verkstedet skal fortsatt kunne si
        // nei — og da skal ingen ha blitt trukket. Forste trekk slippes av
        // godkjenningen i admin, ikke av at kunden trykket ja i Vipps.
        // Bare soknader som faktisk venter paa en avtale. En gammel soknad
        // der medlemmet gjor opp selv skal ikke holde igjen noe.
        $harKol = DB::harKolonne('membership_applications', 'betaling');
        $venterSvar = (int) DB::verdi(
            "SELECT COUNT(*) FROM membership_applications
              WHERE member_id = :m AND status = 'venter'"
            . ($harKol ? " AND betaling = 'trekk'" : ''),
            ['m' => (int) $avtale['member_id']]
        ) > 0;

        // Forste trekk settes naar avtalen blir aktiv. Vi trekker fra dagen
        // etter godkjenning, ikke fra den 1. — da slipper vi aa forklare
        // hvorfor noen betaler full pris for en halv maaned.
        if ($ny === 'aktiv' && $avtale['neste_trekk'] === null && !$venterSvar) {
            $endring['neste_trekk'] = (new DateTimeImmutable('now'))->format('Y-m-d');
        }
        if ($ny !== 'aktiv') {
            $endring['neste_trekk'] = null;
        }

        DB::oppdater('subscriptions', $endring, ['id' => (int) $avtale['id']]);

        // Medlemsstatusen folger avtalen. Uten dette ville noen betalt uten aa
        // faa tilgang, eller hatt tilgang uten aa betale.
        if ($ny === 'aktiv' && !$venterSvar) {
            DB::oppdater('members', [
                'status'          => 'aktiv',
                'medlemskap_type' => $avtale['plan'],
                'start_dato'      => DB::verdi('SELECT start_dato FROM members WHERE id = :m', ['m' => $avtale['member_id']])
                                      ?: gmdate('Y-m-d'),
            ], ['id' => (int) $avtale['member_id']]);
        } elseif (in_array($ny, ['stoppet', 'utlopt'], true)) {
            DB::oppdater('members', ['status' => 'oppsagt'], ['id' => (int) $avtale['member_id']]);
        }

        return $ny;
    }

    /**
     * Slipper forste trekk paa en avtale som har ventet paa godkjenning.
     *
     * Soknaden oppretter avtalen med det samme, men holder trekket igjen til
     * verkstedet har sagt ja (se oppdaterFraVipps). Denne kalles av
     * godkjenningen: den sporr Vipps om avtalen faktisk er godkjent, og setter
     * forste trekk til i dag hvis den er det.
     *
     * @return array{status:string,avtale:?array<string,mixed>}
     */
    public static function slippForsteTrekk(int $medlemId): array
    {
        $a = DB::en(
            'SELECT * FROM subscriptions WHERE member_id = :m ORDER BY id DESC LIMIT 1',
            ['m' => $medlemId]
        );
        if ($a === null) {
            return ['status' => 'ingen', 'avtale' => null];
        }

        // Fasiten er Vipps, ikke raden vaar. Kunden kan ha godkjent uten aa
        // komme tilbake til nettsiden.
        $status = self::oppdaterFraVipps($a);
        if ($status !== 'aktiv') {
            return ['status' => $status, 'avtale' => $a];
        }

        $a = DB::en('SELECT * FROM subscriptions WHERE id = :id', ['id' => (int) $a['id']]);
        if ($a !== null && $a['neste_trekk'] === null) {
            DB::oppdater('subscriptions', [
                'neste_trekk' => (new DateTimeImmutable('now'))->format('Y-m-d'),
            ], ['id' => (int) $a['id']]);
        }
        return ['status' => 'aktiv', 'avtale' => $a];
    }

    /**
     * Er engangsbetalingen for medlemskapet i havn?
     *
     * Eieren, 1. september: «Hun fikk medlemskap selv om betalingen ikke gikk
     * inn hva faen».
     *
     * Han hadde rett. Godkjenningen i admin sjekket bare avtalen i Vipps naar
     * sokeren hadde valgt fast trekk. Valgte hen «ordner selv», ble ingenting
     * sjekket i det hele tatt: ett trykk paa Godkjenn ga full tilgang, og
     * svaret paa skjermen sa «gjor opp selv for hver periode» — som om alt
     * var i orden. Ingen sto igjen med en beskjed om at foerste betaling
     * aldri kom.
     *
     * Denne er motstykket til slippForsteTrekk(), og folger samme regel:
     * fasiten er Vipps, ikke raden vaar. Kunden kan ha betalt uten aa komme
     * tilbake til nettsiden, og da skal ikke godkjenningen stoppes.
     *
     * Svarene:
     *   aktiv    betalingen er i havn
     *   ingen    det finnes ingen betaling aa se paa — en gammel soknad,
     *            fra for innmeldingen krevde noe. Da maa den kreves inn
     *            paa annen maate, og det skal staa i svaret.
     *   ellers   status paa betalingen slik den staar naa
     *
     * @return array{status:string,avtale:array<string,mixed>|null}
     */
    public static function engangsBetalt(int $medlemId): array
    {
        $a = DB::en(
            'SELECT * FROM subscriptions WHERE member_id = :m ORDER BY id DESC LIMIT 1',
            ['m' => $medlemId]
        );
        if ($a === null) {
            return ['status' => 'ingen', 'avtale' => null];
        }
        if ((string) $a['status'] === 'aktiv') {
            return ['status' => 'aktiv', 'avtale' => $a];
        }

        $betaling = DB::en(
            'SELECT * FROM payments WHERE subscription_id = :s ORDER BY id DESC LIMIT 1',
            ['s' => (int) $a['id']]
        );
        if ($betaling === null) {
            return ['status' => 'ingen', 'avtale' => $a];
        }
        // Er den alt bokfoert som betalt, men medlemskapet ikke slaatt paa,
        // retter vi det her framfor aa avvise en som har betalt.
        if ((string) $betaling['status'] === 'betalt') {
            self::betaltEngangs((int) $a['id']);
            return ['status' => 'aktiv', 'avtale' => $a];
        }

        // Sporr Vipps. Cron gjor det samme, men bare de forste to dognene og
        // tre av gangen — en soknad som blir liggende en uke ville ellers
        // blitt avvist selv om pengene kom.
        $ref = (string) ($betaling['vipps_reference'] ?? '');
        if ($ref !== '') {
            try {
                $svar = Vipps::hentBetaling($ref);
                $tilstand = strtoupper((string) ($svar['state'] ?? ''));
                if ($tilstand === 'AUTHORIZED') {
                    Vipps::trekk($ref, (int) ($svar['aggregate']['authorizedAmount']['value'] ?? 0));
                    $tilstand = 'CAPTURED';
                }
                if ($tilstand === 'CAPTURED') {
                    Booking::markerBetalt($ref);
                    return ['status' => 'aktiv', 'avtale' => $a];
                }
            } catch (Throwable $e) {
                // Naar vi ikke faar svar fra Vipps, vet vi ikke — og da skal
                // ingen slippes inn paa en antakelse.
                logg_feil('Fikk ikke sjekket medlemsbetaling for medlem ' . $medlemId, $e);
                return ['status' => 'ukjent', 'avtale' => $a];
            }
        }

        return ['status' => (string) $betaling['status'], 'avtale' => $a];
    }

    /**
     * Hvorfor et medlemskap ikke kan sies opp naa — eller null naar det kan.
     *
     * To regler:
     *
     *   Bindingstid. To maaneder fra innmelding, tolv paa aarsavtalen. Den
     *   staar i «binding_til», satt da avtalen ble opprettet.
     *
     *   Én oppsigelse om gangen. Er den alt sagt opp, staar sluttdatoen.
     *
     * @param array<string,mixed> $avtale
     */
    public static function hvorforIkkeSiOpp(array $avtale): ?string
    {
        if ($avtale['slutter'] !== null) {
            return 'Medlemskapet er alt sagt opp, og gjelder ut '
                . Booking::norskDatoKort((string) $avtale['slutter'] . ' 12:00:00') . '.';
        }
        $binding = $avtale['binding_til'] ?? null;
        if ($binding !== null && (string) $binding >= gmdate('Y-m-d')) {
            $plan = self::plan((string) $avtale['plan']);
            $aar = $plan !== null && (int) $plan['binding_mnd'] >= 12;
            return ($aar
                ? 'Årsavtalen kan ikke sies opp før året er ute. Den løper til '
                : 'Medlemskapet er bundet til ')
                . Booking::norskDatoKort((string) $binding . ' 12:00:00')
                . '. Ta kontakt om noe har endret seg, så finner vi ut av det.';
        }
        return null;
    }

    /**
     * Siste dag et medlemskap gjelder naar det sies opp i dag.
     *
     * Eieren, 29. august: «settes til den siste dagen i maaneden man sier opp,
     * pluss oppsigelsestiden». Sier noen opp 14. september med én maaneds
     * oppsigelse, gjelder medlemskapet altsaa ut oktober — ikke til 14.
     * oktober.
     *
     * Den forrige regelen la maanedene rett paa dagen i dag. To som sa opp
     * samme maaned fikk da hver sin sluttdato, og trekket gikk et halvt
     * intervall inn i en maaned ingen hadde bedt om.
     *
     * Regnestykket gaar via den foerste i maaneden, ikke den siste: «siste
     * dag i denne maaneden» pluss én maaned gir 30. oktober naar man starter
     * paa 30. september, for PHP teller maaneder fra dagen. Foerste i
     * maaneden pluss (N+1) maaneder, minus én dag, treffer alltid den siste —
     * ogsaa i februar.
     *
     * Datoen regnes i norsk tid. Serveren staar i UTC, og en oppsigelse
     * levert 1. oktober klokka 00:30 norsk tid ville ellers telt som
     * september.
     */
    public static function sluttdato(array $avtale): string
    {
        $plan = self::plan((string) $avtale['plan']);
        $mnd = $plan === null ? 1 : max(0, (int) ($plan['oppsigelse_mnd'] ?? 1));
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))
            ->modify('first day of this month')
            ->modify('+' . ($mnd + 1) . ' months')
            ->modify('-1 day')
            ->format('Y-m-d');
    }

    /**
     * Sier opp — med oppsigelsestid.
     *
     * Her ble avtalen stoppet i Vipps med det samme, og medlemmet mistet
     * tilgangen samme sekund. Med én maaneds oppsigelsestid er det feil begge
     * veier: medlemmet har betalt for en maaned til, og verkstedet skal ha den
     * maaneden.
     *
     * Naa settes bare sluttdatoen. Avtalen loper videre, trekket gaar som for,
     * og cron stopper den den dagen den skal — se Medlemskap::tilAvslutning().
     */
    public static function siOpp(array $avtale): void
    {
        $hindring = self::hvorforIkkeSiOpp($avtale);
        if ($hindring !== null) {
            throw new RuntimeException($hindring);
        }
        DB::oppdater('subscriptions', [
            'sagt_opp_at' => gmdate('Y-m-d H:i:s'),
            'slutter'     => self::sluttdato($avtale),
        ], ['id' => (int) $avtale['id']]);
    }

    /**
     * Medlemskap der oppsigelsestida er ute. Kjores av cron.
     *
     * @return list<array<string,mixed>>
     */
    public static function tilAvslutning(): array
    {
        return DB::alle(
            "SELECT * FROM subscriptions
              WHERE slutter IS NOT NULL AND slutter <= CURDATE()
                AND status <> 'stoppet'"
        );
    }

    /** Stopper et medlemskap som har gaatt ut oppsigelsestida si. */
    public static function avslutt(array $avtale): void
    {
        if ($avtale['vipps_agreement_id']) {
            try {
                Vipps::stoppAvtale((string) $avtale['vipps_agreement_id']);
            } catch (Throwable $e) {
                logg_feil('Fikk ikke stoppet avtale ' . $avtale['id'] . ' i Vipps', $e);
            }
        }
        DB::oppdater('subscriptions', [
            'status'      => 'stoppet',
            'neste_trekk' => null,
        ], ['id' => (int) $avtale['id']]);
        DB::oppdater('members', ['status' => 'oppsagt'], ['id' => (int) $avtale['member_id']]);
    }

    /**
     * Avtaler som skal belastes naa. Kjores av cron.
     *
     * @return list<array<string,mixed>>
     */
    public static function tilTrekk(): array
    {
        return DB::alle(
            "SELECT s.*, m.navn, m.epost, m.telefon
               FROM subscriptions s
               JOIN members m ON m.id = s.member_id
              WHERE s.status = 'aktiv'
                AND s.neste_trekk IS NOT NULL
                AND s.neste_trekk <= CURDATE()
                -- Er det sagt opp, trekkes det ikke for en periode som
                -- begynner etter siste dag.
                AND (s.slutter IS NULL OR s.neste_trekk <= s.slutter)
                AND m.anonymisert_at IS NULL"
        );
    }

    /**
     * Ber Vipps om ett trekk, og fører det som en betaling.
     *
     * Idempotensnokkelen bygges av avtalen og maaneden. Kjorer cron to ganger
     * samme natt, ber vi Vipps om det samme trekket — og Vipps gjor det én
     * gang.
     */
    public static function trekk(array $avtale): string
    {
        $maaned = (new DateTimeImmutable((string) $avtale['neste_trekk']))->format('Y-m');
        $nokkel = substr(hash('sha256', 'trekk:' . $avtale['id'] . ':' . $maaned), 0, 36);

        // Er trekket alt fort, gjor vi ikke noe mer. Uten denne kunne en
        // halvveis kjoring gitt to rader i payments for samme maaned.
        $fra = DB::en(
            "SELECT id FROM payments WHERE subscription_id = :s AND idempotency_key = :k",
            ['s' => (int) $avtale['id'], 'k' => $nokkel]
        );
        if ($fra !== null) {
            return 'alt fort';
        }

        $forfall = (new DateTimeImmutable('now'))->modify('+' . self::VARSEL_DAGER . ' days')->format('Y-m-d');
        $referanse = Vipps::nyReferanse('MED');

        $betalingId = DB::settInn('payments', [
            'vipps_reference' => $referanse,
            'type'            => 'recurring_charge',
            'formal'          => 'medlemskap',
            'member_id'       => (int) $avtale['member_id'],
            'subscription_id' => (int) $avtale['id'],
            'belop_ore'       => (int) $avtale['pris_ore'],
            'status'          => 'opprettet',
            'idempotency_key' => $nokkel,
        ]);

        try {
            $trekkId = Vipps::belastAvtale(
                (string) $avtale['vipps_agreement_id'],
                (int) $avtale['pris_ore'],
                'Medlemskap ' . $avtale['plan'],
                $forfall,
                $nokkel
            );
        } catch (Throwable $e) {
            DB::oppdater('payments', ['status' => 'feilet'], ['id' => $betalingId]);
            logg_feil('Trekk feilet for avtale ' . $avtale['id'], $e);
            throw $e;
        }

        // Trekk-ID-en tas vare paa. Den ble kastet for, og da fantes det ingen
        // vei tilbake til Vipps for aa sporre hvordan det gikk: raden ble
        // staaende paa «venter» for alltid, og verken «Medlemskapet ditt er
        // fornyet» eller «Vi fikk ikke trukket betalingen» ble sendt til noen.
        DB::oppdater('payments', [
            'status'        => 'venter',
            'vipps_psp_ref' => $trekkId !== '' ? $trekkId : null,
        ], ['id' => $betalingId]);

        // Neste trekk en maaned fram. Er avtalen en proveperiode, er dette
        // det eneste trekket — da stopper vi den etterpaa.
        $plan = self::plan((string) $avtale['plan']);
        $engangs = $plan !== null && (int) $plan['engangs'] === 1;

        DB::oppdater('subscriptions', [
            'siste_trekk' => (string) $avtale['neste_trekk'],
            'neste_trekk' => $engangs ? null
                : (new DateTimeImmutable((string) $avtale['neste_trekk']))->modify('+1 month')->format('Y-m-d'),
        ], ['id' => (int) $avtale['id']]);

        // Vipps krever at kunden vet om trekket for det skjer.
        if (!empty($avtale['epost'])) {
            Varsel::mal('medlemstrekk_varsel', ['epost' => (string) $avtale['epost']], [
                'navn'  => (string) $avtale['navn'],
                'belop' => Booking::kroner((int) $avtale['pris_ore']),
                'plan'  => (string) $avtale['plan'],
                'dag'   => self::norskDag($forfall),
            ], 'medlemskap', $betalingId);
        }

        return 'bedt om trekk til ' . $forfall;
    }

    /**
     * Hvordan gikk trekket?
     *
     * Maanedstrekket er ikke en ePayment og kan ikke slaas opp med
     * hentBetaling(). Det ligger under avtalen sin, og maa hentes derfra.
     * Uten dette oppslaget sto hvert eneste maanedstrekk paa «venter» i
     * regnskapet, uansett om pengene kom inn eller ikke.
     *
     * Svarer Vipps at trekket er gjort, blir raden betalt og medlemmet faar
     * kvitteringen. Svarer den at det feilet, blir raden feilet og medlemmet
     * faar beskjed om aa aapne Vipps. Alt annet — trekket er bestilt, men ikke
     * forfalt enda — lar vi staa: det er ikke noe galt, det har bare ikke
     * skjedd enda.
     *
     * @param array<string,mixed> $p raden fra payments
     * @return string hva som ble gjort, for loggen
     */
    public static function sjekkTrekk(array $p): string
    {
        $trekkId = trim((string) ($p['vipps_psp_ref'] ?? ''));
        $avtaleId = trim((string) ($p['vipps_agreement_id'] ?? ''));
        if ($trekkId === '' || $avtaleId === '') {
            return 'mangler trekk-id';
        }

        $svar = Vipps::hentTrekk($avtaleId, $trekkId);
        $status = strtoupper((string) ($svar['status'] ?? ''));

        DB::oppdater('payments', [
            'siste_payload' => json_encode($svar, JSON_UNESCAPED_UNICODE),
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ], ['id' => (int) $p['id']]);

        // Gikk pengene inn.
        if ($status === 'CHARGED') {
            DB::oppdater('payments', ['status' => 'betalt'], ['id' => (int) $p['id']]);
            if (!empty($p['epost'])) {
                Varsel::mal('medlemskap_fornyet', ['epost' => (string) $p['epost']], [
                    'navn'        => (string) ($p['navn'] ?? ''),
                    'abonnement'  => (string) ($p['plan'] ?? 'Medlemskapet'),
                ], 'medlemskap', (int) $p['id']);
            }
            return 'betalt';
        }

        // Gikk de ikke. Vipps proever selv i fem dager (retryDays er satt naar
        // trekket bestilles); staar det FAILED, er de dagene brukt opp. Da er
        // det medlemmet selv som maa aapne Vipps, og da maa hen faa vite det.
        if ($status === 'FAILED' || $status === 'CANCELLED') {
            DB::oppdater('payments', ['status' => $status === 'CANCELLED' ? 'avbrutt' : 'feilet'],
                         ['id' => (int) $p['id']]);
            if (!empty($p['epost']) || !empty($p['telefon'])) {
                Varsel::mal('betaling_feilet', [
                    'epost'   => $p['epost'] ?? null,
                    'telefon' => $p['telefon'] ?? null,
                ], [
                    'navn'       => (string) ($p['navn'] ?? ''),
                    'abonnement' => (string) ($p['plan'] ?? 'Medlemskapet'),
                ], 'medlemskap', (int) $p['id']);
            }
            return strtolower($status);
        }

        return 'venter (' . ($status !== '' ? $status : 'ukjent') . ')';
    }

    /**
     * Trekkene som ikke har fatt et svar enda.
     *
     * Trekket bes om noen dager fram i tid, saa det er normalt at et trekk
     * staar en uke for det gjor opp. Etter tretti dager gir vi opp aa sporre:
     * da har Vipps for lengst gitt opp aa proeve.
     *
     * @return list<array<string,mixed>>
     */
    public static function trekkUtenSvar(int $maks = 50): array
    {
        if (!DB::harKolonne('payments', 'vipps_psp_ref')) {
            return [];
        }
        return DB::alle(
            "SELECT p.*, s.vipps_agreement_id, s.plan, m.navn, m.epost, m.telefon
               FROM payments p
               JOIN subscriptions s ON s.id = p.subscription_id
          LEFT JOIN members m ON m.id = p.member_id
              WHERE p.type = 'recurring_charge'
                AND p.status IN ('opprettet', 'venter', 'autorisert')
                AND p.vipps_psp_ref IS NOT NULL
                AND p.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
           ORDER BY p.id
              LIMIT " . max(1, min(200, $maks))
        );
    }

    private static function norskDag(string $dato): string
    {
        $mnd = ['januar', 'februar', 'mars', 'april', 'mai', 'juni',
                'juli', 'august', 'september', 'oktober', 'november', 'desember'];
        $d = new DateTimeImmutable($dato);
        return (int) $d->format('j') . '. ' . $mnd[(int) $d->format('n') - 1];
    }
}

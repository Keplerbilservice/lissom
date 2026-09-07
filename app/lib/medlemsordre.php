<?php
/**
 * Innmeldingen, som en ordre paa serveren.
 *
 * ── Hvorfor denne finnes ────────────────────────────────────────────────
 *
 * Eieren meldte seg inn paa Aarsmedlemskap 7. september 2026 og fikk «Proev
 * Lissom, kr 990, 10 timer, 30 dager». Grunnen sto i skjermkoden:
 *
 *     return p.some(x => x.navn === v) ? v : (p[0] ? p[0].navn : '');
 *
 * Var valget borte, kjopte systemet det FOERSTE medlemskapet i lista i
 * stedet for aa stoppe. Foerste plan er «Proev Lissom», og den har ikke fast
 * trekk — derfor kom det ingen avtale aa godkjenne, bare en vanlig betaling.
 *
 * Det er nayaktig det Eirin beskrev 2. september: «bare en helt vanlig maate
 * aa betale med vipps, alt gikk igjennom, men pengene ble ikke trukket som
 * normalt».
 *
 * Valget forsvant fordi innmeldingen krevde innlogging foerst: kunden ble
 * sendt til Vipps for aa logge inn, og alt som laa i nettleserens minne var
 * borte naar hun kom tilbake. Paa mobil kan turen innom Vipps-appen gi en ny
 * fane, der ogsaa sessionStorage er tomt.
 *
 * ── Regelen ─────────────────────────────────────────────────────────────
 *
 * Raden lages FOER noen forlater sida. Noekkelen staar i adressen resten av
 * veien, og planen leses herfra — aldri fra nettleseren.
 *
 * Og: ingen standardplan. Mangler valget, stopper vi. Et medlemskap er en
 * avtale om penger hver maaned; det skal ikke kunne bli til ved et uhell.
 */

declare(strict_types=1);

final class Medlemsordre
{
    /** Saa lenge en paabegynt innmelding staar aapen. */
    private const LEVETID_TIMER = 24;

    /**
     * Lager ordren og gir noekkelen tilbake.
     *
     * @param array<string,mixed> $felter navn, epost, telefon, erfaring, melding, vilkaar
     * @throws RuntimeException naar planen mangler eller ikke finnes
     */
    public static function opprett(
        string $planNavn,
        string $betaling,
        array $felter,
        ?int $levetidTimer = null,
        ?int $medlemId = null
    ): string
    {
        $planNavn = trim($planNavn);
        if ($planNavn === '') {
            // Ikke «ta den forste». Stopp.
            throw new RuntimeException('Velg hvilket medlemskap du vil ha.');
        }

        $plan = Medlemskap::plan($planNavn);
        if ($plan === null) {
            throw new RuntimeException('Fant ikke medlemskapet «' . $planNavn . '».');
        }

        // Aarsmedlemskapet kan bare betales med fast trekk. Staar det noe
        // annet i forespoerselen, er det planen som gjelder — ikke onsket.
        $maa = Medlemskap::kreverFastTrekk($plan);
        $betaling = $maa ? 'trekk' : ($betaling === 'engang' ? 'engang' : 'trekk');

        $token = bin2hex(random_bytes(16));

        DB::settInn('medlemsordrer', [
            'token'    => $token,
            'plan'     => $planNavn,
            // Prisen slik den sto da hun trykket.
            'pris_ore' => (int) $plan['pris_ore'],
            'betaling' => $betaling,
            'navn'     => mb_substr(trim((string) ($felter['navn'] ?? '')), 0, 191),
            'epost'    => self::ellerNull($felter['epost'] ?? '', 191),
            // Normalisert med det samme. Folk skriver «+47 900 00 000»,
            // «90000000» og «0047 90000000» om det samme nummeret.
            'telefon'  => self::ellerNull(normaliser_telefon((string) ($felter['telefon'] ?? '')), 32),
            'erfaring' => self::ellerNull($felter['erfaring'] ?? '', 1000),
            'melding'  => self::ellerNull($felter['melding'] ?? '', 1000),
            'vilkaar'  => self::ellerNull($felter['vilkaar'] ?? '', 32),
            // Lages lenka av verkstedet, vet vi hvem den gjelder fra for.
            // Da kan den samme lenka finnes igjen i stedet for aa lages paa
            // nytt hver gang personruta aapnes — se forMedlem().
            'medlem_id' => $medlemId,
            // Et doegn naar hun sitter i innmeldingen selv. Sender
            // verkstedet lenka til noen, maa den leve lenger — den skal ut i
            // en e-post og kanskje videre i en melding.
            'utloper'  => gmdate('Y-m-d H:i:s',
                time() + max(1, $levetidTimer ?? self::LEVETID_TIMER) * 3600),
        ]);

        return $token;
    }

    /**
     * Lenka verkstedet sender til ett medlem — den samme hver gang.
     *
     * «Kopier lenka» staar i personruta, og ruta tegnes hver gang den aapnes.
     * Lages ordren der, ville hver visning lagt igjen en ny rad og en ny
     * gyldig lenke. Her finnes en levende ordre paa samme medlem og samme
     * plan igjen; bare naar det ikke finnes noen, lages den.
     */
    public static function forMedlem(int $medlemId, string $planNavn, array $felter,
                                     int $levetidTimer): string
    {
        $finnes = DB::en(
            "SELECT token FROM medlemsordrer
              WHERE medlem_id = :m AND plan = :p AND status = 'ny'
                AND utloper > UTC_TIMESTAMP()
           ORDER BY id DESC LIMIT 1",
            ['m' => $medlemId, 'p' => $planNavn]
        );
        if ($finnes !== null) {
            return (string) $finnes['token'];
        }
        $plan = Medlemskap::plan($planNavn);
        return self::opprett(
            $planNavn,
            $plan !== null && Medlemskap::kreverFastTrekk($plan) ? 'trekk' : 'engang',
            $felter,
            $levetidTimer,
            $medlemId
        );
    }

    /** Ordren bak noekkelen, eller null. @return array<string,mixed>|null */
    public static function hent(string $token): ?array
    {
        if (strlen($token) !== 32) {
            return null;
        }
        return DB::en('SELECT * FROM medlemsordrer WHERE token = :t', ['t' => $token]);
    }

    /**
     * Hvem ordren gjelder, hvis vi kjenner henne. Ellers null.
     *
     * Innmeldingen krever ikke innlogging lenger. Er hun logget inn, er det
     * henne. Ellers ser vi etter telefonnummeret og e-posten hun oppga.
     *
     * @param array<string,mixed> $ordre
     * @return array<string,mixed>|null
     */
    public static function finnMedlem(array $ordre, ?array $innlogget = null): ?array
    {
        if ($innlogget !== null && (int) ($innlogget['id'] ?? 0) > 0) {
            self::knyttTil((int) $ordre['id'], (int) $innlogget['id']);
            return $innlogget;
        }

        $tlf   = trim((string) ($ordre['telefon'] ?? ''));
        $epost = trim((string) ($ordre['epost'] ?? ''));

        $m = null;
        // ── De aatte siste sifrene, ikke tegnene ─────────────────────
        //
        // Her sto «telefon = :t». Nummeret ligger i basen paa flere former —
        // «+4790000000» fra oss, «4790000000» fra Vipps — og kunden skriver
        // det med mellomrom. Sammenligningen traff derfor aldri, og ALLE ble
        // sendt til Vipps for aa bekrefte hvem de var.
        //
        // Eieren, 7. september 2026, foerste forsoek etter omleggingen: «maa
        // fortsatt taste teleefonnummeret da, saa det lover ikke bra».
        //
        // De aatte siste sifrene er nummeret. Resten er skrivemaate.
        $siffer = preg_replace('/[^0-9]/', '', $tlf) ?? '';
        if (strlen($siffer) >= 8) {
            $m = DB::en(
                "SELECT * FROM members
                  WHERE anonymisert_at IS NULL
                    AND telefon IS NOT NULL
                    AND RIGHT(REGEXP_REPLACE(telefon, '[^0-9]', ''), 8) = :t
               ORDER BY id LIMIT 1",
                ['t' => substr($siffer, -8)]
            );
        }
        if ($m === null && $epost !== '') {
            $m = DB::en(
                'SELECT * FROM members WHERE epost = :e AND anonymisert_at IS NULL LIMIT 1',
                ['e' => $epost]
            );
        }
        if ($m === null) {
            return null;
        }

        self::knyttTil((int) $ordre['id'], (int) $m['id']);
        return $m;
    }

    /**
     * Lager raden for en vi ikke kjenner fra for.
     *
     * Krever et navn: «members.navn» kan ikke staa tom, og en medlemsliste
     * med e-postadresser der navnene skal staa er ingen medlemsliste.
     * Mangler navnet, skal meld-inn.php hente det fra Vipps i stedet — se
     * api/meld-inn.php.
     *
     * Raden gir ingen tilgang i seg selv: medlemskapet slaas foerst paa naar
     * Vipps sier at avtalen er godkjent eller betalingen gjennomfort. Se
     * Medlemskap::oppdaterFraVipps() og Booking::markerBetalt().
     *
     * @param array<string,mixed> $ordre
     * @return array<string,mixed>
     */
    public static function lagMedlem(array $ordre): array
    {
        $navn = trim((string) ($ordre['navn'] ?? ''));
        if ($navn === '') {
            throw new RuntimeException('Vi trenger navnet ditt.');
        }
        $tlf   = trim((string) ($ordre['telefon'] ?? ''));
        $epost = trim((string) ($ordre['epost'] ?? ''));

        $id = DB::settInn('members', [
            'navn'    => $navn,
            'epost'   => $epost !== '' ? $epost : null,
            'telefon' => $tlf !== '' ? $tlf : null,
            'rolle'   => 'medlem',
        ]);
        self::knyttTil((int) $ordre['id'], $id);
        return DB::en('SELECT * FROM members WHERE id = :i', ['i' => $id]);
    }

    /** Ordren er sendt videre til Vipps. */
    public static function merkApnet(int $ordreId, ?int $abonnementId = null): void
    {
        DB::oppdater('medlemsordrer', [
            'status'          => 'apnet',
            'apnet_at'        => gmdate('Y-m-d H:i:s'),
            'subscription_id' => $abonnementId,
        ], ['id' => $ordreId]);
    }

    /** Medlemskapet loeper. Ordren er gjort opp. */
    public static function merkFullfort(int $ordreId): void
    {
        DB::oppdater('medlemsordrer', [
            'status'      => 'fullfort',
            'fullfort_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $ordreId]);
    }

    private static function knyttTil(int $ordreId, int $medlemId): void
    {
        DB::oppdater('medlemsordrer', ['medlem_id' => $medlemId], ['id' => $ordreId]);
    }

    private static function ellerNull(mixed $v, int $lengde): ?string
    {
        $s = mb_substr(trim((string) $v), 0, $lengde);
        return $s === '' ? null : $s;
    }
}

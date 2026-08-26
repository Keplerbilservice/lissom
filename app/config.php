<?php
/**
 * Oppsett. Alt som er hemmelig hentes fra secrets.php — ingenting av det
 * havner noen gang i git eller i frontend.
 */

declare(strict_types=1);

final class Config
{
    /** @var array<string,mixed> */
    private static array $s = [];

    /** @param array<string,mixed> $hemmeligheter */
    public static function last(array $hemmeligheter): void
    {
        self::$s = $hemmeligheter;
    }

    /**
     * Oppsett eieren kan endre selv, fra admin.
     *
     * Alt annet i denne klassen settes én gang av den som setter opp
     * nettstedet, og hoerer hjemme i en fil. Innloggingen til e-postkontoen
     * og til SMS-leverandoeren gjor ikke det: den byttes den dagen passordet
     * byttes, av den som eier kontoen. Uten en vei dit har e-post og SMS
     * staatt uvirksomt, fordi det ikke fantes noen maate aa skru det paa uten
     * FTP-tilgang.
     *
     * Vipps-noeklene og databasepassordet staar med vilje ikke her. De skal
     * ikke kunne endres fra en nettleser.
     */
    private const FRA_BASEN = [
        'smtp_vert', 'smtp_port', 'smtp_bruker', 'smtp_passord', 'smtp_sikkerhet',
        'epost_fra', 'epost_fra_navn', 'epost_svar_til',
        'sms_leverandor', 'sveve_bruker', 'sveve_passord', 'sms_avsender',
        // Kontoene og mva-kodene til regnskapet. De er ingen hemmelighet, og
        // det er regnskapsforeren som eier dem — da skal de kunne endres fra
        // admin, ikke ved en ny utlegging av nettsiden.
        'regnskap_konto_kurs', 'regnskap_mva_kurs',
        'regnskap_konto_medlemskap', 'regnskap_mva_medlemskap',
        'regnskap_konto_butikk', 'regnskap_mva_butikk',
        'regnskap_konto_dropin', 'regnskap_mva_dropin',
        'regnskap_konto_gavekort', 'regnskap_mva_gavekort',
        'regnskap_motkonto_vipps', 'regnskap_motkonto_kontant',
        'regnskap_motkonto_faktura',
        // Noekkelen kalenderabonnementet ligger bak. Den lages naar eieren
        // ber om adressen, og kan byttes derfra — da slutter alle gamle
        // adresser aa virke paa én gang. Den staar derfor i basen og ikke i
        // fila: en noekkel som skal kunne byttes fra en knapp, kan ikke
        // ligge et sted bare den som har serveren kommer til.
        'kalender_nokkel', 'verksted_adresse',
        // Standardteksten i kvitteringen etter kjop. Den fylles ut paa nye
        // kurs, og eieren retter eller sletter den paa det enkelte kurset.
        // Sto den i koden, matte hele nettsida legges ut paa nytt for aa
        // endre et komma.
        'kurs_bekreftelse',
    ];

    /** @var array<string,string>|null */
    private static ?array $base = null;

    public static function hent(string $nokkel, mixed $standard = null): mixed
    {
        // Fila gjelder foerst. Staar noekkelen der, er det den som teller —
        // saa den som har satt opp serveren beholder kontrollen.
        $fraFil = self::$s[$nokkel] ?? null;
        if ($fraFil !== null && $fraFil !== '') {
            return $fraFil;
        }
        if (in_array($nokkel, self::FRA_BASEN, true)) {
            $v = self::fraBasen()[$nokkel] ?? '';
            if ($v !== '') {
                return $v;
            }
        }
        return self::$s[$nokkel] ?? $standard;
    }

    /**
     * Leses én gang per forespoersel.
     *
     * Taaler at tabellen ikke finnes: en migrasjon som ikke er kjoert enda
     * skal gi manglende oppsett, ikke en hvit side.
     *
     * @return array<string,string>
     */
    private static function fraBasen(): array
    {
        if (self::$base !== null) {
            return self::$base;
        }
        self::$base = [];
        try {
            if (class_exists('DB', false) && DB::harTabell('innstillinger')) {
                foreach (DB::alle('SELECT nokkel, verdi FROM innstillinger') as $r) {
                    self::$base[(string) $r['nokkel']] = (string) ($r['verdi'] ?? '');
                }
            }
        } catch (Throwable $e) {
            // Uten base er det fila som gjelder, som foer.
        }
        return self::$base;
    }

    /** Kalles etter lagring, saa neste oppslag i samme forespoersel ser det nye. */
    public static function glemBasen(): void
    {
        self::$base = null;
    }

    /** Kaster hvis nøkkelen mangler — brukes for verdier vi ikke kan klare oss uten. */
    public static function krev(string $nokkel): string
    {
        $v = self::$s[$nokkel] ?? '';
        if ($v === '' || $v === null) {
            throw new RuntimeException("Mangler «{$nokkel}» i app/secrets.php");
        }
        return (string) $v;
    }

    public static function miljo(): string
    {
        return (string) (self::$s['miljo'] ?? 'produksjon');
    }

    public static function erUtvikling(): bool
    {
        return self::miljo() !== 'produksjon';
    }

    /** Adressen nettsiden ligger på. Brukes til retur fra Vipps og i lenker i e-post. */
    public static function nettsted(): string
    {
        return rtrim((string) (self::$s['nettsted'] ?? 'https://lissom.no'), '/');
    }

    /** Vipps-miljø: apitest.vipps.no under utvikling, api.vipps.no i produksjon. */
    public static function vippsBase(): string
    {
        return rtrim(self::krev('vipps_base'), '/');
    }

    /**
     * Telefonnumre som får admin-tilgang uansett hva som står i databasen.
     * Nødluke, slik at du ikke kan låse deg selv ute.
     *
     * @return list<string>
     */
    public static function adminNumre(): array
    {
        $r = self::$s['admin_telefoner'] ?? [];
        return array_values(array_filter(array_map(
            static fn($t) => normaliser_telefon((string) $t),
            is_array($r) ? $r : []
        )));
    }
}

/**
 * Gjør et norsk telefonnummer til +47XXXXXXXX. Vipps oppgir nummer med
 * landkode og uten plusstegn, mens folk skriver dem med mellomrom.
 */
function normaliser_telefon(string $raa): string
{
    $t = preg_replace('/[^0-9]/', '', $raa) ?? '';
    if ($t === '') {
        return '';
    }
    if (str_starts_with($t, '0047')) {
        $t = substr($t, 4);
    } elseif (str_starts_with($t, '47') && strlen($t) === 10) {
        $t = substr($t, 2);
    }
    return strlen($t) === 8 ? '+47' . $t : '+' . $t;
}

Config::last($LISSOM_SECRETS);
unset($LISSOM_SECRETS);

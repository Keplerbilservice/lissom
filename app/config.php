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

    public static function hent(string $nokkel, mixed $standard = null): mixed
    {
        return self::$s[$nokkel] ?? $standard;
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

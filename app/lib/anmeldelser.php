<?php
/**
 * Google-anmeldelser nederst paa forsida.
 *
 * Eieren, 29. september 2026: «Legge inn nederst paa siden hva sier google
 * anmeldelsene? Hente fra google min bedrift, vis kun 4 og 5 stjerner».
 * GO paa skissen samme dag.
 *
 * Hentes med Google Places API (New) i vedlikeholdsjobben, én gang i
 * doegnet, og legges i innstillinger-tabellen. Forsida leser bare det som
 * er lagret — den spoer aldri Google mens kunden venter.
 *
 * Noekkelen (google_places_nokkel) limes inn av eieren under Markedsfoering
 * → Innstillinger → Google-anmeldelser. Den ligger i innstillinger, aldri i
 * content_blocks, som alle kan lese.
 *
 * Snittet og antallet er Googles tall for ALLE anmeldelsene. Bare kortene
 * er et utvalg (4 og 5 stjerner, maks 4). Da er utvalget aerlig.
 */

declare(strict_types=1);

final class Anmeldelser
{
    public const SOK = 'Lissom Keramikk Teie';
    public const MAKS = 4;

    /** @return array{rating:float,antall:int,lenke:string,kort:list<array<string,mixed>>,hentet:string}|null */
    public static function lagret(): ?array
    {
        if (!DB::harTabell('innstillinger')) {
            return null;
        }
        $raa = (string) DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'google_anmeldelser'");
        $d = $raa !== '' ? json_decode($raa, true) : null;
        return is_array($d) ? $d : null;
    }

    /**
     * Det forsida viser, eller null naar seksjonen ikke skal vises: ingen
     * data, eller ingen anmeldelser med 4 eller 5 stjerner.
     *
     * @return array{rating:float,antall:int,lenke:string,kort:list<array<string,mixed>>}|null
     */
    public static function forForsida(): ?array
    {
        $d = self::lagret();
        if ($d === null || ($d['kort'] ?? []) === [] || (int) ($d['antall'] ?? 0) <= 0) {
            return null;
        }
        return $d;
    }

    /**
     * Hent fra Google og lagre. $http kan byttes ut i testene.
     *
     * @param (callable(string,string,array<string,mixed>|null,list<string>):array{status:int,json:mixed})|null $http
     * @return array{ok:bool,feil:string}
     */
    public static function hent(?callable $http = null): array
    {
        $nokkel = trim((string) Config::hent('google_places_nokkel', ''));
        if ($nokkel === '') {
            return ['ok' => false, 'feil' => 'Nøkkelen mangler.'];
        }
        $http ??= static function (string $metode, string $url, ?array $kropp, array $hode): array {
            $s = $metode === 'POST' ? http_post_json($url, $kropp ?? [], $hode) : http_get_json($url, $hode);
            return ['status' => $s['status'], 'json' => $s['json']];
        };

        $stedId = (string) DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'google_place_id'");
        if ($stedId === '') {
            $s = $http('POST', 'https://places.googleapis.com/v1/places:searchText', ['textQuery' => self::SOK, 'languageCode' => 'no'],
                ['X-Goog-Api-Key: ' . $nokkel, 'X-Goog-FieldMask: places.id,places.displayName']);
            $stedId = (string) ($s['json']['places'][0]['id'] ?? '');
            if ($stedId === '') {
                return ['ok' => false, 'feil' => self::feilFra($s, 'Fant ikke Lissom Keramikk hos Google.')];
            }
            self::lagre('google_place_id', $stedId);
        }

        $s = $http('GET', 'https://places.googleapis.com/v1/places/' . rawurlencode($stedId) . '?languageCode=no', null,
            ['X-Goog-Api-Key: ' . $nokkel, 'X-Goog-FieldMask: rating,userRatingCount,reviews,googleMapsUri']);
        $j = $s['json'];
        if ($s['status'] < 200 || $s['status'] >= 300 || !is_array($j)) {
            return ['ok' => false, 'feil' => self::feilFra($s, 'Google svarte ikke.')];
        }

        $kort = [];
        foreach ((array) ($j['reviews'] ?? []) as $r) {
            $stjerner = (int) ($r['rating'] ?? 0);
            $tekst = trim((string) ($r['originalText']['text'] ?? $r['text']['text'] ?? ''));
            if ($stjerner < 4 || $tekst === '') {
                continue;
            }
            $kort[] = [
                'stjerner' => $stjerner,
                'tekst'    => $tekst,
                'navn'     => trim((string) ($r['authorAttribution']['displayName'] ?? '')),
                'navnLenke'=> (string) ($r['authorAttribution']['uri'] ?? ''),
                'tid'      => (string) ($r['publishTime'] ?? ''),
                'tidTekst' => (string) ($r['relativePublishTimeDescription'] ?? ''),
            ];
            if (count($kort) >= self::MAKS) {
                break;
            }
        }

        self::lagre('google_anmeldelser', (string) json_encode([
            'rating' => round((float) ($j['rating'] ?? 0), 1),
            'antall' => (int) ($j['userRatingCount'] ?? 0),
            'lenke'  => (string) ($j['googleMapsUri'] ?? ''),
            'kort'   => $kort,
            'hentet' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['ok' => true, 'feil' => ''];
    }

    /** hent(), og husk feilen til admin. Det er denne jobbene og admin bruker. */
    public static function oppdater(?callable $http = null): array
    {
        try {
            $r = self::hent($http);
        } catch (Throwable $e) {
            $r = ['ok' => false, 'feil' => mb_substr($e->getMessage(), 0, 300)];
        }
        if (DB::harTabell('innstillinger')) {
            self::lagre('google_anmeldelser_feil', $r['ok'] ? '' : $r['feil']);
        }
        return $r;
    }

    /** «for 2 uker siden», regnet ut naar sida lages — ikke naar den ble hentet. */
    public static function siden(string $iso, string $reserve = ''): string
    {
        $t = $iso !== '' ? strtotime($iso) : false;
        if ($t === false) {
            return $reserve;
        }
        $dager = (int) floor((time() - $t) / 86400);
        return match (true) {
            $dager < 1   => 'i dag',
            $dager < 2   => 'i går',
            $dager < 7   => 'for ' . $dager . ' dager siden',
            $dager < 14  => 'for 1 uke siden',
            $dager < 30  => 'for ' . intdiv($dager, 7) . ' uker siden',
            $dager < 60  => 'for 1 måned siden',
            $dager < 365 => 'for ' . intdiv($dager, 30) . ' måneder siden',
            $dager < 730 => 'for 1 år siden',
            default      => 'for ' . intdiv($dager, 365) . ' år siden',
        };
    }

    /** @return array<string,mixed> til admin: om noekkelen finnes, og hva som sist ble hentet */
    public static function status(): array
    {
        $d = self::lagret();
        return [
            'harNokkel' => trim((string) Config::hent('google_places_nokkel', '')) !== '',
            'hentet'    => (string) ($d['hentet'] ?? ''),
            'rating'    => (float) ($d['rating'] ?? 0),
            'antall'    => (int) ($d['antall'] ?? 0),
            'kort'      => count((array) ($d['kort'] ?? [])),
            'feil'      => (string) DB::verdi("SELECT verdi FROM innstillinger WHERE nokkel = 'google_anmeldelser_feil'"),
        ];
    }

    public static function lagre(string $nokkel, string $verdi): void
    {
        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi) VALUES (?, ?) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',
            [$nokkel, $verdi]
        );
    }

    /** @param array{status:int,json:mixed} $s */
    private static function feilFra(array $s, string $standard): string
    {
        $m = is_array($s['json'] ?? null) ? (string) ($s['json']['error']['message'] ?? '') : '';
        return $m !== '' ? mb_substr($m, 0, 300) : $standard . ' (HTTP ' . $s['status'] . ')';
    }
}

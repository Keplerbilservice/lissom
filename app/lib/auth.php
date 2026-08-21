<?php
/**
 * Portvakter. Kalles først i endepunkter som ikke er åpne for alle.
 */

declare(strict_types=1);

/** @return array<string,mixed> Medlemmet som er logget inn. */
function krev_medlem(): array
{
    $m = Sesjon::medlem();
    if ($m === null) {
        Svar::feil('Du må være logget inn.', 401, ['loggInn' => true]);
    }
    return $m;
}

/**
 * @return array<string,mixed>
 *
 * Merk: 404 og ikke 403 når en innlogget ikke-admin prøver seg. Da røper vi
 * ikke at endepunktet finnes.
 */
function krev_admin(): array
{
    $m = Sesjon::medlem();
    if ($m === null) {
        Svar::feil('Du må være logget inn.', 401, ['loggInn' => true]);
    }
    if (!Sesjon::erAdmin()) {
        logg('Avvist admin-forsøk', ['medlem' => $m['id']]);
        Svar::feil('Fant ikke siden.', 404);
    }
    return $m;
}

/** Skriver en linje i revisjonsloggen. */
function revider(string $handling, ?string $objektType = null, ?int $objektId = null, array $detaljer = []): void
{
    $m = Sesjon::medlem();
    DB::settInn('audit_log', [
        'member_id'   => $m['id'] ?? null,
        'handling'    => $handling,
        'objekt_type' => $objektType,
        'objekt_id'   => $objektId,
        'detaljer'    => $detaljer === [] ? null : json_encode($detaljer, JSON_UNESCAPED_UNICODE),
        'ip'          => Foresporsel::ipBinaer(),
    ]);
}

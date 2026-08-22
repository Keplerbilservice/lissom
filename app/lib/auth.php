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
 * Krever et aktivt medlemskap — ikke bare innlogging.
 *
 * Vipps Login forteller hvem noen er. Det sier ingenting om at de skal ha
 * tilgang til verkstedet, døra eller de interne kursene. Alle som logger inn
 * får en rad i members med status «ingen»; medlem blir man først når
 * verkstedet har godkjent en søknad.
 *
 * @return array<string,mixed>
 */
function krev_aktivt_medlem(): array
{
    $m = krev_medlem();
    if (!er_aktivt_medlem($m)) {
        Svar::feil('Denne delen er for medlemmer. Send en søknad fra Min side, så ser vi på den.', 403, ['ikkeMedlem' => true]);
    }
    return $m;
}

/** @param array<string,mixed> $medlem */
function er_aktivt_medlem(array $medlem): bool
{
    // Admin er alltid innenfor. Ellers kunne den som driver verkstedet
    // stengt seg selv ute fra medlemsdelen ved et uhell.
    if ((string) ($medlem['rolle'] ?? '') === 'admin') {
        return true;
    }
    return in_array((string) ($medlem['status'] ?? 'ingen'), ['prove', 'aktiv', 'pause'], true);
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

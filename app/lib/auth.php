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

/**
 * Kom kallet fra en side brukeren selv har aapnet?
 *
 * Verner mot at et annet nettsted lar en innlogget admins nettleser gjore noe
 * paa vaare vegne. «Sec-Fetch-Site» er det presise svaret, og den skal foelges
 * naar den er der: er den «cross-site», er det nettopp det vi vil stoppe.
 *
 * Men den er ikke alltid der. Safari fikk den foerst i 16.4, og nettlesere
 * utelater den over vanlig http. Behandler vi fravaer som et angrep, stenger
 * vi ute folk som ikke gjor noe galt — og en adminskjerm som er tom for hver
 * tiende bruker er verre enn ingen skjerm, fordi feilen ikke ser ut som en
 * feil. Da faller vi tilbake paa Origin eller Referer, som sier det samme naar
 * de finnes.
 *
 * Er ingen av delene der, er det ingen nettleserside som staar bak — og en
 * innlogget sesjon er da det vi har aa gaa etter.
 */
/**
 * Hvor mange som kan komme inn i admin.
 *
 * Bade de som staar som admin i databasen og de som er det via
 * noedluke-numrene i secrets.php. Teller vi bare den forste gruppa, ville den
 * siste konto-baserte admin-en vaert umulig aa slette selv om eieren fortsatt
 * kom inn med Vipps.
 *
 * Ligger her og ikke i én skjerm fordi to skjermer kan slette den samme
 * personen: Brukere og Medlemmer. Sto regelen bare det ene stedet, var det en
 * tilfeldighet hvilken dor man gikk inn av naar man laaste seg selv ute.
 */
function antall_admin(): int
{
    $numre = Config::adminNumre();
    if ($numre === []) {
        return (int) DB::verdi("SELECT COUNT(*) FROM members WHERE rolle = 'admin'");
    }
    $plass = implode(',', array_fill(0, count($numre), '?'));
    return (int) DB::verdi(
        "SELECT COUNT(*) FROM members WHERE rolle = 'admin' OR telefon IN ({$plass})",
        $numre
    );
}

function fra_egen_side(): bool
{
    $sfs = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($sfs !== '') {
        return $sfs === 'none' || $sfs === 'same-origin';
    }

    $opphav = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($opphav === '') {
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref !== '') {
            $d = parse_url($ref);
            $opphav = ($d['scheme'] ?? '') . '://' . ($d['host'] ?? '')
                . (isset($d['port']) ? ':' . $d['port'] : '');
        }
    }
    if ($opphav === '') {
        return true;
    }

    $vaar = Config::nettsted();
    // Vertsnavnet er det som betyr noe. Skjemaet kan vaere http lokalt og
    // https ute, og porten foelger med bare naar den ikke er standard.
    $vert = static fn(string $u): string => (string) (parse_url($u, PHP_URL_HOST) ?: '');
    return $vert($opphav) !== '' && $vert($opphav) === $vert($vaar);
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

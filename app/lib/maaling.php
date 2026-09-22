<?php
/**
 * Kjøp målt fra serveren — til GA4 (Measurement Protocol) og Meta
 * (Conversions API).
 *
 * ── Hvorfor ────────────────────────────────────────────────────────────
 *
 * Kjøpet ble bare målt i nettleseren, på returen fra Vipps (maalKjop() i
 * nettsida, «#kjop=»). Kommer kunden ikke tilbake dit — Vipps-appen på
 * telefonen åpner en ny fane, nettleseren lukkes, returen laster ikke —
 * finnes kjøpet for Google og Meta ikke. Målt 21. september 2026: Google
 * Ads hadde «ingen nylige konverteringer» på alle handlingene, og
 * Meta-pikselen 0 kjøp siden 16. september, mens verkstedet solgte plasser.
 * Annonsene byr da uten å vite hva som virker.
 *
 * Her sendes kjøpet fra serveren, fra det ene stedet der en betaling blir
 * ekte: Booking::markerBetalt() — webhooken fra Vipps eller returen, hva som
 * enn kommer først.
 *
 * ── Samtykke ───────────────────────────────────────────────────────────
 *
 * Nettsiden laster ingen måling før noen har sagt ja (nett.js / appen,
 * «lissom-analyse»). Da finnes heller ingen _ga- eller _fbp-cookie. Derfor
 * er cookiene selve samtykket her: står de ikke i forespørselen som startet
 * betalingen, sendes ingenting fra serveren heller. Ingen bakvei rundt
 * boksen.
 *
 * ── Dobbelttelling ─────────────────────────────────────────────────────
 *
 * Nettleseren sender fortsatt sitt kjøp når returen laster. Begge bruker
 * samme id, «L<betalings-id>»: GA4 teller én transaksjon per transaction_id,
 * og Meta slår sammen hendelser med samme event_id fra piksel og server.
 *
 * ── Nøklene ────────────────────────────────────────────────────────────
 *
 * GA4: «API-hemmelighet» (Admin → Datastrømmer → strømmen → Measurement
 * Protocol API secrets). Meta: systembruker-token fra Events Manager →
 * Innstillinger → Conversions API. Begge legges inn under Markedsføring →
 * Måling i admin (innstillinger-tabellen, aldri content_blocks — den kan
 * hvem som helst lese via api/innhold.php).
 */

declare(strict_types=1);

final class Maaling
{
    /**
     * Det fra nettleseren som trengs for å knytte kjøpet til besøket:
     * GA4 sin client_id og session_id, Metas _fbp/_fbc, IP og user-agent.
     * Lagres som JSON på betalingen når den opprettes (migrasjon 203).
     * Tom streng når kunden ikke har samtykket (ingen cookies).
     */
    public static function sporingFraNettleser(): string
    {
        $c = $_COOKIE;
        $ut = [];
        // _ga = GA1.1.<client_id-del1>.<del2>
        if (preg_match('~^GA1\.\d\.(\d+\.\d+)$~', (string) ($c['_ga'] ?? ''), $m) === 1) {
            $ut['cid'] = $m[1];
        }
        // _ga_<måle-id uten G-> = GS1.1.<session_id>.<antall>.…
        foreach ($c as $navn => $verdi) {
            if (str_starts_with((string) $navn, '_ga_') && preg_match('~^GS\d\.\d\.(\d+)\.~', (string) $verdi, $m) === 1) {
                $ut['sid'] = $m[1];
                break;
            }
        }
        if (isset($c['_fbp']) && preg_match('~^fb\.\d\.\d+\.\d+$~', (string) $c['_fbp']) === 1) {
            $ut['fbp'] = (string) $c['_fbp'];
        }
        if (isset($c['_fbc']) && preg_match('~^fb\.\d\.\d+\.[\w-]+$~', (string) $c['_fbc']) === 1) {
            $ut['fbc'] = (string) $c['_fbc'];
        }
        if ($ut === []) {
            return '';
        }
        $ut['ip'] = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $ut['ua'] = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
        $ut['t']  = time();
        return (string) json_encode($ut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Sender kjøpet for en betaling som nettopp ble «betalt». Feiler stille:
     * en måling som ikke kommer fram skal aldri stoppe en betaling.
     */
    public static function kjop(int $betalingId): void
    {
        try {
            self::sendKjop($betalingId);
        } catch (Throwable $e) {
            logg_feil('Måling av kjøp ' . $betalingId . ' feilet', $e);
        }
    }

    /**
     * Sender et betalt kjøp til Meta på nytt som TESThendelse (testkoden fra
     * «Test hendelser» i Hendelsesadministrasjon). Går ikke til GA4, og
     * telles ikke hos Meta — men viser hvilke felter serveren sender.
     * Svaret fra Meta kommer tilbake, så admin kan vise det.
     * @return array{status:int,kropp:string}
     */
    public static function testTilMeta(int $betalingId, string $testkode): array
    {
        self::$test = ['kode' => $testkode, 'svar' => ['status' => 0, 'kropp' => 'Ikke sendt: betalingen mangler samtykke, nøkkel eller Meta-ID.']];
        try {
            self::sendKjop($betalingId);
        } finally {
            $svar = self::$test['svar'];
            self::$test = null;
        }
        return $svar;
    }

    /** @var array{kode:string,svar:array{status:int,kropp:string}}|null */
    private static ?array $test = null;

    private static function sendKjop(int $betalingId): void
    {
        if (!DB::harKolonne('payments', 'sporing')) {
            return; // migrasjon 203 er ikke kjørt
        }
        $b = DB::en('SELECT id, formal, belop_ore, sporing FROM payments WHERE id = :id', ['id' => $betalingId]);
        if ($b === null) {
            return;
        }
        $sporing = json_decode((string) ($b['sporing'] ?? ''), true);
        if (!is_array($sporing) || $sporing === []) {
            return; // ikke samtykket — ingen cookies, ingen måling
        }

        $formal = (string) $b['formal'];
        $NAVN = ['booking' => 'Kurs eller event', 'ordre' => 'Butikk', 'gavekort' => 'Gavekort', 'medlemskap' => 'Medlemskap'];
        $vare = $NAVN[$formal] ?? $formal;
        $belop = round(((int) $b['belop_ore']) / 100, 2);
        $hvem = self::hvem($formal, $betalingId);
        if ($hvem['tittel'] !== '') {
            $vare = $hvem['tittel'];
        }
        $id = 'L' . $betalingId;

        if (self::$test !== null) {
            // Testen: bare Meta, og med en annen id enn det ekte kjøpet, så
            // den ikke slås sammen med det.
            self::tilMeta($sporing, 'TEST-' . $id, $belop, $formal, $vare, $hvem);
            return;
        }
        self::tilGa4($sporing, $id, $belop, $formal, $vare, $hvem);
        self::tilMeta($sporing, $id, $belop, $formal, $vare, $hvem);
    }

    /**
     * Hvem som kjøpte, og hva. Bare til hashing — sendes aldri i klartekst.
     *
     * Jo mer Meta og Google kan kjenne kjøperen igjen på, jo flere kjøp blir
     * tilskrevet annonsen (Metas «hendelsesmatchkvalitet»): e-post og telefon
     * teller mest, så navn, land og en kunde-id. Alt hashes før det sendes.
     *
     * @return array{epost:string,telefon:string,navn:string,medlem:int,tittel:string,slug:string}
     */
    private static function hvem(string $formal, int $betalingId): array
    {
        $ut = ['epost' => '', 'telefon' => '', 'navn' => '', 'medlem' => 0, 'tittel' => '', 'slug' => ''];
        $r = null;
        if ($formal === 'booking') {
            $r = DB::en(
                'SELECT COALESCE(m.epost, b.gjest_epost) AS epost, COALESCE(m.telefon, b.gjest_telefon) AS telefon,
                        COALESCE(NULLIF(m.navn, \'\'), b.gjest_navn) AS navn, b.member_id AS medlem, c.tittel, c.slug
                   FROM bookings b
                   JOIN courses c ON c.id = b.course_id
              LEFT JOIN members m ON m.id = b.member_id
                  WHERE b.payment_id = :p',
                ['p' => $betalingId]
            );
        } elseif ($formal === 'ordre') {
            $r = DB::en(
                'SELECT COALESCE(m.epost, o.kunde_epost) AS epost, COALESCE(m.telefon, o.kunde_telefon) AS telefon,
                        COALESCE(NULLIF(m.navn, \'\'), o.kunde_navn) AS navn, o.member_id AS medlem
                   FROM orders o LEFT JOIN members m ON m.id = o.member_id WHERE o.payment_id = :p',
                ['p' => $betalingId]
            );
        } elseif ($formal === 'gavekort') {
            $r = DB::en('SELECT kjoper_epost AS epost, kjoper_navn AS navn FROM gift_cards WHERE payment_id = :p', ['p' => $betalingId]);
        } elseif ($formal === 'medlemskap') {
            $r = DB::en(
                'SELECT m.epost, m.telefon, m.navn, m.id AS medlem
                   FROM payments p JOIN members m ON m.id = p.member_id WHERE p.id = :p',
                ['p' => $betalingId]
            );
        }
        if ($r !== null) {
            foreach (['epost', 'telefon', 'navn', 'tittel', 'slug'] as $k) {
                $ut[$k] = (string) ($r[$k] ?? '');
            }
            $ut['medlem'] = (int) ($r['medlem'] ?? 0);
        }
        return $ut;
    }

    /**
     * Fornavn og etternavn, slik Meta og Google vil ha dem før hashing: små
     * bokstaver, uten mellomrom rundt. Siste ord er etternavnet.
     * @return array{0:string,1:string}
     */
    private static function navnDeler(string $navn): array
    {
        $deler = preg_split('~\s+~', mb_strtolower(trim($navn))) ?: [];
        $deler = array_values(array_filter($deler, static fn(string $d): bool => $d !== ''));
        if (count($deler) < 2) {
            return [$deler[0] ?? '', ''];
        }
        $etter = array_pop($deler);
        return [implode(' ', $deler), $etter];
    }

    private static function gaId(): string
    {
        $id = trim((string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Marked/GA-id'"));
        return preg_match('~^G-[A-Z0-9]{6,20}$~i', $id) === 1 ? $id : '';
    }

    private static function metaId(): string
    {
        $id = trim((string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Marked/Meta-piksel'"));
        return preg_match('~^\d{15,16}$~', $id) === 1 ? $id : '';
    }

    /** GA4 Measurement Protocol: purchase med samme transaction_id som nettleseren. */
    /** @param array{epost:string,telefon:string,navn:string,medlem:int,tittel:string,slug:string} $hvem */
    private static function tilGa4(array $sporing, string $id, float $belop, string $formal, string $vare, array $hvem): void
    {
        $gaId = self::gaId();
        $hemmelighet = trim((string) Config::hent('maal_ga_api_secret', ''));
        if ($gaId === '' || $hemmelighet === '' || empty($sporing['cid'])) {
            return;
        }
        $params = [
            'transaction_id' => $id,
            'value'          => $belop,
            'currency'       => 'NOK',
            'items'          => [['item_id' => $formal, 'item_name' => $vare, 'price' => $belop, 'quantity' => 1]],
            // Uten disse to havner hendelsen utenfor økten, og Ads ser ingen
            // kilde å tilskrive kjøpet.
            'engagement_time_msec' => 100,
        ];
        if (!empty($sporing['sid'])) {
            $params['session_id'] = (string) $sporing['sid'];
        }
        $kropp = [
            'client_id' => (string) $sporing['cid'],
            'events'    => [['name' => 'purchase', 'params' => $params]],
        ];
        // Brukeroppgitte data, hashet — samme som gtag('set','user_data') i
        // nettleseren, så Google kan kjenne igjen kjøperen på tvers av enheter.
        $ud = [];
        $e = self::normEpost($hvem['epost']);
        if ($e !== '') {
            $ud['sha256_email_address'] = hash('sha256', $e);
        }
        $t = self::e164($hvem['telefon']);
        if ($t !== '') {
            $ud['sha256_phone_number'] = hash('sha256', $t);
        }
        [$fornavn, $etternavn] = self::navnDeler($hvem['navn']);
        if ($fornavn !== '' && $etternavn !== '') {
            $ud['address'] = [[
                'sha256_first_name' => hash('sha256', $fornavn),
                'sha256_last_name'  => hash('sha256', $etternavn),
                'country'           => 'NO',
            ]];
        }
        if ($ud !== []) {
            $kropp['user_data'] = $ud;
        }
        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode($gaId)
             . '&api_secret=' . rawurlencode($hemmelighet);
        $svar = http_kall($url, 'POST', (string) json_encode($kropp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json'], 6);
        if ($svar['status'] < 200 || $svar['status'] >= 300) {
            logg_feil('GA4 svarte ' . $svar['status'] . ' på kjøp ' . $id . ': ' . mb_substr($svar['kropp'], 0, 300));
        }
    }

    /** Meta Conversions API: Purchase med event_id lik pikselens eventID. */
    /** @param array{epost:string,telefon:string,navn:string,medlem:int,tittel:string,slug:string} $hvem */
    private static function tilMeta(array $sporing, string $id, float $belop, string $formal, string $vare, array $hvem): void
    {
        $pikselId = self::metaId();
        $token = trim((string) Config::hent('maal_meta_token', ''));
        if ($pikselId === '' || $token === '' || empty($sporing['fbp'])) {
            return;
        }
        $bruker = [
            'fbp'               => (string) $sporing['fbp'],
            'client_ip_address' => (string) ($sporing['ip'] ?? ''),
            'client_user_agent' => (string) ($sporing['ua'] ?? ''),
        ];
        if (!empty($sporing['fbc'])) {
            $bruker['fbc'] = (string) $sporing['fbc'];
        }
        $e = self::normEpost($hvem['epost']);
        if ($e !== '') {
            $bruker['em'] = [hash('sha256', $e)];
        }
        $t = self::e164($hvem['telefon']);
        if ($t !== '') {
            $bruker['ph'] = [hash('sha256', ltrim($t, '+'))];
        }
        [$fornavn, $etternavn] = self::navnDeler($hvem['navn']);
        if ($fornavn !== '') {
            $bruker['fn'] = [hash('sha256', $fornavn)];
        }
        if ($etternavn !== '') {
            $bruker['ln'] = [hash('sha256', $etternavn)];
        }
        $bruker['country'] = [hash('sha256', 'no')];
        if ($hvem['medlem'] > 0) {
            // Kunde-id: medlemsnummeret, hashet — samme kjøper på tvers av
            // enheter, uten at nummeret forlater serveren.
            $bruker['external_id'] = [hash('sha256', 'lissom-medlem-' . $hvem['medlem'])];
        }
        $hendelse = [
            'event_name'       => 'Purchase',
            'event_time'       => time(),
            'event_id'         => $id,
            'action_source'    => 'website',
            // Kurssida for en booking — samme adresse som pikselens ViewContent.
            'event_source_url' => Config::nettsted() . ($formal === 'booking' && $hvem['slug'] !== '' ? '/kurs/' . $hvem['slug'] : '/'),
            'user_data'        => $bruker,
            'custom_data'      => [
                'value'        => $belop,
                'currency'     => 'NOK',
                'content_type' => 'product',
                'content_ids'  => [$formal],
                'content_name' => $vare,
            ],
        ];
        $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($pikselId) . '/events';
        $felt = [
            'data'         => json_encode([$hendelse], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'access_token' => $token,
        ];
        if (self::$test !== null) {
            $felt['test_event_code'] = self::$test['kode'];
        }
        $kropp = http_build_query($felt);
        $svar = http_kall($url, 'POST', $kropp, ['Content-Type: application/x-www-form-urlencoded'], 6);
        if (self::$test !== null) {
            self::$test['svar'] = ['status' => (int) $svar['status'], 'kropp' => mb_substr((string) $svar['kropp'], 0, 500)];
        }
        if ($svar['status'] < 200 || $svar['status'] >= 300) {
            logg_feil('Meta svarte ' . $svar['status'] . ' på kjøp ' . $id . ': ' . mb_substr($svar['kropp'], 0, 300));
        }
    }

    private static function normEpost(string $epost): string
    {
        $e = mb_strtolower(trim($epost));
        return str_contains($e, '@') ? $e : '';
    }

    /** +47 900 00 000 → +4790000000. Tomt når det ikke ser ut som et nummer. */
    private static function e164(string $telefon): string
    {
        $t = preg_replace('~[^\d+]~', '', $telefon) ?? '';
        if ($t === '') {
            return '';
        }
        if (str_starts_with($t, '00')) {
            $t = '+' . substr($t, 2);
        } elseif (!str_starts_with($t, '+')) {
            $t = '+47' . ltrim($t, '0');
        }
        return preg_match('~^\+\d{8,15}$~', $t) === 1 ? $t : '';
    }
}

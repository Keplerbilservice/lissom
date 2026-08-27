<?php
/**
 * Vipps.
 *
 * Tre produkter i bruk:
 *   Login (OIDC)  — innlogging på Min side
 *   ePayment      — engangskjøp: kurs, events, drop-in, gavekort, butikkordre
 *   Recurring     — månedstrekk for medlemskap
 *
 * Alle nøkler leses fra app/secrets.php, som ligger utenfor webroten og aldri
 * er i git. Ingenting av dette må noen gang havne i frontend.
 */

declare(strict_types=1);

final class Vipps
{
    private static ?string $token = null;
    private static int $tokenUtloper = 0;

    // -----------------------------------------------------------------------
    // Felles
    // -----------------------------------------------------------------------

    /**
     * Noeklene som gjelder betaling — ePayment og Recurring.
     *
     * Vipps deler ut noekler per salgsenhet, og per produkt. Innlogging kan
     * ligge paa én salgsenhet og betaling paa en annen, med hver sin
     * abonnementsnokkel og hvert sitt MSN. Det er nettopp det som skjedde her:
     * betalingen fikk et eget sett da den ble godkjent.
     *
     * Er de egne feltene tomme, brukes de samme som til innlogging. Da
     * oppfoerer alt seg som for, og et oppsett med bare ett sett trenger ikke
     * roeres.
     *
     * @return array{client_id:string,client_secret:string,sub_key:string,msn:string}
     */
    public static function betalingNokler(): array
    {
        $ett = static function (string $eget, string $felles): string {
            $v = trim((string) Config::hent($eget, ''));
            return $v !== '' ? $v : (string) Config::krev($felles);
        };

        return [
            'client_id'     => $ett('vipps_betaling_client_id', 'vipps_client_id'),
            'client_secret' => $ett('vipps_betaling_client_secret', 'vipps_client_secret'),
            'sub_key'       => $ett('vipps_betaling_sub_key', 'vipps_sub_key'),
            'msn'           => $ett('vipps_betaling_msn', 'vipps_msn'),
        ];
    }

    /** Har betalingen sitt eget sett, eller deler den med innloggingen? */
    public static function egneBetalingsnokler(): bool
    {
        foreach (['vipps_betaling_client_id', 'vipps_betaling_client_secret',
                  'vipps_betaling_sub_key', 'vipps_betaling_msn'] as $n) {
            if (trim((string) Config::hent($n, '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Adgangstoken for API-kallene. Vipps sine varer i en time; vi holder på
     * det i minnet gjennom forespørselen og fornyer litt før utløp.
     *
     * Dette er tokenet for betaling. Innloggingen har sin egen flyt (OIDC) og
     * bruker ikke dette.
     */
    public static function token(): string
    {
        if (self::$token !== null && time() < self::$tokenUtloper) {
            return self::$token;
        }

        $n = self::betalingNokler();
        $svar = http_post_form(
            Config::vippsBase() . '/accesstoken/get',
            [],
            [
                'client_id: ' . $n['client_id'],
                'client_secret: ' . $n['client_secret'],
                'Ocp-Apim-Subscription-Key: ' . $n['sub_key'],
                'Merchant-Serial-Number: ' . $n['msn'],
            ]
        );

        if ($svar['status'] !== 200) {
            logg_feil('Fikk ikke adgangstoken fra Vipps: HTTP ' . $svar['status']);
            throw new RuntimeException('Vipps svarte ikke som forventet.');
        }

        $d = json_decode($svar['kropp'], true);
        $token = (string) ($d['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Vipps ga ikke noe adgangstoken.');
        }

        self::$token = $token;
        // Trekk fra et minutt, så vi ikke bruker et token som ryker underveis.
        self::$tokenUtloper = time() + max(60, (int) ($d['expires_in'] ?? 3600)) - 60;

        return $token;
    }

    /**
     * Hodene ePayment og Recurring skal ha. Alltid betalingsnoeklene — et
     * token fra én salgsenhet og et MSN fra en annen gir 401.
     *
     * @return list<string>
     */
    public static function headere(?string $idempotensNokkel = null): array
    {
        $n = self::betalingNokler();
        $h = [
            'Authorization: Bearer ' . self::token(),
            'Ocp-Apim-Subscription-Key: ' . $n['sub_key'],
            'Merchant-Serial-Number: ' . $n['msn'],
            'Vipps-System-Name: lissom',
            'Vipps-System-Version: 1.0',
        ];
        if ($idempotensNokkel !== null) {
            $h[] = 'Idempotency-Key: ' . $idempotensNokkel;
        }
        return $h;
    }

    // -----------------------------------------------------------------------
    // Login (OIDC)
    // -----------------------------------------------------------------------

    /**
     * Bygger adressen brukeren sendes til for å logge inn.
     * `state` lagres i databasen og sjekkes ved retur — det er CSRF-vernet.
     */
    public static function loginUrl(string $state, string $returUrl = '/'): string
    {
        DB::settInn('login_states', [
            'state'      => $state,
            'retur_url'  => mb_substr($returUrl, 0, 255),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
        ]);

        return Config::vippsBase() . '/access-management-1.0/access/oauth2/auth?' . http_build_query([
            'client_id'     => Config::krev('vipps_client_id'),
            'response_type' => 'code',
            'scope'         => 'openid name phoneNumber email',
            'state'         => $state,
            'redirect_uri'  => self::returAdresse(),
        ]);
    }

    /** Må stemme nøyaktig med det som er hvitlistet i Vipps-portalen. */
    public static function returAdresse(): string
    {
        return (string) Config::hent('vipps_redirect_uri', Config::nettsted() . '/api/vipps-callback.php');
    }

    /**
     * Bytter engangskoden fra Vipps mot et token, og henter profilen.
     *
     * @return array{sub:string,navn:string,epost:string,telefon:string}
     */
    /**
     * Finner eller oppretter medlemmet en Vipps-profil hoerer til.
     *
     * Tre veier inn til den samme personen, i denne rekkefolgen:
     *
     *   1. vipps_sub  — har hen logget inn for, er det henne
     *   2. telefon    — meldt inn for haand, eller gjest paa et kurs
     *   3. e-post     — det samme, naar nummeret mangler eller er et annet
     *
     * Uten den tredje sto den samme personen to ganger: meldt inn for haand
     * med e-post og uten telefon, og saa en gang til den dagen hun logget inn
     * med Vipps. Innmeldingsskjemaet slaar opp paa begge to; denne veien inn
     * gjorde det bare paa nummeret.
     *
     * Vi knytter oss bare til rader som ikke alt hoerer til en annen
     * Vipps-konto, og ikke til anonymiserte.
     *
     * Ligger metoden her og ikke i endepunktet, er det fordi den kan proeves:
     * en OAuth-runde mot Vipps kan ikke kjores i en test, men dette kan.
     *
     * @param array{sub:string,navn:string,epost:string,telefon:string} $profil
     */
    public static function medlemFraProfil(array $profil): int
    {
        $medlem = DB::en('SELECT id, rolle FROM members WHERE vipps_sub = :s', ['s' => $profil['sub']]);

        if ($medlem === null && ($profil['telefon'] ?? '') !== '') {
            $medlem = DB::en(
                'SELECT id, rolle FROM members
                  WHERE telefon = :t AND vipps_sub IS NULL AND anonymisert_at IS NULL
                  LIMIT 1',
                ['t' => $profil['telefon']]
            );
        }

        if ($medlem === null && ($profil['epost'] ?? '') !== '') {
            $medlem = DB::en(
                'SELECT id, rolle FROM members
                  WHERE epost = :e AND vipps_sub IS NULL AND anonymisert_at IS NULL
                  LIMIT 1',
                ['e' => $profil['epost']]
            );
        }

        $erAdminNummer = ($profil['telefon'] ?? '') !== ''
            && in_array($profil['telefon'], Config::adminNumre(), true);

        if ($medlem === null) {
            return DB::settInn('members', [
                'vipps_sub' => $profil['sub'],
                'navn'      => $profil['navn'],
                'epost'     => ($profil['epost'] ?? '') !== '' ? $profil['epost'] : null,
                'telefon'   => ($profil['telefon'] ?? '') !== '' ? $profil['telefon'] : null,
                'rolle'     => $erAdminNummer ? 'admin' : 'medlem',
            ]);
        }

        $endringer = [
            'vipps_sub' => $profil['sub'],
            'navn'      => $profil['navn'],
        ];
        if (($profil['epost'] ?? '') !== '')   { $endringer['epost'] = $profil['epost']; }
        if (($profil['telefon'] ?? '') !== '') { $endringer['telefon'] = $profil['telefon']; }
        // Admin-rollen settes kun oppover herfra. Aa ta den bort gjores i
        // admin, slik at et nummer som fjernes fra noedlista ikke mister
        // tilgangen ved et uhell.
        if ($erAdminNummer && $medlem['rolle'] !== 'admin') { $endringer['rolle'] = 'admin'; }

        DB::oppdater('members', $endringer, ['id' => $medlem['id']]);
        return (int) $medlem['id'];
    }

    public static function hentProfil(string $kode): array
    {
        $svar = http_post_form(
            Config::vippsBase() . '/access-management-1.0/access/oauth2/token',
            [
                'grant_type'    => 'authorization_code',
                'code'          => $kode,
                'redirect_uri'  => self::returAdresse(),
                'client_id'     => Config::krev('vipps_client_id'),
                'client_secret' => Config::krev('vipps_client_secret'),
            ],
            ['Ocp-Apim-Subscription-Key: ' . Config::krev('vipps_sub_key')]
        );

        if ($svar['status'] !== 200) {
            logg_feil('Vipps token-bytte feilet: HTTP ' . $svar['status'] . ' ' . $svar['kropp']);
            throw new RuntimeException('Innloggingen feilet hos Vipps.');
        }

        $token = (string) (json_decode($svar['kropp'], true)['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Innloggingen feilet hos Vipps.');
        }

        $bruker = http_get_json(Config::vippsBase() . '/vipps-userinfo-api/userinfo', [
            'Authorization: Bearer ' . $token,
            'Ocp-Apim-Subscription-Key: ' . Config::krev('vipps_sub_key'),
        ]);

        if ($bruker['status'] !== 200 || !is_array($bruker['json'])) {
            throw new RuntimeException('Fikk ikke hentet profilen din fra Vipps.');
        }

        $p = $bruker['json'];
        $sub = (string) ($p['sub'] ?? '');
        if ($sub === '') {
            throw new RuntimeException('Vipps ga ingen bruker-ID.');
        }

        return [
            'sub'     => $sub,
            'navn'    => trim((string) ($p['name'] ?? '')),
            'epost'   => trim((string) ($p['email'] ?? '')),
            'telefon' => normaliser_telefon((string) ($p['phone_number'] ?? '')),
        ];
    }

    // -----------------------------------------------------------------------
    // ePayment
    // -----------------------------------------------------------------------

    /**
     * Oppretter en betaling og returnerer adressen brukeren skal sendes til.
     *
     * Beløpet kommer ALLTID fra databasen, aldri fra nettleseren.
     *
     * @return array{url:string,referanse:string}
     */
    public static function opprettBetaling(
        string $referanse,
        int $belopOre,
        string $beskrivelse,
        string $returUrl,
        ?string $telefon = null
    ): array {
        $idempotens = self::uuid();

        $kropp = [
            'amount' => [
                'currency' => 'NOK',
                'value'    => $belopOre,
            ],
            'paymentMethod'     => ['type' => 'WALLET'],
            'reference'         => $referanse,
            'userFlow'          => 'WEB_REDIRECT',
            'returnUrl'         => $returUrl,
            'paymentDescription'=> mb_substr($beskrivelse, 0, 100),
        ];

        if ($telefon !== null && $telefon !== '') {
            // Vipps vil ha nummeret uten pluss, med landkode.
            $kropp['customer'] = ['phoneNumber' => ltrim($telefon, '+')];
        }

        $svar = http_post_json(
            Config::vippsBase() . '/epayment/v1/payments',
            $kropp,
            self::headere($idempotens)
        );

        if ($svar['status'] !== 201 || !is_array($svar['json'])) {
            logg_feil('Kunne ikke opprette Vipps-betaling: HTTP ' . $svar['status'] . ' ' . $svar['kropp']);
            throw new RuntimeException('Fikk ikke startet betalingen. Prøv igjen om litt.');
        }

        return [
            'url'       => (string) ($svar['json']['redirectUrl'] ?? ''),
            'referanse' => (string) ($svar['json']['reference'] ?? $referanse),
        ];
    }

    // -----------------------------------------------------------------------
    // Recurring — avtaler og manedstrekk
    // -----------------------------------------------------------------------

    /**
     * Oppretter en avtale kunden godkjenner i Vipps.
     *
     * Avtalen er ikke et trekk. Den er en fullmakt: etter godkjenning kan vi
     * belaste den hver maned, til noen stopper den. Forste trekk gjor vi selv
     * med belastAvtale() — det gir oss ett sted a fore alle trekk, framfor at
     * det forste kommer en annen vei enn resten.
     *
     * @return array{url:string,avtaleId:string}
     */
    public static function opprettAvtale(
        string $plan,
        int $prisOre,
        string $beskrivelse,
        string $returUrl,
        ?string $telefon = null
    ): array {
        $kropp = [
            'pricing' => [
                'type'     => 'LEGACY',
                'amount'   => $prisOre,
                'currency' => 'NOK',
            ],
            'interval' => [
                'unit'  => 'MONTH',
                'count' => 1,
            ],
            'merchantRedirectUrl'   => $returUrl,
            'merchantAgreementUrl'  => Config::nettsted() . '/min-side',
            'productName'           => mb_substr($plan, 0, 45),
            'productDescription'    => mb_substr($beskrivelse, 0, 100),
            // Ingen kampanje og ingen forste trekk her: vi belaster selv, sa
            // alle trekk far samme vei gjennom systemet.
            'scope'                 => 'name phoneNumber',
        ];

        if ($telefon !== null && $telefon !== '') {
            $kropp['phoneNumber'] = ltrim($telefon, '+');
        }

        $svar = http_post_json(
            Config::vippsBase() . '/recurring/v3/agreements',
            $kropp,
            self::headere(self::uuid())
        );

        if ($svar['status'] !== 201 || !is_array($svar['json'])) {
            logg_feil('Kunne ikke opprette Vipps-avtale: HTTP ' . $svar['status'] . ' ' . $svar['kropp']);
            throw new RuntimeException('Fikk ikke opprettet avtalen. Prøv igjen om litt.');
        }

        return [
            'url'      => (string) ($svar['json']['vippsConfirmationUrl'] ?? ''),
            'avtaleId' => (string) ($svar['json']['agreementId'] ?? ''),
        ];
    }

    /**
     * Status pa en avtale. Fasiten — vi stoler ikke pa at kunden kom tilbake
     * til kvitteringssiden.
     *
     * @return array<string,mixed>
     */
    public static function hentAvtale(string $avtaleId): array
    {
        $svar = http_get_json(
            Config::vippsBase() . '/recurring/v3/agreements/' . rawurlencode($avtaleId),
            self::headere()
        );

        if ($svar['status'] !== 200 || !is_array($svar['json'])) {
            throw new RuntimeException('Fikk ikke hentet avtalen fra Vipps.');
        }
        return $svar['json'];
    }

    /** Stopper en avtale. Etter dette kan den ikke belastes igjen. */
    public static function stoppAvtale(string $avtaleId): void
    {
        $svar = http_patch_json(
            Config::vippsBase() . '/recurring/v3/agreements/' . rawurlencode($avtaleId),
            ['status' => 'STOPPED'],
            self::headere(self::uuid())
        );

        if ($svar['status'] !== 200 && $svar['status'] !== 204) {
            logg_feil('Kunne ikke stoppe Vipps-avtale: HTTP ' . $svar['status'] . ' ' . $svar['kropp']);
            throw new RuntimeException('Fikk ikke sagt opp avtalen. Ta kontakt, sa ordner vi det.');
        }
    }

    /**
     * Belaster en avtale.
     *
     * Vipps krever at kunden varsles for trekket. Vi ber om trekk noen dager
     * fram i tid og sender varselet selv — da vet kunden hva som kommer, og
     * rekker a si fra.
     *
     * @return string charge-ID-en
     */
    public static function belastAvtale(
        string $avtaleId,
        int $belopOre,
        string $beskrivelse,
        string $forfall,
        string $idempotensnokkel
    ): string {
        $kropp = [
            'amount'          => $belopOre,
            'description'     => mb_substr($beskrivelse, 0, 45),
            'due'             => $forfall,          // YYYY-MM-DD
            'retryDays'       => 5,
            'transactionType' => 'DIRECT_CAPTURE',
        ];

        $svar = http_post_json(
            Config::vippsBase() . '/recurring/v3/agreements/' . rawurlencode($avtaleId) . '/charges',
            $kropp,
            // Idempotensnokkelen er trekkets egen, ikke en tilfeldig: kjorer
            // cron to ganger samme natt, skal kunden belastes én gang.
            self::headere($idempotensnokkel)
        );

        if ($svar['status'] !== 201 || !is_array($svar['json'])) {
            logg_feil('Kunne ikke belaste Vipps-avtale: HTTP ' . $svar['status'] . ' ' . $svar['kropp']);
            throw new RuntimeException('Trekket gikk ikke gjennom.');
        }

        return (string) ($svar['json']['chargeId'] ?? '');
    }

    /**
     * Henter status. Denne er fasiten — vi stoler aldri på at kunden kom
     * tilbake til kvitteringssiden, bare på webhook og dette oppslaget.
     *
     * @return array<string,mixed>
     */
    public static function hentBetaling(string $referanse): array
    {
        $svar = http_get_json(
            Config::vippsBase() . '/epayment/v1/payments/' . rawurlencode($referanse),
            self::headere()
        );

        if ($svar['status'] !== 200 || !is_array($svar['json'])) {
            throw new RuntimeException('Fikk ikke hentet betalingsstatus fra Vipps.');
        }
        return $svar['json'];
    }

    /** Trekker pengene etter at betalingen er godkjent. */
    public static function trekk(string $referanse, int $belopOre): array
    {
        $svar = http_post_json(
            Config::vippsBase() . '/epayment/v1/payments/' . rawurlencode($referanse) . '/capture',
            ['modificationAmount' => ['currency' => 'NOK', 'value' => $belopOre]],
            self::headere(self::uuid())
        );

        if ($svar['status'] >= 300) {
            logg_feil('Trekk feilet for ' . $referanse . ': HTTP ' . $svar['status'] . ' ' . $svar['kropp']);
            throw new RuntimeException('Fikk ikke trukket betalingen.');
        }
        return is_array($svar['json']) ? $svar['json'] : [];
    }

    /** Refusjon. Beløpet regnes ut fra avbestillingsreglene i vilkårene. */
    public static function refunder(string $referanse, int $belopOre): array
    {
        $svar = http_post_json(
            Config::vippsBase() . '/epayment/v1/payments/' . rawurlencode($referanse) . '/refund',
            ['modificationAmount' => ['currency' => 'NOK', 'value' => $belopOre]],
            self::headere(self::uuid())
        );

        if ($svar['status'] >= 300) {
            logg_feil('Refusjon feilet for ' . $referanse . ': HTTP ' . $svar['status'] . ' ' . $svar['kropp']);
            throw new RuntimeException('Fikk ikke refundert betalingen.');
        }
        return is_array($svar['json']) ? $svar['json'] : [];
    }

    /** Avbryter en betaling som ennå ikke er trukket. */
    public static function avbryt(string $referanse): void
    {
        http_post_json(
            Config::vippsBase() . '/epayment/v1/payments/' . rawurlencode($referanse) . '/cancel',
            [],
            self::headere(self::uuid())
        );
    }

    // -----------------------------------------------------------------------
    // Hjelpere
    // -----------------------------------------------------------------------

    /** Vår egen ordrereferanse, samme form som i designet: LIS-10482 */
    // -----------------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------------

    /**
     * Hendelsene vi vil ha beskjed om.
     *
     * Navnene her er Vipps sine; det handleren leser er den korte formen
     * («AUTHORIZED») som kommer i selve meldingen. Vi ber om alle som endrer
     * en betaling — og «created», som er den eneste maaten aa se at noe ble
     * paabegynt og aldri fullfort.
     */
    public const WEBHOOK_HENDELSER = [
        'epayments.payment.created.v1',
        'epayments.payment.authorized.v1',
        'epayments.payment.captured.v1',
        'epayments.payment.refunded.v1',
        'epayments.payment.cancelled.v1',
        'epayments.payment.aborted.v1',
        'epayments.payment.expired.v1',
        'epayments.payment.terminated.v1',
    ];

    /** Adressen Vipps skal melde fra til. Maa vaere https. */
    public static function webhookAdresse(): string
    {
        return Config::nettsted() . '/api/vipps-webhook.php';
    }

    /**
     * Webhookene som er registrert paa salgsenheten for betaling.
     *
     * @return list<array{id:string,url:string,events:list<string>}>
     */
    public static function webhooks(): array
    {
        $svar = http_get_json(Config::vippsBase() . '/webhooks/v1/webhooks', self::headere());
        if ($svar['status'] !== 200 || !is_array($svar['json'])) {
            throw new RuntimeException('Vipps svarte ' . $svar['status'] . ' på webhook-lista.');
        }
        return array_map(static fn($w): array => [
            'id'     => (string) ($w['id'] ?? ''),
            'url'    => (string) ($w['url'] ?? ''),
            'events' => array_map('strval', (array) ($w['events'] ?? [])),
        ], (array) ($svar['json']['webhooks'] ?? []));
    }

    /**
     * Registrerer webhooken og gir tilbake hemmeligheten Vipps signerer med.
     *
     * Hemmeligheten vises bare denne ene gangen — den maa lagres med det
     * samme, ellers maa webhooken registreres paa nytt.
     *
     * @return array{id:string,secret:string}
     */
    public static function registrerWebhook(): array
    {
        $svar = http_post_json(
            Config::vippsBase() . '/webhooks/v1/webhooks',
            ['url' => self::webhookAdresse(), 'events' => self::WEBHOOK_HENDELSER],
            self::headere()
        );

        if ($svar['status'] !== 201 || !is_array($svar['json'])) {
            logg_feil('Kunne ikke registrere webhook: HTTP ' . $svar['status'] . ' ' . $svar['kropp']);
            throw new RuntimeException('Vipps svarte ' . $svar['status'] . ': '
                . mb_substr($svar['kropp'], 0, 300));
        }

        $hemmelighet = (string) ($svar['json']['secret'] ?? '');
        if ($hemmelighet === '') {
            throw new RuntimeException('Vipps ga ingen hemmelighet tilbake.');
        }

        return ['id' => (string) ($svar['json']['id'] ?? ''), 'secret' => $hemmelighet];
    }

    /** Fjerner en registrert webhook. */
    public static function slettWebhook(string $id): void
    {
        $svar = http_kall(
            Config::vippsBase() . '/webhooks/v1/webhooks/' . rawurlencode($id),
            'DELETE',
            null,
            self::headere()
        );
        if ($svar['status'] !== 204 && $svar['status'] !== 200) {
            throw new RuntimeException('Vipps svarte ' . $svar['status'] . ' på sletting.');
        }
    }

    public static function nyReferanse(string $prefiks = 'LIS'): string
    {
        return sprintf('%s-%s-%s', $prefiks, gmdate('ymd'), strtoupper(bin2hex(random_bytes(4))));
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}

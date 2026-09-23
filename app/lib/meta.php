<?php
/**
 * Publisering til Instagram og Facebook.
 *
 * ── Hvorfor ──────────────────────────────────────────────────────────
 *
 * «Sosiale medier»-utkastene var tekst du kopierte ut og limte inn selv.
 * Eieren, 23. september 2026: «kan vi faa en meta publisering i systemet?»
 *
 * ── App Review trengs ikke ───────────────────────────────────────────
 *
 * Instagram Content Publishing krever normalt at Meta godkjenner appen —
 * uker med venting. Det gjelder bare naar man publiserer paa vegne av
 * ANDRES kontoer. Til sin egen holder det at kontoen staar som Instagram
 * Tester i appen, og da virker det uten review i det hele tatt.
 *
 * ── Alltid et trykk ──────────────────────────────────────────────────
 *
 * Ingenting her kalles fra en jobb eller fra Autopilot. Verkstedet har en
 * autopilot som lager utkast av seg selv, og et utkast som legger seg ut
 * paa Instagram uten at noen har lest det er ikke en funksjon — det er en
 * feil som skjer i offentligheten. Publisering skjer bare fra et trykk.
 *
 * ── Tre steg, ikke ett ───────────────────────────────────────────────
 *
 * Instagram tar ikke imot bildet. De henter det selv fra en adresse, og
 * det skjer i tre trinn: lag en beholder, vent til den er ferdig, publiser
 * den. Bildene vaare ligger alt paa en offentlig adresse
 * («lissom.no/api/bilde.php?artikkel=…»), saa den delen er loest.
 *
 * ── Tokenet ──────────────────────────────────────────────────────────
 *
 * Et vanlig side-token varer 60 dager og slutter saa aa virke uten at noen
 * sier fra. Et System User-token fra Business Manager utloeper ikke, og er
 * det som boer staa her. Klassen sier fra naar Meta svarer at tokenet er
 * utgaatt, framfor aa la publiseringen stille feile.
 */

declare(strict_types=1);

final class Meta
{
    /** Versjonen av Graph API vi snakker med. Staar i oppsettet, ikke her. */
    public const VERSJON_STANDARD = 'v21.0';

    private const BASE = 'https://graph.facebook.com/';

    /** Hvor lenge vi venter paa at Instagram blir ferdig med beholderen. */
    private const MAKS_FORSOK = 12;
    private const PAUSE_SEK = 2;

    public static function token(): string
    {
        return trim((string) Config::hent('meta_token', ''));
    }

    public static function igId(): string
    {
        return trim((string) Config::hent('meta_ig_id', ''));
    }

    public static function sideId(): string
    {
        return trim((string) Config::hent('meta_side_id', ''));
    }

    public static function versjon(): string
    {
        $v = trim((string) Config::hent('meta_versjon', ''));
        return $v !== '' ? $v : self::VERSJON_STANDARD;
    }

    public static function klarForInstagram(): bool
    {
        return self::token() !== '' && self::igId() !== '';
    }

    public static function klarForFacebook(): bool
    {
        return self::token() !== '' && self::sideId() !== '';
    }

    /**
     * Hva skjermen trenger for aa si hvordan det staar til.
     *
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        $t = self::token();
        return [
            'instagram' => self::klarForInstagram(),
            'facebook'  => self::klarForFacebook(),
            'igId'      => self::igId(),
            'sideId'    => self::sideId(),
            'versjon'   => self::versjon(),
            'harToken'  => $t !== '',
            'mangler'   => $t === ''
                ? 'Lim inn et System User-token fra Meta Business Manager.'
                : (self::igId() === '' ? 'Instagram-kontoens id mangler.' : null),
        ];
    }

    /**
     * Sjekker at tokenet virker, og hvem det gjelder.
     *
     * Kalles fra oppsettet saa eieren faar vite med én gang om det er
     * riktig — framfor aa oppdage det naar han trykker publiser.
     *
     * @return array{ok: bool, navn: string, feil: string}
     */
    public static function sjekk(): array
    {
        if (self::token() === '') {
            return ['ok' => false, 'navn' => '', 'feil' => 'Tokenet er ikke lagt inn.'];
        }
        $id = self::igId() !== '' ? self::igId() : self::sideId();
        if ($id === '') {
            return ['ok' => false, 'navn' => '', 'feil' => 'Verken Instagram-id eller side-id er lagt inn.'];
        }

        try {
            $svar = self::kall('GET', $id, ['fields' => 'name,username']);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'navn' => '', 'feil' => $e->getMessage()];
        }

        $navn = (string) ($svar['username'] ?? $svar['name'] ?? '');
        return ['ok' => $navn !== '', 'navn' => $navn,
                'feil' => $navn === '' ? 'Meta svarte, men sa ikke hvem kontoen er.' : ''];
    }

    /**
     * Legger ut ett bilde med tekst paa Instagram.
     *
     * @param string $bildeUrl Offentlig adresse. Instagram henter den selv.
     * @return array{id: string, lenke: string}
     */
    public static function publiserInstagram(string $bildeUrl, string $tekst): array
    {
        if (!self::klarForInstagram()) {
            throw new RuntimeException(
                'Instagram er ikke koblet til ennå. Legg inn token og konto-id under '
                . 'Markedsføring → Oppsett.'
            );
        }
        if (!str_starts_with($bildeUrl, 'https://')) {
            throw new RuntimeException(
                'Instagram henter bildet selv, og krever en https-adresse. '
                . 'Bildet må ligge ute på nettsiden først.'
            );
        }

        $ig = self::igId();

        // 1. Beholderen.
        //
        // En video er en Reel hos Instagram, ikke et innlegg med bilde:
        // egen medietype, og adressen heter «video_url». Sender man en mp4
        // som «image_url», svarer de at fila ikke er et bilde — og det er
        // en sann, men ubrukelig, feilmelding.
        $erVideo = str_contains($bildeUrl, '.mp4') || str_contains($bildeUrl, 'video=');
        $beholder = self::kall('POST', $ig . '/media', $erVideo
            ? [
                'media_type' => 'REELS',
                'video_url'  => $bildeUrl,
                'caption'    => mb_substr($tekst, 0, 2200),
            ]
            : [
                'image_url' => $bildeUrl,
                'caption'   => mb_substr($tekst, 0, 2200),
            ]);
        $id = (string) ($beholder['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Instagram lagde ingen beholder for bildet.');
        }

        // 2. Vent til den er ferdig.
        //
        // Instagram laster ned bildet i bakgrunnen. Publiserer vi for tidlig,
        // svarer de «Media ID is not available» — en feil som ser ut som noe
        // annet enn «vent litt».
        // En video skal lastes ned OG kodes om hos Instagram. Tolv forsok
        // paa to sekunder holder til et bilde, ikke til en Reel.
        $forsok = $erVideo ? 60 : self::MAKS_FORSOK;
        $status = '';
        for ($i = 0; $i < $forsok; $i++) {
            $s = self::kall('GET', $id, ['fields' => 'status_code,status']);
            $status = (string) ($s['status_code'] ?? '');
            if ($status === 'FINISHED') {
                break;
            }
            if ($status === 'ERROR' || $status === 'EXPIRED') {
                throw new RuntimeException(
                    'Instagram klarte ikke å hente bildet: ' . (string) ($s['status'] ?? $status)
                );
            }
            sleep(self::PAUSE_SEK);
        }
        if ($status !== 'FINISHED') {
            throw new RuntimeException(
                'Instagram ble ikke ferdig med bildet i tide. Prøv igjen om et minutt — '
                . 'beholderen står klar hos dem i 24 timer.'
            );
        }

        // 3. Publiser.
        $ut = self::kall('POST', $ig . '/media_publish', ['creation_id' => $id]);
        $innleggId = (string) ($ut['id'] ?? '');
        if ($innleggId === '') {
            throw new RuntimeException('Instagram publiserte ikke innlegget.');
        }

        $lenke = '';
        try {
            $p = self::kall('GET', $innleggId, ['fields' => 'permalink']);
            $lenke = (string) ($p['permalink'] ?? '');
        } catch (RuntimeException) {
            // Lenka er en bonus. Innlegget staar ute uansett.
        }

        return ['id' => $innleggId, 'lenke' => $lenke];
    }

    /**
     * Legger ut ett bilde med tekst paa Facebook-sida.
     *
     * Enklere enn Instagram: ett kall, og Facebook henter bildet selv.
     *
     * @return array{id: string, lenke: string}
     */
    public static function publiserFacebook(string $bildeUrl, string $tekst): array
    {
        if (!self::klarForFacebook()) {
            throw new RuntimeException(
                'Facebook-sida er ikke koblet til ennå. Legg inn side-id under '
                . 'Markedsføring → Oppsett.'
            );
        }

        // Sidens eget token, ikke systembrukerens.
        //
        // Instagram-veien gaar gjennom kontoens egen id og godtar
        // systembruker-tokenet. En Facebook-side gjor ikke det: Graph svarte
        // kode 200 «Appen mangler tillatelse til aa publisere» paa
        // /{side}/photos, ogsaa med pages_manage_posts paa tokenet. Sida vil
        // ha et side-token, og det hentes fra sida selv.
        //
        // Maalt 23. september 2026, foerste gang noe ble lagt ut: Instagram
        // gikk gjennom, Facebook stoppet her.
        $ut = self::kall('POST', self::sideId() . '/photos', [
            'url'     => $bildeUrl,
            'message' => mb_substr($tekst, 0, 5000),
        ], self::sideToken());
        $id = (string) ($ut['post_id'] ?? $ut['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Facebook publiserte ikke innlegget.');
        }
        return ['id' => $id, 'lenke' => 'https://www.facebook.com/' . $id];
    }

    /**
     * Sidens eget token, hentet med systembrukerens.
     *
     * Hentes én gang per foresporsel. Feiler det, gaar vi videre med
     * systembruker-tokenet — da sier Meta fra selv, og feilmeldinga blir
     * den samme som for.
     */
    private static function sideToken(): ?string
    {
        static $token = null;
        if ($token !== null) {
            return $token === '' ? null : $token;
        }
        try {
            $svar = self::kall('GET', self::sideId(), ['fields' => 'access_token']);
            $token = trim((string) ($svar['access_token'] ?? ''));
        } catch (Throwable) {
            $token = '';
        }
        return $token === '' ? null : $token;
    }

    // ── Selve kallet ─────────────────────────────────────────────────

    /**
     * Ett kall mot Graph API.
     *
     * Tokenet gaar i et hode og ikke i adressen: en adresse havner i
     * serverlogger og i feilmeldinger, et hode gjor det ikke.
     *
     * @param array<string,string> $felter
     * @return array<string,mixed>
     */
    private static function kall(string $metode, string $sti, array $felter = [], ?string $token = null): array
    {
        $url = self::BASE . self::versjon() . '/' . ltrim($sti, '/');
        $kropp = null;

        if ($metode === 'GET') {
            $url .= '?' . http_build_query($felter);
        } else {
            $kropp = http_build_query($felter);
        }

        $svar = http_kall($url, $metode, $kropp, array_filter([
            'Authorization: Bearer ' . ($token ?? self::token()),
            $metode === 'GET' ? null : 'Content-Type: application/x-www-form-urlencoded',
        ]), 30);

        $json = json_decode((string) $svar['kropp'], true);
        if (!is_array($json)) {
            throw new RuntimeException('Meta svarte med noe vi ikke kunne lese.');
        }

        if ((int) $svar['status'] !== 200 || isset($json['error'])) {
            $f = $json['error'] ?? [];
            $melding = (string) ($f['message'] ?? 'Ukjent feil');
            $kode = (int) ($f['code'] ?? 0);

            // Oversett de som faktisk skjer, til noe eieren kan gjore noe med.
            throw new RuntimeException(match (true) {
                $kode === 190 => 'Tokenet er utløpt eller trukket tilbake. Hent et nytt '
                               . 'System User-token i Meta Business Manager — det utløper ikke.',
                $kode === 200 || $kode === 10 => 'Appen mangler tillatelse til å publisere. '
                               . 'Tokenet trenger instagram_content_publish for Instagram og '
                               . 'pages_manage_posts for Facebook-sida — og systembrukeren må ha '
                               . 'tilgang til begge i Meta Business Manager.',
                $kode === 100 => 'Meta kjente ikke igjen noe i kallet: ' . $melding,
                $kode === 4 || $kode === 32 => 'For mange innlegg på kort tid. Instagram tillater '
                               . '25 innlegg i døgnet. Vent litt.',
                default => 'Meta svarte: ' . $melding,
            });
        }

        return $json;
    }
}

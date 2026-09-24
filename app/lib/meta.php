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
     * Legger ut ett bilde eller én video med tekst paa Facebook-sida.
     *
     * Enklere enn Instagram: ett kall, og Facebook henter fila selv.
     *
     * En video gaar til /videos og ikke /photos, og teksten heter
     * «description» der «message» staar paa et bilde. Sender man en mp4 til
     * /photos, svarer Facebook at fila ikke er et bilde.
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

        // Video eller bilde? To ulike adresser hos Facebook.
        //
        // Var ikke bygget for 23. september 2026 — api/admin/meta.php stoppet
        // en video med en beskjed om at den ikke var koblet paa. Eieren ba om
        // den samme kveld: «i saafall bygger du det og video til facebook».
        $erVideo = str_contains($bildeUrl, '.mp4') || str_contains($bildeUrl, 'video=');

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
        $ut = $erVideo
            ? self::kall('POST', self::sideId() . '/videos', [
                'file_url'    => $bildeUrl,
                'description' => mb_substr($tekst, 0, 5000),
            ], self::sideToken())
            : self::kall('POST', self::sideId() . '/photos', [
                'url'     => $bildeUrl,
                'message' => mb_substr($tekst, 0, 5000),
            ], self::sideToken());

        $id = (string) ($ut['post_id'] ?? $ut['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Facebook publiserte ikke innlegget.');
        }

        // En video svarer med sin egen id, ikke med en innleggs-id. Lenka
        // til selve innlegget finnes foerst naar Facebook har kodet ferdig,
        // saa den peker paa videoen — den virker med det samme.
        return [
            'id'    => $id,
            'lenke' => $erVideo
                ? 'https://www.facebook.com/' . self::sideId() . '/videos/' . $id
                : 'https://www.facebook.com/' . $id,
        ];
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

    // ── Innboksen: kommentarer og meldinger ─────────────────────────
    //
    // Eieren, 23. september 2026: «kan du faktisk svare paa kommentarer og
    // spoersmaal paa insta og face?» Svaret var nei — tokenet hadde lov,
    // men koden kunne bare publisere. Han ba om baade kommentarer og
    // direktemeldinger.
    //
    // Alt her LESER, eller svarer paa noe en kunde har skrevet foerst. Et
    // svar fra verkstedet staar offentlig, og det finnes ingen vei hit fra
    // en cron-jobb eller fra Autopilot — samme regel som publisering.

    /** Hvor mange innlegg og samtaler vi henter om gangen. */
    public const INNBOKS_ANTALL = 15;

    /**
     * Kommentarene paa de siste innleggene, nyeste foerst.
     *
     * Begge kanaler i ett kall hver: Graph tar med kommentarene som et felt
     * paa innlegget, saa vi slipper ett kall per innlegg.
     *
     * Feiler den ene kanalen, kommer den andre likevel. En innboks som er
     * tom fordi Instagram hikket er verre enn en halv innboks med en
     * merknad.
     *
     * @return array{poster: list<array<string,mixed>>, feil: list<string>}
     */
    public static function kommentarer(int $maks = self::INNBOKS_ANTALL): array
    {
        $ut = [];
        $feil = [];

        if (self::klarForInstagram()) {
            try {
                $svar = self::kall('GET', self::igId() . '/media', [
                    'fields' => 'id,permalink,caption,timestamp,'
                              . 'comments{id,text,username,timestamp,replies{id}}',
                    'limit'  => (string) $maks,
                ]);
                foreach ((array) ($svar['data'] ?? []) as $innlegg) {
                    foreach ((array) ($innlegg['comments']['data'] ?? []) as $k) {
                        $ut[] = [
                            'id'      => (string) ($k['id'] ?? ''),
                            'kanal'   => 'Instagram',
                            'fra'     => (string) ($k['username'] ?? 'Ukjent'),
                            'tekst'   => (string) ($k['text'] ?? ''),
                            'tid'     => (string) ($k['timestamp'] ?? ''),
                            'paa'     => self::kort((string) ($innlegg['caption'] ?? '')),
                            'lenke'   => (string) ($innlegg['permalink'] ?? ''),
                            'svart'   => ((array) ($k['replies']['data'] ?? [])) !== [],
                        ];
                    }
                }
            } catch (RuntimeException $e) {
                $feil[] = 'Instagram: ' . $e->getMessage();
            }
        }

        if (self::klarForFacebook()) {
            try {
                $svar = self::kall('GET', self::sideId() . '/feed', [
                    'fields' => 'id,permalink_url,message,created_time,'
                              . 'comments{id,message,from,created_time,comments{id}}',
                    'limit'  => (string) $maks,
                ], self::sideToken());
                foreach ((array) ($svar['data'] ?? []) as $innlegg) {
                    foreach ((array) ($innlegg['comments']['data'] ?? []) as $k) {
                        // Vaare egne svar skal ikke staa som ubesvarte
                        // spoersmaal i innboksen.
                        if ((string) ($k['from']['id'] ?? '') === self::sideId()) {
                            continue;
                        }
                        $ut[] = [
                            'id'      => (string) ($k['id'] ?? ''),
                            'kanal'   => 'Facebook',
                            'fra'     => (string) ($k['from']['name'] ?? 'Ukjent'),
                            'tekst'   => (string) ($k['message'] ?? ''),
                            'tid'     => (string) ($k['created_time'] ?? ''),
                            'paa'     => self::kort((string) ($innlegg['message'] ?? '')),
                            'lenke'   => (string) ($innlegg['permalink_url'] ?? ''),
                            'svart'   => ((array) ($k['comments']['data'] ?? [])) !== [],
                        ];
                    }
                }
            } catch (RuntimeException $e) {
                $feil[] = 'Facebook: ' . $e->getMessage();
            }
        }

        // Nyeste foerst. Begge kanaler gir ISO-tid, saa strengene sorterer
        // riktig uten aa gjores om til tall.
        usort($ut, static fn(array $a, array $b): int => strcmp($b['tid'], $a['tid']));

        return ['poster' => $ut, 'feil' => $feil];
    }

    /**
     * Svarer paa en kommentar.
     *
     * Instagram vil ha svaret under «replies», Facebook under kommentarens
     * egne «comments». Samme tanke, to adresser.
     *
     * @return array{id: string}
     */
    public static function svarKommentar(string $kommentarId, string $tekst, string $kanal): array
    {
        $tekst = trim($tekst);
        if ($tekst === '') {
            throw new RuntimeException('Svaret er tomt.');
        }
        if ($kommentarId === '') {
            throw new RuntimeException('Vet ikke hvilken kommentar svaret gjelder.');
        }

        $sti = $kanal === 'Instagram' ? $kommentarId . '/replies' : $kommentarId . '/comments';
        $ut = self::kall('POST', $sti, ['message' => mb_substr($tekst, 0, 2200)],
                         $kanal === 'Instagram' ? null : self::sideToken());

        $id = (string) ($ut['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException($kanal . ' tok ikke imot svaret.');
        }
        return ['id' => $id];
    }

    /**
     * Skjuler en kommentar. Bare Instagram og Facebook-sida, ikke sletting.
     *
     * Skjuling og ikke sletting med vilje: den som skrev ser sin egen
     * kommentar staa, og vi slipper en krangel om sensur. Sletting finnes,
     * men er ikke bygget — den hoerer hjemme et sted der man er sikker.
     */
    public static function skjulKommentar(string $kommentarId, string $kanal): void
    {
        if ($kommentarId === '') {
            throw new RuntimeException('Vet ikke hvilken kommentar det gjelder.');
        }
        self::kall('POST', $kommentarId, ['hide' => 'true'],
                   $kanal === 'Instagram' ? null : self::sideToken());
    }

    /**
     * Samtalene i innboksen, nyeste foerst.
     *
     * Begge kanaler gaar gjennom SIDA: en Instagram-samtale hentes med
     * platform=instagram paa sidas conversations, ikke paa Instagram-kontoen.
     * Det er lett aa lete lenge etter det.
     *
     * @return array{samtaler: list<array<string,mixed>>, feil: list<string>}
     */
    public static function samtaler(int $maks = self::INNBOKS_ANTALL): array
    {
        $ut = [];
        $feil = [];
        if (!self::klarForFacebook()) {
            return ['samtaler' => [], 'feil' => ['Facebook-sida er ikke koblet til.']];
        }
        $token = self::sideToken();
        $metaSa = [];

        foreach (['messenger' => 'Facebook', 'instagram' => 'Instagram'] as $plattform => $kanal) {
            self::$sisteMetaFeil = '';
            try {
                $svar = self::kall('GET', self::sideId() . '/conversations', [
                    'platform' => $plattform,
                    'fields'   => 'id,updated_time,snippet,unread_count,participants',
                    'limit'    => (string) $maks,
                ], $token);
                foreach ((array) ($svar['data'] ?? []) as $s) {
                    // Den andre parten — ikke sida selv.
                    $navn = 'Ukjent';
                    $hvem = '';
                    foreach ((array) ($s['participants']['data'] ?? []) as $p) {
                        if (!in_array((string) ($p['id'] ?? ''),
                                      array_filter([self::sideId(), self::igId()]), true)) {
                            $navn = (string) ($p['name'] ?? $p['username'] ?? 'Ukjent');
                            $hvem = (string) ($p['id'] ?? '');
                        }
                    }
                    $ut[] = [
                        'id'      => (string) ($s['id'] ?? ''),
                        'kanal'   => $kanal,
                        'fra'     => $navn,
                        'hvem'    => $hvem,
                        'tekst'   => (string) ($s['snippet'] ?? ''),
                        'tid'     => (string) ($s['updated_time'] ?? ''),
                        'ulest'   => (int) ($s['unread_count'] ?? 0),
                    ];
                }
            } catch (RuntimeException $e) {
                $feil[] = $kanal . ': ' . $e->getMessage();
                if (self::$sisteMetaFeil !== '') {
                    $metaSa[] = $kanal . ': ' . self::$sisteMetaFeil;
                }
            }
        }

        // Mangler «pages_messaging», svarer Graph med «tillatelse mangler»
        // eller «objektet finnes ikke» — to setninger som sender folk til
        // hver sin blindvei. Tillatelsen hoerer til et eget
        // meldings-bruksomraade paa appen, og det er der jobben ligger.
        // Bare naar begge kanalene feilet: virker den ene, er ikke
        // tillatelsen problemet, og teksten ville sendt folk feil vei.
        if ($ut === [] && count($feil) === 2) {
            $sier = implode(' ', $feil);
            if (stripos($sier, 'permission') !== false
                || stripos($sier, 'tillatelse') !== false
                || stripos($sier, 'does not exist') !== false) {
                $feil = ['Meldinger krever tillatelsen «pages_messaging». Den hører til et '
                       . 'eget meldings-bruksområde på Meta-appen, og er ikke lagt til ennå. '
                       . 'Kommentarer virker uten den.'];
            }
        }
        if ($metaSa !== []) {
            $feil[] = 'Meta svarte: ' . implode(' · ', $metaSa);
        }

        usort($ut, static fn(array $a, array $b): int => strcmp($b['tid'], $a['tid']));
        return ['samtaler' => $ut, 'feil' => $feil];
    }

    /**
     * Meldingene i én samtale, eldste foerst — som en samtale leses.
     *
     * @return list<array<string,mixed>>
     */
    public static function meldinger(string $samtaleId, int $maks = 25): array
    {
        if ($samtaleId === '') {
            throw new RuntimeException('Vet ikke hvilken samtale det gjelder.');
        }
        $svar = self::kall('GET', $samtaleId, [
            'fields' => 'messages.limit(' . $maks . '){id,message,from,created_time}',
        ], self::sideToken());

        $ut = [];
        foreach ((array) ($svar['messages']['data'] ?? []) as $m) {
            $ut[] = [
                'id'    => (string) ($m['id'] ?? ''),
                'fra'   => (string) ($m['from']['name'] ?? $m['from']['username'] ?? ''),
                // Instagram-meldinger bærer kontoens egen id, ikke sidas.
                'oss'   => in_array((string) ($m['from']['id'] ?? ''),
                                    array_filter([self::sideId(), self::igId()]), true),
                'tekst' => (string) ($m['message'] ?? ''),
                'tid'   => (string) ($m['created_time'] ?? ''),
            ];
        }
        // Graph gir nyeste foerst; en samtale leses andre veien.
        return array_reverse($ut);
    }

    /**
     * Sender et svar i en samtale.
     *
     * ── Doegnet ──────────────────────────────────────────────────────
     *
     * Meta slipper bare gjennom et svar innen 24 timer etter kundens siste
     * melding. Etter det maa meldinga merkes med en grunn Meta godtar, og
     * «vi rakk ikke aa svare» er ikke en av dem. Feilen derfra (kode 10,
     * underkode 2018278) oversettes til noe som sier hva som faktisk skjedde,
     * framfor «tillatelse mangler» — som sender folk til feil sted.
     *
     * @return array{id: string}
     */
    public static function svarMelding(string $mottakerId, string $tekst): array
    {
        $tekst = trim($tekst);
        if ($tekst === '') {
            throw new RuntimeException('Svaret er tomt.');
        }
        if ($mottakerId === '') {
            throw new RuntimeException('Vet ikke hvem svaret skal til.');
        }

        try {
            $ut = self::kall('POST', self::sideId() . '/messages', [
                'recipient'      => json_encode(['id' => $mottakerId]),
                'messaging_type' => 'RESPONSE',
                'message'        => json_encode(['text' => mb_substr($tekst, 0, 2000)]),
            ], self::sideToken());
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), '2018278')
                || stripos($e->getMessage(), 'outside') !== false
                || stripos($e->getMessage(), '24') !== false) {
                throw new RuntimeException(
                    'Det er gått mer enn 24 timer siden kunden skrev, og da slipper '
                    . 'Meta ikke gjennom et vanlig svar. Svar i Meta Business Suite, '
                    . 'eller be kunden skrive på nytt.'
                );
            }
            throw $e;
        }

        $id = (string) ($ut['message_id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Meldingen ble ikke sendt.');
        }
        return ['id' => $id];
    }

    /** Foerste linje av et innlegg, saa man ser hva kommentaren staar paa. */
    private static function kort(string $tekst): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $tekst));
        return $t === '' ? '' : (mb_strlen($t) > 60 ? mb_substr($t, 0, 60) . ' …' : $t);
    }

    // ── Selve kallet ─────────────────────────────────────────────────

    /**
     * Metas egen ordlyd fra siste feil, foer den ble oversatt.
     *
     * Oversettelsen i kall() er til for eieren, men den slaar sammen feil
     * med ulik aarsak. Innboksen viser denne bak sin egen tekst, saa man
     * ser hva Meta faktisk sa. (Eieren, 24. september 2026.)
     */
    private static string $sisteMetaFeil = '';

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
            self::$sisteMetaFeil = $melding . ' (kode ' . $kode
                . (isset($f['error_subcode']) ? ', underkode ' . (int) $f['error_subcode'] : '')
                . ')';

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

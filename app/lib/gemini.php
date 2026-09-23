<?php
/**
 * Google Gemini — bildene AI-en lager.
 *
 * ── Hvorfor denne finnes ved siden av AI ─────────────────────────────
 *
 * AI (Claude) skriver tekst. Den lager ikke bilder, og Anthropic har ikke
 * noe endepunkt for det. Samtidig sto 8 av 12 kurs uten bilde — blant dem
 * Nybegynner dreiekurs og Paint on Pots, de to som gaar oftest.
 *
 * Eieren, 23. september 2026: «jeg vil koble gemini inn med ai ... naar vi
 * oppretter kurs, saa vil jeg kunne lage et bilde rett i kursoppretteren».
 *
 * Derfor: Gemini gjor det Claude ikke kan, og de deler regnskapet.
 *
 * ── Én logg, ett tak ─────────────────────────────────────────────────
 *
 * Kallene skrives i «ai_logg» sammen med Claude sine, og trekkes fra det
 * samme maanedstaket. To kasser ville betydd to steder aa se etter naar
 * regningen kommer, og et tak som ikke holder.
 *
 * Kolonnen «modell» skiller dem. Den fantes fra migrasjon 024.
 *
 * ── Modellen staar i basen, ikke i koden ─────────────────────────────
 *
 * Google bytter navn paa bildemodellene sine oftere enn vi legger ut ny
 * kode. Staar navnet her, maa hele nettsida ut paa nytt for aa bytte et
 * ord. Derfor leses det fra oppsettet, med et fornuftig utgangspunkt.
 * Det samme gjelder prisen per bilde: den er et anslag til
 * kostnadsoversikten, og den skal kunne rettes naar Google endrer den.
 *
 * ── Noekkelen ────────────────────────────────────────────────────────
 *
 * Den ligger i «innstillinger» og ikke i secrets.php, av samme grunn som
 * kalendernoekkelen: eieren skal kunne lime den inn og bytte den selv.
 * Den forlater aldri serveren — nettleseren snakker med oss, vi snakker
 * med Google. Endepunktet viser den aldri tilbake, bare om den er satt.
 */

declare(strict_types=1);

final class Gemini
{
    /**
     * Brukes naar ingenting er satt i oppsettet.
     *
     * Sto foerst paa «gemini-2.5-flash-image», som var det jeg kjente til.
     * Sjekket mot Googles modelliste 23. september 2026: den er én
     * generasjon bak. «gemini-3.1-flash-image» — Nano Banana 2 — er den
     * som er ment for produksjon naa.
     *
     * De andre som finnes, om noen skal byttes inn fra oppsettet:
     *   gemini-3.1-flash-lite-image   raskest og billigst
     *   gemini-3-pro-image            4K, og leselig tekst i bildet
     *
     * Imagen er lagt ned, og skal ikke brukes.
     */
    public const MODELL_STANDARD = 'gemini-3.1-flash-image';

    /** Anslag i ore per bilde, naar ingenting er satt. */
    private const PRIS_ORE_STANDARD = 45;

    /**
     * Referansebildene som foelger med hvert kall.
     *
     * Uten dem lagde modellen glansbilder: polerte studiofoto som kunne
     * vaert hvilket som helst keramikkverksted. Eieren, 23. september 2026:
     * «jeg vil at du skal bygge alle bildene og videoene du generer i lissom
     * med disse referansene». Med bilder av det ekte rommet — furureolene,
     * malingsflaskene paa rekke, det lyse gulvet — blir resultatet
     * gjenkjennelig som Lissom.
     *
     * Taket paa tre er ikke tilfeldig: hvert bilde som sendes med koster
     * tokens og tid, og det fjerde gjor lite annet enn aa gjore kallet
     * tregere. Er det flere i mappa, sendes de tre nyeste.
     */
    private const MAPPE_REFERANSER = 'referanser';
    public const MAKS_REFERANSER = 3;

    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public static function noekkel(): string
    {
        return trim((string) Config::hent('gemini_api_key', ''));
    }

    public static function tilgjengelig(): bool
    {
        return self::noekkel() !== '';
    }

    public static function modell(): string
    {
        $m = trim((string) Config::hent('gemini_modell', ''));
        return $m !== '' ? $m : self::MODELL_STANDARD;
    }

    public static function prisOre(): int
    {
        $p = (int) Config::hent('gemini_pris_ore', 0);
        return $p > 0 ? $p : self::PRIS_ORE_STANDARD;
    }

    /**
     * Filnavnene paa referansebildene, nyeste foerst.
     *
     * @return list<string>
     */
    public static function referanser(): array
    {
        $mappe = Bilder::mappe(self::MAPPE_REFERANSER);
        $funn = glob($mappe . '/*.jpg') ?: [];
        // Nyeste foerst, saa den som nettopp ble lastet opp er med blant de
        // tre som faktisk sendes.
        usort($funn, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        return array_map('basename', $funn);
    }

    /** Mappa referansebildene ligger i — brukt av opplasting og sletting. */
    public static function referanseMappe(): string
    {
        return self::MAPPE_REFERANSER;
    }

    /**
     * Referansebildene som deler til API-kallet.
     *
     * Bildene ligger alt som JPEG paa disk — Bilder::taImot() tegner om alt
     * som lastes opp — saa de kan leses rett inn uten aa gaa veien om GD.
     *
     * @return list<array<string,mixed>>
     */
    private static function referanseDeler(): array
    {
        $ut = [];
        foreach (array_slice(self::referanser(), 0, self::MAKS_REFERANSER) as $navn) {
            $sti = Bilder::sti($navn, self::MAPPE_REFERANSER);
            if ($sti === null) {
                continue;
            }
            $raa = @file_get_contents($sti);
            if ($raa === false || $raa === '') {
                continue;
            }
            $ut[] = ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($raa)]];
        }
        return $ut;
    }

    /**
     * Hva skjermen trenger for aa si hvordan det staar til.
     *
     * Samme form som AI::status(), saa de to kan vises ved siden av
     * hverandre uten at skjermen maa kjenne to ulike svar.
     *
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        $satt = self::tilgjengelig();
        // Booking::kroner() runder til hele kroner, og et bilde koster under
        // én. «kr. 0,-» ville sagt at det er gratis. Under hundre ore staar
        // det derfor i ore.
        $ore = self::prisOre();
        return [
            'klar'    => $satt,
            'modell'  => self::modell(),
            'pris'    => $ore < 100 ? $ore . ' øre' : Booking::kroner($ore),
            'bilder'  => (int) DB::verdi(
                "SELECT COUNT(*) FROM ai_logg WHERE modell LIKE 'gemini%' AND ok = 1"
            ),
            'referanser' => self::referanser(),
            'mangler' => $satt
                ? null
                : 'Lim inn nøkkelen fra aistudio.google.com under Markedsføring → Oppsett.',
        ];
    }

    /**
     * Lager ett bilde, og legger det i biblioteket.
     *
     * Svaret fra Google er base64 inne i «inlineData». Vi skriver aldri det
     * raa svaret til disk: Bilder::taImotData() leser bytene, kontrollerer
     * at det faktisk ER et bilde, skalerer til 1400 piksler og tegner det
     * om til JPEG — samme vei som alt annet som lastes opp.
     *
     * @return array{navn: string, url: string, kostnadOre: int}
     */
    public static function lagBilde(string $ledetekst, string $formal = 'Bilde'): array
    {
        $noekkel = self::noekkel();
        if ($noekkel === '') {
            throw new RuntimeException(
                'Gemini er ikke koblet til ennå. Lim inn nøkkelen under Markedsføring → Oppsett.'
            );
        }

        $ledetekst = trim($ledetekst);
        if ($ledetekst === '') {
            throw new RuntimeException('Skriv hva bildet skal vise.');
        }

        // Samme tak som teksten. Er det naadd, koster ikke dette heller noe.
        $tak = AI::tak();
        if (AI::bruktDenneMaaneden() >= $tak * 100) {
            throw new RuntimeException(
                'Taket på ' . Booking::kroner($tak * 100) . ' for denne måneden er nådd. '
                . 'Du kan heve det under Markedsføring → Oppsett.'
            );
        }

        $modell = self::modell();
        $url = self::BASE . rawurlencode($modell) . ':generateContent';

        // Bildene av det ekte verkstedet. Er mappa tom, gaar kallet som for.
        $referanser = self::referanseDeler();

        // Noekkelen gaar i et hode, ikke i adressen: en adresse havner i
        // serverlogger og i feilmeldinger, et hode gjor det ikke.
        $svar = http_kall(
            $url,
            'POST',
            json_encode([
                'contents' => [[
                    // Referansebildene foerst, teksten sist. Modellen leser
                    // delene i rekkefoelge, og en instruksjon som staar etter
                    // bildene gjelder bildene som kom foer.
                    'parts' => array_merge(
                        $referanser,
                        [['text' => self::rammeInn($ledetekst, $referanser !== [])]]
                    ),
                ]],
            ], JSON_UNESCAPED_UNICODE),
            [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $noekkel,
            ],
            60
        );

        $json = json_decode((string) $svar['kropp'], true);

        if ((int) $svar['status'] !== 200) {
            $melding = (string) ($json['error']['message'] ?? 'Ukjent feil');
            AI::loggKall($formal, $modell, 0, 0, 0, false, $melding);

            throw new RuntimeException(match (true) {
                (int) $svar['status'] === 400 && str_contains($melding, 'API key')
                    => 'Nøkkelen ble ikke godtatt. Sjekk den under Markedsføring → Oppsett.',
                (int) $svar['status'] === 403
                    => 'Nøkkelen har ikke tilgang til ' . $modell . '. Sjekk i Google AI Studio '
                     . 'at modellen er slått på for prosjektet.',
                (int) $svar['status'] === 404
                    => 'Google kjenner ikke modellen «' . $modell . '». Rett modellnavnet under '
                     . 'Markedsføring → Oppsett.',
                (int) $svar['status'] === 429
                    => 'For mange bilder på kort tid, eller gratiskvoten er brukt opp. Vent litt.',
                (int) $svar['status'] >= 500
                    => 'Google svarer ikke akkurat nå. Prøv igjen om litt.',
                default => 'Gemini svarte ikke: ' . $melding,
            });
        }

        // Bildet ligger som base64 i en av delene. Modellen svarer ofte med
        // en tekstdel ved siden av — den er kommentaren dens, ikke bildet.
        $raa = '';
        foreach (($json['candidates'][0]['content']['parts'] ?? []) as $del) {
            $data = $del['inlineData']['data'] ?? $del['inline_data']['data'] ?? '';
            if (is_string($data) && $data !== '') {
                $raa = base64_decode($data, true) ?: '';
                if ($raa !== '') {
                    break;
                }
            }
        }

        if ($raa === '') {
            // Modellen kan ha nektet — da staar grunnen i teksten, og den er
            // mer til hjelp enn «ingen bilde i svaret».
            $tekst = '';
            foreach (($json['candidates'][0]['content']['parts'] ?? []) as $del) {
                $tekst .= (string) ($del['text'] ?? '');
            }
            $grunn = trim($tekst) !== ''
                ? ' Gemini svarte: ' . mb_substr(trim($tekst), 0, 200)
                : '';
            AI::loggKall($formal, $modell, 0, 0, 0, false, 'Ingen bildedata i svaret.' . $grunn);
            throw new RuntimeException('Gemini lagde ikke noe bilde denne gangen.' . $grunn);
        }

        $ore = self::prisOre();
        $navn = Bilder::taImotData($raa, 'artikler');
        AI::loggKall($formal, $modell, 0, 0, $ore, true, null);

        return [
            'navn'       => $navn,
            'url'        => 'api/bilde.php?artikkel=' . $navn,
            'kostnadOre' => $ore,
        ];
    }

    /**
     * Ledeteksten, med det som alltid skal gjelde.
     *
     * Uten dette kommer det glansbilder: polerte studiofoto med perfekt lys
     * som ikke ligner et verksted paa Teie. Rammen sier hva slags bilde
     * dette skal vaere — og hva det ikke skal ha, for tekst i et generert
     * bilde blir nesten alltid feilstavet.
     */
    private static function rammeInn(string $ledetekst, bool $medReferanser = false): string
    {
        // Staar det bilder foran teksten, maa det sies hva de er til. Uten
        // dette prover modellen aa REDIGERE det forste bildet framfor aa lage
        // et nytt i samme stil.
        $forlegg = $medReferanser
            ? "Bildene over er fotografier fra dette verkstedet. Bruk dem som "
            . "forlegg for rommet, lyset og fargene — de samme furureolene, det "
            . "samme lyse gulvet, den samme keramikken. Lag et NYTT bilde i samme "
            . "stil; ikke rediger bildene over.

"
            : '';

        return $forlegg . $ledetekst . "\n\n"
            . "Fotografisk bilde, ikke illustrasjon eller 3D. Naturlig dagslys, "
            . "rolig og nordisk. Varme, jordnære farger — leire, tre, lys keramikk. "
            . "Ingen tekst, ingen bokstaver, ingen logo og ingen vannmerke i bildet. "
            . "Ingen glansbildepreg: dette skal ligne et ekte lite keramikkverksted, "
            . "ikke en reklamekatalog. Liggende format.";
    }
}

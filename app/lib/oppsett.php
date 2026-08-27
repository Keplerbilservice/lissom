<?php
/**
 * Oppsettet — teksten og bildet satt sammen slik kanalen vil ha det.
 *
 * AI-en skriver teksten og eieren velger bildet. Det som manglet var det
 * siste leddet: at de to ble til noe ferdig.
 *
 *   Artikkel      virket fra for: toppbilde med bildetekst, avsnitt under.
 *   Nyhetsbrev    var ren tekst. Bildet ble aldri med i det hele tatt.
 *   Sosialt       var tekst aa kopiere. Bildet laa i basen og gikk ingen
 *                 steder — heller ikke i riktig format for kanalen.
 *
 * Her ligger begge deler. Ett sted, fordi det som vises i forhaandsvisningen
 * skal vaere noeyaktig det som sendes — ikke en etterlikning som etter hvert
 * sier noe annet.
 */

declare(strict_types=1);

final class Oppsett
{
    /**
     * Formatene kanalene faktisk bruker.
     *
     * Instagram viser 4:5 stoerst i stroemmen; en firkant er tryggere naar
     * det samme bildet skal gjenbrukes. Story og reel er 9:16. Facebook og
     * LinkedIn er liggende. Tallene er piksler, ikke forholdstall, fordi det
     * er dem som skal staa i nedlastingen.
     */
    public const FORMATER = [
        'Instagram/innlegg'  => [1080, 1350, 'Instagram-innlegg (4:5)'],
        'Instagram/story'    => [1080, 1920, 'Instagram story (9:16)'],
        'Instagram/reels'    => [1080, 1920, 'Reel (9:16)'],
        'Instagram/karusell' => [1080, 1080, 'Karusell (1:1)'],
        'Facebook/innlegg'   => [1200, 630,  'Facebook-innlegg (1,91:1)'],
        'Facebook/story'     => [1080, 1920, 'Facebook story (9:16)'],
        'TikTok/innlegg'     => [1080, 1920, 'TikTok (9:16)'],
        'LinkedIn/innlegg'   => [1200, 627,  'LinkedIn-innlegg (1,91:1)'],
    ];

    /** Formatet for en kanal og en form, med reserve for det vi ikke kjenner. */
    public static function format(string $kanal, string $form): array
    {
        $n = $kanal . '/' . ($form ?: 'innlegg');
        if (isset(self::FORMATER[$n])) {
            return self::FORMATER[$n];
        }
        // Ukjent kombinasjon: firkant holder overalt.
        return [1080, 1080, $kanal . ' (1:1)'];
    }

    /**
     * Adressen til bildet, slik en e-postleser kan hente det.
     *
     * Bildene ligger enten som en fil i rota eller bak api/bilde.php. Begge
     * maa bli en hel adresse: en e-post aapnes et helt annet sted enn
     * nettsida, og «uploads_kopp.jpg» peker da ingen steder.
     */
    public static function bildeUrl(string $bilde, int $bredde = 0): string
    {
        $b = trim($bilde);
        if ($b === '') {
            return '';
        }
        // Adressen til nettstedet staar ett sted fra for.
        $rot = Config::nettsted();
        if (str_starts_with($b, 'http://') || str_starts_with($b, 'https://')) {
            return $b;
        }
        if (str_contains($b, 'api/bilde.php')) {
            $skille = str_contains($b, '?') ? '&' : '?';
            return $rot . '/' . ltrim($b, '/') . ($bredde > 0 ? $skille . 'b=' . $bredde : '');
        }
        // Egne filer har mindre utgaver ved siden av seg: «-800» foran
        // punktumet. Finnes den ikke, gjor originalen samme nytte.
        if ($bredde > 0 && preg_match('~^(.+)\.(jpe?g|png|webp)$~i', $b, $m) === 1) {
            $mindre = $m[1] . '-' . $bredde . '.' . $m[2];
            if (is_file(dirname(__DIR__, 2) . '/' . $mindre)) {
                return $rot . '/' . $mindre;
            }
        }
        return $rot . '/' . ltrim($b, '/');
    }

    /**
     * Nyhetsbrevet som HTML.
     *
     * Tabelloppsett og innebygde stiler, ikke fordi det er pent aa skrive,
     * men fordi det er det e-postlesere faktisk faar til. Gmail stryker
     * <style>-blokker, Outlook kan ikke flexbox, og et brev som ser riktig
     * ut i nettleseren kan falle fra hverandre i innboksen.
     *
     * Bredden er 600 piksler — det staar alle steder, og er smalt nok til at
     * en telefon slipper aa skalere.
     */
    public static function epost(string $tittel, string $tekst, string $bilde = '',
                                 string $bildetekst = '', array $knapp = []): string
    {
        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Avsnitt skilles med blank linje, som ellers i systemet.
        $avsnitt = array_values(array_filter(array_map(
            static fn($a) => trim($a),
            preg_split('/\n\s*\n/', trim($tekst)) ?: []
        )));

        $kropp = '';
        foreach ($avsnitt as $i => $a) {
            // Foerste avsnitt er ingressen: litt stoerre, som i et blad.
            $stil = $i === 0
                ? 'margin:0 0 18px;font-family:Georgia,serif;font-size:17px;line-height:1.65;color:#4D1D12;'
                : 'margin:0 0 16px;font-family:Georgia,serif;font-size:15px;line-height:1.7;color:#4D1D12;';
            $kropp .= '<p style="' . $stil . '">' . nl2br($e($a)) . '</p>';
        }

        $bildeblokk = '';
        $url = self::bildeUrl($bilde, 800);
        if ($url !== '') {
            $bildeblokk =
                '<tr><td style="padding:0 0 24px;">'
                . '<img src="' . $e($url) . '" width="600" alt="' . $e($bildetekst ?: $tittel) . '"'
                . ' style="display:block;width:100%;max-width:600px;height:auto;border:0;border-radius:12px;">'
                . ($bildetekst !== ''
                    ? '<div style="margin-top:8px;font-family:Georgia,serif;font-size:13px;'
                    . 'line-height:1.5;color:#8A7A70;">' . $e($bildetekst) . '</div>'
                    : '')
                . '</td></tr>';
        }

        $knappblokk = '';
        if (!empty($knapp['tekst']) && !empty($knapp['url'])) {
            // Knappen tegnes som en tabellcelle med bakgrunn. En <button>
            // gjor ingenting i en e-post, og en lenke med padding faller
            // sammen i Outlook.
            $knappblokk =
                '<tr><td style="padding:8px 0 0;">'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td style="background:#4D1D12;border-radius:999px;">'
                . '<a href="' . $e((string) $knapp['url']) . '"'
                . ' style="display:inline-block;padding:13px 26px;font-family:Georgia,serif;'
                . 'font-size:15px;font-weight:bold;color:#FAF6F1;text-decoration:none;">'
                . $e((string) $knapp['tekst']) . '</a>'
                . '</td></tr></table></td></tr>';
        }

        return
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
          . ' style="background:#FAF6F1;padding:24px 12px;"><tr><td align="center">'
          . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"'
          . ' style="max-width:600px;width:100%;background:#FFFFFF;border-radius:16px;padding:32px;">'
          . $bildeblokk
          . '<tr><td style="padding:0 0 14px;">'
          . '<h1 style="margin:0;font-family:Georgia,serif;font-size:26px;line-height:1.25;'
          . 'color:#4D1D12;">' . $e($tittel) . '</h1></td></tr>'
          . '<tr><td>' . $kropp . '</td></tr>'
          . $knappblokk
          . '</table></td></tr></table>';
    }

    /**
     * Teksten til et innlegg, satt sammen slik den limes inn.
     *
     * Emneknaggene staar til slutt, etter en blank linje. Det er slik de
     * skrives paa Instagram og Facebook — inni teksten gjor de den vanskelig
     * aa lese.
     *
     * @param list<string> $tagger
     */
    public static function sosialTekst(string $tekst, array $tagger): string
    {
        $rene = [];
        foreach ($tagger as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            $rene[] = str_starts_with($t, '#') ? $t : '#' . ltrim($t, '#');
        }
        return $rene === [] ? trim($tekst) : trim($tekst) . "\n\n" . implode(' ', $rene);
    }
}

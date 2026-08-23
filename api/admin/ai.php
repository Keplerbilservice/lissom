<?php
/**
 * AI-en i markedsforingen.
 *
 *   POST handling=artikkel     lag en artikkel eller guide
 *   POST handling=kursboost    hele pakka for ett kurs
 *   POST handling=nyhetsbrev   manedsbrev, hostbrev, julekampanje, medlemsbrev
 *   POST handling=sosialt      innlegg til en kanal
 *   POST handling=seoside      forslag til en side som mangler
 *   POST handling=assistent    sporsmaal i adminen
 *   POST handling=autopilot    ukas forslag, samlet
 *   POST handling=godkjenn     ta et utkast i bruk
 *   POST handling=forkast      legg et utkast bort
 *
 * Alt AI-en lager blir liggende som utkast. Ingenting gaar ut for eieren har
 * lest det og trykket godkjenn — heller ikke fra autopiloten. Det er ikke en
 * begrensning vi kan fjerne senere; det er poenget. Teksten baerer verkstedets
 * navn, og da skal et menneske ha sett den.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
krev_admin();

$kropp = Foresporsel::kropp();
$handling = Foresporsel::tekst('handling');

// AI::spor() kaster RuntimeException med tekst som er skrevet for aa vises
// til eieren — «noekkelen ble ikke godtatt», «taket er naadd». Uten dette
// blir de til en 500 med filsti og linjenummer i svaret, og eieren sitter
// igjen med «app/lib/ai.php:123» i stedet for hva hun skal gjore.
set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof RuntimeException) {
        logg('AI-kall stoppet', ['feil' => $e->getMessage()]);
        Svar::feil($e->getMessage(), 400);
    }
    logg_feil('Uventet feil i AI-kall', $e);
    Svar::feil('Noe gikk galt. Prøv igjen, eller si fra hvis det gjentar seg.', 500);
});

/** Fakta + stemme, som folger med hvert kall. */
$rolle = static fn(string $oppgave): string =>
    "Du skriver for et lite keramikkverksted i Norge.\n\n"
    . AI::omLissom() . "\n" . AI::stemme() . "\n" . $oppgave;

/** Legger et utkast i koen og svarer med det. */
$lagre = static function (string $type, string $tittel, string $tekst, ?array $data, ?string $kontekst, int $ore) {
    $id = DB::settInn('ai_utkast', [
        'type'        => $type,
        'tittel'      => mb_substr($tittel, 0, 191),
        'tekst'       => $tekst,
        'data'        => $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
        'kontekst'    => $kontekst === null ? null : mb_substr($kontekst, 0, 191),
        'kostnad_ore' => $ore,
    ]);
    revider('ai_utkast', 'ai', $id, ['type' => $type]);
    Svar::ok([
        'id'       => $id,
        'tittel'   => $tittel,
        'tekst'    => $tekst,
        'data'     => $data,
        'kostnad'  => Booking::kroner($ore),
        'beskjed'  => 'Utkastet er klart. Les gjennom før du tar det i bruk.',
    ]);
};

/** Henter et kurs med datoene sine, til de handlingene som gjelder ett kurs. */
$kursMedDatoer = static function (int $kursId): array {
    $k = DB::en('SELECT * FROM courses WHERE id = :i', ['i' => $kursId]);
    if ($k === null) {
        Svar::feil('Fant ikke kurset.');
    }
    $okter = DB::alle(
        "SELECT id, start_tid, COALESCE(kapasitet, (SELECT kapasitet FROM courses WHERE id = :i)) AS kapasitet
           FROM course_sessions
          WHERE course_id = :i AND status = 'planlagt' AND start_tid > UTC_TIMESTAMP()
          ORDER BY start_tid LIMIT 6",
        ['i' => $kursId]
    );
    $linjer = [];
    foreach ($okter as $o) {
        $linjer[] = Booking::norskDato((string) $o['start_tid'])
            . ' — ' . Booking::ledigePlasser((int) $o['id']) . ' av ' . (int) $o['kapasitet'] . ' plasser ledig';
    }
    return [
        'kurs'   => $k,
        'datoer' => $linjer,
        'tekst'  => $k['tittel'] . ' (' . $k['type'] . '), ' . Booking::kroner((int) $k['pris_ore'])
            . ".\n" . (trim((string) ($k['beskrivelse'] ?? '')) ?: '(ingen beskrivelse lagt inn)')
            . "\n\nDatoer framover:\n" . (implode("\n", $linjer) ?: '(ingen datoer lagt ut)'),
    ];
};

switch ($handling) {

    // ── Artikkel eller guide ────────────────────────────────────────────
    case 'artikkel':
        $emne = trim(mb_substr((string) ($kropp['emne'] ?? ''), 0, 191));
        if ($emne === '') {
            Svar::feil('Skriv hva artikkelen skal handle om.');
        }
        $kategori = trim(mb_substr((string) ($kropp['kategori'] ?? ''), 0, 64));

        $r = AI::sporJson(
            $rolle(
                "Du skriver en artikkel til nettsidens kunnskapsbank. Den skal hjelpe leseren "
                . "med noe konkret, ikke selge. En som soker paa emnet skal finne svaret her.\n\n"
                . "Svar med JSON: {\"tittel\": \"...\", \"ingress\": \"...\", \"innhold\": \"...\", "
                . "\"fokusord\": \"...\", \"metabeskrivelse\": \"...\"}\n"
                . "innhold er ren tekst med avsnitt skilt av doble linjeskift, 400-700 ord. "
                . "Bruk mellomtitler paa egne linjer der det hjelper lesingen. "
                . "metabeskrivelse er 120-155 tegn."
            ),
            'Emne: ' . $emne . ($kategori !== '' ? "\nKategori: " . $kategori : ''),
            'artikkel',
            8000
        );

        $tekst = trim((string) ($r['innhold'] ?? ''));
        if ($tekst === '') {
            Svar::feil('AI-en svarte uten innhold. Prøv igjen.');
        }
        $lagre('artikkel', (string) ($r['tittel'] ?? $emne), $tekst, [
            'ingress'         => $r['ingress'] ?? '',
            'fokusord'        => $r['fokusord'] ?? '',
            'metabeskrivelse' => $r['metabeskrivelse'] ?? '',
            'kategori'        => $kategori ?: null,
        ], $emne, AI::sisteKostnad());
        // (kostnaden logges i AI::spor; utkastet far den fra loggen under)

    // ── Kursboost: hele pakka for ett kurs ──────────────────────────────
    case 'kursboost':
        $k = $kursMedDatoer((int) ($kropp['kursId'] ?? 0));

        $r = AI::sporJson(
            $rolle(
                "Du lager markedsforingen for ett bestemt kurs. Alt skal bygge paa datoene og "
                . "prisen som staar under — finn aldri paa nye.\n\n"
                . "Svar med JSON: {\"artikkel\": {\"tittel\": \"...\", \"tekst\": \"...\"}, "
                . "\"facebook\": \"...\", \"instagram\": \"...\", \"hashtags\": [\"...\"], "
                . "\"epost\": {\"emne\": \"...\", \"tekst\": \"...\"}, \"medlemmer\": \"...\"}\n"
                . "artikkel.tekst er 250-400 ord til nettsida. facebook er 3-6 setninger. "
                . "instagram er kortere, med linjeskift. hashtags er 5-8 uten emneknagg-tegnet. "
                . "epost.tekst er en kort e-post til kundelista. medlemmer er 2-3 setninger "
                . "til dem som allerede er medlemmer."
            ),
            $k['tekst'],
            'kursboost',
            10000
        );

        $lagre('kursboost', 'Kursboost: ' . $k['kurs']['tittel'],
            (string) ($r['artikkel']['tekst'] ?? ''), $r, (string) $k['kurs']['tittel'], AI::sisteKostnad());

    // ── Nyhetsbrev ──────────────────────────────────────────────────────
    case 'nyhetsbrev':
        $slag = (string) ($kropp['slag'] ?? 'maned');
        $navn = [
            'maned'   => 'månedens nyhetsbrev',
            'host'    => 'høstnyhetsbrev',
            'jul'     => 'julekampanje',
            'medlem'  => 'medlemsbrev',
        ][$slag] ?? 'nyhetsbrev';

        // Det AI-en skal bygge paa: ekte kurs, ekte ledige plasser.
        $kommende = DB::alle(
            "SELECT c.tittel, c.type, c.pris_ore, cs.id AS okt, cs.start_tid,
                    COALESCE(cs.kapasitet, c.kapasitet) AS kapasitet
               FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
              WHERE cs.status = 'planlagt' AND c.status = 'publisert'
                AND cs.start_tid > UTC_TIMESTAMP()
                AND cs.start_tid < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 WEEK)
              ORDER BY cs.start_tid LIMIT 20"
        );
        $linjer = [];
        foreach ($kommende as $o) {
            $linjer[] = '- ' . $o['tittel'] . ', ' . Booking::norskDato((string) $o['start_tid'])
                . ', ' . Booking::ledigePlasser((int) $o['okt']) . ' av ' . (int) $o['kapasitet'] . ' ledig, '
                . Booking::kroner((int) $o['pris_ore']);
        }
        if ($linjer === []) {
            Svar::feil('Det er ingen kurs lagt ut de neste åtte ukene. Legg ut datoer først, så har nyhetsbrevet noe å fortelle om.');
        }

        $r = AI::sporJson(
            $rolle(
                "Du skriver et {$navn} til folk som har vaert paa kurs hos oss eller staar paa lista. "
                . "Bygg det paa kursene under. Finn aldri paa datoer, priser eller plasser.\n\n"
                . "Svar med JSON: {\"emne\": \"...\", \"tekst\": \"...\"}\n"
                . "emne er e-postemnet, under 60 tegn, uten utropstegn. "
                . "tekst er selve brevet, 200-350 ord, avsnitt skilt av doble linjeskift. "
                . "Avslutt uten signatur — den legges paa av systemet."
            ),
            "Kurs og events framover:\n" . implode("\n", $linjer),
            'nyhetsbrev',
            8000
        );

        $lagre('nyhetsbrev', (string) ($r['emne'] ?? $navn),
            (string) ($r['tekst'] ?? ''), ['slag' => $slag], $navn, AI::sisteKostnad());

    // ── Sosiale medier ──────────────────────────────────────────────────
    case 'sosialt':
        $kanal = (string) ($kropp['kanal'] ?? 'Instagram');
        $form  = (string) ($kropp['form'] ?? 'innlegg');
        $om    = trim(mb_substr((string) ($kropp['om'] ?? ''), 0, 300));
        if (!in_array($kanal, ['Instagram', 'Facebook', 'TikTok', 'LinkedIn'], true)) {
            Svar::feil('Ukjent kanal.');
        }
        if (!in_array($form, ['innlegg', 'story', 'reels', 'karusell'], true)) {
            Svar::feil('Ukjent form.');
        }
        if ($om === '') {
            Svar::feil('Skriv hva innlegget skal handle om.');
        }

        $vink = [
            'innlegg'  => 'et vanlig innlegg',
            'story'    => 'en kort story-tekst, maks to setninger',
            'reels'    => 'manus til en reel: hva som skjer i bildet, og teksten som leses eller staar',
            'karusell' => 'en karusell: 4-6 kort, hvert med en kort overskrift og en setning',
        ][$form];

        $r = AI::sporJson(
            $rolle(
                "Du skriver {$vink} til {$kanal}.\n\n"
                . "Svar med JSON: {\"tekst\": \"...\", \"hashtags\": [\"...\"]}\n"
                . ($form === 'karusell' ? "tekst har ett kort per avsnitt, nummerert.\n" : '')
                . "hashtags er 5-8 stykker uten emneknagg-tegnet, paa norsk der det passer. "
                . ($kanal === 'LinkedIn' ? "LinkedIn: saklig tone, ingen emojier.\n" : "Hoyst én emoji, og bare om den tilfoerer noe.\n")
            ),
            'Handler om: ' . $om,
            'sosialt',
            4000
        );

        $lagre('sosialt', $kanal . ' — ' . $om, (string) ($r['tekst'] ?? ''),
            ['kanal' => $kanal, 'form' => $form, 'hashtags' => $r['hashtags'] ?? []], $om, AI::sisteKostnad());

    // ── Side som mangler ────────────────────────────────────────────────
    case 'seoside':
        $ord = trim(mb_substr((string) ($kropp['sokeord'] ?? ''), 0, 191));
        if ($ord === '') {
            Svar::feil('Hvilket søkeord skal siden svare på?');
        }
        $r = AI::sporJson(
            $rolle(
                "Ingen av sidene vaare svarer paa dette soket. Skriv sida som gjor det.\n\n"
                . "Svar med JSON: {\"tittel\": \"...\", \"ingress\": \"...\", \"innhold\": \"...\", "
                . "\"metabeskrivelse\": \"...\", \"slug\": \"...\"}\n"
                . "innhold er 350-600 ord. slug er smaa bokstaver med bindestrek, uten ae oe aa."
            ),
            'Søkeord: ' . $ord,
            'seoside',
            8000
        );
        $lagre('seo', (string) ($r['tittel'] ?? $ord), (string) ($r['innhold'] ?? ''), [
            'ingress'         => $r['ingress'] ?? '',
            'metabeskrivelse' => $r['metabeskrivelse'] ?? '',
            'slug'            => $r['slug'] ?? '',
            'sokeord'         => $ord,
        ], $ord, AI::sisteKostnad());

    // ── Medlemskommunikasjon ────────────────────────────────────────────
    case 'medlemsbrev':
        $om = trim(mb_substr((string) ($kropp['om'] ?? ''), 0, 300));
        $antall = (int) DB::verdi("SELECT COUNT(*) FROM members WHERE status = 'aktiv'");
        $r = AI::sporJson(
            $rolle(
                "Du skriver til verkstedets {$antall} aktive medlemmer. De kjenner stedet fra for — "
                . "ikke forklar hva keramikk er, og ikke selg dem noe de allerede har.\n\n"
                . "Svar med JSON: {\"emne\": \"...\", \"tekst\": \"...\"}\n"
                . "tekst er 150-250 ord."
            ),
            $om !== '' ? 'Handler om: ' . $om : 'En vanlig oppdatering fra verkstedet denne måneden.',
            'medlemsbrev',
            4000
        );
        $lagre('medlemsbrev', (string) ($r['emne'] ?? 'Medlemsbrev'),
            (string) ($r['tekst'] ?? ''), null, $om ?: 'månedlig', AI::sisteKostnad());

    // ── Assistenten ─────────────────────────────────────────────────────
    case 'assistent':
        $sporsmaal = trim(mb_substr((string) ($kropp['sporsmal'] ?? ''), 0, 1000));
        if ($sporsmaal === '') {
            Svar::feil('Skriv spørsmålet ditt.');
        }

        // Assistenten skal svare paa tall den faktisk har, ikke gjette.
        $fakta = "Tall fra databasen akkurat naa:\n";
        $fakta .= '- Aktive medlemmer: ' . DB::verdi("SELECT COUNT(*) FROM members WHERE status = 'aktiv'") . "\n";
        $fakta .= '- Kurs og events publisert: ' . DB::verdi("SELECT COUNT(*) FROM courses WHERE status = 'publisert'") . "\n";
        $fakta .= '- Bookinger siste 30 dager: ' . DB::verdi(
            "SELECT COUNT(*) FROM bookings WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)") . "\n";

        $tomme = DB::alle(
            "SELECT c.tittel, cs.id AS okt, cs.start_tid, COALESCE(cs.kapasitet, c.kapasitet) AS kap
               FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
              WHERE cs.status = 'planlagt' AND c.status = 'publisert' AND c.type <> 'dropin'
                AND cs.start_tid > UTC_TIMESTAMP()
                AND cs.start_tid < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 WEEK)
              ORDER BY cs.start_tid LIMIT 20"
        );
        $fakta .= "\nKurs framover, med ledige plasser:\n";
        foreach ($tomme as $o) {
            $fakta .= '- ' . $o['tittel'] . ', ' . Booking::norskDato((string) $o['start_tid'])
                . ': ' . Booking::ledigePlasser((int) $o['okt']) . ' av ' . (int) $o['kap'] . " ledig\n";
        }

        $r = AI::spor(
            $rolle(
                "Du er markedsforingshjelpen til den som driver verkstedet. Svar kort og konkret, "
                . "og bygg paa tallene under. Vet du ikke noe, si det — ikke gjett. "
                . "Foreslaa gjerne hva hun bor gjore, i prioritert rekkefolge."
            ) . "\n\n" . $fakta,
            $sporsmaal,
            'assistent',
            4000
        );
        Svar::ok(['svar' => $r['tekst'], 'kostnad' => Booking::kroner($r['kostnadOre'])]);

    // ── Autopiloten: ukas forslag ───────────────────────────────────────
    case 'autopilot':
        $tomme = DB::alle(
            "SELECT c.id, c.tittel, cs.id AS okt, cs.start_tid, COALESCE(cs.kapasitet, c.kapasitet) AS kap
               FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
              WHERE cs.status = 'planlagt' AND c.status = 'publisert' AND c.type <> 'dropin'
                AND cs.start_tid > UTC_TIMESTAMP()
                AND cs.start_tid < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 6 WEEK)
              ORDER BY cs.start_tid LIMIT 15"
        );
        $liste = [];
        foreach ($tomme as $o) {
            $ledig = Booking::ledigePlasser((int) $o['okt']);
            if ($ledig > 0) {
                $liste[] = '- ' . $o['tittel'] . ', ' . Booking::norskDato((string) $o['start_tid'])
                    . ': ' . $ledig . ' av ' . (int) $o['kap'] . ' ledig';
            }
        }
        if ($liste === []) {
            Svar::feil('Alt er fullbooket de neste seks ukene. Da er det ingenting autopiloten trenger å foreslå.');
        }

        $r = AI::sporJson(
            $rolle(
                "Foreslaa ukas markedsforing. Vaer knapp — dette skal kunne leses paa et halvt minutt.\n\n"
                . "Svar med JSON: {\"artikler\": [{\"tittel\": \"...\", \"hvorfor\": \"...\"}], "
                . "\"innlegg\": [{\"kanal\": \"...\", \"om\": \"...\"}], "
                . "\"nyhetsbrev\": {\"emne\": \"...\", \"hvorfor\": \"...\"}, "
                . "\"kurs\": [{\"tittel\": \"...\", \"hvorfor\": \"...\"}]}\n"
                . "Hoyst 3 artikler, 2 innlegg, 1 nyhetsbrev. Under kurs: de som trenger det mest."
            ),
            "Kurs med ledige plasser:\n" . implode("\n", $liste),
            'autopilot',
            6000
        );
        $lagre('seo', 'Autopilot — ukas forslag', '', $r, 'autopilot', AI::sisteKostnad());

    // ── Ta i bruk, eller legg bort ──────────────────────────────────────
    case 'godkjenn':
        $id = (int) ($kropp['id'] ?? 0);
        $u = DB::en('SELECT * FROM ai_utkast WHERE id = :i', ['i' => $id]);
        if ($u === null) {
            Svar::feil('Fant ikke utkastet.');
        }
        if ($u['status'] !== 'utkast') {
            Svar::feil('Utkastet er allerede behandlet.');
        }

        $data = json_decode((string) ($u['data'] ?? '{}'), true) ?: [];
        $resultat = null;

        // Artikler og SEO-sider blir til artikler. Resten godkjennes bare —
        // nyhetsbrev sendes fra Beskjeder, innlegg limes inn i kanalen.
        if (in_array($u['type'], ['artikkel', 'seo'], true) && trim((string) $u['tekst']) !== '') {
            $slug = trim((string) ($data['slug'] ?? ''));
            if ($slug === '') {
                $slug = mb_strtolower((string) $u['tittel']);
                $slug = strtr($slug, ['æ' => 'ae', 'ø' => 'o', 'å' => 'a']);
                $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '', '-');
            }
            // To artikler kan ikke dele adresse.
            $grunn = $slug;
            $n = 2;
            while ((int) DB::verdi('SELECT COUNT(*) FROM articles WHERE slug = :s', ['s' => $slug]) > 0) {
                $slug = $grunn . '-' . $n++;
            }
            $resultat = DB::settInn('articles', [
                'tittel'    => $u['tittel'],
                'kategori'  => $data['kategori'] ?? null,
                'slug'      => $slug,
                'fokus_ord' => $data['fokusord'] ?? ($data['sokeord'] ?? null),
                'ingress'   => $data['ingress'] ?? null,
                'innhold'   => $u['tekst'],
                'kilde'     => 'ai',
                // Kladd, ikke publisert. Godkjenning betyr «denne vil jeg ha»,
                // ikke «legg den ut naa» — eieren velger tidspunktet selv.
                'status'    => 'kladd',
            ]);
        }

        DB::oppdater('ai_utkast', ['status' => 'godkjent', 'resultat_id' => $resultat], ['id' => $id]);
        revider('ai_godkjent', 'ai', $id, ['type' => $u['type']]);
        Svar::ok([
            'beskjed' => $resultat !== null
                ? 'Lagt i kunnskapsbanken som kladd. Publiser den når du er klar.'
                : 'Utkastet er godkjent.',
            'artikkelId' => $resultat,
        ]);

    case 'forkast':
        $id = (int) ($kropp['id'] ?? 0);
        if (DB::en('SELECT id FROM ai_utkast WHERE id = :i', ['i' => $id]) === null) {
            Svar::feil('Fant ikke utkastet.');
        }
        DB::oppdater('ai_utkast', ['status' => 'forkastet'], ['id' => $id]);
        Svar::ok(['beskjed' => 'Utkastet er lagt bort.']);

    default:
        Svar::feil('Ukjent handling.');
}

<?php
/**
 * Markedsforing — alt som ikke krever et AI-kall.
 *
 *   GET                     hele skjermen: tavle, SEO-muligheter, kurs, sokeord, analyse
 *   GET ?utkast=<id>        ett utkast med hele teksten
 *   POST handling=sokeord   legg til, endre eller fjern et sokeord
 *   POST handling=innstilling  lagre GA-id, tak for AI-bruk, Google-kobling
 *
 * Tallene her regnes fra databasen. Ingenting er anslag, og ingenting er
 * skrevet inn for haand — er det ingen kurs som fylles tregt, staar lista tom
 * framfor aa vise et oppdiktet eksempel.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

$oslo = new DateTimeZone('Europe/Oslo');
$utc  = new DateTimeZone('UTC');
$naa  = new DateTimeImmutable('now', $oslo);

// ─────────────────────────────────────────────────────────────── lesing

// Teksten til ett utkast, hentet for seg.
//
// Lista over utkast baerer bare overskriftene. Foertti artikler paa noen tusen
// tegn hver ville vaert en megabyte ved hver eneste lasting av skjermen, og
// alt sammen for aa vise en tittel. Teksten hentes derfor naar den skal leses.
if (Foresporsel::metode() === 'GET' && Foresporsel::tekst('utkast') !== '') {
    $u = DB::en(
        'SELECT id, type, tittel, tekst, data, kontekst, status, kostnad_ore, created_at
           FROM ai_utkast WHERE id = :i',
        ['i' => (int) Foresporsel::tekst('utkast')]
    );
    if ($u === null) {
        Svar::feil('Fant ikke utkastet.', 404);
    }
    $data = json_decode((string) ($u['data'] ?? '{}'), true) ?: [];
    Svar::json(['utkast' => [
        'id'        => (int) $u['id'],
        'type'      => $u['type'],
        'tittel'    => $u['tittel'],
        'tekst'     => (string) $u['tekst'],
        'kontekst'  => (string) $u['kontekst'],
        'status'    => $u['status'],
        // Emneknagger og billedforslag ligger i data-feltet, ikke i teksten.
        // De skal limes inn hver for seg, saa de vises hver for seg.
        'hashtags'  => array_values(array_filter(array_map(
            static fn($h) => trim((string) $h),
            (array) ($data['hashtags'] ?? [])
        ))),
        'bildeforslag' => trim((string) ($data['bildeforslag'] ?? '')),
        // Bildet eieren faktisk valgte, i motsetning til forslaget over, som
        // er AI-ens beskrivelse i ord av et bilde som ikke finnes.
        'bilde'        => trim((string) ($data['bilde'] ?? '')) ?: null,
        'ingress'   => trim((string) ($data['ingress'] ?? '')),
        // Kanalen og formen innlegget ble skrevet for. Uten dem vet ikke
        // skjermen hvilket format bildet skal ha — Instagram vil ha 4:5,
        // en story 9:16, LinkedIn liggende.
        'kanal'     => trim((string) ($data['kanal'] ?? '')),
        'form'      => trim((string) ($data['form'] ?? '')),
        // Teksten satt sammen slik den limes inn: brodteksten, blank linje,
        // og emneknaggene til slutt. Bygget av serveren, saa den som
        // kopierer faar noeyaktig det som staar i forhaandsvisningen.
        'limtekst'  => Oppsett::sosialTekst((string) $u['tekst'], (array) ($data['hashtags'] ?? [])),
    ] + (static function () use ($data): array {
        $kanal = trim((string) ($data['kanal'] ?? ''));
        if ($kanal === '') {
            return [];
        }
        [$b, $h, $navn] = Oppsett::format($kanal, trim((string) ($data['form'] ?? '')));
        return ['format' => ['bredde' => $b, 'hoyde' => $h, 'navn' => $navn]];
    })()]);
}

if (Foresporsel::metode() === 'GET') {

    // ── Kurs som trenger hjelp, og kurs som snart er fulle ──────────────
    //
    // Begge regnes av de samme tallene: hvor mange plasser som er igjen mot
    // hvor mange det var. Et kurs som gaar om under fem uker og har mer enn
    // halvparten ledig, trenger markedsforing. Et med under en fjerdedel
    // igjen er snart fullt, og da er det en annen beskjed som skal ut.
    $okter = DB::alle(
        "SELECT cs.id, cs.start_tid, COALESCE(cs.kapasitet, c.kapasitet) AS kapasitet,
                cs.manuelt_opptatt, c.id AS kurs_id, c.tittel, c.type, c.slug, c.pris_ore
           FROM course_sessions cs
           JOIN courses c ON c.id = cs.course_id
          WHERE cs.status = 'planlagt'
            AND c.status = 'publisert'
            -- Drop-in staar utenfor. Den er walk-in: ledige plasser er
            -- normaltilstanden, ikke et tegn paa at noe maa markedsfores.
            -- Tok vi den med, ville de tolv drop-in-tidene begravd de to
            -- kursene som faktisk trenger hjelp.
            AND c.type <> 'dropin'
            AND cs.start_tid > UTC_TIMESTAMP()
            AND cs.start_tid < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 WEEK)
          ORDER BY cs.start_tid"
    );

    $tomme = [];
    $fulle = [];
    foreach ($okter as $o) {
        $kap = (int) $o['kapasitet'];
        if ($kap <= 0) {
            continue;
        }
        $ledige = Booking::ledigePlasser((int) $o['id']);
        $tatt   = $kap - $ledige;
        $start  = (new DateTimeImmutable((string) $o['start_tid'], $utc))->setTimezone($oslo);
        $dager  = (int) $naa->diff($start)->format('%a');

        $rad = [
            'oktId'   => (int) $o['id'],
            'kursId'  => (int) $o['kurs_id'],
            'tittel'  => $o['tittel'],
            'type'    => $o['type'],
            'dato'    => Booking::norskDato((string) $o['start_tid']),
            'dager'   => $dager,
            'ledige'  => $ledige,
            'tatt'    => $tatt,
            'kapasitet' => $kap,
            'andel'   => (int) round($tatt / $kap * 100),
            'pris'    => Booking::kroner((int) $o['pris_ore']),
        ];

        if ($ledige > 0 && $dager <= 35 && $ledige > $kap / 2) {
            $tomme[] = $rad;
        } elseif ($ledige > 0 && $ledige <= max(1, (int) floor($kap / 4))) {
            $fulle[] = $rad;
        }
    }

    // ── SEO-muligheter ──────────────────────────────────────────────────
    //
    // Regnet ut, ikke gjettet. Hver mulighet peker paa noe som mangler i
    // basen, og sier hva som skal gjores med det.
    $muligheter = [];

    $seoLagret = [];
    foreach (DB::alle("SELECT nokkel, verdi FROM content_blocks WHERE nokkel LIKE 'SEO/%'") as $r) {
        $seoLagret[substr((string) $r['nokkel'], 4)] = json_decode((string) $r['verdi'], true) ?: [];
    }

    // Sokeord uten en side som svarer paa dem.
    foreach (DB::alle('SELECT ord, maalside FROM marked_sokeord ORDER BY sortering, ord') as $s) {
        if (trim((string) $s['maalside']) === '') {
            $muligheter[] = [
                'type'    => 'Mangler side',
                'hva'     => 'Ingen side svarer på «' . $s['ord'] . '»',
                'hvorfor' => 'Søkeordet står på lista, men peker ikke til noen side. Da vet ikke Google hvilken side som er svaret.',
                'grep'    => 'Lag en artikkel, eller pek søkeordet til en side som finnes.',
                'sokeord' => $s['ord'],
                'alvor'   => 'hoy',
            ];
        }
    }

    // Sider med SEO-oppforing der beskrivelsen er for kort eller mangler.
    foreach ($seoLagret as $side => $d) {
        $meta = trim((string) ($d['meta'] ?? ''));
        if ($meta === '') {
            $muligheter[] = [
                'type' => 'Dårlig tittel', 'hva' => $side . ' mangler beskrivelse',
                'hvorfor' => 'Google skriver da sin egen, plukket fra siden. Den blir sjelden god.',
                'grep' => 'Fyll inn beskrivelsen under Søkeord og sider.', 'alvor' => 'hoy',
            ];
        } elseif (mb_strlen($meta) < 70) {
            $muligheter[] = [
                'type' => 'Dårlig tittel', 'hva' => $side . ' har kort beskrivelse (' . mb_strlen($meta) . ' tegn)',
                'hvorfor' => 'Under 70 tegn utnytter ikke plassen Google gir deg i treffet.',
                'grep' => 'Skriv den ut til 120–155 tegn.', 'alvor' => 'lav',
            ];
        }
    }

    // Artikler som ikke er rort paa lenge.
    foreach (DB::alle(
        "SELECT id, tittel, updated_at FROM articles
          WHERE status = 'publisert' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 MONTH)
          ORDER BY updated_at LIMIT 10"
    ) as $a) {
        $endret = (new DateTimeImmutable((string) $a['updated_at'], $utc))->setTimezone($oslo);
        $muligheter[] = [
            'type' => 'Foreldet innhold',
            'hva' => '«' . $a['tittel'] . '» er ikke endret siden ' . $endret->format('j.n.Y'),
            'hvorfor' => 'Google ser paa hvor ferskt innholdet er. Et aar gammel guide faller nedover.',
            'grep' => 'Les gjennom og oppdater, eller la AI-en foreslå en oppdatering.',
            'artikkelId' => (int) $a['id'], 'alvor' => 'lav',
        ];
    }

    // Finnes det sporsmaal og svar i det hele tatt?
    $harFaq = (int) DB::verdi(
        "SELECT COUNT(*) FROM content_blocks WHERE nokkel LIKE '%Spørsmål%' OR nokkel LIKE '%Svar %'"
    );
    if ($harFaq === 0) {
        $muligheter[] = [
            'type' => 'Mangler FAQ',
            'hva' => 'Ingen spørsmål og svar er lagt inn',
            'hvorfor' => 'Google viser gjerne spørsmål og svar rett i treffet. Uten dem taper du plassen til noen andre.',
            'grep' => 'Legg inn de fem spørsmålene dere får oftest på telefonen.',
            'alvor' => 'hoy',
        ];
    }

    // ── Analyse ─────────────────────────────────────────────────────────
    //
    // Uten Google Analytics har vi ingen besokstall — og da sier vi det, i
    // stedet for aa vise en graf uten tall bak.
    $gaId = trim((string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Marked/GA-id'"));

    // Det vi vet selv, uten Google: hva folk faktisk har booket og kjopt.
    $mestBookede = DB::alle(
        "SELECT c.tittel, COUNT(*) AS antall, SUM(b.antall) AS plasser
           FROM bookings b JOIN courses c ON c.id = b.course_id
          WHERE b.status IN ('betalt','reservert')
            AND b.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 MONTH)
          GROUP BY c.id ORDER BY plasser DESC LIMIT 8"
    );

    // Utkastraden slik tavla trenger den: bildet ut av data-feltet, og
    // data-feltet ut av svaret. Resten av det som ligger der — fokusord,
    // metabeskrivelse, hashtags — hoerer til inne i utkastet, ikke paa tavla.
    $medBilde = static function (array $r): array {
        $d = json_decode((string) ($r['data'] ?? '{}'), true) ?: [];
        unset($r['data']);
        $r['bilde'] = trim((string) ($d['bilde'] ?? '')) ?: null;
        return $r;
    };

    Svar::json([
        'ai'          => AI::status(),
        'kursTomme'   => $tomme,
        'kursFulle'   => $fulle,
        'muligheter'  => $muligheter,
        'sokeord'     => DB::alle('SELECT id, ord, maalside, notat FROM marked_sokeord ORDER BY sortering, ord'),
        // Bildet eieren valgte da utkastet ble laget ligger i data-feltet.
        // Tavla viser det, saa man ser hva utkastet kommer til aa se ut som
        // uten aa aapne det.
        'utkast'      => array_map($medBilde, DB::alle(
            "SELECT id, type, tittel, kontekst, status, kostnad_ore, created_at, data
               FROM ai_utkast WHERE status = 'utkast' ORDER BY id DESC LIMIT 40"
        )),
        // Godkjente utkast forsvant fra tavla i det de ble godkjent. En
        // artikkel havnet i kunnskapsbanken, men et nyhetsbrev eller et
        // innlegg gikk ingen steder — det sto bare «godkjent», og teksten var
        // borte. Naa blir de staaende, med teksten i behold, til de er brukt.
        'godkjente'   => array_map($medBilde, DB::alle(
            "SELECT id, type, tittel, kontekst, status, resultat_id, created_at, data
               FROM ai_utkast WHERE status = 'godkjent' ORDER BY id DESC LIMIT 20"
        )),
        'artikler'    => DB::alle(
            'SELECT id, tittel, kategori, slug, status, kilde, updated_at FROM articles ORDER BY sortering, id DESC'
        ),
        'analyse'     => [
            'gaTilkoblet' => $gaId !== '',
            'gaId'        => $gaId,
            'mestBookede' => array_map(static fn($r) => [
                'tittel'  => $r['tittel'],
                'plasser' => (int) $r['plasser'],
                'kjop'    => (int) $r['antall'],
            ], $mestBookede),
            // Vi later ikke som vi har besokstall vi ikke har.
            'mangler'     => $gaId === ''
                ? 'Besøkstall, mest leste sider og søkeord krever Google Analytics. Legg inn måle-ID-en under Innstillinger.'
                : null,
        ],
        'innstillinger' => [
            'gaId'        => $gaId,
            'aiTak'       => AI::tak(),
            'googleBedrift' => trim((string) DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = 'Marked/Google-bedrift'")),
        ],
        'forbruk'     => DB::alle(
            "SELECT formal, COUNT(*) AS kall, SUM(kostnad_ore) AS ore
               FROM ai_logg WHERE ok = 1 GROUP BY formal ORDER BY ore DESC LIMIT 10"
        ),
    ]);
}

// ─────────────────────────────────────────────────────────────── skriving
Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();

$kropp = Foresporsel::kropp();

switch (Foresporsel::tekst('handling')) {

    case 'sokeord':
        $ord = trim(mb_substr((string) ($kropp['ord'] ?? ''), 0, 191));
        $id  = (int) ($kropp['id'] ?? 0);

        if (($kropp['fjern'] ?? false) === true && $id > 0) {
            DB::kjor('DELETE FROM marked_sokeord WHERE id = :i', ['i' => $id]);
            revider('sokeord_fjernet', 'marked', $id);
            Svar::ok(['beskjed' => 'Søkeordet er fjernet.']);
        }

        if ($ord === '') {
            Svar::feil('Skriv inn søkeordet.');
        }
        if ((int) DB::verdi('SELECT COUNT(*) FROM marked_sokeord') >= 200 && $id === 0) {
            Svar::feil('Det er nok søkeord nå — rydd i lista før du legger til flere.');
        }

        $felter = [
            'ord'      => $ord,
            'maalside' => mb_substr(trim((string) ($kropp['maalside'] ?? '')), 0, 64) ?: null,
            'notat'    => mb_substr(trim((string) ($kropp['notat'] ?? '')), 0, 500) ?: null,
        ];

        if ($id > 0) {
            DB::oppdater('marked_sokeord', $felter, ['id' => $id]);
        } else {
            // Det samme ordet to ganger er ikke to muligheter, det er én.
            if ((int) DB::verdi('SELECT COUNT(*) FROM marked_sokeord WHERE ord = :o', ['o' => $ord]) > 0) {
                Svar::feil('«' . $ord . '» står på lista fra før.');
            }
            $id = DB::settInn('marked_sokeord', $felter + ['sortering' => 999]);
        }
        revider('sokeord_lagret', 'marked', $id, ['ord' => $ord]);
        Svar::ok(['beskjed' => 'Søkeordet er lagret.', 'id' => $id]);

    case 'innstilling':
        $lagre = static function (string $nokkel, string $verdi): void {
            DB::kjor(
                'INSERT INTO content_blocks (nokkel, verdi) VALUES (:n, :v)
                 ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',
                ['n' => $nokkel, 'v' => $verdi]
            );
        };

        if (array_key_exists('gaId', $kropp)) {
            $ga = trim((string) $kropp['gaId']);
            // G-XXXXXXXXXX er formen Google gir ut i dag. Tar imot tom for aa
            // koble fra, men ikke noe som aapenbart ikke er en maale-ID —
            // ellers legges det inn en tagg som stille lar vaere aa virke.
            if ($ga !== '' && preg_match('/^G-[A-Z0-9]{6,20}$/i', $ga) !== 1) {
                Svar::feil('Måle-ID-en ser ikke riktig ut. Den skal se ut som G-ABC1234567, og står i Google Analytics under Admin → Datastrømmer.');
            }
            $lagre('Marked/GA-id', strtoupper($ga));
        }

        if (array_key_exists('aiTak', $kropp)) {
            $tak = (int) preg_replace('/\D+/', '', (string) $kropp['aiTak']);
            if ($tak < 0 || $tak > 20000) {
                Svar::feil('Taket må være mellom 0 og 20 000 kroner.');
            }
            $lagre('Marked/AI-tak', (string) $tak);
        }

        if (array_key_exists('googleBedrift', $kropp)) {
            $lagre('Marked/Google-bedrift', mb_substr(trim((string) $kropp['googleBedrift']), 0, 191));
        }

        revider('marked_innstilling', 'marked', null);
        Svar::ok(['beskjed' => 'Innstillingen er lagret.']);

    default:
        Svar::feil('Ukjent handling.');
}

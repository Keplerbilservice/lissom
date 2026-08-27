<?php
/**
 * Registrerer webhooken hos Vipps, og lagrer hemmeligheten den kommer med.
 *
 *   GET                       webhookene som er registrert naa
 *   POST handling=registrer   registrerer paa nytt og lagrer hemmeligheten
 *   POST handling=slett       fjerner én
 *
 * Vipps melder fra hit naar en betaling endrer tilstand. Uten webhooken faar
 * vi det likevel med oss — cron sporr Vipps hvert femte minutt — men da tar
 * det opptil fem minutter for kvitteringen gaar ut, og kunden staar og lurer.
 *
 * Hemmeligheten Vipps signerer med vises bare den ene gangen webhooken
 * opprettes. Derfor lagres den med det samme, i innstillinger. Den kan ikke
 * hentes igjen — bare erstattes av en ny registrering.
 */

declare(strict_types=1);

require __DIR__ . '/../_boot.php';

krev_admin();

if (Foresporsel::metode() === 'GET') {
    try {
        $liste = Vipps::webhooks();
    } catch (Throwable $e) {
        Svar::json([
            'feil'      => $e->getMessage(),
            'webhooks'  => [],
            'adresse'   => Vipps::webhookAdresse(),
            'har_hemmelighet' => trim((string) Config::hent('vipps_webhook_secret', '')) !== '',
        ]);
    }

    $vaar = Vipps::webhookAdresse();
    Svar::json([
        'webhooks' => $liste,
        'adresse'  => $vaar,
        // Er den vaar registrert, og har vi hemmeligheten til den?
        'registrert' => array_values(array_filter($liste, static fn($w) => $w['url'] === $vaar)) !== [],
        'har_hemmelighet' => trim((string) Config::hent('vipps_webhook_secret', '')) !== '',
        'hendelser' => Vipps::WEBHOOK_HENDELSER,
    ]);
}

Foresporsel::krevMetode('POST');
Foresporsel::krevSammeOpphav();
$kropp = Foresporsel::kropp();

switch (Foresporsel::tekst('handling')) {

    case 'registrer':
        if (!DB::harTabell('innstillinger')) {
            Svar::feil('Migrasjon 036 er ikke kjørt. Kjør oppdateringen først, så kan hemmeligheten lagres.');
        }
        // Adressen maa vaere https. Vipps godtar ikke http, og en webhook som
        // peker paa noe annet enn den ekte sida ville sendt betalingene dit.
        if (!str_starts_with(Vipps::webhookAdresse(), 'https://')) {
            Svar::feil('Adressen må være https. Sjekk «nettsted» i secrets.php — den står nå som '
                . Vipps::webhookAdresse() . '.');
        }

        // Rydd bort en gammel registrering paa samme adresse foerst. Ellers
        // sender Vipps hver hendelse to ganger, og vi sitter med en
        // hemmelighet som bare passer den ene.
        $fjernet = 0;
        try {
            foreach (Vipps::webhooks() as $w) {
                if ($w['url'] === Vipps::webhookAdresse() && $w['id'] !== '') {
                    Vipps::slettWebhook($w['id']);
                    $fjernet++;
                }
            }
        } catch (Throwable $e) {
            // Fikk vi ikke lista, gaar vi videre og registrerer. En dublett
            // er bedre enn ingen webhook — og duplikater fanges uansett av
            // event_id i vipps_webhook_events.
            logg('Fikk ikke ryddet gamle webhooks', ['feil' => $e->getMessage()]);
        }

        try {
            $ny = Vipps::registrerWebhook();
        } catch (Throwable $e) {
            Svar::feil($e->getMessage());
        }

        DB::kjor(
            'INSERT INTO innstillinger (nokkel, verdi, endret_av) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret_av = VALUES(endret_av)',
            ['vipps_webhook_secret', $ny['secret'], (int) (Sesjon::medlem()['id'] ?? 0) ?: null]
        );
        Config::glemBasen();

        // Hemmeligheten revideres ikke — bare at den ble byttet.
        revider('vipps_webhook_registrert', null, null, ['fjernet' => $fjernet]);

        Svar::ok([
            'beskjed' => $fjernet > 0
                ? 'Webhooken er registrert på nytt. Den gamle er fjernet.'
                : 'Webhooken er registrert. Vipps melder fra hit med én gang noe skjer.',
            'adresse' => Vipps::webhookAdresse(),
        ]);

    case 'slett':
        $id = trim((string) ($kropp['id'] ?? ''));
        if ($id === '') {
            Svar::feil('Mangler hvilken som skal fjernes.');
        }
        try {
            Vipps::slettWebhook($id);
        } catch (Throwable $e) {
            Svar::feil($e->getMessage());
        }
        revider('vipps_webhook_slettet', null, null, ['id' => $id]);
        Svar::ok(['beskjed' => 'Webhooken er fjernet. Betalinger fanges nå opp av cron i stedet, '
                             . 'som spør Vipps hvert femte minutt.']);

    default:
        Svar::feil('Ukjent handling.');
}

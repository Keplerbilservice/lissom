<?php
/**
 * Det som er likt i api/skisser.php (Min side) og api/admin/skisser.php.
 *
 * Portvakten står i hvert endepunkt; her er det bare handlingene. Reglene for
 * hvem som ser og endrer hva, ligger i Skisser — ikke her, og ikke i
 * skjermbildet.
 *
 *   GET                                   tavlene du ser
 *   GET  ?id=12                           én tavle med sidene
 *   GET  ?id=12&versjoner=1&nr=2          versjonene av side 2
 *   POST handling=ny         { tittel }
 *   POST handling=lagre      { id, nr, data }
 *   POST handling=navn       { id, tittel }
 *   POST handling=slett-side { id, nr }
 *   POST handling=slett      { id }
 *   POST handling=del        { id, medlemmer, deltakere }   bare admin
 *   POST handling=bilde      multipart: id + bilde
 */

declare(strict_types=1);

final class SkisserApi
{
/** @param array<string,mixed> $m */
public static function haandter(array $m): never
{
    if (Foresporsel::metode() === 'GET') {
        $id = Foresporsel::heltall('id');
        if ($id > 0) {
            if (Foresporsel::tekst('versjoner') === '1') {
                Svar::json(['ok' => true, 'versjoner' => Skisser::versjoner($id, Foresporsel::heltall('nr', 1), $m)]);
            }
            $t = Skisser::hent($id, $m);
            if ($t === null) {
                Svar::feil('Fant ikke tavla.', 404);
            }
            Svar::json(['ok' => true, 'tavle' => $t]);
        }
        Svar::json([
            'ok'     => true,
            'admin'  => Skisser::erAdmin($m),
            'tavler' => Skisser::liste($m),
            'maks'   => ['sider' => Skisser::MAKS_SIDER, 'tavler' => Skisser::MAKS_TAVLER],
        ]);
    }

    Foresporsel::krevMetode('POST');
    Foresporsel::krevSammeOpphav();

    // Bildet kommer som multipart: $_POST, ikke JSON-kroppen.
    $handling = (string) ($_POST['handling'] ?? Foresporsel::tekst('handling'));
    $kropp = Foresporsel::kropp();
    $id = (int) ($kropp['id'] ?? $_POST['id'] ?? 0);

    try {
        switch ($handling) {
            case 'ny':
                $ny = Skisser::ny($m, (string) ($kropp['tittel'] ?? ''));
                Svar::ok(['id' => $ny, 'tavle' => Skisser::hent($ny, $m)]);

            case 'lagre':
                $data = $kropp['data'] ?? '';
                if (is_array($data)) {
                    $data = (string) json_encode($data, JSON_UNESCAPED_UNICODE);
                }
                Skisser::lagreSide($id, (int) ($kropp['nr'] ?? 1), (string) $data, $m);
                Svar::ok(['lagret' => gmdate('c')]);

            case 'navn':
                Skisser::giNavn($id, (string) ($kropp['tittel'] ?? ''), $m);
                Svar::ok();

            case 'slett-side':
                Skisser::slettSide($id, (int) ($kropp['nr'] ?? 0), $m);
                Svar::ok(['tavle' => Skisser::hent($id, $m)]);

            case 'slett':
                Skisser::slett($id, $m);
                Svar::ok();

            case 'del':
                Skisser::del($id, !empty($kropp['medlemmer']), !empty($kropp['deltakere']), $m);
                Svar::ok(['tavle' => Skisser::hent($id, $m)]);

            case 'bilde':
                if (!isset($_FILES['bilde']) || ($_FILES['bilde']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    Svar::feil('Du må velge et bilde.');
                }
                $r = Skisser::hent($id, $m);
                if ($r === null || empty($r['kanEndre'])) {
                    Svar::feil('Fant ikke tavla.', 404);
                }
                $fil = Bilder::taImot($_FILES['bilde'], Skisser::MAPPE);
                Svar::ok(['url' => Skisser::leggTilBilde($id, $fil, $m)]);
        }
    } catch (DomainException $e) {
        Svar::feil($e->getMessage(), 404);
    } catch (RuntimeException $e) {
        Svar::feil($e->getMessage());
    }

    Svar::feil('Ukjent handling.');
}

/** Et bilde fra en tavle, for den som får se tavla. */
public static function leverBilde(string $fil): never
{
    if (preg_match('/^[A-Za-z0-9._-]{8,64}$/', $fil) !== 1) {
        Svar::feil('Fant ikke bildet.', 404);
    }
    $sti = Bilder::sti($fil, Skisser::MAPPE);
    if ($sti === null || !Skisser::kanSeBilde($fil, Sesjon::medlem())) {
        Svar::feil('Fant ikke bildet.', 404);
    }
    // Privat: en tavle som ikke er delt, skal ikke ligge i noen delt mellomlagring.
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($sti));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($sti);
    exit;
}
}

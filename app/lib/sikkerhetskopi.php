<?php
/**
 * Nattlig kopi av databasen.
 *
 * ── Hvorfor ──────────────────────────────────────────────────────────────
 *
 * Koden ligger i git og kan hentes tilbake fra en tagg. Basen laa uten kopi
 * i det hele tatt: medlemmer, bookinger, betalinger, innhold — og
 * «innstillinger», som holder Meta-tokenet og maale-noeklene. Ingenting av
 * det finnes noe annet sted.
 *
 * Eieren, 23. september 2026: «legg inn auto back upp hver natt». Samme
 * kveld sviktet phpMyAdmin hos verten («Access denied for user
 * cpses_…»), saa en kopi tatt for haand var ikke engang mulig.
 *
 * ── Hvor den legger seg ──────────────────────────────────────────────────
 *
 * ~/lissom-sikkerhetskopier/lissom-2026-09-23.sql.gz
 *
 * Over public_html, saa fila ikke kan lastes ned fra nettet, og med
 * rettighet 0600. Den inneholder personopplysninger og hemmeligheter —
 * ligger den i en mappe nettet naar, er kopien selv lekkasjen.
 *
 * ── To veier ─────────────────────────────────────────────────────────────
 *
 * «mysqldump» naar den finnes, ellers en ren PHP-dump. Delt webhotell slaar
 * ofte av proc_open, og en sikkerhetskopi som bare virker noen steder er
 * ikke en sikkerhetskopi. Passordet gaar gjennom miljoevariabelen MYSQL_PWD
 * og aldri paa kommandolinja — der ville det staatt synlig for alle som
 * kjorer «ps» paa samme tjener.
 */

declare(strict_types=1);

final class Sikkerhetskopi
{
    /** Hvor mange dogn som beholdes. Eldre slettes av jobben selv. */
    public const BEHOLD_DAGER = 14;

    /** Mappa kopiene ligger i. Over public_html med vilje. */
    public static function mappe(): string
    {
        return dirname(APP_DIR, 2) . '/lissom-sikkerhetskopier';
    }

    /**
     * Tar kopien. Kalles fra bin/cron.php.
     *
     * Trygg aa kjore flere ganger i dognet: fila heter det samme hele dagen
     * og skrives over. To kjoringer gir én fil, ikke to.
     *
     * @return array{fil: string, bytes: int, metode: string, slettet: int}
     */
    public static function kjor(): array
    {
        $mappe = self::mappe();
        if (!is_dir($mappe) && !@mkdir($mappe, 0700, true) && !is_dir($mappe)) {
            throw new RuntimeException('Fikk ikke laget mappa ' . $mappe);
        }
        @chmod($mappe, 0700);

        $fil = $mappe . '/lissom-' . gmdate('Y-m-d') . '.sql.gz';
        $raa = $fil . '.delvis';

        // Skriver til «.delvis» foerst og gir den riktig navn til slutt.
        // Stopper jobben midtveis — tom disk, tidsavbrudd — staar gaarsdagens
        // kopi urort, og ingen tror en halv fil er hel.
        $metode = self::medMysqldump($raa) ? 'mysqldump' : 'php';
        if ($metode === 'php') {
            self::medPhp($raa);
        }

        if (!is_file($raa) || filesize($raa) < 1024) {
            @unlink($raa);
            throw new RuntimeException('Kopien ble tom. Ingenting er skrevet.');
        }
        @unlink($fil);
        if (!@rename($raa, $fil)) {
            @unlink($raa);
            throw new RuntimeException('Fikk ikke gitt kopien riktig navn.');
        }
        @chmod($fil, 0600);

        return [
            'fil'     => $fil,
            'bytes'   => (int) filesize($fil),
            'metode'  => $metode,
            'slettet' => self::rydd(),
        ];
    }

    /**
     * mysqldump, komprimert mens den skrives.
     *
     * Returnerer false naar veien ikke finnes — da tar medPhp() over.
     */
    private static function medMysqldump(string $ut): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $av = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('proc_open', $av, true)) {
            return false;
        }

        $kommando = [
            'mysqldump',
            '--host=' . Config::hent('db_vert', 'localhost'),
            '--user=' . Config::krev('db_bruker'),
            // Uten denne staar hele tabellen i minnet hos mysqldump.
            '--quick',
            '--single-transaction',
            '--default-character-set=utf8mb4',
            '--no-tablespaces',
            '--add-drop-table',
            Config::krev('db_navn'),
        ];

        $ror = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // Passordet i miljoet, ikke i argumentene. «ps» viser argumentene.
        $miljo = ['MYSQL_PWD' => Config::krev('db_passord'), 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
        $p = @proc_open($kommando, $ror, $rorene, null, $miljo);
        if (!is_resource($p)) {
            return false;
        }

        $gz = gzopen($ut, 'wb6');
        if ($gz === false) {
            proc_close($p);
            return false;
        }
        while (!feof($rorene[1])) {
            $bit = fread($rorene[1], 262144);
            if ($bit === false || $bit === '') {
                break;
            }
            gzwrite($gz, $bit);
        }
        gzclose($gz);
        fclose($rorene[1]);
        $feil = (string) stream_get_contents($rorene[2]);
        fclose($rorene[2]);
        $kode = proc_close($p);

        if ($kode !== 0) {
            @unlink($ut);
            // Ikke en feil aa melde: finnes ikke mysqldump paa denne
            // tjeneren, tar PHP-veien over. Men skriv det i loggen, saa det
            // er kjent hvilken vei kopien faktisk gaar.
            logg('mysqldump gikk ikke — tar PHP-veien', ['kode' => $kode, 'feil' => mb_substr($feil, 0, 300)]);
            return false;
        }
        return true;
    }

    /**
     * Dump skrevet av PHP selv. Virker uten proc_open og uten mysqldump.
     *
     * Radene hentes i bolker. Hele «bookings» i ett svar ville lagt basen i
     * minnet paa en tjener som har lite av det.
     */
    private static function medPhp(string $ut): void
    {
        $gz = gzopen($ut, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('Fikk ikke skrevet til ' . $ut);
        }
        $skriv = static function (string $s) use ($gz): void {
            gzwrite($gz, $s);
        };

        $pdo = DB::kobling();
        $skriv('-- Lissom, kopi tatt ' . gmdate('Y-m-d H:i:s') . " UTC\n");
        $skriv("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        // Navn og definisjoner foerst, mens svarene fortsatt bufres. Under
        // stroemmingen lenger nede taaler ikke forbindelsen en sporring til.
        $tabeller = [];
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$navn, $mene]) {
            $sitert = '`' . str_replace('`', '``', (string) $navn) . '`';
            $erView = strtoupper((string) $mene) === 'VIEW';
            $lag = $erView ? null : $pdo->query("SHOW CREATE TABLE {$sitert}")->fetch(PDO::FETCH_NUM);
            $tabeller[] = ['sitert' => $sitert, 'view' => $erView, 'lag' => (string) ($lag[1] ?? '')];
        }

        // Ett oeyeblikksbilde for hele kopien. Uten det kan en booking som
        // skjer midt i dumpen staa i «bookings» og mangle i «payments».
        // mysqldump gjor det samme med --single-transaction.
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        // Radene stroemmes, én om gangen.
        //
        // Her sto «LIMIT 500 OFFSET n» i loekke foerst. Uten ORDER BY gir
        // MariaDB ingen garanti for rekkefolgen mellom to sporringer: samme
        // rad kunne komme to ganger, og en annen falle ut — uten at noe sa
        // fra. En kopi som mister rader i stillhet er verre enn ingen kopi,
        // fordi den ser hel ut den dagen man trenger den.
        //
        // En ubufret sporring henter radene etter hvert, saa hele tabellen
        // aldri staar i minnet, og rekkefolgen er den basen selv leser i.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            foreach ($tabeller as $t) {
                if ($t['view']) {
                    $skriv("DROP VIEW IF EXISTS {$t['sitert']};\n");
                    continue;
                }
                $skriv("DROP TABLE IF EXISTS {$t['sitert']};\n" . $t['lag'] . ";\n");

                $rader = $pdo->query("SELECT * FROM {$t['sitert']}");
                $bolk = [];
                $tom = static function () use (&$bolk, $skriv, $t): void {
                    if ($bolk === []) {
                        return;
                    }
                    $skriv("INSERT INTO {$t['sitert']} VALUES\n" . implode(",\n", $bolk) . ";\n");
                    $bolk = [];
                };
                while (($rad = $rader->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $felt = [];
                    foreach ($rad as $v) {
                        $felt[] = $v === null ? 'NULL'
                            : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v));
                    }
                    $bolk[] = '(' . implode(',', $felt) . ')';
                    if (count($bolk) >= 200) {
                        $tom();
                    }
                }
                $rader->closeCursor();
                $tom();
                $skriv("\n");
            }
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            $pdo->exec('COMMIT');
        }

        $skriv("SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
    }

    /**
     * Sletter kopier eldre enn BEHOLD_DAGER.
     *
     * Uten denne fyller kopiene disken, og da stopper nettsida — en
     * sikkerhetskopi som velter tjeneren har gjort mer skade enn nytte.
     */
    private static function rydd(): int
    {
        $grense = time() - (self::BEHOLD_DAGER * 86400);
        $slettet = 0;
        foreach (glob(self::mappe() . '/lissom-*.sql.gz') ?: [] as $f) {
            if (filemtime($f) < $grense && @unlink($f)) {
                $slettet++;
            }
        }
        return $slettet;
    }
}

<?php
/**
 * Bakgrunnsarbeid uten cron.
 *
 * Kvitteringer og betalingsoppslag ligger i ko og trenger noe som tommer den.
 * Cron-jobber i cPanel er den ryddige losningen, men de maa settes opp for
 * haand. Denne lar vanlig trafikk paa nettsiden gjore jobben i stedet.
 *
 * Slik unngaar vi at det gaar utover den som besoker siden:
 *   - hoyst en gang i minuttet, uansett hvor mange som er inne samtidig
 *   - kjorer ETTER at svaret er sendt til nettleseren
 *   - smaa porsjoner, og feil faar aldri velte forespoerselen
 *
 * Dette erstatter ikke cron helt: en side uten besokende blir aldri tikket.
 * Kvitteringer gaar likevel ut, for den som nettopp betalte ER trafikk.
 */

declare(strict_types=1);

final class Tikk
{
    /** Registrerer at arbeidet skal gjores naar svaret er levert. */
    public static function planlegg(): void
    {
        if (PHP_SAPI === 'cli') {
            return; // cron kjorer jobbene direkte
        }

        register_shutdown_function(static function (): void {
            // Slipp nettleseren fri forst der serveren stotter det, saa ingen
            // venter paa at vi sender e-post.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                self::kjor();
            } catch (Throwable $e) {
                logg_feil('Bakgrunnsarbeid feilet', $e);
            }
        });
    }

    private static function kjor(): void
    {
        // Forste forespoersel i hvert minuttvindu vinner. Resten gaar videre.
        if (!Rate::tillat('tikk', 1, 60, 'server')) {
            return;
        }

        [$sendt, $feilet] = Utsending::tomKo(10);
        if ($sendt > 0 || $feilet > 0) {
            logg('Varselko tomt av trafikk', ['sendt' => $sendt, 'feilet' => $feilet]);
        }

        self::sjekkHengendeBetalinger();

        // Stengetid. Eieren, 2. september: «Automatisk utstemplibg kl 23».
        //
        // Rettinga skjedde bare naar noen slo opp innstemplinga eller aapnet
        // medlemslista. Glemte den siste av gaarde seg en fredag kveld, sto
        // hen innstemplet til noen logget inn — og aapningstida paa nettsida,
        // som leser om verkstedet er bemannet, sa «aapent naa» hele natta.
        //
        // Her er det trafikken paa sida som lukker den, innen minuttet.
        try {
            $lukket = Stempling::lukkGlemte();
            if ($lukket > 0) {
                logg('Glemte innstemplinger lukket', ['antall' => $lukket]);
            }
        } catch (Throwable $e) {
            logg_feil('Kunne ikke lukke glemte innstemplinger', $e);
        }

        // Paint on Pots og lignende folger aapningstidene. Kalenderen endrer
        // seg — et kurs settes opp, et avlyses, en feriedag legges inn — og
        // da skal de aapne plassene folge etter uten at noen maa trykke paa
        // noe. Feil her skal aldri velte en forespoersel.
        try {
            $r = Apent::leggUtPaaApneTider();
            if ($r['laget'] > 0 || $r['fjernet'] > 0) {
                logg('Aapne plasser oppdatert', $r);
            }
        } catch (Throwable $e) {
            logg_feil('Kunne ikke legge ut aapne plasser', $e);
        }
    }

    /**
     * Betalinger som har staatt og ventet en stund. Webhooken er kilden, men
     * kommer den ikke fram, ville kunden staatt som ubetalt i det uendelige.
     */
    private static function sjekkHengendeBetalinger(): void
    {
        // «type <> recurring_charge» sto i bin/cron.php, men manglet her.
        // Maanedstrekkene er ikke ePayment og finnes ikke paa den adressen:
        // hvert oppslag gir 404 og en linje i feilloggen. Verre er det at de
        // ligger foerst i «ORDER BY id» og aldri gaar bort — tre gamle trekk
        // ville brukt opp hele LIMIT-en, og en ekte kursbetaling som hang
        // ville aldri blitt sjekket. Trekkene hentes fra avtalen sin, i
        // cron-jobben «medlemstrekk».
        //
        // «opprettet» staar med her, som i cron. Alle kanalene setter
        // «venter» med én gang Vipps har svart, saa en rad blir bare staaende
        // paa «opprettet» hvis PHP doer akkurat mellom de to linjene — men da
        // er den ellers usynlig for alle sikkerhetsnett.
        $venter = DB::alle(
            "SELECT vipps_reference
               FROM payments
              WHERE status IN ('opprettet','venter','autorisert')
                AND type <> 'recurring_charge'
                AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)
                AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 MINUTE)
              ORDER BY id
              LIMIT 3"
        );

        // Selve behandlingen ligger i Vipps::synkroniser(). Den sto her, og
        // bin/cron.php hadde sin egen halve utgave som bare lagret svaret
        // uten aa gjore noe med det. Naa er det ett sted, og begge kaller
        // det samme.
        foreach ($venter as $p) {
            Vipps::synkroniser((string) $p['vipps_reference']);
        }
    }
}

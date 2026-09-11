<?php
/**
 * Avmelding fra tilbud paa e-post.
 *
 * Eieren, 11. september 2026: medlemsinvitasjonen etter kurset (malen
 * «fortsett») er markedsføring til tidligere kunder. Lovlig etter
 * markedsføringsloven § 15, men bare med en avmelding som virker — og
 * personvernsida sier «du kan melde deg av når som helst».
 *
 * Én rad per e-postadresse, med en personlig kode. Lenka i e-posten er
 * /avmelding?k=<kode>; aapnes den, settes «reservert», og jobbene som sender
 * tilbud hopper over adressen. Koden er tilfeldig og lang nok til at den
 * ikke kan gjettes; den avsloerer ikke adressen.
 */

declare(strict_types=1);

final class Avmelding
{
    public static function klar(): bool
    {
        return DB::harTabell('epost_avmelding');
    }

    /** Lenka til e-posten. Lager raden om den ikke finnes. */
    public static function lenke(string $epost): string
    {
        $epost = mb_strtolower(trim($epost));
        $rad = DB::en('SELECT kode FROM epost_avmelding WHERE epost = :e', ['e' => $epost]);
        if ($rad === null) {
            $kode = bin2hex(random_bytes(16));
            DB::settInn('epost_avmelding', ['epost' => $epost, 'kode' => $kode]);
        } else {
            $kode = (string) $rad['kode'];
        }
        return 'https://lissom.no/avmelding?k=' . $kode;
    }

    /** Har adressen sagt nei til tilbud? */
    public static function erReservert(string $epost): bool
    {
        if (!self::klar()) {
            return false;
        }
        return (int) (DB::verdi(
            'SELECT reservert FROM epost_avmelding WHERE epost = :e',
            ['e' => mb_strtolower(trim($epost))]
        ) ?? 0) === 1;
    }

    /** Setter reservasjonen for koden. Sann naar koden finnes. */
    public static function reserver(string $kode): bool
    {
        if (!self::klar() || !preg_match('/^[a-f0-9]{32}$/', $kode)) {
            return false;
        }
        $id = DB::verdi('SELECT id FROM epost_avmelding WHERE kode = :k', ['k' => $kode]);
        if ($id === null) {
            return false;
        }
        DB::oppdater('epost_avmelding', ['reservert' => 1, 'reservert_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $id]);
        return true;
    }
}

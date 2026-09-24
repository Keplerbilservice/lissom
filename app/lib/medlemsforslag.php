<?php
/**
 * Medlemmer foreslaar Instagram-innlegg.
 *
 * Eieren, 24. september 2026: «De kan lage et forslag til instagram post,
 * enten en video paa maks 15 sekunder, eller et bilde […] maa godkjennes av
 * admin, paa denne maaten saa vil jeg faa mange fler til aa legge ut og
 * skryte av lissom keramikk». Godkjent forslag legges ut paa
 * @lissom_keramikk med medlemmets tekst, en fast linje fra malen og
 * hashtaggene.
 *
 * ── Hvorfor fila er privat til den er godkjent ────────────────────────
 *
 * Meta henter bildet eller videoen selv fra en offentlig adresse. Den
 * adressen skal bare virke fra det oeyeblikket verkstedet har sagt ja —
 * et forslag som venter eller er avvist er noe et medlem sendte til
 * verkstedet, ikke til alle. Se api/bilde.php ?forslag=.
 *
 * ── Instagrams egne grenser ──────────────────────────────────────────
 *
 * Et bilde maa ligge mellom 4:5 (staaende) og 1,91:1 (liggende), ellers
 * avviser Instagram det. Et vanlig mobilbilde er 3:4 — for smalt. Derfor
 * beskjaeres det fra midten allerede ved opplasting, saa medlemmet ser
 * akkurat det som blir lagt ut. En Reel maa vaere minst tre sekunder.
 */

declare(strict_types=1);

final class Medlemsforslag
{
    /** Eierens grense. Litt slark for avrunding i telefonens teller. */
    public const MAKS_SEKUNDER = 15.5;
    /** Instagrams nedre grense for en Reel. */
    public const MIN_SEKUNDER = 3.0;
    private const MAKS_VIDEO_BYTES = 100 * 1024 * 1024;
    private const MAPPE = 'forslag';
    public const STANDARD_MAL = '🏺 Laget av {medlem}, medlem hos Lissom Keramikk';
    /** Kommer alltid med, uansett hva medlemmet skrev. */
    public const FAST_TAGG = '#lissomkeramikk';

    public static function klar(): bool
    {
        return DB::harTabell('medlemsforslag');
    }

    /** Bryteren ⊙ Synlighet → Paa Min side → Del paa Instagram. */
    public static function paa(): bool
    {
        return (string) DB::verdi(
            "SELECT verdi FROM content_blocks WHERE nokkel = 'Vis/medlemsforslag'"
        ) !== 'nei';
    }

    public static function mal(): string
    {
        $v = trim((string) DB::verdi(
            "SELECT verdi FROM content_blocks WHERE nokkel = 'Marked/Medlemsforslag mal'"
        ));
        return $v === '' ? self::STANDARD_MAL : $v;
    }

    /** Full sti til fila, eller null hvis navnet ikke er et av vaare. */
    public static function sti(string $fil): ?string
    {
        if (preg_match('/^[0-9a-f]{32}\.(jpg|mp4)$/', $fil) !== 1) {
            return null;
        }
        $sti = Bilder::mappe(self::MAPPE) . '/' . $fil;
        return is_file($sti) ? $sti : null;
    }

    public static function slettFil(string $fil): void
    {
        $sti = self::sti($fil);
        if ($sti !== null) {
            @unlink($sti);
        }
    }

    /**
     * Tar imot bildet, og beskjaerer det til noe Instagram godtar.
     *
     * @throws RuntimeException med tekst som kan vises til medlemmet
     */
    public static function taImotBilde(array $fil): string
    {
        $navn = Bilder::taImot($fil, self::MAPPE);
        $sti = Bilder::mappe(self::MAPPE) . '/' . $navn;
        self::tilInstagramForhold($sti);
        return $navn;
    }

    /** Beskjaerer fra midten til mellom 4:5 og 1,91:1. Roerer ikke et bilde som passer. */
    private static function tilInstagramForhold(string $sti): void
    {
        $info = @getimagesize($sti);
        if ($info === false) {
            return;
        }
        [$b, $h] = $info;
        if ($b <= 0 || $h <= 0) {
            return;
        }
        $forhold = $b / $h;
        if ($forhold >= 0.8 && $forhold <= 1.91) {
            return;
        }
        $nyB = $b;
        $nyH = $h;
        if ($forhold < 0.8) {
            $nyH = (int) floor($b / 0.8);
        } else {
            $nyB = (int) floor($h * 1.91);
        }
        $kilde = @imagecreatefromjpeg($sti);
        if ($kilde === false) {
            return;
        }
        $ny = imagecreatetruecolor($nyB, $nyH);
        imagecopy($ny, $kilde, 0, 0, intdiv($b - $nyB, 2), intdiv($h - $nyH, 2), $nyB, $nyH);
        imagejpeg($ny, $sti, 85);
        imagedestroy($kilde);
        imagedestroy($ny);
    }

    /**
     * Tar imot en video (MP4 eller MOV fra telefonen) og sjekker lengden.
     *
     * Lengden leses fra fila selv (mvhd-boksen), ikke fra det nettleseren
     * sa: nettleseren sjekker ogsaa, men den kan man gaa rundt.
     *
     * @throws RuntimeException med tekst som kan vises til medlemmet
     */
    public static function taImotVideo(array $fil): string
    {
        if (!isset($fil['error']) || $fil['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($fil['error'] ?? -1) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Videoen er for stor.',
                UPLOAD_ERR_NO_FILE => 'Du må velge en video.',
                default => 'Videoen kom ikke fram. Prøv igjen.',
            });
        }
        if (($fil['size'] ?? 0) > self::MAKS_VIDEO_BYTES) {
            throw new RuntimeException('Videoen er for stor. Maks 100 MB.');
        }
        $tmp = (string) ($fil['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Videoen kom ikke fram. Prøv igjen.');
        }

        $hode = (string) file_get_contents($tmp, false, null, 0, 12);
        if (strlen($hode) < 12 || substr($hode, 4, 4) !== 'ftyp') {
            throw new RuntimeException('Videoen må være MP4 eller MOV.');
        }

        $sek = self::varighet($tmp);
        if ($sek === null) {
            throw new RuntimeException('Fikk ikke lest hvor lang videoen er. Prøv en annen fil.');
        }
        if ($sek > self::MAKS_SEKUNDER) {
            throw new RuntimeException('Videoen er lengre enn 15 sekunder.');
        }
        if ($sek < self::MIN_SEKUNDER) {
            throw new RuntimeException('Videoen må være minst 3 sekunder.');
        }

        // Alltid «.mp4» i navnet, ogsaa for en MOV: Meta skiller video fra
        // bilde paa adressen (Meta::publiserInstagram), og Instagram tar imot
        // begge beholderne.
        $navn = bin2hex(random_bytes(16)) . '.mp4';
        $maal = Bilder::mappe(self::MAPPE) . '/' . $navn;
        if (!move_uploaded_file($tmp, $maal)) {
            throw new RuntimeException('Videoen kom ikke fram. Prøv igjen.');
        }
        @chmod($maal, 0644);
        return $navn;
    }

    /**
     * Lengden i sekunder, lest fra mvhd-boksen i en MP4/MOV. Null hvis den
     * ikke finnes.
     */
    public static function varighet(string $sti): ?float
    {
        $f = @fopen($sti, 'rb');
        if ($f === false) {
            return null;
        }
        try {
            return self::finnMvhd($f, 0, (int) filesize($sti), 0);
        } finally {
            fclose($f);
        }
    }

    /** @param resource $f */
    private static function finnMvhd($f, int $fra, int $til, int $dybde): ?float
    {
        if ($dybde > 4) {
            return null;
        }
        $pos = $fra;
        while ($pos + 8 <= $til) {
            fseek($f, $pos);
            $hode = fread($f, 8);
            if ($hode === false || strlen($hode) < 8) {
                return null;
            }
            $storrelse = unpack('N', substr($hode, 0, 4))[1];
            $type = substr($hode, 4, 4);
            $hodeLengde = 8;
            if ($storrelse === 1) {
                $stor = fread($f, 8);
                if ($stor === false || strlen($stor) < 8) {
                    return null;
                }
                $d = unpack('Nhoy/Nlav', $stor);
                $storrelse = $d['hoy'] * 4294967296 + $d['lav'];
                $hodeLengde = 16;
            } elseif ($storrelse === 0) {
                $storrelse = $til - $pos;
            }
            if ($storrelse < $hodeLengde) {
                return null;
            }

            if ($type === 'moov') {
                return self::finnMvhd($f, $pos + $hodeLengde, $pos + $storrelse, $dybde + 1);
            }
            if ($type === 'mvhd') {
                fseek($f, $pos + $hodeLengde);
                $versjon = ord((string) fread($f, 1));
                fread($f, 3);
                if ($versjon === 1) {
                    fread($f, 16);
                    $d = unpack('Nskala/Nhoy/Nlav', (string) fread($f, 12));
                    $lengde = $d['hoy'] * 4294967296 + $d['lav'];
                } else {
                    fread($f, 8);
                    $d = unpack('Nskala/Nlengde', (string) fread($f, 8));
                    $lengde = $d['lengde'];
                }
                return $d['skala'] > 0 ? $lengde / $d['skala'] : null;
            }
            $pos += $storrelse;
        }
        return null;
    }

    /**
     * Hashtaggene slik de skal staa: med #, uten dubletter, #lissomkeramikk
     * alltid med. Instagram tar 30; vi stopper paa 20.
     */
    public static function hashtags(string $raa): string
    {
        $ut = [];
        foreach (preg_split('/[\s,;]+/u', $raa) ?: [] as $ord) {
            $ord = ltrim(trim($ord), '#');
            $ord = (string) preg_replace('/[^\p{L}\p{N}_]/u', '', $ord);
            if ($ord === '') {
                continue;
            }
            $ut[mb_strtolower($ord)] = '#' . $ord;
        }
        $fast = ltrim(self::FAST_TAGG, '#');
        unset($ut[$fast]);
        return implode(' ', array_slice(array_merge([self::FAST_TAGG], array_values($ut)), 0, 20));
    }

    /** «@kari_leire» — eller tomt hvis det ikke ser ut som et brukernavn. */
    public static function brukernavn(string $raa): string
    {
        $n = ltrim(trim($raa), '@');
        return preg_match('/^[A-Za-z0-9._]{1,30}$/', $n) === 1 ? '@' . $n : '';
    }

    /**
     * Teksten som legges ut: medlemmets tekst, den faste linja, hashtaggene.
     *
     * @param array<string,mixed> $forslag rad fra medlemsforslag
     */
    public static function bildetekst(array $forslag, string $fornavn): string
    {
        $medlem = (string) ($forslag['instagram'] ?? '') !== ''
            ? (string) $forslag['instagram']
            : ($fornavn !== '' ? $fornavn : 'et medlem');
        $linje = str_replace('{medlem}', $medlem, self::mal());
        return trim((string) ($forslag['tekst'] ?? '')) . "\n\n" . $linje . "\n\n"
             . self::hashtags((string) ($forslag['hashtags'] ?? ''));
    }

    /** Fornavnet, som i resten av huset: foerste ord i navnet. */
    public static function fornavn(string $navn): string
    {
        $n = trim($navn);
        return $n === '' ? '' : (string) preg_split('/\s+/u', $n)[0];
    }
}

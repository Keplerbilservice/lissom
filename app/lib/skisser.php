<?php
/**
 * Skisser: tavler der verkstedet, medlemmer og kursdeltakere kan tegne med
 * fingeren eller pennen, legge inn bilder og skrive notater.
 *
 * Eieren, 30. september 2026: «jeg vil ha et skisseprogram, dette skal være
 * tilgjengelig i admin, men admin må også kunne velge å vise det, da må
 * bryter ligge på synlighet … man kan også laste opp bilder her, tegne med
 * fingeren og ta notater, poenget er en start på et nytt design». Valgt
 * samme dag: medlemmer og deltakere kan tegne selv, egne tavler ser bare de
 * selv og admin, og admin kan dele sine tavler.
 *
 * Den første modulen etter «moduler nå»: egen side (skisser.html), eget API
 * (api/skisser.php, api/admin/skisser.php), egne tabeller (migrasjon 236) og
 * egne brytere. Ingenting her brukes av resten av systemet, så går det i
 * stykker, gjør ikke resten det.
 *
 * Tre brytere i content_blocks, som de andre under ⊙ Synlighet:
 *
 *   Vis/skisser             hele modulen. Mangler raden, er den på.
 *   Vis/skissermedlemmer    medlemmene får skisser på Min side. Av fra start.
 *   Vis/skisserdeltakere    kursdeltakerne får det samme. Av fra start.
 *
 * Av betyr at serveren også stopper — ikke bare at knappen er borte.
 */

declare(strict_types=1);

final class Skisser
{
    /** Så mange versjoner per side ligger igjen. Eldre slettes. */
    public const VERSJONER = 20;

    /** Så stor kan én side være, i tegn JSON. Bilder ligger ikke i den. */
    public const MAKS_SIDE = 2_000_000;

    /** Så mange tavler kan ett medlem ha. Admin har ingen grense. */
    public const MAKS_TAVLER = 30;

    /** Så mange sider på én tavle. */
    public const MAKS_SIDER = 20;

    /** Mappen bildene legges i, under Bilder::mappe(). */
    public const MAPPE = 'skisser';

    public static function klar(): bool
    {
        return DB::harTabell('skisser') && DB::harTabell('skisse_sider');
    }

    private static function bryter(string $nokkel): ?string
    {
        $v = DB::verdi('SELECT verdi FROM content_blocks WHERE nokkel = :n', ['n' => 'Vis/' . $nokkel]);
        return $v === null || $v === false ? null : (string) $v;
    }

    /** Hele modulen. Mangler raden, er den på. */
    public static function modulPaa(): bool
    {
        return self::klar() && self::bryter('skisser') !== 'nei';
    }

    /** Medlemmene. Av fra start (migrasjonen skriver «nei»); bare «ja» er på. */
    public static function forMedlemmer(): bool
    {
        return self::modulPaa() && self::bryter('skissermedlemmer') === 'ja';
    }

    /** Kursdeltakerne. Av fra start; bare «ja» er på. */
    public static function forDeltakere(): bool
    {
        return self::modulPaa() && self::bryter('skisserdeltakere') === 'ja';
    }

    /** @param array<string,mixed> $m */
    public static function erAdmin(array $m): bool
    {
        return (string) ($m['rolle'] ?? '') === 'admin';
    }

    /** @param array<string,mixed> $m */
    public static function erMedlem(array $m): bool
    {
        return in_array((string) ($m['status'] ?? ''), ['prove', 'aktiv', 'pause'], true);
    }

    /**
     * Har gått eller skal på et kurs: en påmelding som er betalt eller holdt av.
     *
     * @param array<string,mixed> $m
     */
    public static function erDeltaker(array $m): bool
    {
        return (int) DB::verdi(
            "SELECT COUNT(*) FROM bookings WHERE member_id = :m AND status IN ('betalt', 'reservert')",
            ['m' => (int) $m['id']]
        ) > 0;
    }

    /**
     * Slipper modulen inn denne personen på Min side?
     *
     * Admin er alltid innenfor så lenge modulen er på. Et medlem når inn når
     * medlemsbryteren står på, en deltaker når deltakerbryteren gjør det.
     *
     * @param array<string,mixed> $m
     */
    public static function slippInn(array $m): bool
    {
        if (!self::modulPaa()) {
            return false;
        }
        if (self::erAdmin($m)) {
            return true;
        }
        return (self::forMedlemmer() && self::erMedlem($m))
            || (self::forDeltakere() && self::erDeltaker($m));
    }

    /**
     * Tavlene denne personen ser: sine egne, og det admin har delt med en
     * gruppe hen er med i. Admin ser alle.
     *
     * @param array<string,mixed> $m
     * @return list<array<string,mixed>>
     */
    public static function liste(array $m): array
    {
        $sql = 'SELECT s.*, mb.navn AS eier_navn,
                       (SELECT COUNT(*) FROM skisse_sider sd WHERE sd.skisse_id = s.id) AS sider
                  FROM skisser s
             LEFT JOIN members mb ON mb.id = s.eier_id';
        if (self::erAdmin($m)) {
            $rader = DB::alle($sql . ' ORDER BY s.updated_at DESC, s.id DESC');
        } else {
            $vilkaar = ['s.eier_id = :m'];
            if (self::forMedlemmer() && self::erMedlem($m)) {
                $vilkaar[] = 's.delt_medlemmer = 1';
            }
            if (self::forDeltakere() && self::erDeltaker($m)) {
                $vilkaar[] = 's.delt_deltakere = 1';
            }
            $rader = DB::alle($sql . ' WHERE ' . implode(' OR ', $vilkaar) . ' ORDER BY s.updated_at DESC, s.id DESC',
                ['m' => (int) $m['id']]);
        }
        return array_map(static fn(array $r): array => self::ut($r, $m), $rader);
    }

    /**
     * Én tavle, hvis denne personen får se den. Null betyr «finnes ikke for
     * deg» — vi sier ikke om den finnes for noen andre.
     *
     * @param array<string,mixed> $m
     * @return array<string,mixed>|null
     */
    public static function hent(int $id, array $m): ?array
    {
        $r = DB::en('SELECT s.*, mb.navn AS eier_navn FROM skisser s LEFT JOIN members mb ON mb.id = s.eier_id WHERE s.id = :i',
            ['i' => $id]);
        if ($r === null || !self::kanSe($r, $m)) {
            return null;
        }
        $ut = self::ut($r, $m);
        $ut['sider'] = array_map(static fn(array $s): array => [
            'id'   => (int) $s['id'],
            'nr'   => (int) $s['nr'],
            'data' => (string) $s['data'],
        ], DB::alle('SELECT id, nr, data FROM skisse_sider WHERE skisse_id = :s ORDER BY nr', ['s' => $id]));
        return $ut;
    }

    /**
     * @param array<string,mixed> $r rad fra skisser
     * @param array<string,mixed> $m
     */
    public static function kanSe(array $r, array $m): bool
    {
        if (self::erAdmin($m)) {
            return true;
        }
        if ((int) ($r['eier_id'] ?? 0) === (int) $m['id']) {
            return true;
        }
        return ((int) $r['delt_medlemmer'] === 1 && self::forMedlemmer() && self::erMedlem($m))
            || ((int) $r['delt_deltakere'] === 1 && self::forDeltakere() && self::erDeltaker($m));
    }

    /**
     * Bare eieren og admin endrer. En delt tavle er til å se på.
     *
     * @param array<string,mixed> $r
     * @param array<string,mixed> $m
     */
    public static function kanEndre(array $r, array $m): bool
    {
        return self::erAdmin($m) || (int) ($r['eier_id'] ?? 0) === (int) $m['id'];
    }

    /**
     * @param array<string,mixed> $r
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    private static function ut(array $r, array $m): array
    {
        return [
            'id'            => (int) $r['id'],
            'tittel'        => (string) $r['tittel'],
            'eierId'        => $r['eier_id'] === null ? null : (int) $r['eier_id'],
            'eierNavn'      => (string) ($r['eier_navn'] ?? ''),
            'egen'          => (int) ($r['eier_id'] ?? 0) === (int) $m['id'],
            'deltMedlemmer' => (int) $r['delt_medlemmer'] === 1,
            'deltDeltakere' => (int) $r['delt_deltakere'] === 1,
            'kanEndre'      => self::kanEndre($r, $m),
            'antallSider'   => (int) ($r['sider'] ?? 0),
            'endret'        => (string) $r['updated_at'],
        ];
    }

    /**
     * Ny tavle med én tom side.
     *
     * @param array<string,mixed> $m
     */
    public static function ny(array $m, string $tittel): int
    {
        $tittel = self::renTittel($tittel);
        if (!self::erAdmin($m)) {
            $har = (int) DB::verdi('SELECT COUNT(*) FROM skisser WHERE eier_id = :m', ['m' => (int) $m['id']]);
            if ($har >= self::MAKS_TAVLER) {
                throw new RuntimeException('Du har ' . $har . ' tavler alt. Slett en før du lager en ny.');
            }
        }
        return DB::iTransaksjon(static function () use ($m, $tittel): int {
            $id = DB::settInn('skisser', ['eier_id' => (int) $m['id'], 'tittel' => $tittel]);
            DB::settInn('skisse_sider', ['skisse_id' => $id, 'nr' => 1, 'data' => '{}']);
            return $id;
        });
    }

    private static function renTittel(string $t): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        return $t === '' ? 'Ny tavle' : mb_substr($t, 0, 120);
    }

    /**
     * Lagrer én side. Forrige innhold legges i versjonene først.
     *
     * @param array<string,mixed> $m
     */
    public static function lagreSide(int $skisseId, int $nr, string $data, array $m): void
    {
        $r = DB::en('SELECT * FROM skisser WHERE id = :i', ['i' => $skisseId]);
        if ($r === null || !self::kanSe($r, $m)) {
            throw new DomainException('Fant ikke tavla.');
        }
        if (!self::kanEndre($r, $m)) {
            throw new DomainException('Denne tavla kan du se på, men ikke endre.');
        }
        if (strlen($data) > self::MAKS_SIDE) {
            throw new RuntimeException('Siden er for stor til å lagres. Del den på flere sider.');
        }
        if ($nr < 1 || $nr > self::MAKS_SIDER) {
            throw new RuntimeException('En tavle kan ha opptil ' . self::MAKS_SIDER . ' sider.');
        }
        json_decode($data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Siden kunne ikke leses.');
        }

        DB::iTransaksjon(static function () use ($skisseId, $nr, $data, $m): void {
            $side = DB::en('SELECT id, data FROM skisse_sider WHERE skisse_id = :s AND nr = :n', ['s' => $skisseId, 'n' => $nr]);
            if ($side === null) {
                DB::settInn('skisse_sider', ['skisse_id' => $skisseId, 'nr' => $nr, 'data' => $data]);
            } elseif ((string) $side['data'] !== $data) {
                if (DB::harTabell('skisse_versjoner') && (string) $side['data'] !== '{}') {
                    DB::settInn('skisse_versjoner', [
                        'side_id' => (int) $side['id'], 'data' => (string) $side['data'], 'lagret_av' => (int) $m['id'],
                    ]);
                    // Bare de siste versjonene blir liggende.
                    $grense = DB::verdi(
                        'SELECT id FROM skisse_versjoner WHERE side_id = :s ORDER BY id DESC LIMIT 1 OFFSET ' . self::VERSJONER,
                        ['s' => (int) $side['id']]
                    );
                    if ($grense !== null && $grense !== false) {
                        DB::kjor('DELETE FROM skisse_versjoner WHERE side_id = :s AND id <= :g',
                            ['s' => (int) $side['id'], 'g' => (int) $grense]);
                    }
                }
                DB::kjor('UPDATE skisse_sider SET data = :d WHERE id = :i', ['d' => $data, 'i' => (int) $side['id']]);
            }
            DB::kjor('UPDATE skisser SET updated_at = CURRENT_TIMESTAMP WHERE id = :i', ['i' => $skisseId]);
        });
    }

    /**
     * Versjonene av én side, nyeste først.
     *
     * @param array<string,mixed> $m
     * @return list<array<string,mixed>>
     */
    public static function versjoner(int $skisseId, int $nr, array $m): array
    {
        $r = DB::en('SELECT * FROM skisser WHERE id = :i', ['i' => $skisseId]);
        if ($r === null || !self::kanEndre($r, $m) || !DB::harTabell('skisse_versjoner')) {
            return [];
        }
        return array_map(static fn(array $v): array => [
            'id' => (int) $v['id'], 'naar' => (string) $v['created_at'], 'data' => (string) $v['data'],
        ], DB::alle(
            'SELECT v.id, v.created_at, v.data FROM skisse_versjoner v
               JOIN skisse_sider sd ON sd.id = v.side_id
              WHERE sd.skisse_id = :s AND sd.nr = :n ORDER BY v.id DESC',
            ['s' => $skisseId, 'n' => $nr]
        ));
    }

    /** @param array<string,mixed> $m */
    public static function slettSide(int $skisseId, int $nr, array $m): void
    {
        $r = self::endrebar($skisseId, $m);
        $antall = (int) DB::verdi('SELECT COUNT(*) FROM skisse_sider WHERE skisse_id = :s', ['s' => $skisseId]);
        if ($antall <= 1) {
            throw new RuntimeException('En tavle må ha minst én side.');
        }
        DB::iTransaksjon(static function () use ($skisseId, $nr): void {
            $id = DB::verdi('SELECT id FROM skisse_sider WHERE skisse_id = :s AND nr = :n', ['s' => $skisseId, 'n' => $nr]);
            if ($id === null || $id === false) {
                return;
            }
            if (DB::harTabell('skisse_versjoner')) {
                DB::kjor('DELETE FROM skisse_versjoner WHERE side_id = :i', ['i' => (int) $id]);
            }
            DB::kjor('DELETE FROM skisse_sider WHERE id = :i', ['i' => (int) $id]);
            // Sidene etter rykker opp, så nummereringen har ingen hull.
            DB::kjor('UPDATE skisse_sider SET nr = nr - 1 WHERE skisse_id = :s AND nr > :n ORDER BY nr', ['s' => $skisseId, 'n' => $nr]);
        });
        unset($r);
    }

    /**
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    private static function endrebar(int $skisseId, array $m): array
    {
        $r = DB::en('SELECT * FROM skisser WHERE id = :i', ['i' => $skisseId]);
        if ($r === null || !self::kanSe($r, $m)) {
            throw new DomainException('Fant ikke tavla.');
        }
        if (!self::kanEndre($r, $m)) {
            throw new DomainException('Denne tavla kan du se på, men ikke endre.');
        }
        return $r;
    }

    /** @param array<string,mixed> $m */
    public static function giNavn(int $skisseId, string $tittel, array $m): void
    {
        self::endrebar($skisseId, $m);
        DB::kjor('UPDATE skisser SET tittel = :t WHERE id = :i', ['t' => self::renTittel($tittel), 'i' => $skisseId]);
    }

    /**
     * Deling. Bare admin, og bare på tavler admin eier: et medlems egen
     * tavle er privat, også for admin å dele videre.
     *
     * @param array<string,mixed> $m
     */
    public static function del(int $skisseId, bool $medlemmer, bool $deltakere, array $m): void
    {
        if (!self::erAdmin($m)) {
            throw new DomainException('Bare verkstedet kan dele tavler.');
        }
        $r = DB::en('SELECT * FROM skisser WHERE id = :i', ['i' => $skisseId]);
        if ($r === null) {
            throw new DomainException('Fant ikke tavla.');
        }
        $eier = $r['eier_id'] === null ? null : DB::en('SELECT rolle FROM members WHERE id = :i', ['i' => (int) $r['eier_id']]);
        if ($eier !== null && (string) $eier['rolle'] !== 'admin') {
            throw new DomainException('Tavla er medlemmets egen, og kan ikke deles.');
        }
        DB::kjor('UPDATE skisser SET delt_medlemmer = :a, delt_deltakere = :b WHERE id = :i',
            ['a' => $medlemmer ? 1 : 0, 'b' => $deltakere ? 1 : 0, 'i' => $skisseId]);
    }

    /** @param array<string,mixed> $m */
    public static function slett(int $skisseId, array $m): void
    {
        self::endrebar($skisseId, $m);
        $filer = DB::harTabell('skisse_bilder')
            ? array_map(static fn($r) => (string) $r['fil'], DB::alle('SELECT fil FROM skisse_bilder WHERE skisse_id = :s', ['s' => $skisseId]))
            : [];
        DB::iTransaksjon(static function () use ($skisseId): void {
            if (DB::harTabell('skisse_versjoner')) {
                DB::kjor('DELETE v FROM skisse_versjoner v JOIN skisse_sider sd ON sd.id = v.side_id WHERE sd.skisse_id = :s', ['s' => $skisseId]);
            }
            DB::kjor('DELETE FROM skisse_sider WHERE skisse_id = :s', ['s' => $skisseId]);
            if (DB::harTabell('skisse_bilder')) {
                DB::kjor('DELETE FROM skisse_bilder WHERE skisse_id = :s', ['s' => $skisseId]);
            }
            DB::kjor('DELETE FROM skisser WHERE id = :s', ['s' => $skisseId]);
        });
        foreach ($filer as $f) {
            Bilder::slett($f, self::MAPPE);
        }
    }

    /**
     * Et bilde som er tatt imot med Bilder::taImot(), knyttes til tavla.
     *
     * @param array<string,mixed> $m
     */
    public static function leggTilBilde(int $skisseId, string $fil, array $m): string
    {
        self::endrebar($skisseId, $m);
        DB::settInn('skisse_bilder', ['skisse_id' => $skisseId, 'fil' => $fil, 'lastet_opp_av' => (int) $m['id']]);
        return 'api/skisser.php?bilde=' . rawurlencode($fil);
    }

    /**
     * Får denne personen se bildet? Samme regel som tavla det ligger på.
     *
     * @param array<string,mixed>|null $m
     */
    public static function kanSeBilde(string $fil, ?array $m): bool
    {
        if ($m === null || !DB::harTabell('skisse_bilder')) {
            return false;
        }
        $r = DB::en('SELECT s.* FROM skisse_bilder b JOIN skisser s ON s.id = b.skisse_id WHERE b.fil = :f', ['f' => $fil]);
        return $r !== null && self::kanSe($r, $m);
    }
}

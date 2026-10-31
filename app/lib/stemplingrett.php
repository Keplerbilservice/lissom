<?php
/** Forslag og revisjon av verkstedøkter. Alle tidspunkter lagres i UTC. */
declare(strict_types=1);

final class StemplingRett
{
    private static function oslo(): DateTimeZone { return new DateTimeZone('Europe/Oslo'); }
    private static function utc(): DateTimeZone { return new DateTimeZone('UTC'); }

    private static function tidspunkt(string $dato, string $klokke): DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dato)
            || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $klokke)) {
            throw new InvalidArgumentException('Skriv dato og klokkeslett som ÅÅÅÅ-MM-DD og TT:MM.');
        }
        $tekst = $dato . ' ' . $klokke;
        $t = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $tekst, self::oslo());
        if ($t === false || $t->format('Y-m-d H:i') !== $tekst) {
            throw new InvalidArgumentException('Datoen eller klokkeslettet finnes ikke.');
        }
        return $t->setTimezone(self::utc());
    }

    /** @return array{inn:string,ut:string,minutter:int} */
    public static function beregn(string $dato, string $inn, string $ut): array
    {
        $fra = self::tidspunkt($dato, $inn);
        $til = self::tidspunkt($dato, $ut);
        if ($til < $fra && (int) substr($inn, 0, 2) >= 20 && (int) substr($ut, 0, 2) <= 6) {
            $neste = $fra->setTimezone(self::oslo())->modify('+1 day')->format('Y-m-d');
            $til = self::tidspunkt($neste, $ut);
        }
        if ($til <= $fra || $til->getTimestamp() - $fra->getTimestamp() > 12 * 3600) {
            throw new InvalidArgumentException('Utstemplingen må være etter innstemplingen. Økter over midnatt må starte etter kl. 20.');
        }
        if ($til->getTimestamp() > time() + 60) {
            throw new InvalidArgumentException('Tidspunktet kan ikke være i framtiden.');
        }
        return [
            'inn' => $fra->format('Y-m-d H:i:s'),
            'ut' => $til->format('Y-m-d H:i:s'),
            'minutter' => min(360, max(0, (int) round(($til->getTimestamp() - $fra->getTimestamp()) / 60))),
        ];
    }

    private static function lokal(?string $utc): ?string
    {
        return $utc === null ? null : (new DateTimeImmutable($utc, self::utc()))
            ->setTimezone(self::oslo())->format('Y-m-d H:i');
    }

    /** Økter og forslag for ett medlem. Ingen annen persons data eksponeres fra medlems-API. */
    public static function oversikt(int $medlemId, int $valgtSakId = 0, int $valgtOktId = 0): array
    {
        $oktRader = DB::alle('SELECT id, inn_tid, ut_tid, minutter, auto_lukket FROM check_ins
                     WHERE member_id = :m ORDER BY inn_tid DESC, id DESC LIMIT 40', ['m' => $medlemId]);
        $sakRader = DB::alle('SELECT r.id, r.check_in_id, r.foreslatt_inn, r.foreslatt_ut, r.forklaring,
                            r.status, r.admin_notat, r.created_at,
                            c.inn_tid AS faktisk_inn, c.ut_tid AS faktisk_ut
                       FROM stempling_rettinger r LEFT JOIN check_ins c ON c.id = r.check_in_id
                      WHERE r.member_id = :m ORDER BY r.id DESC LIMIT 30', ['m' => $medlemId]);
        if ($valgtSakId > 0 && !array_filter($sakRader, static fn(array $r): bool => (int) $r['id'] === $valgtSakId)) {
            $valgt = DB::en('SELECT r.id, r.check_in_id, r.foreslatt_inn, r.foreslatt_ut, r.forklaring,
                                   r.status, r.admin_notat, r.created_at,
                                   c.inn_tid AS faktisk_inn, c.ut_tid AS faktisk_ut
                              FROM stempling_rettinger r LEFT JOIN check_ins c ON c.id = r.check_in_id
                             WHERE r.member_id = :m AND r.id = :s', ['m' => $medlemId, 's' => $valgtSakId]);
            if ($valgt !== null) { $sakRader[] = $valgt; }
        }
        foreach ($sakRader as $r) {
            if ((int) $r['id'] === $valgtSakId) { $valgtOktId = (int) ($r['check_in_id'] ?? 0); break; }
        }
        if ($valgtOktId > 0 && !array_filter($oktRader, static fn(array $r): bool => (int) $r['id'] === $valgtOktId)) {
            $valgt = DB::en('SELECT id, inn_tid, ut_tid, minutter, auto_lukket FROM check_ins
                              WHERE member_id = :m AND id = :i', ['m' => $medlemId, 'i' => $valgtOktId]);
            if ($valgt !== null) { $oktRader[] = $valgt; }
        }
        $okter = array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'inn' => self::lokal((string) $r['inn_tid']),
            'ut' => self::lokal($r['ut_tid'] === null ? null : (string) $r['ut_tid']),
            'minutter' => $r['minutter'] === null ? null : (int) $r['minutter'],
            'auto' => (bool) $r['auto_lukket'],
        ], $oktRader);
        $saker = array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'oktId' => $r['check_in_id'] === null ? null : (int) $r['check_in_id'],
            'inn' => self::lokal((string) $r['foreslatt_inn']),
            'ut' => self::lokal((string) $r['foreslatt_ut']),
            'forklaring' => (string) $r['forklaring'], 'status' => (string) $r['status'],
            'adminNotat' => (string) ($r['admin_notat'] ?? ''),
            'endeligInn' => self::lokal($r['faktisk_inn'] === null ? null : (string) $r['faktisk_inn']),
            'endeligUt' => self::lokal($r['faktisk_ut'] === null ? null : (string) $r['faktisk_ut']),
            'opprettet' => self::lokal((string) $r['created_at']),
        ], $sakRader);
        return ['okter' => $okter, 'saker' => $saker];
    }

    public static function foresla(int $medlemId, int $oktId, string $dato, string $inn, string $ut, string $forklaring, int $sakId = 0): int
    {
        $forklaring = trim($forklaring);
        if (mb_strlen($forklaring) < 5 || mb_strlen($forklaring) > 1000) {
            throw new InvalidArgumentException('Beskriv kort hva som ble feil (5–1000 tegn).');
        }
        $tid = self::beregn($dato, $inn, $ut);
        return DB::iTransaksjon(static function () use ($medlemId, $oktId, $tid, $forklaring, $sakId): int {
            if ($sakId > 0) {
                $sak = DB::en('SELECT id, check_in_id, status FROM stempling_rettinger
                               WHERE id = :s AND member_id = :m FOR UPDATE', ['s' => $sakId, 'm' => $medlemId]);
                if ($sak === null || $sak['status'] !== 'trenger_info'
                    || (int) ($sak['check_in_id'] ?? 0) !== $oktId) {
                    throw new InvalidArgumentException('Saken kan ikke oppdateres. Last siden på nytt.');
                }
                DB::oppdater('stempling_rettinger', ['foreslatt_inn' => $tid['inn'],
                    'foreslatt_ut' => $tid['ut'], 'forklaring' => $forklaring, 'status' => 'apen'], ['id' => $sakId]);
                revider('stempling_forslag_supplert', 'stempling_retting', $sakId);
                return $sakId;
            }
            if ($oktId > 0) {
                $okt = DB::en('SELECT id, ut_tid FROM check_ins WHERE id = :i AND member_id = :m FOR UPDATE',
                    ['i' => $oktId, 'm' => $medlemId]);
                if ($okt === null || $okt['ut_tid'] === null) {
                    throw new InvalidArgumentException('Velg en avsluttet økt som tilhører deg.');
                }
                if (DB::en("SELECT id FROM stempling_rettinger WHERE check_in_id = :i
                              AND status IN ('apen','trenger_info') LIMIT 1 FOR UPDATE", ['i' => $oktId])) {
                    throw new InvalidArgumentException('Det finnes allerede en åpen sak for denne økten.');
                }
            }
            $id = DB::settInn('stempling_rettinger', [
                'member_id' => $medlemId, 'check_in_id' => $oktId ?: null,
                'foreslatt_inn' => $tid['inn'], 'foreslatt_ut' => $tid['ut'],
                'forklaring' => $forklaring,
            ]);
            revider('stempling_forslag', 'stempling_retting', $id, ['okt' => $oktId ?: null]);
            return $id;
        });
    }

    /** Admin kan rette en valgt, lukket økt eller legge inn en manglende økt. */
    public static function rett(int $medlemId, int $oktId, string $dato, string $inn, string $ut,
                                string $grunn, int $adminId, int $sakId = 0): array
    {
        $grunn = trim($grunn);
        // Begrunnelse er valgfri ved retting (eieren 31.10.2026).
        if (mb_strlen($grunn) > 1000) {
            throw new InvalidArgumentException('Begrunnelsen kan være høyst 1000 tegn.');
        }
        $tid = self::beregn($dato, $inn, $ut);
        return DB::iTransaksjon(static function () use ($medlemId, $oktId, $tid, $grunn, $adminId, $sakId): array {
            if (!DB::en('SELECT id FROM members WHERE id = :m FOR UPDATE', ['m' => $medlemId])) {
                throw new InvalidArgumentException('Fant ikke medlemmet.');
            }
            $sak = null;
            if ($sakId > 0) {
                $sak = DB::en('SELECT * FROM stempling_rettinger WHERE id = :s AND member_id = :m FOR UPDATE',
                    ['s' => $sakId, 'm' => $medlemId]);
                if ($sak === null || !in_array($sak['status'], ['apen', 'trenger_info'], true)
                    || (int) ($sak['check_in_id'] ?? 0) !== $oktId) {
                    throw new InvalidArgumentException('Saken er endret eller tilhører en annen økt.');
                }
            }
            $gammel = null;
            if ($oktId > 0) {
                $gammel = DB::en('SELECT id, inn_tid, ut_tid, minutter FROM check_ins
                                  WHERE id = :i AND member_id = :m FOR UPDATE', ['i' => $oktId, 'm' => $medlemId]);
                if ($gammel === null || $gammel['ut_tid'] === null) {
                    throw new InvalidArgumentException('Økten finnes ikke eller er fortsatt åpen.');
                }
            }
            $overlapp = DB::en('SELECT id FROM check_ins WHERE member_id = :m AND id <> :i
                                 AND inn_tid < :ut AND (ut_tid IS NULL OR ut_tid > :inn)
                                 LIMIT 1 FOR UPDATE',
                ['m' => $medlemId, 'i' => $oktId, 'inn' => $tid['inn'], 'ut' => $tid['ut']]);
            if ($overlapp !== null) {
                throw new InvalidArgumentException('Tiden overlapper en annen økt.');
            }
            if ($oktId > 0) {
                DB::oppdater('check_ins', ['inn_tid' => $tid['inn'], 'ut_tid' => $tid['ut'],
                    'minutter' => $tid['minutter'], 'auto_lukket' => 0], ['id' => $oktId]);
                $id = $oktId;
            } else {
                $id = DB::settInn('check_ins', ['member_id' => $medlemId, 'inn_tid' => $tid['inn'],
                    'ut_tid' => $tid['ut'], 'minutter' => $tid['minutter'], 'auto_lukket' => 0]);
            }
            if ($sak !== null) {
                DB::oppdater('stempling_rettinger', ['status' => 'rettet', 'check_in_id' => $id,
                    'admin_notat' => $grunn, 'behandlet_av' => $adminId,
                    'behandlet_at' => gmdate('Y-m-d H:i:s')], ['id' => $sakId]);
            }
            revider('stempling_admin_rettet', 'check_in', $id, [
                'medlem' => $medlemId, 'for' => $gammel, 'etter' => $tid,
                'grunn' => $grunn, 'sak' => $sakId ?: null, 'av' => $adminId,
            ]);
            return ['id' => $id, 'minutter' => $tid['minutter']];
        });
    }

    public static function avgjor(int $sakId, string $status, string $notat, int $adminId): void
    {
        if (!in_array($status, ['trenger_info', 'avslaatt'], true) || mb_strlen(trim($notat)) < 5) {
            throw new InvalidArgumentException('Skriv en begrunnelse på minst fem tegn.');
        }
        DB::iTransaksjon(static function () use ($sakId, $status, $notat, $adminId): void {
            $sak = DB::en('SELECT id, status FROM stempling_rettinger WHERE id = :s FOR UPDATE', ['s' => $sakId]);
            if ($sak === null || !in_array($sak['status'], ['apen', 'trenger_info'], true)) {
                throw new InvalidArgumentException('Saken er allerede behandlet.');
            }
            DB::oppdater('stempling_rettinger', ['status' => $status, 'admin_notat' => trim($notat),
                'behandlet_av' => $adminId, 'behandlet_at' => gmdate('Y-m-d H:i:s')], ['id' => $sakId]);
            revider('stempling_sak_' . $status, 'stempling_retting', $sakId, ['av' => $adminId]);
        });
    }
}

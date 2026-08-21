<?php
/**
 * Databasetilkobling. Én PDO-instans per forespørsel.
 */

declare(strict_types=1);

final class DB
{
    private static ?PDO $pdo = null;

    public static function kobling(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $vert = Config::hent('db_vert', 'localhost');
        $navn = Config::krev('db_navn');
        $dsn  = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $vert, $navn);

        self::$pdo = new PDO($dsn, Config::krev('db_bruker'), Config::krev('db_passord'), [
            // Ekte forberedte spørringer, ikke emulerte. Uten dette sender PDO
            // sammensatt SQL til serveren, og heltall kommer tilbake som tekst.
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        // Databasen snakker UTC med oss. Norsk tid regnes ut ved visning.
        self::$pdo->exec("SET time_zone = '+00:00'");

        return self::$pdo;
    }

    /** @param array<string|int,mixed> $param */
    public static function kjor(string $sql, array $param = []): PDOStatement
    {
        $st = self::kobling()->prepare($sql);
        $st->execute($param);
        return $st;
    }

    /**
     * @param array<string|int,mixed> $param
     * @return array<string,mixed>|null
     */
    public static function en(string $sql, array $param = []): ?array
    {
        $rad = self::kjor($sql, $param)->fetch();
        return $rad === false ? null : $rad;
    }

    /**
     * @param array<string|int,mixed> $param
     * @return list<array<string,mixed>>
     */
    public static function alle(string $sql, array $param = []): array
    {
        return self::kjor($sql, $param)->fetchAll();
    }

    /** @param array<string|int,mixed> $param */
    public static function verdi(string $sql, array $param = []): mixed
    {
        $v = self::kjor($sql, $param)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string,mixed> $data */
    public static function settInn(string $tabell, array $data): int
    {
        $kolonner = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $tabell,
            implode(', ', array_map(static fn($k) => "`{$k}`", $kolonner)),
            implode(', ', array_map(static fn($k) => ":{$k}", $kolonner))
        );
        self::kjor($sql, $data);
        return (int) self::kobling()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $hvor
     */
    public static function oppdater(string $tabell, array $data, array $hvor): int
    {
        $sett = implode(', ', array_map(static fn($k) => "`{$k}` = :s_{$k}", array_keys($data)));
        $der  = implode(' AND ', array_map(static fn($k) => "`{$k}` = :w_{$k}", array_keys($hvor)));

        $param = [];
        foreach ($data as $k => $v) { $param["s_{$k}"] = $v; }
        foreach ($hvor as $k => $v) { $param["w_{$k}"] = $v; }

        return self::kjor("UPDATE `{$tabell}` SET {$sett} WHERE {$der}", $param)->rowCount();
    }

    /** Kjører $arbeid inne i en transaksjon og ruller tilbake ved feil. */
    public static function iTransaksjon(callable $arbeid): mixed
    {
        $pdo = self::kobling();
        $pdo->beginTransaction();
        try {
            $resultat = $arbeid($pdo);
            $pdo->commit();
            return $resultat;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

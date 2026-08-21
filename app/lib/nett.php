<?php
/**
 * Små curl-hjelpere. Vi holder oss til det PHP har innebygd — ingen Composer,
 * ingen vendor-mappe som kan knekke når webhotellet oppgraderer noe.
 */

declare(strict_types=1);

/**
 * @param array<string,string> $felter
 * @param list<string> $headere
 * @return array{status:int,kropp:string}
 */
function http_post_form(string $url, array $felter, array $headere = []): array
{
    return http_kall($url, 'POST', http_build_query($felter), array_merge(
        ['Content-Type: application/x-www-form-urlencoded'],
        $headere
    ));
}

/**
 * @param array<string,mixed> $data
 * @param list<string> $headere
 * @return array{status:int,kropp:string,json:mixed}
 */
function http_post_json(string $url, array $data, array $headere = []): array
{
    $svar = http_kall($url, 'POST', json_encode($data, JSON_UNESCAPED_UNICODE), array_merge(
        ['Content-Type: application/json', 'Accept: application/json'],
        $headere
    ));
    $svar['json'] = json_decode($svar['kropp'], true);
    return $svar;
}

/**
 * @param list<string> $headere
 * @return array{status:int,kropp:string,json:mixed}
 */
function http_get_json(string $url, array $headere = []): array
{
    $svar = http_kall($url, 'GET', null, array_merge(['Accept: application/json'], $headere));
    $svar['json'] = json_decode($svar['kropp'], true);
    return $svar;
}

/**
 * @param list<string> $headere
 * @return array{status:int,kropp:string}
 */
function http_kall(string $url, string $metode, ?string $kropp, array $headere = [], int $tidsavbrudd = 20): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Fikk ikke opprettet curl-kall');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metode,
        CURLOPT_HTTPHEADER     => $headere,
        CURLOPT_TIMEOUT        => $tidsavbrudd,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($kropp !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $kropp);
    }

    $svar = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $feil = curl_error($ch);
    curl_close($ch);

    if ($svar === false) {
        throw new RuntimeException('Nettverksfeil mot ' . parse_url($url, PHP_URL_HOST) . ': ' . $feil);
    }

    return ['status' => $status, 'kropp' => (string) $svar];
}

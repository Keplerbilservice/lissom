<?php
/**
 * Logging. Går til feilloggen på webhotellet, som du finner i cPanel
 * under «Errors» eller i ~/logs/.
 */

declare(strict_types=1);

function logg(string $melding, array $kontekst = []): void
{
    $linje = '[lissom] ' . $melding;
    if ($kontekst !== []) {
        $linje .= ' ' . json_encode($kontekst, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    error_log($linje);
}

function logg_feil(string $melding, ?Throwable $e = null): void
{
    $linje = '[lissom] FEIL: ' . $melding;
    if ($e instanceof Throwable) {
        $linje .= ' — ' . $e::class . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine();
    }
    error_log($linje);
}

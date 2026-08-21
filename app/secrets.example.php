<?php
/**
 * MAL — kopier til app/secrets.php på serveren og fyll inn.
 *
 * app/secrets.php skal ALDRI ligge i git. Den lastes opp én gang manuelt med
 * Filbehandler eller FTP, og settes til rettighet 600 slik at bare din egen
 * konto kan lese den.
 *
 * Ligger utenfor public_html, så den kan ikke lastes ned fra nettet uansett.
 */

declare(strict_types=1);

return [
    // 'produksjon' eller 'test'. Alt annet enn 'produksjon' viser tekniske
    // feilmeldinger og slår av krav om HTTPS på cookies.
    'miljo' => 'test',

    'nettsted' => 'https://test.lissom.no',

    // Hvilke adresser som får lov til å kalle API-et.
    'tillatte_opphav' => [
        'https://lissom.no',
        'https://www.lissom.no',
        'https://test.lissom.no',
    ],

    // --- Database (fra cPanel → MySQL Database) ---------------------------
    'db_vert'    => 'localhost',
    'db_navn'    => 'BRUKER_lissom',
    'db_bruker'  => 'BRUKER_lissom',
    'db_passord' => '',

    // --- Vipps (portal.vipps.no → Utvikler) -------------------------------
    // Test:       https://apitest.vipps.no
    // Produksjon: https://api.vipps.no
    'vipps_base'          => 'https://apitest.vipps.no',
    'vipps_msn'           => '',   // Merchant Serial Number, salgsenheten
    'vipps_client_id'     => '',
    'vipps_client_secret' => '',
    'vipps_sub_key'       => '',   // Ocp-Apim-Subscription-Key (primary)

    // Må stemme nøyaktig med redirect-URI-en i Vipps-portalen.
    'vipps_redirect_uri'  => 'https://test.lissom.no/api/vipps-callback.php',

    // Hemmelighet Vipps signerer webhooks med. Settes når webhooken registreres.
    'vipps_webhook_secret' => '',

    // --- E-post (Domene.no) -----------------------------------------------
    'epost_fra'       => 'post@lissom.no',
    'epost_fra_navn'  => 'Lissom Keramikk',
    'epost_svar_til'  => 'post@lissom.no',

    // --- SMS (sveve.no) ----------------------------------------------------
    'sveve_bruker'  => '',
    'sveve_passord' => '',
    'sms_avsender'  => 'Lissom',

    // --- Nødluke -----------------------------------------------------------
    // Disse numrene får admin-tilgang uansett hva som står i databasen, slik at
    // du ikke kan låse deg selv ute. Samme nummer som du bruker i Vipps.
    'admin_telefoner' => [
        '+4700000000',
    ],

    // Nøkkel som må sendes med for at cron-jobbene skal kunne kjøres over HTTP.
    // Lag en tilfeldig streng: php -r "echo bin2hex(random_bytes(24));"
    'cron_nokkel' => '',
];

<?php
declare(strict_types=1);
// EXAMPLE ONLY. Fill privately on the test server, outside its public root.
return [
    'miljo'=>'produksjon', // Secure cookies and generic errors; DB is explicitly TEST.
    'nettsted'=>'https://test.lissom.no',
    'tillatte_opphav'=>['https://test.lissom.no'],
    'db_vert'=>'localhost','db_port'=>3306,
    'db_navn'=>'rbvapxvz_lissom_mcp_test','db_bruker'=>'rbvapxvz_mcp_test','db_passord'=>'',
    'mcp_enabled'=>false,'mcp_test_mode'=>true,'mcp_issuer'=>'https://test.lissom.no',
    'mcp_private_key'=>'','mcp_encryption_key'=>'',
    'admin_telefoner'=>[],
];

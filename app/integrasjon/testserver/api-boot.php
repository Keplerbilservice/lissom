<?php
declare(strict_types=1);
// Fixed test layout: ~/public_html/lissom-mcp-test/api/ -> ~/lissom-mcp-test-private/.
// No ancestor search and no fallback to the production application.
$testBootstrap = dirname(__DIR__,3).'/lissom-mcp-test-private/bootstrap.php';
header('X-Robots-Tag: noindex, nofollow, noarchive');
if (!is_file($testBootstrap)) {
    http_response_code(503);
    exit('Lissom – TEST er ikke satt opp.');
}
require $testBootstrap;

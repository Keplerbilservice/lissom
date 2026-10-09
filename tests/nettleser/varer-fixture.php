<?php
/** Testdata til tests/nyadmin-varer.mjs (/ny-admin › Varer, eieren 09.10.2026). seed | inspect | rydd. */
declare(strict_types=1);
require __DIR__ . '/testdatabase.php';
krev_testdatabase(dirname(__DIR__, 2));
require dirname(__DIR__, 2) . '/app/bootstrap.php';
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $tag = 'VarerTest-' . bin2hex(random_bytes(4));
    $admin = DB::settInn('members', ['navn' => $tag, 'epost' => $tag . '@e2e.lissom.test', 'rolle' => 'admin', 'status' => 'aktiv']);
    $medlem = DB::settInn('members', ['navn' => $tag . ' Kari', 'epost' => $tag . '-m@e2e.lissom.test', 'rolle' => 'medlem', 'status' => 'aktiv']);
    $token = bin2hex(random_bytes(32));
    DB::settInn('sessions', ['member_id' => $admin, 'token_hash' => hash('sha256', $token), 'maate' => 'passord', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
    $lev = (int) DB::verdi('SELECT id FROM leverandorer WHERE aktiv = 1 ORDER BY id LIMIT 1');
    $intern = DB::settInn('products', ['tittel' => $tag . ' Leire', 'pris_ore' => 30000, 'kun_medlemmer' => 1, 'i_nettbutikk' => 0, 'lager' => 6, 'status' => 'publisert', 'leverandor_id' => $lev]);
    $nett = DB::settInn('products', ['tittel' => $tag . ' Kopp', 'pris_ore' => 39000, 'kun_medlemmer' => 0, 'i_nettbutikk' => 1, 'lager' => 4, 'status' => 'publisert']);
    $salg = DB::settInn('member_sales', ['member_id' => $medlem, 'tittel' => $tag . ' Krus', 'pris_ore' => 25000, 'vippsnummer' => '90000000', 'kategori' => 'Kopper', 'antall' => 1, 'status' => 'publisert']);
    echo json_encode(compact('tag', 'admin', 'medlem', 'token', 'intern', 'nett', 'salg', 'lev'));
    exit;
}
$s = json_decode($argv[2] ?? '{}', true);
if (!is_array($s) || !str_starts_with((string) ($s['tag'] ?? ''), 'VarerTest-')) {
    throw new RuntimeException('Ukjent fixture');
}
if ($mode === 'inspect') {
    echo json_encode([
        'intern' => DB::en('SELECT tittel, lager, lager_min, lager_maks FROM products WHERE id = :i', ['i' => (int) $s['intern']]),
        'linjer' => DB::alle("SELECT id, antall, status FROM handleliste_linjer WHERE product_id = :i AND member_id IS NULL ORDER BY id", ['i' => (int) $s['intern']]),
        'salg'   => DB::en('SELECT tittel, pris_ore FROM member_sales WHERE id = :i', ['i' => (int) $s['salg']]),
        'nett'   => DB::en('SELECT id FROM products WHERE id = :i', ['i' => (int) $s['nett']]),
    ]);
    exit;
}
if ($mode === 'rydd') {
    $p = DB::alle('SELECT id FROM products WHERE tittel LIKE :t', ['t' => $s['tag'] . '%']);
    foreach (array_map(static fn($r) => (int) $r['id'], $p) as $id) {
        DB::kjor('DELETE FROM handleliste_linjer WHERE product_id = :i', ['i' => $id]);
        DB::kjor("DELETE FROM notifications WHERE ref_type = 'product' AND ref_id = :i", ['i' => $id]);
        DB::kjor('DELETE FROM products WHERE id = :i', ['i' => $id]);
    }
    DB::kjor('DELETE FROM member_sales WHERE tittel LIKE :t', ['t' => $s['tag'] . '%']);
    foreach ([(int) $s['admin'], (int) $s['medlem']] as $m) {
        DB::kjor('DELETE FROM sessions WHERE member_id = :i', ['i' => $m]);
        DB::kjor('DELETE FROM audit_log WHERE member_id = :i', ['i' => $m]);
        DB::kjor('DELETE FROM members WHERE id = :i', ['i' => $m]);
    }
    echo '{}';
}

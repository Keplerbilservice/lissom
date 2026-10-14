<?php
declare(strict_types=1);
// Render faktisk footer; innloggingslenken maa ikke avhenge av sesjonen.
final class Nett {
    public static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
    public static function innh(string $n): string { return ''; }
    public static function lagret(): array { return []; }
    public static function rot(): string { return dirname(__DIR__); }
}
final class Sesjon {
    public static bool $admin = false;
    public static function erAdmin(): bool { return self::$admin; }
}
require __DIR__ . '/../app/nett/deler.php';
foreach ([false, true] as $admin) {
    Sesjon::$admin = $admin;
    $html = Deler::bunn();
    if (substr_count($html, 'href="/admin"') !== 1 || substr_count($html, 'href="/min-side"') !== 1) {
        fwrite(STDERR, "FEIL: footer mangler fast innloggingslenke\n");
        exit(1);
    }
}
echo "OK: Admin og Min side i faktisk footer med og uten adminsesjon\n";

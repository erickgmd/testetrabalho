<?php
declare(strict_types=1);

$host = "aws-1-sa-east-1.pooler.supabase.com";
$port = "6543";
$dbname = "postgres";
$user = "postgres.tsmevymxeauuprotfqbz";
$pass = "cashflow1254@!";

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

    $conn = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    exit("Erro ao conectar no banco: " . $e->getMessage());
}
?>
<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$TOKEN = "";

$input = json_decode(file_get_contents("php://input"), true);

if (!$input || !isset($input["message"])) {
    http_response_code(200);
    exit;
}

$message = trim($input["message"]["text"] ?? '');
$chat_id = $input["message"]["chat"]["id"] ?? null;
$nome = $input["message"]["chat"]["first_name"] ?? 'Usuário';

if (!$chat_id || $message === '') {
    http_response_code(200);
    exit;
}

/**
 * Envia mensagem para o Telegram
 */
function enviarMensagem(int|string $chat_id, string $texto, string $TOKEN): void
{
    $url = "https://api.telegram.org/bot{$TOKEN}/sendMessage";

    $payload = [
        'chat_id' => $chat_id,
        'text' => $texto
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_exec($ch);
}

/**
 * Busca ou cria usuário pelo telegram_id
 */
function buscarOuCriarUsuario(PDO $conn, int|string $chat_id, string $nome): array
{
    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE telegram_id = :telegram_id LIMIT 1");
    $stmt->execute([':telegram_id' => $chat_id]);
    $usuario = $stmt->fetch();

    if ($usuario) {
        return $usuario;
    }

    $insert = $conn->prepare("
        INSERT INTO usuarios (telegram_id, nome)
        VALUES (:telegram_id, :nome)
    ");
    $insert->execute([
        ':telegram_id' => $chat_id,
        ':nome' => $nome
    ]);

    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE telegram_id = :telegram_id LIMIT 1");
    $stmt->execute([':telegram_id' => $chat_id]);
    return $stmt->fetch();
}

/**
 * Salva transação
 */
function salvarTransacao(PDO $conn, int $usuario_id, string $tipo, float $valor, string $categoria, string $data): void
{
    $stmt = $conn->prepare("
        INSERT INTO transacoes (usuario_id, tipo, valor, categoria, data)
        VALUES (:usuario_id, :tipo, :valor, :categoria, :data)
    ");

    $stmt->execute([
        ':usuario_id' => $usuario_id,
        ':tipo' => $tipo,
        ':valor' => $valor,
        ':categoria' => $categoria,
        ':data' => $data
    ]);
}

/**
 * Busca saldo
 */
function buscarSaldo(PDO $conn, int $usuario_id): array
{
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN tipo = 'receita' THEN valor ELSE 0 END), 0) AS receitas,
            COALESCE(SUM(CASE WHEN tipo = 'despesa' THEN valor ELSE 0 END), 0) AS despesas
        FROM transacoes
        WHERE usuario_id = :usuario_id
    ");

    $stmt->execute([':usuario_id' => $usuario_id]);
    $row = $stmt->fetch();

    $receitas = (float)($row['receitas'] ?? 0);
    $despesas = (float)($row['despesas'] ?? 0);
    $saldo = $receitas - $despesas;

    return [
        'receitas' => $receitas,
        'despesas' => $despesas,
        'saldo' => $saldo
    ];
}

/**
 * Formata valor em real
 */
function formatarReal(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

$usuario = buscarOuCriarUsuario($conn, $chat_id, $nome);
$usuario_id = (int)$usuario['id'];

/*
|--------------------------------------------------------------------------
| COMANDOS
|--------------------------------------------------------------------------
*/
if ($message === "/start") {
    enviarMensagem(
        $chat_id,
        "Olá, {$nome}.\n\nUse os comandos:\n/saldo\n\nOu envie assim:\nreceita 500 salario 2026-03-19\ndespesa 120 mercado 2026-03-19",
        $TOKEN
    );
    http_response_code(200);
    exit;
}

if ($message === "/saldo") {
    $dados = buscarSaldo($conn, $usuario_id);

    enviarMensagem(
        $chat_id,
        "💰 Saldo atual\n\nReceitas: " . formatarReal($dados['receitas']) .
        "\nDespesas: " . formatarReal($dados['despesas']) .
        "\nSaldo: " . formatarReal($dados['saldo']),
        $TOKEN
    );

    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| FORMATO DE MENSAGEM:
| receita 500 salario 2026-03-19
| despesa 120 mercado 2026-03-19
|--------------------------------------------------------------------------
*/
$partes = preg_split('/\s+/', $message);

if (count($partes) >= 4) {
    $tipo = strtolower($partes[0]);
    $valor = str_replace(',', '.', $partes[1]);
    $categoria = strtolower($partes[2]);
    $data = $partes[3];

    if (
        in_array($tipo, ['receita', 'despesa'], true) &&
        is_numeric($valor) &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)
    ) {
        salvarTransacao($conn, $usuario_id, $tipo, (float)$valor, $categoria, $data);

        $dados = buscarSaldo($conn, $usuario_id);

        enviarMensagem(
            $chat_id,
            "✅ Transação registrada com sucesso.\n\nSaldo atual: " . formatarReal($dados['saldo']),
            $TOKEN
        );

        http_response_code(200);
        exit;
    }
}

enviarMensagem(
    $chat_id,
    "Não entendi sua mensagem.\n\nExemplos:\nreceita 500 salario 2026-03-19\ndespesa 120 mercado 2026-03-19\n/saldo",
    $TOKEN
);

http_response_code(200);
exit;
?>
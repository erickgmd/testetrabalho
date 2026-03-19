<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$TOKEN = 'SEU_TOKEN_DO_BOT';

function responderOk(): void
{
    http_response_code(200);
    echo 'OK';
    exit;
}

function enviarMensagem(int|string $chatId, string $texto, string $token): void
{
    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    $payload = [
        'chat_id' => $chatId,
        'text' => $texto
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_exec($ch);
}

function formatarReal(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function buscarUsuarioVinculado(PDO $conn, int|string $telegramId): ?array
{
    $stmt = $conn->prepare("
        SELECT u.*
        FROM telegram_vinculos tv
        INNER JOIN usuarios u ON u.id = tv.usuario_id
        WHERE tv.telegram_id = :telegram_id
        LIMIT 1
    ");
    $stmt->execute([':telegram_id' => $telegramId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    return $usuario ?: null;
}

function buscarSaldo(PDO $conn, int $usuarioId): array
{
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN tipo = 'receita' THEN valor ELSE 0 END), 0) AS receitas,
            COALESCE(SUM(CASE WHEN tipo = 'despesa' THEN valor ELSE 0 END), 0) AS despesas
        FROM transacoes
        WHERE usuario_id = :usuario_id
    ");
    $stmt->execute([':usuario_id' => $usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $receitas = (float) ($row['receitas'] ?? 0);
    $despesas = (float) ($row['despesas'] ?? 0);

    return [
        'receitas' => $receitas,
        'despesas' => $despesas,
        'saldo' => $receitas - $despesas
    ];
}

function salvarTransacao(
    PDO $conn,
    int $usuarioId,
    string $tipo,
    float $valor,
    string $categoria,
    string $data
): void {
    $stmt = $conn->prepare("
        INSERT INTO transacoes (usuario_id, tipo, valor, categoria, data)
        VALUES (:usuario_id, :tipo, :valor, :categoria, :data)
    ");

    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':tipo' => $tipo,
        ':valor' => $valor,
        ':categoria' => $categoria,
        ':data' => $data
    ]);
}

$inputRaw = file_get_contents('php://input');
if (!$inputRaw) {
    responderOk();
}

$update = json_decode($inputRaw, true);
if (!$update || !isset($update['message'])) {
    responderOk();
}

$message = trim($update['message']['text'] ?? '');
$chatId = $update['message']['chat']['id'] ?? null;
$nomeTelegram = $update['message']['chat']['first_name'] ?? 'Usuário';

if (!$chatId) {
    responderOk();
}

if ($message === '/start') {
    $texto = "Olá, {$nomeTelegram}.\n\n"
        . "Para começar, vincule sua conta do site com:\n"
        . "/vincular SEU_CODIGO\n\n"
        . "Depois você poderá usar:\n"
        . "/saldo\n"
        . "receita 500 salario 2026-03-19\n"
        . "despesa 120 mercado 2026-03-19";

    enviarMensagem($chatId, $texto, $TOKEN);
    responderOk();
}

if (str_starts_with($message, '/vincular')) {
    $partes = preg_split('/\s+/', $message);
    $codigo = trim($partes[1] ?? '');

    if ($codigo === '') {
        enviarMensagem($chatId, "Use assim:\n/vincular SEU_CODIGO", $TOKEN);
        responderOk();
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM telegram_vinculos
        WHERE codigo_vinculo = :codigo
        LIMIT 1
    ");
    $stmt->execute([':codigo' => $codigo]);
    $vinculo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vinculo) {
        enviarMensagem($chatId, "Código inválido.", $TOKEN);
        responderOk();
    }

    if (!empty($vinculo['telegram_id'])) {
        enviarMensagem($chatId, "Esse código já foi utilizado.", $TOKEN);
        responderOk();
    }

    $updateStmt = $conn->prepare("
        UPDATE telegram_vinculos
        SET telegram_id = :telegram_id,
            nome_telegram = :nome_telegram
        WHERE id = :id
    ");

    $updateStmt->execute([
        ':telegram_id' => $chatId,
        ':nome_telegram' => $nomeTelegram,
        ':id' => $vinculo['id']
    ]);

    enviarMensagem($chatId, "Conta vinculada com sucesso ao Cash Flow.", $TOKEN);
    responderOk();
}

$usuario = buscarUsuarioVinculado($conn, $chatId);

if (!$usuario) {
    enviarMensagem(
        $chatId,
        "Sua conta ainda não está vinculada.\n\nAcesse o site, gere seu código e envie:\n/vincular SEU_CODIGO",
        $TOKEN
    );
    responderOk();
}

$usuarioId = (int) $usuario['id'];

if ($message === '/saldo') {
    $saldo = buscarSaldo($conn, $usuarioId);

    $texto = "💰 Saldo atual\n\n"
        . "Receitas: " . formatarReal($saldo['receitas']) . "\n"
        . "Despesas: " . formatarReal($saldo['despesas']) . "\n"
        . "Saldo: " . formatarReal($saldo['saldo']);

    enviarMensagem($chatId, $texto, $TOKEN);
    responderOk();
}

$partes = preg_split('/\s+/', $message);

if (count($partes) >= 4) {
    $tipo = strtolower(trim($partes[0]));
    $valorRaw = str_replace(',', '.', trim($partes[1]));
    $categoria = strtolower(trim($partes[2]));
    $data = trim($partes[3]);

    $tipoValido = in_array($tipo, ['receita', 'despesa'], true);
    $valorValido = is_numeric($valorRaw) && (float)$valorRaw > 0;
    $dataValida = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $data);

    if ($tipoValido && $valorValido && $dataValida) {
        salvarTransacao($conn, $usuarioId, $tipo, (float)$valorRaw, $categoria, $data);

        $saldo = buscarSaldo($conn, $usuarioId);

        enviarMensagem(
            $chatId,
            "✅ Transação registrada com sucesso.\n\nSaldo atual: " . formatarReal($saldo['saldo']),
            $TOKEN
        );

        responderOk();
    }
}

enviarMensagem(
    $chatId,
    "Não entendi sua mensagem.\n\nUse:\n"
    . "/saldo\n"
    . "/vincular SEU_CODIGO\n"
    . "receita 500 salario 2026-03-19\n"
    . "despesa 120 mercado 2026-03-19",
    $TOKEN
);

responderOk();
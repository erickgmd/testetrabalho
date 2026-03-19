<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$TOKEN = '8308783962:AAFpg2xrjevfet-q-6jt2kHNc7n_IFMstt8';

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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);

    if ($response === false) {
        error_log('Erro cURL Telegram: ' . curl_error($ch));
    }

    curl_close($ch);
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

/**
 * Formatos aceitos:
 * receita 500 salario 2026-03-19
 * despesa 120 mercado 2026-03-19
 * despesas 120 mercado extra 2026-03-19
 * receitas 250 freelance 2026-03-19
 */
function interpretarTransacao(string $message): ?array
{
    $partes = preg_split('/\s+/', trim($message));

    if (!$partes || count($partes) < 4) {
        return null;
    }

    $tipoBruto = strtolower(trim((string)$partes[0]));
    $valorBruto = str_replace(',', '.', trim((string)$partes[1]));
    $data = trim((string)$partes[count($partes) - 1]);

    $mapaTipos = [
        'receita' => 'receita',
        'receitas' => 'receita',
        'despesa' => 'despesa',
        'despesas' => 'despesa',
    ];

    if (!isset($mapaTipos[$tipoBruto])) {
        return null;
    }

    $tipo = $mapaTipos[$tipoBruto];

    if (!is_numeric($valorBruto) || (float)$valorBruto <= 0) {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        return null;
    }

    $categoriaPartes = array_slice($partes, 2, -1);
    $categoria = trim(implode(' ', $categoriaPartes));

    if ($categoria === '') {
        return null;
    }

    return [
        'tipo' => $tipo,
        'valor' => (float)$valorBruto,
        'categoria' => strtolower($categoria),
        'data' => $data
    ];
}

try {
    $inputRaw = file_get_contents('php://input');

    if (!$inputRaw) {
        responderOk();
    }

    $update = json_decode($inputRaw, true);

    if (!$update || !isset($update['message'])) {
        responderOk();
    }

    $message = trim((string)($update['message']['text'] ?? ''));
    $chatId = $update['message']['chat']['id'] ?? null;
    $nomeTelegram = trim((string)($update['message']['chat']['first_name'] ?? 'Usuário'));

    if (!$chatId) {
        responderOk();
    }

    if ($message === '/start') {
        enviarMensagem(
            $chatId,
            "Olá, {$nomeTelegram}.\n\nPara vincular sua conta, envie:\n/vincular SEU_CODIGO\n\nDepois use:\n/saldo\n\nExemplos de transação:\nreceita 500 salario 2026-03-19\ndespesa 120 mercado 2026-03-19",
            $TOKEN
        );
        responderOk();
    }

    if (str_starts_with($message, '/vincular')) {
        $partes = preg_split('/\s+/', $message);
        $codigo = trim((string)($partes[1] ?? ''));

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

        enviarMensagem($chatId, "Conta vinculada com sucesso.", $TOKEN);
        responderOk();
    }

    $usuario = buscarUsuarioVinculado($conn, $chatId);

    if (!$usuario) {
        enviarMensagem(
            $chatId,
            "Sua conta ainda não está vinculada.\n\nEnvie:\n/vincular SEU_CODIGO",
            $TOKEN
        );
        responderOk();
    }

    $usuarioId = (int)$usuario['id'];

    if ($message === '/saldo') {
        $saldo = buscarSaldo($conn, $usuarioId);

        $texto = "💰 Saldo atual\n\n"
            . "Receitas: " . formatarReal($saldo['receitas']) . "\n"
            . "Despesas: " . formatarReal($saldo['despesas']) . "\n"
            . "Saldo: " . formatarReal($saldo['saldo']);

        enviarMensagem($chatId, $texto, $TOKEN);
        responderOk();
    }

    $transacao = interpretarTransacao($message);

    if ($transacao !== null) {
        salvarTransacao(
            $conn,
            $usuarioId,
            $transacao['tipo'],
            $transacao['valor'],
            $transacao['categoria'],
            $transacao['data']
        );

        $saldo = buscarSaldo($conn, $usuarioId);

        enviarMensagem(
            $chatId,
            "✅ " . ucfirst($transacao['tipo']) . " registrada.\n"
            . "Categoria: {$transacao['categoria']}\n"
            . "Valor: " . formatarReal($transacao['valor']) . "\n"
            . "Data: {$transacao['data']}\n\n"
            . "Saldo atual: " . formatarReal($saldo['saldo']),
            $TOKEN
        );

        responderOk();
    }

    enviarMensagem(
        $chatId,
        "Comando não reconhecido.\n\nUse:\n/vincular SEU_CODIGO\n/saldo\n\nExemplos:\nreceita 500 salario 2026-03-19\ndespesa 120 mercado 2026-03-19",
        $TOKEN
    );

    responderOk();

} catch (Throwable $e) {
    error_log('Erro no webhook: ' . $e->getMessage());
    responderOk();
}
<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$TOKEN = 'COLOQUE_SEU_NOVO_TOKEN_AQUI';

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

function buscarSaldoAtual(PDO $conn, int $usuarioId): float
{
    $stmt = $conn->prepare("
        SELECT COALESCE(saldo_total, 0) AS saldo_total
        FROM transacoes
        WHERE usuario_id = :usuario_id
        ORDER BY data_transacao DESC, criado_em DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([':usuario_id' => $usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (float)($row['saldo_total'] ?? 0);
}

function buscarResumo(PDO $conn, int $usuarioId): array
{
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN tipo = 'income' THEN valor ELSE 0 END), 0) AS receitas,
            COALESCE(SUM(CASE WHEN tipo = 'expense' THEN valor ELSE 0 END), 0) AS despesas
        FROM transacoes
        WHERE usuario_id = :usuario_id
    ");
    $stmt->execute([':usuario_id' => $usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $receitas = (float)($row['receitas'] ?? 0);
    $despesas = (float)($row['despesas'] ?? 0);
    $saldo = buscarSaldoAtual($conn, $usuarioId);

    return [
        'receitas' => $receitas,
        'despesas' => $despesas,
        'saldo' => $saldo
    ];
}

function salvarTransacao(
    PDO $conn,
    int $usuarioId,
    string $descricao,
    float $valor,
    string $categoria,
    string $tipo,
    string $data
): void {
    $stmtSaldo = $conn->prepare("
        SELECT COALESCE(saldo_total, 0) AS saldo_total
        FROM transacoes
        WHERE usuario_id = :usuario_id
        ORDER BY data_transacao DESC, criado_em DESC, id DESC
        LIMIT 1
    ");
    $stmtSaldo->execute([
        ':usuario_id' => $usuarioId
    ]);

    $ultima = $stmtSaldo->fetch(PDO::FETCH_ASSOC);
    $saldoAnterior = (float)($ultima['saldo_total'] ?? 0);

    $novoSaldo = $tipo === 'income'
        ? $saldoAnterior + $valor
        : $saldoAnterior - $valor;

    $stmt = $conn->prepare("
        INSERT INTO transacoes (
            usuario_id,
            descricao,
            valor,
            categoria,
            tipo,
            data_transacao,
            saldo_total
        ) VALUES (
            :usuario_id,
            :descricao,
            :valor,
            :categoria,
            :tipo,
            :data_transacao,
            :saldo_total
        )
    ");

    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':descricao' => $descricao,
        ':valor' => $valor,
        ':categoria' => $categoria,
        ':tipo' => $tipo,
        ':data_transacao' => $data,
        ':saldo_total' => $novoSaldo
    ]);
}

/*
Formatos aceitos:
receita 5000 salario salario 2026-03-19
despesa 1000 aluguel de jetski transporte 2026-03-19

Regra:
- palavra 1 = tipo
- palavra 2 = valor
- última = data
- penúltima = categoria
- meio = descrição
*/
function interpretarTransacao(string $message): ?array
{
    $partes = preg_split('/\s+/', trim($message));

    if (!$partes || count($partes) < 5) {
        return null;
    }

    $tipoBruto = strtolower(trim((string)$partes[0]));
    $valorBruto = str_replace(',', '.', trim((string)$partes[1]));
    $data = trim((string)$partes[count($partes) - 1]);
    $categoria = strtolower(trim((string)$partes[count($partes) - 2]));
    $descricaoPartes = array_slice($partes, 2, -2);
    $descricao = trim(implode(' ', $descricaoPartes));

    $mapaTipos = [
        'receita' => 'income',
        'receitas' => 'income',
        'despesa' => 'expense',
        'despesas' => 'expense'
    ];

    if (!isset($mapaTipos[$tipoBruto])) {
        return null;
    }

    if (!is_numeric($valorBruto) || (float)$valorBruto <= 0) {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        return null;
    }

    if ($descricao === '' || $categoria === '') {
        return null;
    }

    return [
        'tipo' => $mapaTipos[$tipoBruto],
        'valor' => (float)$valorBruto,
        'descricao' => $descricao,
        'categoria' => $categoria,
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
            "Olá, {$nomeTelegram}.\n\nPara vincular sua conta:\n/vincular SEU_CODIGO\n\nPara consultar saldo:\n/saldo\n\nPara lançar transação:\nreceita 5000 salario salario 2026-03-19\ndespesa 1000 aluguel de jetski transporte 2026-03-19",
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
        $resumo = buscarResumo($conn, $usuarioId);

        $texto = "💰 Saldo atual\n\n"
            . "Receitas: " . formatarReal($resumo['receitas']) . "\n"
            . "Despesas: " . formatarReal($resumo['despesas']) . "\n"
            . "Saldo total: " . formatarReal($resumo['saldo']);

        enviarMensagem($chatId, $texto, $TOKEN);
        responderOk();
    }

    $transacao = interpretarTransacao($message);

    if ($transacao !== null) {
        salvarTransacao(
            $conn,
            $usuarioId,
            $transacao['descricao'],
            $transacao['valor'],
            $transacao['categoria'],
            $transacao['tipo'],
            $transacao['data']
        );

        $saldoAtual = buscarSaldoAtual($conn, $usuarioId);

        enviarMensagem(
            $chatId,
            "✅ Transação registrada.\n\n"
            . "Descrição: {$transacao['descricao']}\n"
            . "Categoria: {$transacao['categoria']}\n"
            . "Valor: " . formatarReal($transacao['valor']) . "\n"
            . "Tipo: {$transacao['tipo']}\n"
            . "Data: {$transacao['data']}\n\n"
            . "Saldo total: " . formatarReal($saldoAtual),
            $TOKEN
        );

        responderOk();
    }

    enviarMensagem(
        $chatId,
        "Comando não reconhecido.\n\nUse:\n/vincular SEU_CODIGO\n/saldo\n\nExemplo:\nreceita 5000 salario salario 2026-03-19\ndespesa 1000 aluguel de jetski transporte 2026-03-19",
        $TOKEN
    );

    responderOk();

} catch (Throwable $e) {
    error_log('Erro no webhook: ' . $e->getMessage());
    responderOk();
}
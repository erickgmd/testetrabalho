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

function buscarExtrato(PDO $conn, int $usuarioId): array
{
    $stmt = $conn->prepare("
        SELECT descricao, valor, tipo, categoria, data_transacao
        FROM transacoes
        WHERE usuario_id = :usuario_id
        ORDER BY data_transacao DESC, criado_em DESC, id DESC
        LIMIT 5
    ");
    $stmt->execute([':usuario_id' => $usuarioId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function detectarCategoria(string $descricao): string
{
    $descricao = strtolower($descricao);

    if (
        str_contains($descricao, 'ifood') ||
        str_contains($descricao, 'mercado') ||
        str_contains($descricao, 'restaurante') ||
        str_contains($descricao, 'lanche')
    ) {
        return 'alimentacao';
    }

    if (
        str_contains($descricao, 'uber') ||
        str_contains($descricao, 'gasolina') ||
        str_contains($descricao, 'combustivel') ||
        str_contains($descricao, 'onibus')
    ) {
        return 'transporte';
    }

    if (
        str_contains($descricao, 'aluguel') ||
        str_contains($descricao, 'condominio') ||
        str_contains($descricao, 'energia') ||
        str_contains($descricao, 'agua')
    ) {
        return 'moradia';
    }

    if (
        str_contains($descricao, 'academia') ||
        str_contains($descricao, 'farmacia') ||
        str_contains($descricao, 'consulta')
    ) {
        return 'saude';
    }

    if (
        str_contains($descricao, 'freelance') ||
        str_contains($descricao, 'salario') ||
        str_contains($descricao, 'pagamento')
    ) {
        return 'renda';
    }

    return 'outros';
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

1) Manual completo
receita 5000 salario renda 2026-03-19
despesa 1000 aluguel transporte 2026-03-19

2) Com data automática
despesa 120 mercado alimentacao hoje
receita 500 freelance renda ontem

3) Com categoria automática
despesa 50 ifood auto hoje

Regra:
- palavra 1 = tipo
- palavra 2 = valor
- última = data|hoje|ontem
- penúltima = categoria|auto
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
    $dataRaw = strtolower(trim((string)$partes[count($partes) - 1]));
    $categoriaRaw = strtolower(trim((string)$partes[count($partes) - 2]));
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

    if ($dataRaw === 'hoje') {
        $data = date('Y-m-d');
    } elseif ($dataRaw === 'ontem') {
        $data = date('Y-m-d', strtotime('-1 day'));
    } else {
        $data = $dataRaw;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        return null;
    }

    if ($descricao === '') {
        return null;
    }

    $categoria = $categoriaRaw === 'auto'
        ? detectarCategoria($descricao)
        : $categoriaRaw;

    if ($categoria === '') {
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
            "Olá, {$nomeTelegram}.\n\nPara vincular sua conta:\n/vincular SEU_CODIGO\n\nComandos disponíveis:\n/saldo\n/extrato\n\nExemplos:\nreceita 5000 salario renda 2026-03-19\ndespesa 100 mercado alimentacao hoje\ndespesa 50 ifood auto hoje",
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

    if ($message === '/extrato') {
        $extrato = buscarExtrato($conn, $usuarioId);

        if (empty($extrato)) {
            enviarMensagem($chatId, "Nenhuma transação encontrada.", $TOKEN);
            responderOk();
        }

        $texto = "📊 Últimas transações:\n\n";

        foreach ($extrato as $t) {
            $sinal = $t['tipo'] === 'income' ? '+' : '-';
            $texto .= $t['descricao']
                . " | " . $t['categoria']
                . " | " . $sinal . formatarReal((float)$t['valor'])
                . " | " . $t['data_transacao'] . "\n";
        }

        $saldo = buscarSaldoAtual($conn, $usuarioId);
        $texto .= "\n💰 Saldo: " . formatarReal($saldo);

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
        "Comando não reconhecido.\n\nUse:\n/vincular SEU_CODIGO\n/saldo\n/extrato\n\nExemplos:\nreceita 5000 salario renda 2026-03-19\ndespesa 120 mercado alimentacao hoje\ndespesa 50 ifood auto hoje",
        $TOKEN
    );

    responderOk();

} catch (Throwable $e) {
    error_log('Erro no webhook: ' . $e->getMessage());
    responderOk();
}
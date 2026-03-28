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

function normalizarTexto(string $texto): string
{
    $texto = trim(mb_strtolower($texto, 'UTF-8'));

    $mapa = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
        'é' => 'e', 'ê' => 'e',
        'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u',
        'ç' => 'c'
    ];

    return strtr($texto, $mapa);
}

function converterDataEntrada(string $data): ?string
{
    $data = trim(normalizarTexto($data));

    if ($data === 'hoje') {
        return date('Y-m-d');
    }

    if ($data === 'ontem') {
        return date('Y-m-d', strtotime('-1 day'));
    }

    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $data)) {
        [$dia, $mes, $ano] = explode('/', $data);
        return "{$ano}-{$mes}-{$dia}";
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        return $data;
    }

    return null;
}

function formatarDataBR(string $data): string
{
    $timestamp = strtotime($data);
    if ($timestamp === false) {
        return $data;
    }

    return date('d/m/Y', $timestamp);
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

/*
 * Saldo real calculado pela soma das transações.
 * Isso evita erro mesmo se alguma linha antiga tiver saldo_total inconsistente.
 */
function buscarSaldoAtual(PDO $conn, int $usuarioId): float
{
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(
                CASE
                    WHEN tipo = 'income' THEN valor
                    WHEN tipo = 'expense' THEN -valor
                    ELSE 0
                END
            ), 0) AS saldo
        FROM transacoes
        WHERE usuario_id = :usuario_id
    ");

    $stmt->execute([
        ':usuario_id' => $usuarioId
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (float)($row['saldo'] ?? 0);
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

    return [
        'receitas' => $receitas,
        'despesas' => $despesas,
        'saldo' => $receitas - $despesas
    ];
}

function buscarExtrato(PDO $conn, int $usuarioId, ?string $inicio = null, ?string $fim = null): array
{
    if ($inicio && $fim) {
        $stmt = $conn->prepare("
            SELECT descricao, valor, tipo, categoria, data_transacao
            FROM transacoes
            WHERE usuario_id = :usuario_id
              AND data_transacao BETWEEN :inicio AND :fim
            ORDER BY data_transacao DESC, criado_em DESC, id DESC
            LIMIT 100
        ");

        $stmt->execute([
            ':usuario_id' => $usuarioId,
            ':inicio' => $inicio,
            ':fim' => $fim
        ]);
    } else {
        $stmt = $conn->prepare("
            SELECT descricao, valor, tipo, categoria, data_transacao
            FROM transacoes
            WHERE usuario_id = :usuario_id
            ORDER BY data_transacao DESC, criado_em DESC, id DESC
            LIMIT 5
        ");

        $stmt->execute([
            ':usuario_id' => $usuarioId
        ]);
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function gerarInsights(PDO $conn, int $usuarioId): string
{
    $stmt = $conn->prepare("
        SELECT categoria, tipo, SUM(valor) AS total
        FROM transacoes
        WHERE usuario_id = :usuario_id
        GROUP BY categoria, tipo
    ");
    $stmt->execute([':usuario_id' => $usuarioId]);
    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$dados) {
        return "Sem dados suficientes para gerar insights.";
    }

    $gastos = [];
    $receitas = 0.0;
    $despesas = 0.0;

    foreach ($dados as $d) {
        $total = (float)$d['total'];

        if ($d['tipo'] === 'income') {
            $receitas += $total;
        } else {
            $despesas += $total;
            $gastos[$d['categoria']] = $total;
        }
    }

    arsort($gastos);
    $topCategoria = array_key_first($gastos);
    $topValor = $gastos[$topCategoria] ?? 0;

    return "📊 Insights\n\n"
        . "Maior gasto: " . ($topCategoria ?? 'N/A') . " (" . formatarReal((float)$topValor) . ")\n"
        . "Receitas: " . formatarReal($receitas) . "\n"
        . "Despesas: " . formatarReal($despesas) . "\n"
        . "Saldo: " . formatarReal($receitas - $despesas);
}

function detectarCategoria(string $descricao): string
{
    $descricao = normalizarTexto($descricao);

    if (
        str_contains($descricao, 'ifood') ||
        str_contains($descricao, 'mercado') ||
        str_contains($descricao, 'restaurante') ||
        str_contains($descricao, 'lanche') ||
        str_contains($descricao, 'padaria')
    ) {
        return 'alimentacao';
    }

    if (
        str_contains($descricao, 'uber') ||
        str_contains($descricao, '99') ||
        str_contains($descricao, 'gasolina') ||
        str_contains($descricao, 'combustivel') ||
        str_contains($descricao, 'onibus') ||
        str_contains($descricao, 'taxi')
    ) {
        return 'transporte';
    }

    if (
        str_contains($descricao, 'aluguel') ||
        str_contains($descricao, 'condominio') ||
        str_contains($descricao, 'energia') ||
        str_contains($descricao, 'agua') ||
        str_contains($descricao, 'internet')
    ) {
        return 'moradia';
    }

    if (
        str_contains($descricao, 'academia') ||
        str_contains($descricao, 'farmacia') ||
        str_contains($descricao, 'consulta') ||
        str_contains($descricao, 'remedio')
    ) {
        return 'saude';
    }

    if (
        str_contains($descricao, 'freelance') ||
        str_contains($descricao, 'salario') ||
        str_contains($descricao, 'pagamento') ||
        str_contains($descricao, 'empresa') ||
        str_contains($descricao, 'cliente')
    ) {
        return 'renda';
    }

    return 'outros';
}

/*
 * Continua preenchendo saldo_total na tabela,
 * mas usando o saldo real calculado por SUM.
 */
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
        SELECT
            COALESCE(SUM(
                CASE
                    WHEN tipo = 'income' THEN valor
                    WHEN tipo = 'expense' THEN -valor
                    ELSE 0
                END
            ), 0) AS saldo
        FROM transacoes
        WHERE usuario_id = :usuario_id
    ");
    $stmtSaldo->execute([
        ':usuario_id' => $usuarioId
    ]);

    $row = $stmtSaldo->fetch(PDO::FETCH_ASSOC);
    $saldoAnterior = (float)($row['saldo'] ?? 0);

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

function interpretarTransacao(string $message): ?array
{
    $partes = preg_split('/\s+/', trim($message));

    if (!$partes || count($partes) < 5) {
        return null;
    }

    $tipoBruto = normalizarTexto((string)$partes[0]);
    $valorBruto = str_replace(',', '.', trim((string)$partes[1]));
    $dataRaw = (string)$partes[count($partes) - 1];
    $categoriaRaw = normalizarTexto((string)$partes[count($partes) - 2]);
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

    $data = converterDataEntrada($dataRaw);
    if ($data === null) {
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

/*
 * Frases mais humanas:
 * gastei 50 no uber hoje
 * paguei 120 de internet ontem
 * comprei remedio por 30
 * recebi 500 de freelance hoje
 * ganhei 1200 com cliente ontem
 */
function interpretarNatural(string $message): ?array
{
    $msg = normalizarTexto($message);

    $data = date('Y-m-d');
    if (str_contains($msg, ' ontem')) {
        $data = date('Y-m-d', strtotime('-1 day'));
    }

    if (preg_match('/^(gastei|paguei|comprei|transferi)\s+(\d+(?:[.,]\d+)?)/', $msg, $m)) {
        $valor = (float)str_replace(',', '.', $m[2]);
        $descricao = 'gasto';

        if (preg_match('/(?:gastei|paguei|comprei|transferi)\s+\d+(?:[.,]\d+)?\s+(?:no|na|com|de|por)\s+(.+?)(?:\s+hoje|\s+ontem|$)/', $msg, $descMatch)) {
            $descricao = trim($descMatch[1]);
        }

        return [
            'tipo' => 'expense',
            'valor' => $valor,
            'descricao' => $descricao,
            'categoria' => detectarCategoria($descricao),
            'data' => $data
        ];
    }

    if (preg_match('/^(recebi|ganhei|entrou|caiu|faturei)\s+(\d+(?:[.,]\d+)?)/', $msg, $m)) {
        $valor = (float)str_replace(',', '.', $m[2]);
        $descricao = 'renda';

        if (preg_match('/(?:recebi|ganhei|entrou|caiu|faturei)\s+\d+(?:[.,]\d+)?\s+(?:de|com|referente a|do|da)?\s*(.+?)(?:\s+hoje|\s+ontem|$)/', $msg, $descMatch)) {
            $descricao = trim($descMatch[1]);
            if ($descricao === '') {
                $descricao = 'renda';
            }
        }

        return [
            'tipo' => 'income',
            'valor' => $valor,
            'descricao' => $descricao,
            'categoria' => detectarCategoria($descricao),
            'data' => $data
        ];
    }

    return null;
}

function interpretarComandoExtrato(string $message): ?array
{
    $partes = preg_split('/\s+/', trim($message));

    if (!$partes || count($partes) === 0) {
        return null;
    }

    $comando = normalizarTexto((string)$partes[0]);

    if (!in_array($comando, ['extrato', '/extrato'], true)) {
        return null;
    }

    if (count($partes) === 1) {
        return ['tipo' => 'ultimos'];
    }

    if (count($partes) === 3) {
        $inicio = converterDataEntrada((string)$partes[1]);
        $fim = converterDataEntrada((string)$partes[2]);

        if (!$inicio || !$fim) {
            return ['tipo' => 'erro'];
        }

        return [
            'tipo' => 'periodo',
            'inicio' => $inicio,
            'fim' => $fim
        ];
    }

    return ['tipo' => 'erro'];
}

function interpretarPerguntaHumana(string $message): ?string
{
    $msg = normalizarTexto($message);

    $perguntasSaldo = [
        'quanto eu tenho',
        'qual meu saldo',
        'quanto tenho hoje',
        'quanto eu tenho hoje',
        'meu saldo'
    ];

    foreach ($perguntasSaldo as $p) {
        if (str_contains($msg, $p)) {
            return 'saldo';
        }
    }

    $perguntasInsights = [
        'onde gasto mais',
        'qual meu maior gasto',
        'com o que gasto mais',
        'onde estou gastando mais'
    ];

    foreach ($perguntasInsights as $p) {
        if (str_contains($msg, $p)) {
            return 'insights';
        }
    }

    return null;
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

    $messageOriginal = trim((string)($update['message']['text'] ?? ''));
    $messageNormalizada = normalizarTexto($messageOriginal);
    $chatId = $update['message']['chat']['id'] ?? null;
    $nomeTelegram = trim((string)($update['message']['chat']['first_name'] ?? 'Usuário'));

    if (!$chatId) {
        responderOk();
    }

    if ($messageNormalizada === '/start' || $messageNormalizada === 'start') {
        enviarMensagem(
            $chatId,
            "Olá, {$nomeTelegram}.\n\nPara vincular sua conta:\nvincular SEU_CODIGO\n\nComandos disponíveis:\nsaldo\nextrato\nextrato 01/03/2026 31/03/2026\ninsights\n\nExemplos:\nreceita 5000 salario renda 19/03/2026\ndespesa 100 mercado alimentacao hoje\ndespesa 50 ifood auto ontem\ngastei 30 no uber hoje\npaguei 120 de internet ontem\nrecebi 500 freelance hoje\nganhei 1200 com cliente ontem",
            $TOKEN
        );
        responderOk();
    }

    if (str_starts_with($messageNormalizada, '/vincular') || str_starts_with($messageNormalizada, 'vincular')) {
        $partes = preg_split('/\s+/', $messageOriginal);
        $codigo = trim((string)($partes[1] ?? ''));

        if ($codigo === '') {
            enviarMensagem($chatId, "Use assim:\nvincular SEU_CODIGO", $TOKEN);
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
            "Sua conta ainda não está vinculada.\n\nEnvie:\nvincular SEU_CODIGO",
            $TOKEN
        );
        responderOk();
    }

    $usuarioId = (int)$usuario['id'];

    $perguntaHumana = interpretarPerguntaHumana($messageOriginal);

    if ($messageNormalizada === '/saldo' || $messageNormalizada === 'saldo' || $perguntaHumana === 'saldo') {
        $resumo = buscarResumo($conn, $usuarioId);

        $texto = "💰 Saldo atual\n\n"
            . "Receitas: " . formatarReal($resumo['receitas']) . "\n"
            . "Despesas: " . formatarReal($resumo['despesas']) . "\n"
            . "Saldo total: " . formatarReal($resumo['saldo']);

        enviarMensagem($chatId, $texto, $TOKEN);
        responderOk();
    }

    $extratoCmd = interpretarComandoExtrato($messageOriginal);

    if ($extratoCmd !== null) {
        if ($extratoCmd['tipo'] === 'erro') {
            enviarMensagem(
                $chatId,
                "Formato inválido.\n\nUse:\nextrato\nou\nextrato 01/03/2026 31/03/2026",
                $TOKEN
            );
            responderOk();
        }

        if ($extratoCmd['tipo'] === 'ultimos') {
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
                    . " | " . formatarDataBR((string)$t['data_transacao']) . "\n";
            }

            $saldo = buscarSaldoAtual($conn, $usuarioId);
            $texto .= "\n💰 Saldo: " . formatarReal($saldo);

            enviarMensagem($chatId, $texto, $TOKEN);
            responderOk();
        }

        if ($extratoCmd['tipo'] === 'periodo') {
            $extrato = buscarExtrato(
                $conn,
                $usuarioId,
                $extratoCmd['inicio'],
                $extratoCmd['fim']
            );

            if (empty($extrato)) {
                enviarMensagem(
                    $chatId,
                    "Nenhuma transação encontrada no período de "
                    . formatarDataBR($extratoCmd['inicio'])
                    . " até "
                    . formatarDataBR($extratoCmd['fim'])
                    . ".",
                    $TOKEN
                );
                responderOk();
            }

            $texto = "📊 Extrato do período\n";
            $texto .= formatarDataBR($extratoCmd['inicio']) . " até " . formatarDataBR($extratoCmd['fim']) . "\n\n";

            $receitas = 0.0;
            $despesas = 0.0;

            foreach ($extrato as $t) {
                $valor = (float)$t['valor'];
                $sinal = $t['tipo'] === 'income' ? '+' : '-';

                if ($t['tipo'] === 'income') {
                    $receitas += $valor;
                } else {
                    $despesas += $valor;
                }

                $texto .= $t['descricao']
                    . " | " . $t['categoria']
                    . " | " . $sinal . formatarReal($valor)
                    . " | " . formatarDataBR((string)$t['data_transacao']) . "\n";
            }

            $texto .= "\nReceitas no período: " . formatarReal($receitas);
            $texto .= "\nDespesas no período: " . formatarReal($despesas);
            $texto .= "\nSaldo no período: " . formatarReal($receitas - $despesas);

            enviarMensagem($chatId, $texto, $TOKEN);
            responderOk();
        }
    }

    if ($messageNormalizada === '/insights' || $messageNormalizada === 'insights' || $perguntaHumana === 'insights') {
        $texto = gerarInsights($conn, $usuarioId);
        enviarMensagem($chatId, $texto, $TOKEN);
        responderOk();
    }

    $transacao = interpretarNatural($messageOriginal);

    if ($transacao === null) {
        $transacao = interpretarTransacao($messageOriginal);
    }

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
            . "Data: " . formatarDataBR($transacao['data']) . "\n\n"
            . "Saldo total: " . formatarReal($saldoAtual),
            $TOKEN
        );

        responderOk();
    }

    enviarMensagem(
        $chatId,
        "Comando não reconhecido.\n\nUse:\nvincular SEU_CODIGO\nsaldo\nextrato\nextrato 01/03/2026 31/03/2026\ninsights\n\nExemplos:\nreceita 5000 salario renda 19/03/2026\ndespesa 120 mercado alimentacao hoje\ndespesa 50 ifood auto ontem\ngastei 30 no uber hoje\npaguei 120 de internet ontem\nrecebi 500 freelance hoje\nganhei 1200 com cliente ontem\n\nPerguntas aceitas:\nquanto eu tenho hoje?\nonde gasto mais?",
        $TOKEN
    );

    responderOk();

} catch (Throwable $e) {
    error_log('Erro no webhook: ' . $e->getMessage());
    responderOk();
}
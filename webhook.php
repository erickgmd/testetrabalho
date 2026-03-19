<?php
declare(strict_types=1);

$TOKEN = "8308783962:AAFpg2xrjevfet-q-6jt2kHNc7n_IFMstt8";

$input = json_decode(file_get_contents("php://input"), true);

if (!$input || !isset($input["message"])) {
    http_response_code(200);
    echo "OK";
    exit;
}

$chat_id = $input["message"]["chat"]["id"] ?? null;
$message = trim($input["message"]["text"] ?? '');

function enviarMensagem($chat_id, $texto, $TOKEN): void
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
    curl_close($ch);
}

if ($message === "/start") {
    enviarMensagem($chat_id, "Bot funcionando corretamente.", $TOKEN);
}

http_response_code(200);
echo "OK";
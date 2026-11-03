<?php
declare(strict_types=1);

// ADICIONE ESTAS DUAS LINHAS:
ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();

// Cabeçalhos JSON e CORS (Permite chamadas locais ou do GitHub Pages)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // Em produção, troque '*' pelo domínio do seu site
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

// 1. Rate Limiting Simples por Sessão (Máximo de 8 mensagens por minuto)
$now = time();
if (!isset($_SESSION['chat_requests'])) {
    $_SESSION['chat_requests'] = [];
}
// Limpa requisições com mais de 60 segundos
$_SESSION['chat_requests'] = array_filter($_SESSION['chat_requests'], fn($t) => ($now - $t) < 60);

if (count($_SESSION['chat_requests']) >= 8) {
    http_response_code(429);
    echo json_encode([
        'reply' => 'Você enviou muitas mensagens seguidas. Aguarde um instante ou chame a Amanda direto no WhatsApp: https://wa.me/5588992474831'
    ]);
    exit;
}
$_SESSION['chat_requests'][] = $now;

// 2. Carrega a API Key do arquivo .env ou variáveis de ambiente do servidor
function getApiKey(): ?string {
    $envKey = getenv('GEMINI_API_KEY');
    if ($envKey) return $envKey;

    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            [$name, $value] = explode('=', $line, 2) + [NULL, NULL];
            if (trim((string)$name) === 'GEMINI_API_KEY') {
                return trim((string)$value, " \t\n\r\0\x0B\"'");
            }
        }
    }
    return null;
}

$apiKey = getApiKey();
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'Chave da API do Gemini não configurada no servidor.']);
    exit;
}

// 3. Recebe e valida o JSON enviado pelo Frontend
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!isset($data['history']) || !is_array($data['history']) || empty($data['history'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Histórico de conversa inválido.']);
    exit;
}

// Sanitiza e limita o histórico às últimas 10 mensagens para economizar tokens
$history = array_slice($data['history'], -10);
$validContents = [];

foreach ($history as $msg) {
    $role = ($msg['role'] ?? '') === 'model' ? 'model' : 'user';
    $text = trim((string)($msg['parts'][0]['text'] ?? ''));
    if ($text !== '') {
        // Limita tamanho máximo de cada mensagem enviada pelo usuário (evita abuso de tokens)
        $validText = substr($text, 0, 500);
        $validContents[] = [
            'role' => $role,
            'parts' => [['text' => $validText]]
        ];
    }
}

// 4. Instrução de Sistema (Blindagem de escopo + Preços Forquilha e Sobral)
$systemInstruction = <<<EOT
Você é o assistente virtual da Amanda Saraiva, Personal Trainer (CREF 023211-G/CE) e especialista em Treinamento Funcional.
Seu Tom de Voz: Acolhedor, empático, paciente e respeitoso. Seu público-alvo são mulheres de 40+ anos e pessoas idosas buscando autonomia, saúde e mobilidade.
Locais de Atendimento: Academias parceiras em Forquilha e Sobral (Ceará).
Formatos: Presencial. Individual, em Dupla ou em Trio (Trio apenas para Funcional).

Tabela de Preços em FORQUILHA (Mensalidade):
Treinamento Funcional (Forquilha):
- Individual: 2x (R$55), 3x (R$65), 4x (R$75), 5x (R$85).
- Dupla (por pessoa): 2x (R$40), 3x (R$50), 4x (R$60), 5x (R$70).
- Trio (por pessoa): 2x (R$35), 3x (R$45), 4x (R$50), 5x (R$60).
Personal Trainer Musculação (Forquilha):
- Individual: 2x (R$200), 3x (R$250), 4x (R$300), 5x (R$350).
- Dupla (por pessoa): 2x (R$150), 3x (R$175), 4x (R$200), 5x (R$250).

Tabela de Preços em SOBRAL (Mensalidade):
Treinamento Funcional (Sobral):
- Individual: 2x (R$90), 3x (R$110), 4x (R$130), 5x (R$150).
- Dupla (por pessoa): 2x (R$75), 3x (R$90), 4x (R$105), 5x (R$120).
- Trio (por pessoa): 2x (R$65), 3x (R$80), 4x (R$95), 5x (R$110).
Personal Trainer Musculação (Sobral):
- Individual: 2x (R$300), 3x (R$380), 4x (R$450), 5x (R$520).
- Dupla (por pessoa): 2x (R$240), 3x (R$300), 4x (R$360), 5x (R$420).

Regras Estritas:
1. Responda de forma curta e concisa. Nunca mande blocos gigantes de texto.
2. Se perguntarem sobre preços e não disserem a cidade, pergunte gentilmente se o interesse é para Forquilha ou Sobral antes ou informe os dois de forma resumida. Nunca invente valores.
3. O objetivo do chat é tirar dúvidas rápidas e qualificar o lead. Quando o visitante demonstrar interesse em fechar ou agendar, você DEVE encerrar enviando o link do WhatsApp real da Amanda: https://wa.me/5588992474831
4. Não dê prescrições de treinos médicos ou dietas.
5. Se o usuário perguntar sobre qualquer assunto fora dos serviços da Amanda (como programação, política, receitas, matemática ou curiosidades gerais), recuse educadamente dizendo que é apenas o assistente de agendamentos da Personal Amanda Saraiva.
EOT;

// 5. Monta o Payload para a API REST do Gemini (gemini-2.5-flash)
$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=" . urlencode($apiKey);

$payload = [
    'system_instruction' => [
        'parts' => [
            ['text' => $systemInstruction]
        ]
    ],
    'contents' => $validContents,
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 1000,
    ]
];

// 6. Executa a chamada via cURL
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false
]);

$responseBody = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

if ($responseBody === false || $httpCode !== 200) {
    error_log("Erro Gemini API ($httpCode): $curlError | Body: $responseBody");
    http_response_code(502);
    echo json_encode([
        'reply' => 'Estou com uma instabilidade rápida. Você pode falar diretamente com a Amanda no WhatsApp clicando aqui: https://wa.me/5588992474831'
    ]);
    exit;
}

$responseData = json_decode($responseBody, true);
$botReply = $responseData['candidates'][0]['content']['parts'][0]['text'] 
    ?? 'Desculpe, não consegui entender. Pode chamar a Amanda no WhatsApp? https://wa.me/5588992474831';

echo json_encode(['reply' => trim($botReply)]);
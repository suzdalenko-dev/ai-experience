<?php

declare(strict_types=1);
set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');

function response_error(int $statusCode, string $message): void{
    http_response_code($statusCode);
    echo json_encode(['error' => $message,], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rawBody = file_get_contents('php://input');

if ($rawBody == false || trim($rawBody) == ''){
    response_error(400, 'No se ha recibido contenido..');
}

$requestData = json_decode($rawBody, true);
if (!is_array($requestData)) {
    response_error(400, 'El contenido recibido no es un JSON válido');
}

$message = $requestData['mensaje'] ?? null;

if (!is_string($message) || trim($message) == '') {
    response_error(400, 'Falta el campo mensaje.');
}

$message    = trim($message);
$configPath = '/var/www/html/ai-config/claude-config.php';
if (!is_readable($configPath)) {
    response_error(500, 'No se puede leer claude-config.php.');
}
try{
    $config = require $configPath;
} catch (\Exception $e){
    response_error(500, $e->getMessage());
}
if(!is_array($config)){
    response_error(500, 'Configuración no valida');
} 

$oauthToken = trim((string) $config['oauth_token']);
$model      = trim((string) $config['model']);
$effort     = trim((string) $config['effort']);
$maxTurns   = (string) $config['max_turns'];

$command = [
    'claude', '-p',
    '--output-format', 'json',
    '--model', $model,
    '--effort', $effort,
    '--max-turns', $maxTurns,
    '--no-session-persistence',
    '--safe-mode',
    '--no-chrome',
    '--permission-mode', '-dontAsk',
    '--disable-slash-commands',
    '--tools', '',
    '--disallowedTools', 'mcp__*',
];

$environment = [
    'HOME' =>
        '/var/www',

    'PATH' =>
        '/usr/local/sbin:/usr/local/bin:'
        . '/usr/sbin:/usr/bin:/sbin:/bin',

    'LANG' =>
        'C.UTF-8',

    'LC_ALL' =>
        'C.UTF-8',

    'USER' =>
        'www-data',

    'LOGNAME' =>
        'www-data',

    /*
     * El token solamente se entrega al proceso mediante
     * una variable de entorno.
     */
    'CLAUDE_CODE_OAUTH_TOKEN' =>
        $oauthToken,

    /*
     * Indicar a Claude que no guarde el historial.
     */
    'CLAUDE_CODE_SKIP_PROMPT_HISTORY' =>
        '1',

    /*
     * Desactivar funciones no necesarias.
     */
    'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' =>
        '1',

    'CLAUDE_CODE_DISABLE_FEEDBACK_SURVEY' =>
        '1',

    'CLAUDE_CODE_AUTO_CONNECT_IDE' =>
        'false',

    'ENABLE_CLAUDEAI_MCP_SERVERS' =>
        'false',

    'DISABLE_TELEMETRY' =>
        '1',

    'DISABLE_ERROR_REPORTING' =>
        '1',

    'DISABLE_UPDATES' =>
        '1',

    /*
     * Evitar logs de npm.
     */
    'NPM_CONFIG_LOGS_MAX' =>
        '0',

    'NPM_CONFIG_LOGLEVEL' =>
        'silent',

    'NPM_CONFIG_UPDATE_NOTIFIER' =>
        'false',
];

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$startTime = microtime(true);

$process = proc_open(
    $command,
    $descriptors,
    $pipes,
    dirname(__DIR__),
    $environment
);

if (!is_resource($process)) {
    response_error(500, 'PHP no pudo ejecutar el comando claude');
}

/*
|--------------------------------------------------------------------------
| 7. ENVIAR LA PREGUNTA A CLAUDE
|--------------------------------------------------------------------------
*/

$written = fwrite($pipes[0], $message);
fclose($pipes[0]);

if ($written === false) {
    proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    response_error(500, 'No se pudo enviar la pregunta a Claude.');
}


/*
|--------------------------------------------------------------------------
| 8. RECOGER RESPUESTA Y ERRORES
|--------------------------------------------------------------------------
*/

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);

fclose($pipes[1]);
fclose($pipes[2]);

$exitCode   = proc_close($process);
$durationMs = (int) round((microtime(true) - $startTime) * 1000);

/*
|--------------------------------------------------------------------------
| 9. COMPROBAR EL RESULTADO DEL COMANDO
|--------------------------------------------------------------------------
*/

if ($exitCode !== 0) {
    $errorDetail = trim((string) $stderr);

    if ($errorDetail === '') {
        $errorDetail = trim((string) $stdout);
    }

    /*
     * Evitar que el token aparezca en el error.
     */
    $errorDetail = str_replace($oauthToken, '[TOKEN_OCULTO]', $errorDetail);
    response_error(500, 'Claude terminó con código '. $exitCode. ': '. substr($errorDetail, 0, 3000));
}


/*
|--------------------------------------------------------------------------
| 10. INTERPRETAR EL JSON DE CLAUDE
|--------------------------------------------------------------------------
*/

$claudeData = json_decode(
    (string) $stdout,
    true
);

if (!is_array($claudeData)) {
    response_error(
        500,
        'Claude no devolvió un JSON válido.'
    );
}

if (!empty($claudeData['is_error'])) {
    response_error(
        500,
        (string) (
            $claudeData['result']
            ?? 'Claude devolvió un error.'
        )
    );
}

$answer = trim(
    (string) (
        $claudeData['result'] ?? ''
    )
);

if ($answer === '') {
    $answer = '(Claude no devolvió texto)';
}

/*
|--------------------------------------------------------------------------
| 11. DEVOLVER LA RESPUESTA AL INDEX.HTML
|--------------------------------------------------------------------------
*/

http_response_code(200);

echo json_encode(
    [
        'respuesta' =>
            $answer,

        'coste' =>
            $claudeData['total_cost_usd'] ?? null,

        'duracionMs' =>
            $durationMs,
    ],
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
);
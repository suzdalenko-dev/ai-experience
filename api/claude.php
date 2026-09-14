<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PASO 2: HTML -> PHP -> CLAUDE -> PHP -> HTML
|--------------------------------------------------------------------------
|
| En este paso:
|
| - Recibimos la pregunta del navegador.
| - PHP ejecuta el comando "claude".
| - La pregunta se introduce por stdin.
| - Claude devuelve JSON por stdout.
| - PHP devuelve la respuesta al navegador.
|
| No guardamos conversaciones ni sesiones.
|
*/

set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');


function responderError(int $statusCode, string $message): void
{
    http_response_code($statusCode);

    echo json_encode(
        [
            'error' => $message,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| 1. VALIDAR PETICIÓN
|--------------------------------------------------------------------------
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responderError(
        405,
        'Este endpoint solamente acepta POST.'
    );
}


/*
|--------------------------------------------------------------------------
| 2. LEER EL JSON DEL FETCH
|--------------------------------------------------------------------------
*/

$rawBody = file_get_contents('php://input');

if (
    $rawBody === false
    || trim($rawBody) === ''
) {
    responderError(
        400,
        'No se ha recibido contenido.'
    );
}

$requestData = json_decode($rawBody, true);

if (!is_array($requestData)) {
    responderError(
        400,
        'El contenido recibido no es un JSON válido.'
    );
}

$message = $requestData['mensaje'] ?? null;

if (
    !is_string($message)
    || trim($message) === ''
) {
    responderError(
        400,
        'Falta el campo mensaje.'
    );
}

$message = trim($message);


/*
|--------------------------------------------------------------------------
| 3. CARGAR CONFIGURACIÓN DE CLAUDE
|--------------------------------------------------------------------------
*/

$configPath =
    '/var/www/html/ai-config/claude-config.php';

if (!is_readable($configPath)) {
    responderError(
        500,
        'No se puede leer claude-config.php.'
    );
}

try {
    $config = require $configPath;
} catch (Throwable $error) {
    responderError(
        500,
        'claude-config.php contiene un error de PHP.'
    );
}

if (!is_array($config)) {
    responderError(
        500,
        'claude-config.php debe devolver un array.'
    );
}

$oauthToken = trim(
    (string) ($config['oauth_token'] ?? '')
);

$model = trim(
    (string) ($config['model'] ?? 'sonnet')
);

$effort = trim(
    (string) ($config['effort'] ?? 'high')
);

$maxTurns = (int) (
    $config['max_turns'] ?? 1
);

if ($oauthToken === '') {
    responderError(
        500,
        'No hay un oauth_token configurado.'
    );
}

$maxTurns = max(
    1,
    min(10, $maxTurns)
);


/*
|--------------------------------------------------------------------------
| 4. CONSTRUIR COMANDO DE CLAUDE
|--------------------------------------------------------------------------
|
| IMPORTANTE:
|
| NO usamos --bare.
|
| --bare no acepta CLAUDE_CODE_OAUTH_TOKEN y provocaría:
|
|   Not logged in · Please run /login
|
| --safe-mode desactiva CLAUDE.md, hooks, skills, plugins,
| MCP, memoria automática, etc., pero mantiene la
| autenticación normal.
|
*/

$command = [
    'claude',
    /*
     * Ejecución no interactiva.
     */
    '-p',

    '--output-format',
    'json',

    '--model',
    $model,

    '--effort',
    $effort,

    '--max-turns',
    (string) $maxTurns,

    /*
     * Nunca persistir sesiones.
     */
    '--no-session-persistence',

    /*
     * Sin integración con Chrome.
     */
    '--no-chrome',

    /*
     * Nunca pedir permisos interactivamente.
     */
    '--permission-mode',
    'dontAsk',

    /*
     * Sin comandos/skills.
     */
    '--disable-slash-commands',

    /*
     * SIN herramientas internas.
     *
     * Claude no podrá:
     * - leer archivos
     * - escribir archivos
     * - editar archivos
     * - ejecutar Bash
     */
    '--tools',
    '',

    /*
     * Bloqueo adicional de TODAS las herramientas,
     * incluidas MCP.
     */
    '--disallowedTools',
    '*',
];

/*
|--------------------------------------------------------------------------
| 5. PREPARAR EL ENTORNO DEL PROCESO
|--------------------------------------------------------------------------
|
| Apache ejecuta PHP como www-data.
| Por tanto, el comando claude también se ejecutará como www-data.
|
*/

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


/*
|--------------------------------------------------------------------------
| 6. CREAR EL PROCESO
|--------------------------------------------------------------------------
|
| Descriptor 0: PHP -> stdin de Claude.
| Descriptor 1: stdout de Claude -> PHP.
| Descriptor 2: errores de Claude -> PHP.
|
*/

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
    responderError(
        500,
        'PHP no pudo ejecutar el comando claude.'
    );
}


/*
|--------------------------------------------------------------------------
| 7. ENVIAR LA PREGUNTA A CLAUDE
|--------------------------------------------------------------------------
*/

$written = fwrite(
    $pipes[0],
    $message
);

fclose($pipes[0]);

if ($written === false) {
    proc_terminate($process);

    fclose($pipes[1]);
    fclose($pipes[2]);

    proc_close($process);

    responderError(
        500,
        'No se pudo enviar la pregunta a Claude.'
    );
}


/*
|--------------------------------------------------------------------------
| 8. RECOGER RESPUESTA Y ERRORES
|--------------------------------------------------------------------------
*/

$stdout = stream_get_contents(
    $pipes[1]
);

$stderr = stream_get_contents(
    $pipes[2]
);

fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($process);

$durationMs = (int) round(
    (microtime(true) - $startTime) * 1000
);


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
    $errorDetail = str_replace(
        $oauthToken,
        '[TOKEN_OCULTO]',
        $errorDetail
    );

    responderError(
        500,
        'Claude terminó con código '
        . $exitCode
        . ': '
        . substr($errorDetail, 0, 3000)
    );
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
    responderError(
        500,
        'Claude no devolvió un JSON válido.'
    );
}

if (!empty($claudeData['is_error'])) {
    responderError(
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
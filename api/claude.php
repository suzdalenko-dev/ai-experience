<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PUENTE PHP -> CLAUDE CODE
|--------------------------------------------------------------------------
|
| Frontend:
|   /var/www/html/ai-experience/index.html
|
| Backend:
|   /var/www/html/ai-experience/api/claude.php
|
| Configuración y datos privados:
|   /var/www/html/ai-config/
|
| Dentro de ai-config se guardarán:
|
|   claude-config.php
|   sessions/sesiones.json
|   conversations/*.jsonl
|   claude-data/
|   logs/chat.log
|   logs/claude-error.log
|   logs/php-error.log
|   locks/
|   home/
|   cache/
|   tmp/
|   work/
|   xdg/
|
*/

set_time_limit(0);
umask(0007);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');


/*
|--------------------------------------------------------------------------
| FUNCIONES GENERALES
|--------------------------------------------------------------------------
*/

function responderError(int $codigo, string $mensaje): void
{
    http_response_code($codigo);

    echo json_encode(
        ['error' => $mensaje],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}


function asegurarDirectorio(string $ruta): void
{
    if (!is_dir($ruta)) {
        if (!mkdir($ruta, 0770, true) && !is_dir($ruta)) {
            responderError(
                500,
                'No se pudo crear un directorio dentro de ai-config.'
            );
        }
    }

    @chmod($ruta, 0770);
}


function registrarLog(
    string $archivo,
    string $nivel,
    string $evento,
    array $datos = []
): void {
    $registro = array_merge(
        [
            'fecha'  => date(DATE_ATOM),
            'nivel'  => $nivel,
            'evento' => $evento,
        ],
        $datos
    );

    $json = json_encode(
        $registro,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json !== false) {
        @file_put_contents(
            $archivo,
            $json . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        @chmod($archivo, 0660);
    }
}


/*
|--------------------------------------------------------------------------
| GESTIÓN DEL MAPA DE SESIONES
|--------------------------------------------------------------------------
*/

function leerSesiones(string $archivo): array
{
    if (!is_file($archivo)) {
        return [];
    }

    $json = file_get_contents($archivo);

    if ($json === false) {
        return [];
    }

    $sesiones = json_decode($json, true);

    return is_array($sesiones) ? $sesiones : [];
}


function obtenerSesion(
    string $archivoSesiones,
    string $archivoBloqueo,
    string $clave
): ?string {
    $bloqueo = fopen($archivoBloqueo, 'c');

    if ($bloqueo === false) {
        responderError(500, 'No se pudo abrir el bloqueo de sesiones.');
    }

    if (!flock($bloqueo, LOCK_SH)) {
        fclose($bloqueo);
        responderError(500, 'No se pudo leer el mapa de sesiones.');
    }

    $sesiones = leerSesiones($archivoSesiones);
    $sessionId = $sesiones[$clave] ?? null;

    flock($bloqueo, LOCK_UN);
    fclose($bloqueo);

    if (!is_string($sessionId) || $sessionId === '') {
        return null;
    }

    return $sessionId;
}


function guardarSesion(
    string $archivoSesiones,
    string $archivoBloqueo,
    string $clave,
    ?string $sessionId
): void {
    $bloqueo = fopen($archivoBloqueo, 'c');

    if ($bloqueo === false) {
        responderError(500, 'No se pudo abrir el bloqueo de sesiones.');
    }

    if (!flock($bloqueo, LOCK_EX)) {
        fclose($bloqueo);
        responderError(500, 'No se pudo modificar el mapa de sesiones.');
    }

    $sesiones = leerSesiones($archivoSesiones);

    if ($sessionId === null || $sessionId === '') {
        unset($sesiones[$clave]);
    } else {
        $sesiones[$clave] = $sessionId;
    }

    $json = json_encode(
        $sesiones,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    if (
        $json === false
        || file_put_contents($archivoSesiones, $json, LOCK_EX) === false
    ) {
        flock($bloqueo, LOCK_UN);
        fclose($bloqueo);

        responderError(500, 'No se pudo guardar el mapa de sesiones.');
    }

    @chmod($archivoSesiones, 0660);

    flock($bloqueo, LOCK_UN);
    fclose($bloqueo);
}


/*
|--------------------------------------------------------------------------
| COPIA DE LAS CONVERSACIONES
|--------------------------------------------------------------------------
*/

function copiarConversacion(
    string $directorioClaude,
    string $directorioConversaciones,
    string $sessionId
): bool {
    /*
     * Evita que un session_id manipulado pueda construir rutas arbitrarias.
     */
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $sessionId)) {
        return false;
    }

    $destino = $directorioConversaciones
        . '/'
        . $sessionId
        . '.jsonl';

    /*
     * Claude guarda normalmente las conversaciones en:
     *
     * claude-data/projects/ai-experience/SESSION_ID.jsonl
     *
     * El comodín mantiene compatibilidad con versiones que calculan
     * automáticamente el nombre del proyecto.
     */
    $candidatos = glob(
        $directorioClaude
        . '/projects/*/'
        . $sessionId
        . '.jsonl'
    ) ?: [];

    foreach ($candidatos as $origen) {
        if (!is_file($origen)) {
            continue;
        }

        if (@copy($origen, $destino)) {
            @chmod($destino, 0660);
            return true;
        }
    }

    return false;
}


/*
|--------------------------------------------------------------------------
| LOCALIZAR APLICACIÓN Y CONFIGURACIÓN EXTERNA
|--------------------------------------------------------------------------
*/

/*
 * Este archivo está en:
 *
 * /var/www/html/ai-experience/api/claude.php
 *
 * dirname(__DIR__) será:
 *
 * /var/www/html/ai-experience
 */
$RAIZ_APLICACION = realpath(dirname(__DIR__));

if ($RAIZ_APLICACION === false) {
    responderError(500, 'No se pudo localizar ai-experience.');
}


/*
 * La configuración está en una carpeta hermana:
 *
 * /var/www/html/ai-config/claude-config.php
 */
$ARCHIVO_CONFIGURACION =
    dirname($RAIZ_APLICACION)
    . '/ai-config/claude-config.php';

if (!is_readable($ARCHIVO_CONFIGURACION)) {
    responderError(
        500,
        'No se encuentra /var/www/html/ai-config/claude-config.php.'
    );
}

$ARCHIVO_CONFIGURACION = realpath($ARCHIVO_CONFIGURACION);

if ($ARCHIVO_CONFIGURACION === false) {
    responderError(500, 'No se pudo resolver claude-config.php.');
}


/*
 * La carpeta donde está claude-config.php será la raíz de absolutamente
 * todos los datos privados controlados por esta aplicación.
 */
$RAIZ_CONFIG = dirname($ARCHIVO_CONFIGURACION);


/*
 * Comprobación adicional: ai-config no puede estar dentro de ai-experience.
 */
$prefijoAplicacion =
    rtrim($RAIZ_APLICACION, DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR;

$prefijoConfig =
    rtrim($RAIZ_CONFIG, DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR;

if (strpos($prefijoConfig, $prefijoAplicacion) === 0) {
    responderError(
        500,
        'ai-config debe estar fuera del repositorio ai-experience.'
    );
}


/*
|--------------------------------------------------------------------------
| DIRECTORIOS PRIVADOS
|--------------------------------------------------------------------------
*/

$DIRECTORIO_SESIONES       = $RAIZ_CONFIG . '/sessions';
$DIRECTORIO_CONVERSACIONES = $RAIZ_CONFIG . '/conversations';
$DIRECTORIO_CLAUDE         = $RAIZ_CONFIG . '/claude-data';
$DIRECTORIO_LOGS           = $RAIZ_CONFIG . '/logs';
$DIRECTORIO_BLOQUEOS       = $RAIZ_CONFIG . '/locks';

$DIRECTORIO_HOME           = $RAIZ_CONFIG . '/home';
$DIRECTORIO_CACHE          = $RAIZ_CONFIG . '/cache';
$DIRECTORIO_CACHE_NPM      = $DIRECTORIO_CACHE . '/npm';
$DIRECTORIO_TEMPORALES     = $RAIZ_CONFIG . '/tmp';
$DIRECTORIO_TRABAJO        = $RAIZ_CONFIG . '/work';

$DIRECTORIO_XDG_CONFIG     = $RAIZ_CONFIG . '/xdg/config';
$DIRECTORIO_XDG_DATA       = $RAIZ_CONFIG . '/xdg/data';
$DIRECTORIO_XDG_STATE      = $RAIZ_CONFIG . '/xdg/state';


$directorios = [
    $DIRECTORIO_SESIONES,
    $DIRECTORIO_CONVERSACIONES,
    $DIRECTORIO_CLAUDE,
    $DIRECTORIO_LOGS,
    $DIRECTORIO_BLOQUEOS,
    $DIRECTORIO_HOME,
    $DIRECTORIO_CACHE,
    $DIRECTORIO_CACHE_NPM,
    $DIRECTORIO_TEMPORALES,
    $DIRECTORIO_TRABAJO,
    $DIRECTORIO_XDG_CONFIG,
    $DIRECTORIO_XDG_DATA,
    $DIRECTORIO_XDG_STATE,
];

foreach ($directorios as $directorio) {
    asegurarDirectorio($directorio);
}


/*
|--------------------------------------------------------------------------
| ARCHIVOS PRIVADOS
|--------------------------------------------------------------------------
*/

$ARCHIVO_SESIONES =
    $DIRECTORIO_SESIONES . '/sesiones.json';

$ARCHIVO_BLOQUEO_SESIONES =
    $DIRECTORIO_BLOQUEOS . '/sesiones.lock';

$ARCHIVO_LOG_CHAT =
    $DIRECTORIO_LOGS . '/chat.log';

$ARCHIVO_LOG_CLAUDE =
    $DIRECTORIO_LOGS . '/claude-error.log';

$ARCHIVO_LOG_PHP =
    $DIRECTORIO_LOGS . '/php-error.log';

$ARCHIVO_ULTIMA_LIMPIEZA =
    $RAIZ_CONFIG . '/ultima_limpieza.txt';


/*
 * Desde este punto, los errores internos de PHP se escriben en ai-config.
 */
ini_set('error_log', $ARCHIVO_LOG_PHP);


/*
|--------------------------------------------------------------------------
| CARGAR claude-config.php
|--------------------------------------------------------------------------
*/

try {
    $configuracion = require $ARCHIVO_CONFIGURACION;
} catch (Throwable $error) {
    registrarLog(
        $ARCHIVO_LOG_CLAUDE,
        'error',
        'configuracion_invalida',
        [
            'detalle' => $error->getMessage(),
        ]
    );

    responderError(
        500,
        'El archivo ai-config/claude-config.php no es válido.'
    );
}

if (!is_array($configuracion)) {
    responderError(
        500,
        'claude-config.php debe devolver un array de configuración.'
    );
}


/*
|--------------------------------------------------------------------------
| VALIDAR CONFIGURACIÓN
|--------------------------------------------------------------------------
*/

$TOKEN = trim(
    (string) ($configuracion['oauth_token'] ?? '')
);

if (
    $TOKEN === ''
    || strpos($TOKEN, 'PEGA_AQUI') !== false
    || strpos($TOKEN, 'REPLACE_WITH') !== false
) {
    responderError(
        500,
        'No se ha configurado un token válido de Claude.'
    );
}


$MODELO = trim(
    (string) ($configuracion['model'] ?? 'sonnet')
);

$ESFUERZO = trim(
    (string) ($configuracion['effort'] ?? 'high')
);

$MAX_TURNOS = (int) (
    $configuracion['max_turns'] ?? 5
);

$MAX_TURNOS = max(1, min(20, $MAX_TURNOS));


$DIAS_LIMPIEZA = (int) (
    $configuracion['retention_days'] ?? 30
);

$DIAS_LIMPIEZA = max(1, min(365, $DIAS_LIMPIEZA));


/*
 * Aunque alguien escriba otras herramientas en claude-config.php,
 * solamente permitimos estas dos.
 */
$HERRAMIENTAS_PERMITIDAS = [
    'WebSearch',
    'WebFetch',
];

$herramientasSolicitadas =
    $configuracion['allowed_tools'] ?? [];

if (!is_array($herramientasSolicitadas)) {
    $herramientasSolicitadas = [];
}

$HERRAMIENTAS = array_values(
    array_intersect(
        $HERRAMIENTAS_PERMITIDAS,
        $herramientasSolicitadas
    )
);

$HERRAMIENTAS_CSV = implode(',', $HERRAMIENTAS);


/*
|--------------------------------------------------------------------------
| ENTORNO AISLADO DE CLAUDE
|--------------------------------------------------------------------------
|
| HOME ya no es /var/www.
|
| CLAUDE_CONFIG_DIR contiene:
|   - configuración interna
|   - conversaciones originales
|   - plugins
|   - datos de proyectos
|
| Cualquier caché o temporal convencional también se redirige a ai-config.
|
*/

$ENTORNO = [
    'HOME' =>
        $DIRECTORIO_HOME,

    'PATH' =>
        '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',

    'LANG' =>
        'C.UTF-8',

    'LC_ALL' =>
        'C.UTF-8',

    'USER' =>
        'www-data',

    'LOGNAME' =>
        'www-data',

    'CLAUDE_CODE_OAUTH_TOKEN' =>
        $TOKEN,

    'CLAUDE_CONFIG_DIR' =>
        $DIRECTORIO_CLAUDE,

    'CLAUDE_CODE_PROJECT_DIR_NAME' =>
        'ai-experience',

    'XDG_CONFIG_HOME' =>
        $DIRECTORIO_XDG_CONFIG,

    'XDG_CACHE_HOME' =>
        $DIRECTORIO_CACHE,

    'XDG_DATA_HOME' =>
        $DIRECTORIO_XDG_DATA,

    'XDG_STATE_HOME' =>
        $DIRECTORIO_XDG_STATE,

    'TMPDIR' =>
        $DIRECTORIO_TEMPORALES,

    'TMP' =>
        $DIRECTORIO_TEMPORALES,

    'TEMP' =>
        $DIRECTORIO_TEMPORALES,

    'NPM_CONFIG_CACHE' =>
        $DIRECTORIO_CACHE_NPM,

    /*
     * Evita que Claude Code realice actualizaciones automáticas,
     * telemetría, informes de error y tráfico auxiliar.
     */
    'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' =>
        '1',

    'DISABLE_UPDATES' =>
        '1',

    'DISABLE_LOGIN_COMMAND' =>
        '1',

    'DISABLE_LOGOUT_COMMAND' =>
        '1',

    /*
     * No cargar automáticamente conectores MCP de claude.ai.
     * Cuando preparemos tu MCP local, se cargará expresamente.
     */
    'ENABLE_CLAUDEAI_MCP_SERVERS' =>
        'false',
];


/*
|--------------------------------------------------------------------------
| LIMPIEZA MENSUAL
|--------------------------------------------------------------------------
|
| En la primera ejecución solamente se crea la marca.
| No se borra nada.
|
| A partir de entonces, cuando hayan pasado los días configurados:
|   - se borran conversaciones originales .jsonl
|   - se borran las copias de conversations/
|   - se borra el mapa de sesiones
|
*/

if (!is_file($ARCHIVO_ULTIMA_LIMPIEZA)) {
    file_put_contents(
        $ARCHIVO_ULTIMA_LIMPIEZA,
        (string) time(),
        LOCK_EX
    );

    @chmod($ARCHIVO_ULTIMA_LIMPIEZA, 0660);
} else {
    $ultimaLimpieza = (int) file_get_contents(
        $ARCHIVO_ULTIMA_LIMPIEZA
    );

    $segundosRetencion =
        $DIAS_LIMPIEZA * 24 * 60 * 60;

    if (time() - $ultimaLimpieza > $segundosRetencion) {
        /*
         * Historiales originales de Claude.
         */
        foreach (
            glob(
                $DIRECTORIO_CLAUDE . '/projects/*/*.jsonl'
            ) ?: []
            as $archivo
        ) {
            @unlink($archivo);
        }

        /*
         * Copias organizadas de las conversaciones.
         */
        foreach (
            glob(
                $DIRECTORIO_CONVERSACIONES . '/*.jsonl'
            ) ?: []
            as $archivo
        ) {
            @unlink($archivo);
        }

        /*
         * Reiniciar mapa IP -> sesión.
         */
        @unlink($ARCHIVO_SESIONES);

        file_put_contents(
            $ARCHIVO_ULTIMA_LIMPIEZA,
            (string) time(),
            LOCK_EX
        );

        @chmod($ARCHIVO_ULTIMA_LIMPIEZA, 0660);

        registrarLog(
            $ARCHIVO_LOG_CHAT,
            'info',
            'limpieza_periodica',
            [
                'dias_retencion' => $DIAS_LIMPIEZA,
            ]
        );
    }
}


/*
|--------------------------------------------------------------------------
| COMPROBAR MÉTODO HTTP
|--------------------------------------------------------------------------
*/

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'POST';

if ($metodo !== 'POST') {
    header('Allow: POST');

    responderError(
        405,
        'Este endpoint solamente acepta peticiones POST.'
    );
}


/*
|--------------------------------------------------------------------------
| LEER ENTRADA JSON
|--------------------------------------------------------------------------
*/

$entradaCruda = file_get_contents('php://input');

if (
    $entradaCruda === false
    || strlen($entradaCruda) > 100000
) {
    responderError(
        400,
        'La petición no es válida o es demasiado grande.'
    );
}

$entrada = json_decode($entradaCruda, true);

if (!is_array($entrada)) {
    responderError(
        400,
        'El cuerpo de la petición debe ser JSON válido.'
    );
}

$mensaje = trim(
    (string) ($entrada['mensaje'] ?? '')
);

$nuevoChat = !empty(
    $entrada['nuevo']
);


/*
|--------------------------------------------------------------------------
| IDENTIFICAR CONVERSACIÓN
|--------------------------------------------------------------------------
|
| Cada dirección IP de la red tiene su propia conversación.
|
*/

$claveCliente =
    $_SERVER['REMOTE_ADDR'] ?? 'desconocido';

$hashCliente =
    hash('sha256', $claveCliente);


/*
 * Evita que dos peticiones simultáneas de la misma máquina
 * ejecuten Claude al mismo tiempo sobre la misma conversación.
 */
$archivoBloqueoCliente =
    $DIRECTORIO_BLOQUEOS
    . '/'
    . $hashCliente
    . '.lock';

$bloqueoCliente = fopen(
    $archivoBloqueoCliente,
    'c'
);

if (
    $bloqueoCliente === false
    || !flock($bloqueoCliente, LOCK_EX)
) {
    responderError(
        500,
        'No se pudo bloquear la conversación.'
    );
}


/*
|--------------------------------------------------------------------------
| NUEVO CHAT
|--------------------------------------------------------------------------
*/

if ($nuevoChat) {
    guardarSesion(
        $ARCHIVO_SESIONES,
        $ARCHIVO_BLOQUEO_SESIONES,
        $claveCliente,
        null
    );

    registrarLog(
        $ARCHIVO_LOG_CHAT,
        'info',
        'nuevo_chat',
        [
            'cliente' => $hashCliente,
        ]
    );

    flock($bloqueoCliente, LOCK_UN);
    fclose($bloqueoCliente);

    echo json_encode(
        ['ok' => true],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}


if ($mensaje === '') {
    flock($bloqueoCliente, LOCK_UN);
    fclose($bloqueoCliente);

    responderError(
        400,
        'Falta el mensaje.'
    );
}


/*
|--------------------------------------------------------------------------
| BUSCAR SESIÓN ANTERIOR
|--------------------------------------------------------------------------
*/

$sesionPrevia = obtenerSesion(
    $ARCHIVO_SESIONES,
    $ARCHIVO_BLOQUEO_SESIONES,
    $claveCliente
);


/*
|--------------------------------------------------------------------------
| CONSTRUIR COMANDO CLAUDE
|--------------------------------------------------------------------------
|
| --tools:
|   restringe las herramientas que realmente existen para Claude.
|
| --allowedTools:
|   preautoriza las herramientas permitidas.
|
| Claude no recibe:
|   Bash
|   Read
|   Write
|   Edit
|
*/

$comando = [
    'claude',
    '-p',

    '--output-format',
    'json',

    '--model',
    $MODELO,

    '--effort',
    $ESFUERZO,

    '--max-turns',
    (string) $MAX_TURNOS,

    '--setting-sources',
    'user',

    '--permission-mode',
    'dontAsk',

    '--disable-slash-commands',

    '--tools',
    $HERRAMIENTAS_CSV,
];

if ($HERRAMIENTAS !== []) {
    $comando[] = '--allowedTools';
    $comando[] = $HERRAMIENTAS_CSV;
}

if ($sesionPrevia !== null) {
    $comando[] = '--resume';
    $comando[] = $sesionPrevia;
}


/*
|--------------------------------------------------------------------------
| EJECUTAR CLAUDE
|--------------------------------------------------------------------------
|
| Claude se ejecuta desde:
|
| /var/www/html/ai-config/work
|
| Por tanto, no utiliza ai-experience como directorio de trabajo.
|
*/

$descriptores = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$inicio = microtime(true);

registrarLog(
    $ARCHIVO_LOG_CHAT,
    'info',
    'consulta_iniciada',
    [
        'cliente'       => $hashCliente,
        'sesion_previa' => $sesionPrevia,
        'modelo'        => $MODELO,
        'esfuerzo'      => $ESFUERZO,
    ]
);

$proceso = proc_open(
    $comando,
    $descriptores,
    $pipes,
    $DIRECTORIO_TRABAJO,
    $ENTORNO
);

if (!is_resource($proceso)) {
    registrarLog(
        $ARCHIVO_LOG_CLAUDE,
        'error',
        'proc_open_fallido'
    );

    flock($bloqueoCliente, LOCK_UN);
    fclose($bloqueoCliente);

    responderError(
        500,
        "No se pudo ejecutar 'claude'."
    );
}


/*
 * El mensaje se envía por STDIN.
 * No se introduce como argumento del comando.
 */
fwrite($pipes[0], $mensaje);
fclose($pipes[0]);


/*
 * PHP queda bloqueado hasta que Claude termina.
 */
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);

fclose($pipes[1]);
fclose($pipes[2]);

$codigoSalida = proc_close($proceso);

$duracionMilisegundos = (int) round(
    (microtime(true) - $inicio) * 1000
);


/*
|--------------------------------------------------------------------------
| CONTROL DE ERRORES DE CLAUDE
|--------------------------------------------------------------------------
*/

if ($codigoSalida !== 0) {
    $detalleError = trim(
        (string) ($stderr !== '' ? $stderr : $stdout)
    );

    registrarLog(
        $ARCHIVO_LOG_CLAUDE,
        'error',
        'claude_codigo_salida',
        [
            'cliente'      => $hashCliente,
            'codigo'       => $codigoSalida,
            'duracion_ms'  => $duracionMilisegundos,
            'detalle'      => $detalleError,
        ]
    );

    /*
     * Si la sesión anterior ya no existe, eliminamos el identificador
     * para que la siguiente petición empiece una conversación nueva.
     */
    if ($sesionPrevia !== null) {
        guardarSesion(
            $ARCHIVO_SESIONES,
            $ARCHIVO_BLOQUEO_SESIONES,
            $claveCliente,
            null
        );
    }

    flock($bloqueoCliente, LOCK_UN);
    fclose($bloqueoCliente);

    responderError(
        500,
        'Claude no pudo completar la solicitud. Revisa ai-config/logs/claude-error.log.'
    );
}


/*
|--------------------------------------------------------------------------
| DECODIFICAR RESPUESTA
|--------------------------------------------------------------------------
*/

$datosClaude = json_decode(
    (string) $stdout,
    true
);

if (!is_array($datosClaude)) {
    registrarLog(
        $ARCHIVO_LOG_CLAUDE,
        'error',
        'respuesta_json_invalida',
        [
            'cliente'     => $hashCliente,
            'duracion_ms' => $duracionMilisegundos,
            'stdout'      => (string) $stdout,
        ]
    );

    flock($bloqueoCliente, LOCK_UN);
    fclose($bloqueoCliente);

    responderError(
        500,
        'Claude devolvió una respuesta no válida.'
    );
}


if (!empty($datosClaude['is_error'])) {
    registrarLog(
        $ARCHIVO_LOG_CLAUDE,
        'error',
        'claude_is_error',
        [
            'cliente'     => $hashCliente,
            'duracion_ms' => $duracionMilisegundos,
            'detalle'     => $datosClaude['result'] ?? null,
        ]
    );

    flock($bloqueoCliente, LOCK_UN);
    fclose($bloqueoCliente);

    responderError(
        500,
        'Claude devolvió un error.'
    );
}


/*
|--------------------------------------------------------------------------
| GUARDAR SESIÓN Y CONVERSACIÓN
|--------------------------------------------------------------------------
*/

$sessionId = isset($datosClaude['session_id'])
    ? (string) $datosClaude['session_id']
    : '';

if ($sessionId !== '') {
    guardarSesion(
        $ARCHIVO_SESIONES,
        $ARCHIVO_BLOQUEO_SESIONES,
        $claveCliente,
        $sessionId
    );

    $conversacionCopiada = copiarConversacion(
        $DIRECTORIO_CLAUDE,
        $DIRECTORIO_CONVERSACIONES,
        $sessionId
    );

    if (!$conversacionCopiada) {
        registrarLog(
            $ARCHIVO_LOG_CLAUDE,
            'warning',
            'conversacion_no_copiada',
            [
                'cliente'   => $hashCliente,
                'sessionId' => $sessionId,
            ]
        );
    }
}


/*
|--------------------------------------------------------------------------
| REGISTRAR RESULTADO
|--------------------------------------------------------------------------
*/

registrarLog(
    $ARCHIVO_LOG_CHAT,
    'info',
    'consulta_completada',
    [
        'cliente'     => $hashCliente,
        'sessionId'   => $sessionId,
        'duracion_ms' => $duracionMilisegundos,
        'coste_usd'   => $datosClaude['total_cost_usd'] ?? null,
    ]
);


/*
|--------------------------------------------------------------------------
| LIBERAR BLOQUEO
|--------------------------------------------------------------------------
*/

flock($bloqueoCliente, LOCK_UN);
fclose($bloqueoCliente);


/*
|--------------------------------------------------------------------------
| RESPONDER AL FRONTEND
|--------------------------------------------------------------------------
*/

echo json_encode(
    [
        'respuesta' =>
            $datosClaude['result'] ?? '(sin respuesta)',

        'chatId' =>
            $claveCliente,

        'sessionId' =>
            $sessionId,

        'coste' =>
            $datosClaude['total_cost_usd'] ?? null,

        'duracionMs' =>
            $duracionMilisegundos,
    ],
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
);
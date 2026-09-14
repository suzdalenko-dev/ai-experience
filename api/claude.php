<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HTML -> PHP -> CLAUDE -> PHP -> HTML
|--------------------------------------------------------------------------
|
| OBJETIVO:
|
| - Recibir una pregunta del navegador.
| - Ejecutar Claude Code mediante PHP.
| - NO guardar conversaciones.
| - NO guardar sesiones de Claude.
| - NO guardar historial de Claude.
| - NO permitir herramientas Read / Write / Bash / Edit.
| - Mantener solamente una memoria MUY pequeña por IP.
|
| Persistencia permitida:
|
| /var/www/html/ai-config/storage/
| └── 192-168-1-131/
|     └── memory.txt
|
| memory.txt:
|
| - máximo 300 caracteres
| - solo información estable y útil
| - nunca conversaciones completas
|
*/


set_time_limit(0);

header(
    'Content-Type: application/json; charset=utf-8'
);


/*
|--------------------------------------------------------------------------
| CONFIGURACIÓN DE MEMORIA
|--------------------------------------------------------------------------
*/

const STORAGE_PATH =
    '/var/www/html/ai-config/storage';

/*
 * Límite DURO de memoria persistente por IP.
 *
 * 7777 caracteres obliga a Claude a conservar
 * solamente lo verdaderamente importante.
 */
const MAX_MEMORY_LENGTH = 7777;


/*
|--------------------------------------------------------------------------
| FUNCIONES
|--------------------------------------------------------------------------
*/

function responderError(
    int $statusCode,
    string $message
): void {
    http_response_code(
        $statusCode
    );

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
 * Obtener la IP directamente de Apache.
 *
 * NO usamos X-Forwarded-For deliberadamente.
 *
 * De esta manera un cliente no puede simplemente
 * enviar una cabecera HTTP falsa para intentar
 * acceder a la memoria de otra IP.
 */
function obtenerIpCliente(): string
{
    $ip = trim(
        (string) (
            $_SERVER['REMOTE_ADDR']
            ?? ''
        )
    );


    /*
     * IPv4 representada como IPv6.
     *
     * Ejemplo:
     *
     * ::ffff:192.168.1.131
     *
     * pasa a:
     *
     * 192.168.1.131
     */
    if (
        str_starts_with(
            $ip,
            '::ffff:'
        )
    ) {
        $ip = substr(
            $ip,
            7
        );
    }


    if (
        filter_var(
            $ip,
            FILTER_VALIDATE_IP
        ) === false
    ) {
        responderError(
            500,
            'No se pudo determinar una IP válida.'
        );
    }


    return $ip;
}


/*
 * Convertir una IP en nombre seguro de carpeta.
 *
 * 192.168.1.131
 *
 * ->
 *
 * 192-168-1-131
 */
function convertirIpEnDirectorio(
    string $ip
): string {
    return str_replace(
        [
            '.',
            ':',
        ],
        '-',
        $ip
    );
}


/*
 * La memoria siempre:
 *
 * - ocupa una sola línea
 * - no tiene espacios repetidos
 * - tiene como máximo MAX_MEMORY_LENGTH caracteres
 */
function normalizarMemoria(
    string $memory
): string {
    $memory = trim(
        $memory
    );


    $memory =
        preg_replace(
            '/\s+/u',
            ' ',
            $memory
        )
        ?? $memory;


    if (
        function_exists(
            'mb_substr'
        )
    ) {
        return mb_substr(
            $memory,
            0,
            MAX_MEMORY_LENGTH,
            'UTF-8'
        );
    }


    return substr(
        $memory,
        0,
        MAX_MEMORY_LENGTH
    );
}


/*
 * Eliminar recursivamente un directorio TEMPORAL.
 *
 * Esta función solamente se utilizará sobre /dev/shm.
 *
 * Nunca sobre ai-config/storage.
 */
function eliminarDirectorioRecursivo(
    string $directory
): void {
    if (
        $directory === ''
        || !is_dir($directory)
    ) {
        return;
    }


    $files = scandir(
        $directory
    );


    if ($files === false) {
        return;
    }


    foreach ($files as $file) {
        if (
            $file === '.'
            || $file === '..'
        ) {
            continue;
        }


        $path =
            $directory
            . DIRECTORY_SEPARATOR
            . $file;


        if (
            is_dir($path)
            && !is_link($path)
        ) {
            eliminarDirectorioRecursivo(
                $path
            );

            continue;
        }


        @unlink(
            $path
        );
    }


    @rmdir(
        $directory
    );
}


/*
 * Crear un directorio privado.
 */
function crearDirectorioPrivado(
    string $directory
): void {
    if (
        is_dir($directory)
    ) {
        return;
    }


    if (
        !mkdir(
            $directory,
            0777,
            true
        )
        && !is_dir($directory)
    ) {
        responderError(
            500,
            'No se pudo crear el directorio temporal necesario.'
        );
    }


    @chmod(
        $directory,
        0777
    );
}


/*
|--------------------------------------------------------------------------
| SEGURIDAD DE PERMISOS
|--------------------------------------------------------------------------
|
| Todo fichero creado por PHP tendrá permisos privados
| salvo que indiquemos expresamente otra cosa.
|
*/

umask(
    0077
);


/*
|--------------------------------------------------------------------------
| 1. VALIDAR PETICIÓN
|--------------------------------------------------------------------------
*/

if (
    ($_SERVER['REQUEST_METHOD'] ?? '')
    !== 'POST'
) {
    responderError(
        405,
        'Este endpoint solamente acepta POST.'
    );
}


/*
|--------------------------------------------------------------------------
| 2. LEER JSON DEL NAVEGADOR
|--------------------------------------------------------------------------
*/

$rawBody =
    file_get_contents(
        'php://input'
    );


if (
    $rawBody === false
    || trim($rawBody) === ''
) {
    responderError(
        400,
        'No se ha recibido contenido.'
    );
}


$requestData =
    json_decode(
        $rawBody,
        true
    );


if (
    !is_array(
        $requestData
    )
) {
    responderError(
        400,
        'El contenido recibido no es un JSON válido.'
    );
}


$message =
    $requestData['mensaje']
    ?? null;


if (
    !is_string($message)
    || trim($message) === ''
) {
    responderError(
        400,
        'Falta el campo mensaje.'
    );
}


$message =
    trim(
        $message
    );


/*
|--------------------------------------------------------------------------
| 3. IDENTIFICAR AL USUARIO POR IP
|--------------------------------------------------------------------------
*/

$clientIp =
    obtenerIpCliente();


$ipDirectoryName =
    convertirIpEnDirectorio(
        $clientIp
    );


$userStorageDirectory =
    STORAGE_PATH
    . '/'
    . $ipDirectoryName;


$memoryPath =
    $userStorageDirectory
    . '/memory.txt';


/*
|--------------------------------------------------------------------------
| 4. LEER MEMORIA MÍNIMA ACTUAL
|--------------------------------------------------------------------------
|
| IMPORTANTE:
|
| Aquí NO creamos todavía ninguna carpeta.
|
| Si este usuario nunca tiene nada importante que recordar,
| no se guardará absolutamente ningún fichero.
|
*/

$currentMemory = '';


if (
    is_file($memoryPath)
    && is_readable($memoryPath)
) {
    $memoryContent =
        file_get_contents(
            $memoryPath
        );


    if (
        $memoryContent !== false
    ) {
        $currentMemory =
            normalizarMemoria(
                $memoryContent
            );
    }
}


/*
|--------------------------------------------------------------------------
| 5. CARGAR CONFIGURACIÓN GENERAL DE CLAUDE
|--------------------------------------------------------------------------
*/

$configPath =
    '/var/www/html/ai-config/claude-config.php';


if (
    !is_readable(
        $configPath
    )
) {
    responderError(
        500,
        'No se puede leer claude-config.php.'
    );
}


try {
    $config =
        require $configPath;
} catch (Throwable $error) {
    responderError(
        500,
        'claude-config.php contiene un error de PHP.'
    );
}


if (
    !is_array($config)
) {
    responderError(
        500,
        'claude-config.php debe devolver un array.'
    );
}


$oauthToken =
    trim(
        (string) (
            $config['oauth_token']
            ?? ''
        )
    );


$model =
    trim(
        (string) (
            $config['model']
            ?? 'sonnet'
        )
    );


$effort =
    trim(
        (string) (
            $config['effort']
            ?? 'high'
        )
    );


$maxTurns =
    (int) (
        $config['max_turns']
        ?? 1
    );


if (
    $oauthToken === ''
) {
    responderError(
        500,
        'No hay un oauth_token configurado.'
    );
}


$maxTurns =
    max(
        1,
        min(
            11,
            $maxTurns
        )
    );


/*
|--------------------------------------------------------------------------
| 6. CREAR PROMPT CON MEMORIA MÍNIMA
|--------------------------------------------------------------------------
|
| Claude NO controla directamente ningún fichero.
|
| PHP:
|
| - lee memory.txt
| - entrega esa memoria a Claude
| - Claude propone la memoria actualizada
| - PHP decide si escribirla
|
*/

$memoryContext =
    $currentMemory !== ''
        ? $currentMemory
        : '(sin memoria)';


$prompt = <<<PROMPT
Eres un asistente conversacional.

Existe una memoria persistente MUY LIMITADA controlada exclusivamente por el servidor.

MEMORIA ACTUAL:
{$memoryContext}

MENSAJE ACTUAL DEL USUARIO:
{$message}

REGLAS ESTRICTAS DE MEMORIA:

1. Conserva únicamente información estable y realmente útil para conversaciones futuras.

2. La memoria completa debe ser extremadamente breve.

3. Máximo 7777 caracteres en total.

4. Ejemplos de información que SÍ puede merecer memoria:
   - nombre del usuario;
   - empresa habitual;
   - idioma preferido;
   - una preferencia estable realmente importante.

5. NO guardes:
   - conversaciones;
   - preguntas;
   - respuestas;
   - saludos;
   - información temporal;
   - información irrelevante;
   - explicaciones;
   - logs;
   - fechas salvo que sean imprescindibles;
   - contraseñas;
   - tokens;
   - API keys;
   - secretos;
   - credenciales.

6. Si no existe ninguna información nueva realmente importante,
   devuelve EXACTAMENTE la memoria actual.

7. Si el usuario corrige un dato ya existente,
   sustituye el dato anterior.

8. Si el usuario pide olvidar un dato,
   elimínalo de la memoria.

9. Si el usuario pide borrar toda la memoria,
   devuelve memory como cadena vacía.

10. Nunca intentes usar herramientas para guardar memoria.

11. Nunca intentes usar Read, Write, Edit, Bash o herramientas similares.

12. No expliques internamente el funcionamiento de la memoria salvo que el usuario lo pregunte.

RESPONDE EXCLUSIVAMENTE CON JSON VÁLIDO.

No uses Markdown.
No uses bloques ```.

Formato obligatorio:

{"answer":"respuesta normal para el usuario","memory":"memoria persistente completa y mínima"}
PROMPT;


/*
|--------------------------------------------------------------------------
| 7. CREAR ENTORNO TEMPORAL EN RAM
|--------------------------------------------------------------------------
|
| IMPORTANTE:
|
| Claude NO utilizará:
|
| /var/www/.claude
|
| ni ninguna HOME persistente.
|
| Toda su configuración interna estará en:
|
| /dev/shm/...
|
| /dev/shm es memoria RAM.
|
| Además usamos un directorio diferente para CADA petición.
|
*/

try {
    $requestId =
        bin2hex(
            random_bytes(12)
        );
} catch (Throwable $error) {
    responderError(
        500,
        'No se pudo crear el entorno temporal de Claude.'
    );
}


$runtimeRoot =
    '/dev/shm/claude-php-'
    . $requestId;


$runtimeHome =
    $runtimeRoot
    . '/home';


$runtimeConfig =
    $runtimeRoot
    . '/config';


$runtimeWork =
    $runtimeRoot
    . '/work';


$runtimeTmp =
    $runtimeRoot
    . '/tmp';


$runtimeCache =
    $runtimeRoot
    . '/cache';


$runtimeXdgConfig =
    $runtimeRoot
    . '/xdg-config';


$runtimeXdgData =
    $runtimeRoot
    . '/xdg-data';


$runtimeXdgState =
    $runtimeRoot
    . '/xdg-state';


crearDirectorioPrivado(
    $runtimeHome
);

crearDirectorioPrivado(
    $runtimeConfig
);

crearDirectorioPrivado(
    $runtimeWork
);

crearDirectorioPrivado(
    $runtimeTmp
);

crearDirectorioPrivado(
    $runtimeCache
);

crearDirectorioPrivado(
    $runtimeXdgConfig
);

crearDirectorioPrivado(
    $runtimeXdgData
);

crearDirectorioPrivado(
    $runtimeXdgState
);


/*
 * Incluso si PHP termina mediante exit,
 * intentaremos eliminar todo el entorno RAM.
 */
register_shutdown_function(
    static function () use (
        $runtimeRoot
    ): void {
        eliminarDirectorioRecursivo(
            $runtimeRoot
        );
    }
);


/*
|--------------------------------------------------------------------------
| 8. CONSTRUIR COMANDO DE CLAUDE
|--------------------------------------------------------------------------
|
| Claude:
|
| - no persiste sesiones;
| - no dispone de herramientas;
| - no puede usar Read;
| - no puede usar Write;
| - no puede usar Edit;
| - no puede usar Bash;
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
     * Nunca guardar sesiones.
     */
    '--no-session-persistence',

    /*
     * No integración con Chrome.
     */
    '--no-chrome',

    /*
     * Nunca solicitar permisos.
     */
    '--permission-mode',
    'dontAsk',

    /*
     * Sin slash commands.
     */
    '--disable-slash-commands',

    /*
     * CERO herramientas.
     */
    '--tools',
    '',

    /*
     * Defensa adicional:
     * bloquear cualquier herramienta.
     */
    '--disallowedTools',
    '*',
];


/*
|--------------------------------------------------------------------------
| 9. PREPARAR ENTORNO DEL PROCESO
|--------------------------------------------------------------------------
*/

$environment = [
    /*
     * HOME temporal en RAM.
     */
    'HOME' =>
        $runtimeHome,

    /*
     * Directorio interno de Claude temporal.
     */
    'CLAUDE_CONFIG_DIR' =>
        $runtimeConfig,

    /*
     * Temporales en RAM.
     */
    'TMPDIR' =>
        $runtimeTmp,

    'CLAUDE_CODE_TMPDIR' =>
        $runtimeTmp,

    /*
     * Cachés XDG también en RAM.
     */
    'XDG_CONFIG_HOME' =>
        $runtimeXdgConfig,

    'XDG_CACHE_HOME' =>
        $runtimeCache,

    'XDG_DATA_HOME' =>
        $runtimeXdgData,

    'XDG_STATE_HOME' =>
        $runtimeXdgState,

    /*
     * PATH necesario para encontrar claude/node/etc.
     */
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
     * Token OAuth.
     */
    'CLAUDE_CODE_OAUTH_TOKEN' =>
        $oauthToken,

    /*
     * No guardar historial de prompts.
     */
    'CLAUDE_CODE_SKIP_PROMPT_HISTORY' =>
        '1',

    /*
     * Desactivar memoria automática propia de Claude.
     *
     * Nuestra única memoria persistente es memory.txt.
     */
    'CLAUDE_CODE_DISABLE_AUTO_MEMORY' =>
        '1',

    /*
     * Reducir tráfico y funciones auxiliares.
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
     * npm tampoco debe utilizar almacenamiento persistente.
     */
    'NPM_CONFIG_CACHE' =>
        $runtimeCache,

    'NPM_CONFIG_LOGS_MAX' =>
        '0',

    'NPM_CONFIG_LOGLEVEL' =>
        'silent',

    'NPM_CONFIG_UPDATE_NOTIFIER' =>
        'false',
];


/*
|--------------------------------------------------------------------------
| 10. CREAR PROCESO
|--------------------------------------------------------------------------
*/

$descriptors = [
    0 => [
        'pipe',
        'r',
    ],

    1 => [
        'pipe',
        'w',
    ],

    2 => [
        'pipe',
        'w',
    ],
];


$startTime =
    microtime(
        true
    );


/*
 * IMPORTANTE:
 *
 * Claude se ejecuta desde un directorio vacío en RAM.
 *
 * NO desde:
 *
 * /var/www/html/ai-experience
 *
 * De esta forma tampoco toma ese proyecto como su CWD.
 */
$process =
    proc_open(
        $command,
        $descriptors,
        $pipes,
        $runtimeWork,
        $environment
    );


if (
    !is_resource(
        $process
    )
) {
    responderError(
        500,
        'PHP no pudo ejecutar el comando claude.'
    );
}


/*
|--------------------------------------------------------------------------
| 11. ENVIAR PREGUNTA + MEMORIA A CLAUDE
|--------------------------------------------------------------------------
*/

$written =
    fwrite(
        $pipes[0],
        $prompt
    );


fclose(
    $pipes[0]
);


if (
    $written === false
) {
    proc_terminate(
        $process
    );

    fclose(
        $pipes[1]
    );

    fclose(
        $pipes[2]
    );

    proc_close(
        $process
    );

    responderError(
        500,
        'No se pudo enviar la pregunta a Claude.'
    );
}


/*
|--------------------------------------------------------------------------
| 12. RECOGER RESPUESTA
|--------------------------------------------------------------------------
*/

$stdout =
    stream_get_contents(
        $pipes[1]
    );


$stderr =
    stream_get_contents(
        $pipes[2]
    );


fclose(
    $pipes[1]
);

fclose(
    $pipes[2]
);


$exitCode =
    proc_close(
        $process
    );


$durationMs =
    (int) round(
        (
            microtime(true)
            - $startTime
        )
        * 1000
    );


/*
|--------------------------------------------------------------------------
| 13. COMPROBAR RESULTADO DE CLAUDE
|--------------------------------------------------------------------------
*/

if (
    $exitCode !== 0
) {
    $errorDetail =
        trim(
            (string) $stderr
        );


    if (
        $errorDetail === ''
    ) {
        $errorDetail =
            trim(
                (string) $stdout
            );
    }


    /*
     * Nunca devolver accidentalmente el token.
     */
    $errorDetail =
        str_replace(
            $oauthToken,
            '[TOKEN_OCULTO]',
            $errorDetail
        );


    responderError(
        500,
        'Claude terminó con código '
        . $exitCode
        . ': '
        . substr(
            $errorDetail,
            0,
            3000
        )
    );
}


/*
|--------------------------------------------------------------------------
| 14. INTERPRETAR JSON EXTERIOR DE CLAUDE CODE
|--------------------------------------------------------------------------
*/

$claudeData =
    json_decode(
        (string) $stdout,
        true
    );


if (
    !is_array(
        $claudeData
    )
) {
    responderError(
        500,
        'Claude no devolvió un JSON válido.'
    );
}


if (
    !empty(
        $claudeData['is_error']
    )
) {
    responderError(
        500,
        (string) (
            $claudeData['result']
            ?? 'Claude devolvió un error.'
        )
    );
}


/*
|--------------------------------------------------------------------------
| 15. EXTRAER RESULTADO DEL MODELO
|--------------------------------------------------------------------------
*/

$rawResult =
    trim(
        (string) (
            $claudeData['result']
            ?? ''
        )
    );


/*
 * Defensa por si alguna vez Claude devuelve:
 *
 * ```json
 * {...}
 * ```
 *
 * aunque le hemos indicado que no lo haga.
 */
if (
    str_starts_with(
        $rawResult,
        '```'
    )
) {
    $rawResult =
        preg_replace(
            '/^```(?:json)?\s*/i',
            '',
            $rawResult
        )
        ?? $rawResult;


    $rawResult =
        preg_replace(
            '/\s*```$/',
            '',
            $rawResult
        )
        ?? $rawResult;


    $rawResult =
        trim(
            $rawResult
        );
}


/*
|--------------------------------------------------------------------------
| 16. INTERPRETAR RESPUESTA + MEMORIA
|--------------------------------------------------------------------------
*/

$assistantData =
    json_decode(
        $rawResult,
        true
    );


if (
    is_array($assistantData)
    && isset($assistantData['answer'])
    && is_string($assistantData['answer'])
    && array_key_exists(
        'memory',
        $assistantData
    )
    && is_string(
        $assistantData['memory']
    )
) {
    /*
     * Respuesta visible.
     */
    $answer =
        trim(
            $assistantData['answer']
        );


    if (
        $answer === ''
    ) {
        $answer =
            '(Claude no devolvió texto)';
    }


    /*
     * Memoria propuesta.
     */
    $newMemory =
        normalizarMemoria(
            $assistantData['memory']
        );


    /*
     * Solamente escribir en disco si
     * realmente ha cambiado.
     */
    if (
        $newMemory
        !== $currentMemory
    ) {
        /*
         * Si hay algo que recordar.
         */
        if (
            $newMemory !== ''
        ) {
            /*
             * Crear STORAGE solamente cuando haga falta.
             */
            if (
                !is_dir(
                    STORAGE_PATH
                )
            ) {
                if (
                    !mkdir(
                        STORAGE_PATH,
                        0777,
                        true
                    )
                    && !is_dir(
                        STORAGE_PATH
                    )
                ) {
                    responderError(
                        500,
                        'No se pudo crear ai-config/storage.'
                    );
                }


                @chmod(
                    STORAGE_PATH,
                    0777
                );
            }


            /*
             * Crear carpeta de esta IP.
             */
            if (
                !is_dir(
                    $userStorageDirectory
                )
            ) {
                if (
                    !mkdir(
                        $userStorageDirectory,
                        0777,
                        true
                    )
                    && !is_dir(
                        $userStorageDirectory
                    )
                ) {
                    responderError(
                        500,
                        'No se pudo crear la memoria del usuario.'
                    );
                }


                @chmod(
                    $userStorageDirectory,
                    0777
                );
            }


            /*
             * ÚNICO fichero persistente por IP.
             */
            $writtenMemory =
                file_put_contents(
                    $memoryPath,
                    $newMemory,
                    LOCK_EX
                );


            if (
                $writtenMemory === false
            ) {
                responderError(
                    500,
                    'No se pudo guardar la memoria del usuario.'
                );
            }


            @chmod(
                $memoryPath,
                0666
            );
        } else {
            /*
             * Si la nueva memoria está vacía,
             * eliminar memory.txt.
             */
            if (
                is_file(
                    $memoryPath
                )
            ) {
                @unlink(
                    $memoryPath
                );
            }


            /*
             * Si la carpeta de la IP queda vacía,
             * eliminarla también.
             */
            if (
                is_dir(
                    $userStorageDirectory
                )
            ) {
                @rmdir(
                    $userStorageDirectory
                );
            }
        }
    }
} else {
    /*
     * MUY IMPORTANTE:
     *
     * Si Claude no devuelve correctamente nuestro JSON:
     *
     * - mostramos lo que haya respondido;
     * - NO escribimos absolutamente nada en memoria.
     *
     * Fail-safe.
     */
    $answer =
        $rawResult !== ''
            ? $rawResult
            : '(Claude no devolvió texto)';
}


/*
|--------------------------------------------------------------------------
| 17. ELIMINAR YA EL ENTORNO TEMPORAL
|--------------------------------------------------------------------------
|
| register_shutdown_function también lo hará,
| pero lo eliminamos cuanto antes.
|
*/

eliminarDirectorioRecursivo(
    $runtimeRoot
);


/*
|--------------------------------------------------------------------------
| 18. DEVOLVER RESPUESTA AL NAVEGADOR
|--------------------------------------------------------------------------
*/

http_response_code(
    200
);


echo json_encode(
    [
        'respuesta' =>
            $answer,

        'coste' =>
            $claudeData['total_cost_usd']
            ?? null,

        'duracionMs' =>
            $durationMs,

        /*
         * Útil mientras estás probándolo.
         *
         * Puedes eliminar estos dos campos después.
         */
        'ip' =>
            $clientIp,

        'memoriaCaracteres' =>
            function_exists(
                'mb_strlen'
            )
                ? mb_strlen(
                    $currentMemory,
                    'UTF-8'
                )
                : strlen(
                    $currentMemory
                ),
    ],
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
);
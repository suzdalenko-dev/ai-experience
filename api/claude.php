<?php
// claude.php — Puente PHP entre la web y Claude Code (para Apache)
// Ubicación: /var/www/html/api/chat-claude/claude.php
//
// Modelo clásico de PHP: cada petición nace, ejecuta claude (bloqueante),
// guarda la sesión en disco y muere.
//
// Las sesiones se guardan en sesiones.json, con la IP del cliente como
// clave: cada máquina de la red tiene su propia conversación.
//
// LIMPIEZA MENSUAL: cada 30 días se borran todos los historiales de
// conversación (.jsonl) y el mapa de sesiones. Las credenciales de
// login (/var/www/.claude/.credentials.json) NO se tocan.
/*
¡LO TIENES! "Long-lived authentication token created successfully — valid for 1 year". Enhorabuena, esta era la última batalla.
A tu pregunta: dura 1 año, y cuando caduque pasa esto: el chat empezará a devolver el mismo 401 de siempre ("Invalid bearer token"), sin avisos previos ni nada roto — solo la llave vencida. La solución será exactamente lo que acabas de hacer, que ya te sabes de memoria: claude setup-token → enlace → autorizar en el navegador → código → nuevo token → pegarlo en el $ENTORNO del claude.php. Cinco minutos, una vez al año. Apúntate un recordatorio en el calendario para julio de 2027 y listo. (Nada más se pierde: los historiales y sesiones no dependen del token.)
*/

set_time_limit(0); // sin límite de tiempo para esta petición
header('Content-Type: application/json; charset=utf-8');

$CARPETA_HISTORY  = __DIR__ . '/history';
@mkdir($CARPETA_HISTORY, 0755, true);

$ARCHIVO_SESIONES = $CARPETA_HISTORY . '/sesiones.json';
$CLAUDE_CWD       = __DIR__;

// IMPORTANTE (Apache): PHP corre como el usuario www-data, y claude busca
// sus credenciales en $HOME/.claude. El HOME de www-data es /var/www.
$ENTORNO = [
    'HOME' => '/var/www',
    'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    'CLAUDE_CODE_OAUTH_TOKEN' => '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',
];



// Carpeta donde claude guarda los historiales de ESTE proyecto:
// la ruta del cwd con las barras convertidas en guiones.
$CARPETA_HISTORIALES = $ENTORNO['HOME'] . '/.claude/projects/' . str_replace('/', '-', $CLAUDE_CWD);

// ---------- limpieza mensual ("cron del pobre") ----------
// En cada petición miramos si han pasado 30 días desde la última limpieza.
// Si sí: borramos historiales + mapa de sesiones (siempre juntos, para no
// dejar session_ids apuntando a archivos que ya no existen).

$MARCA_LIMPIEZA = $CARPETA_HISTORY . '/ultima_limpieza.txt';
$DIAS_LIMPIEZA  = 30;

$ultimaLimpieza = (int) @file_get_contents($MARCA_LIMPIEZA);
if (time() - $ultimaLimpieza > $DIAS_LIMPIEZA * 24 * 3600) {
    // 1. Borrar historiales de la carpeta interna de Claude
    foreach (glob($CARPETA_HISTORIALES . '/*.jsonl') ?: [] as $archivo) {
        @unlink($archivo);
    }
    // 2. Borrar copias locales en history/
    foreach (glob($CARPETA_HISTORY . '/*.jsonl') ?: [] as $archivo) {
        @unlink($archivo);
    }
    // 3. Borrar el mapa IP -> session_id
    @unlink($ARCHIVO_SESIONES);
    file_put_contents($MARCA_LIMPIEZA, time(), LOCK_EX);
}

// ---------- utilidades de persistencia (esto sustituye al Map de Node) ----------

function leerSesiones(string $ruta): array {
    if (!file_exists($ruta)) return [];
    $json  = file_get_contents($ruta);
    $datos = json_decode($json, true);
    return is_array($datos) ? $datos : [];
}

function guardarSesiones(string $ruta, array $sesiones): void {
    // LOCK_EX: bloqueo exclusivo para que dos peticiones simultáneas
    // no escriban el archivo a la vez y lo corrompan
    file_put_contents($ruta, json_encode($sesiones, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function responderError(int $codigo, string $mensaje): void {
    http_response_code($codigo);
    echo json_encode(['error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- entrada ----------

$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$mensaje = trim($entrada['mensaje'] ?? '');
$nuevo   = !empty($entrada['nuevo']);

// La clave de sesión es la IP del cliente: cada host, su conversación
$clave = $_SERVER['REMOTE_ADDR'] ?? 'desconocido';

// "Nuevo chat": borramos la sesión guardada de esta IP y listo
if ($nuevo) {
    $sesiones = leerSesiones($ARCHIVO_SESIONES);
    unset($sesiones[$clave]);
    guardarSesiones($ARCHIVO_SESIONES, $sesiones);
    echo json_encode(['ok' => true]);
    exit;
}

if ($mensaje === '') {
    responderError(400, 'Falta el mensaje');
}

// ---------- ejecutar claude ----------

$sesiones     = leerSesiones($ARCHIVO_SESIONES);
$sesionPrevia = $sesiones[$clave] ?? null;

$MODELO   = 'opus';     # haiku | sonnet | opus
$ESFUERZO = 'max';      # `min`, `low`, `medium`, `high`, `max`
 
// El prompt va por stdin (no como argumento): sin problemas de escapado.
// --allowedTools: en headless no hay humano a quien pedir permiso, así que
// preautorizamos SOLO búsqueda web (nunca Bash/Write/Edit en un chat de red).
$cmd = [
    'claude',
    '-p',
    '--output-format', 'json',
    '--model', $MODELO,
    '--effort', $ESFUERZO,
    '--max-turns', '5',
    '--allowedTools', 'WebSearch,WebFetch'
];


if ($sesionPrevia) {
    $cmd[] = '--resume';
    $cmd[] = $sesionPrevia;
}

$descriptores = [
    0 => ['pipe', 'r'], // stdin
    1 => ['pipe', 'w'], // stdout
    2 => ['pipe', 'w'], // stderr
];

$proceso = proc_open($cmd, $descriptores, $pipes, $CLAUDE_CWD, $ENTORNO);
if (!is_resource($proceso)) {
    responderError(500, "No se pudo ejecutar 'claude'. ¿Está instalado y en el PATH?");
}

fwrite($pipes[0], $mensaje);
fclose($pipes[0]);

// Aquí PHP se queda BLOQUEADO esperando a claude (el camarero parado
// frente a la cocina). Es el modelo clásico: simple y funciona.
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$codigo = proc_close($proceso);

if ($codigo !== 0) {
    // Caso especial: la sesión previa fue borrada por la limpieza mensual o
    // ya no existe. Reintentamos UNA vez sin --resume (conversación nueva).
    if ($sesionPrevia) {
        $sesiones = leerSesiones($ARCHIVO_SESIONES);
        unset($sesiones[$clave]);
        guardarSesiones($ARCHIVO_SESIONES, $sesiones);
        responderError(500, "La sesión anterior ya no existe (posible limpieza mensual). Vuelve a enviar el mensaje: empezará una conversación nueva.");
    }
    responderError(500, "claude terminó con código $codigo: " . ($stderr ?: $stdout));
}

$data = json_decode($stdout, true);
if (!is_array($data)) {
    responderError(500, 'No se pudo parsear la respuesta: ' . $stdout);
}

if (!empty($data['is_error'])) {
    responderError(500, $data['result'] ?? 'claude devolvió un error');
}

// ---------- guardar sesión y responder ----------

// Releemos antes de guardar: otra petición pudo escribir mientras esperábamos
$sesiones = leerSesiones($ARCHIVO_SESIONES);
if (!empty($data['session_id'])) {
    $sesiones[$clave] = $data['session_id'];
    guardarSesiones($ARCHIVO_SESIONES, $sesiones);
}

echo json_encode([
    'respuesta' => $data['result'] ?? '(sin respuesta)',
    'chatId'    => $clave,
    'coste'     => $data['total_cost_usd'] ?? null,
], JSON_UNESCAPED_UNICODE);
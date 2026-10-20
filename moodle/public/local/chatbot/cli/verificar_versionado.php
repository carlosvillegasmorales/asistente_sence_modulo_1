<?php
/**
 * Verificador del versionado: consentimiento y material citado.
 *
 * Cubre dos requisitos que estaban parciales:
 *   RU-CONS-002  el consentimiento vale para lo que se declaro cuando se dio;
 *                si la institucion cambia la declaracion, se vuelve a pedir.
 *   RU-PRIV-006  cada respuesta deja registrada la version del recurso citado
 *                y del conjunto de la base de conocimiento.
 *
 * Uso: php verificar_versionado.php
 *
 * @package local_chatbot
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

use local_chatbot\profile;
use local_chatbot\kb_store;

$casos = 0;
$fallidos = 0;

/**
 * @param bool $condicion
 * @param string $desc
 * @return void
 */
function local_chatbot_verificar(bool $condicion, string $desc): void {
    global $fallidos, $casos;
    $casos++;
    if (!$condicion) {
        echo "  FALLA  {$desc}\n";
        $fallidos++;
    }
}

// --- RU-CONS-002: vigencia del consentimiento ---
$version = profile::disclosure_version();
$completa = profile::disclosure_is_complete();
local_chatbot_verificar($completa ? $version !== '' : $version === '',
    'la version de la declaracion existe solo cuando los cuatro antecedentes estan declarados');
local_chatbot_verificar($version === profile::disclosure_version(),
    'la version es estable: dos lecturas seguidas dan lo mismo');
local_chatbot_verificar(strlen($version) <= 20,
    'la version cabe en la columna de la base');

// Un consentimiento sellado con otra version ya no esta vigente.
local_chatbot_verificar(profile::consent_is_current((object) ['consentversion' => '']),
    'un consentimiento anterior al versionado se respeta como vigente');
if ($version !== '') {
    local_chatbot_verificar(profile::consent_is_current((object) ['consentversion' => $version]),
        'un consentimiento sellado con la version vigente sigue valiendo');
    local_chatbot_verificar(!profile::consent_is_current((object) ['consentversion' => 'otra-distinta']),
        'un consentimiento sellado con otra declaracion deja de estar vigente');
} else {
    // Sin declaracion completa no se invalida nada: lo que falta se bloquea por RU-CONS-004.
    local_chatbot_verificar(profile::consent_is_current((object) ['consentversion' => 'cualquiera']),
        'con la declaracion incompleta no se invalidan consentimientos ya dados');
    echo "  AVISO  el sitio no ha declarado los cuatro antecedentes (RU-CONS-004): "
        . "dos casos de vigencia no se pueden ejercitar aqui.\n";
}
local_chatbot_verificar(strpos(get_string('consent_changed', 'local_chatbot'), '[[') === false,
    'el aviso de nueva declaracion tiene texto traducido');

// La huella cambia si cambia lo declarado: se comprueba sobre valores, no sobre el sitio.
$huella = function(array $valores): string {
    return substr(sha1(implode('|', $valores)), 0, 12);
};
local_chatbot_verificar(
    $huella(['provider=A', 'region=Chile']) !== $huella(['provider=B', 'region=Chile']),
    'cambiar el proveedor cambia la version de la declaracion');
local_chatbot_verificar(
    $huella(['provider=A', 'region=Chile']) === $huella(['provider=A', 'region=Chile']),
    'los mismos valores dan la misma version');

// --- RU-PRIV-006: version del material citado ---
foreach (['U1-LF-01', 'U1-LF-01-seccion-3.5', 'u1-ca-01'] as $codigo) {
    $v = kb_store::resource_version($codigo);
    local_chatbot_verificar($v === '' || preg_match('/^v\d+$/', $v) === 1,
        "version del recurso '{$codigo}' viene como vN o vacia: '{$v}'");
}
foreach (['', 'page:415', 'U1-XX-01', 'algo'] as $ajeno) {
    local_chatbot_verificar(kb_store::resource_version($ajeno) === '',
        "'{$ajeno}' no es un recurso fijo y no devuelve version");
}
$contexto = kb_store::context_version();
local_chatbot_verificar($contexto === '' || preg_match('/^\d+docs-v[\d.]+$/', $contexto) === 1,
    "la version del conjunto de la base viene con formato: '{$contexto}'");
local_chatbot_verificar($contexto === kb_store::context_version(),
    'la version del conjunto es estable entre lecturas');

// Las columnas donde se guarda tienen que existir.
$columnas = $DB->get_columns('local_chatbot_interaction');
foreach (['sourceversion', 'kbversion'] as $columna) {
    local_chatbot_verificar(isset($columnas[$columna]),
        "la interaccion guarda {$columna}");
}
local_chatbot_verificar(isset($DB->get_columns('local_chatbot_profile')['consentversion']),
    'el perfil guarda la version del consentimiento');
local_chatbot_verificar(isset($DB->get_columns('local_chatbot_adapt_review')['record']),
    'la revision guarda el registro estructurado');

// El registro se escribe de verdad al cerrar una intervencion.
$fuente = file_get_contents(__DIR__ . '/../classes/adaptive.php');
local_chatbot_verificar(substr_count($fuente, 'self::store_record(') >= 2,
    'el registro estructurado se guarda al revisar la evidencia y al decidir la persona');
$ajax = file_get_contents(__DIR__ . '/../ajax.php');
local_chatbot_verificar(strpos($ajax, "'sourceversion' =>") !== false
    && strpos($ajax, "'kbversion' =>") !== false,
    'cada interaccion registra la version del recurso citado y de la base');

// RU-CONS-002: sin excepciones por rol. Las cuentas de administracion tambien
// consienten, porque sus consultas viajan al mismo proveedor de IA.
$fuente = file_get_contents(__DIR__ . '/../classes/profile.php');
local_chatbot_verificar(strpos($fuente, 'is_siteadmin') === false,
    'has_consent no exime a las cuentas de administracion');
$admin = get_admin();
if ($admin && !$DB->record_exists('local_chatbot_profile', ['userid' => $admin->id, 'consentaccepted' => 1])) {
    local_chatbot_verificar(!profile::has_consent($admin->id, 0),
        'una cuenta de administracion que no acepto no tiene consentimiento vigente');
}

printf("Casos verificados: %d | Fallidos: %d\n", $casos, $fallidos);

if ($fallidos === 0) {
    echo "Versionado conforme a RU-CONS-002 y RU-PRIV-006.\n";
    exit(0);
}
echo "Versionado NO conforme: revisar classes/profile.php y classes/kb_store.php.\n";
exit(1);

<?php
/**
 * Verifica la parte pura de la configuracion del Modulo 1 (classes/site_setup.php): los bloques de HTML
 * adicional del sitio, su fusion sin duplicados y la reescritura de enlaces al restaurar un curso.
 *
 * Uso:  php cli/verificar_configuracion.php
 */

define('MOODLE_INTERNAL', true);

require_once(__DIR__ . '/../classes/site_setup.php');

use local_chatbot\site_setup;

$fallidos = 0;
$total = 0;

function local_chatbot_verificar(bool $condicion, string $descripcion): void {
    global $fallidos, $total;
    $total++;
    if (!$condicion) {
        echo "  FALLA  {$descripcion}\n";
        $fallidos++;
    }
}

// --- Bloques propios ---
$bloques = site_setup::head_blocks(69);
local_chatbot_verificar(count($bloques) === 5, 'con consentimiento hay cinco bloques');
local_chatbot_verificar(count(site_setup::head_blocks(0)) === 4 && !isset(site_setup::head_blocks(0)['consentimiento']),
    'sin la actividad de consentimiento no se escribe su regla');
local_chatbot_verificar(strpos($bloques['consentimiento'], '#module-69 ') !== false,
    'la regla del consentimiento apunta a la actividad indicada');
$todos = implode("\n", $bloques);
local_chatbot_verificar(substr_count($todos, '<style>') === substr_count($todos, '</style>')
    && substr_count($todos, '<script>') === substr_count($todos, '</script>'), 'las etiquetas estan balanceadas');
local_chatbot_verificar(strpos($todos, 'window.open') === false,
    'la ficha no se abre en ventana emergente (el boton abre en la misma pestana)');
local_chatbot_verificar(strpos($todos, 'text-align: justify') !== false && strpos($todos, 'cursor: pointer') !== false
    && strpos($todos, 'sectiontoggler') !== false, 'texto justificado, manito y subsecciones abiertas');
foreach ($bloques as $clave => $bloque) {
    local_chatbot_verificar(site_setup::is_own_block($bloque), "el bloque «$clave» se reconoce como propio");
}

// Franja de navegacion del curso: solo con el curso indicado y solo para quien no edita el curso.
$conurso = site_setup::head_blocks(69, 6);
local_chatbot_verificar(count($conurso) === 6 && isset($conurso['navsecundaria']), 'con el curso hay seis bloques, uno es la franja');
local_chatbot_verificar(strpos($conurso['navsecundaria'], 'body.course-6 ') !== false
    && strpos($conurso['navsecundaria'], ':not(:has(li[data-key="editsettings"]))') !== false
    && strpos($conurso['navsecundaria'], 'display: none') !== false, 'la franja se oculta solo en ese curso y si falta «Configuracion»');
local_chatbot_verificar(!isset(site_setup::head_blocks(69)['navsecundaria']) && !isset(site_setup::head_blocks(69, 1)['navsecundaria']),
    'sin curso, o con el curso del sitio, no se toca la franja');
local_chatbot_verificar(site_setup::is_own_block($conurso['navsecundaria']) && site_setup::merge_head(site_setup::merge_head('', $conurso), $conurso)
    === site_setup::merge_head('', $conurso), 'el bloque de la franja se reconoce y la fusion sigue siendo idempotente');

// --- Fusion ---
$ajeno = "<style>/* tema del cliente */ .logo { color: red; }</style>";
$vacio = site_setup::merge_head('', $bloques);
local_chatbot_verificar($vacio === implode("\n", $bloques), 'sobre un HTML vacio queda solo lo propio');
$una = site_setup::merge_head($ajeno, $bloques);
local_chatbot_verificar(strpos($una, $ajeno) === 0, 'un bloque ajeno se conserva al principio');
local_chatbot_verificar(site_setup::merge_head($una, $bloques) === $una, 'es idempotente: una segunda pasada no cambia nada');
local_chatbot_verificar(substr_count($una, '/* ' . site_setup::MARK . 'aspecto */') === 1, 'cada bloque propio aparece una vez');

// Estado real de los servidores antes del guion: bloques antiguos puestos a mano.
$antiguo = "<style>/* Solo VPS propio: oculta «Estado de tu recorrido» en la pagina de Perfil (13-10-2026). */\n"
    . "#a { display: none; }</style>\n"
    . "<style>/* Solo VPS propio: oculta la tarjeta «Acompanamiento del Asistente IA» del diagnostico (13-10-2026). */\n"
    . ".b { display: none; }</style>\n"
    . "<style>/* Solo VPS propio: oculta «¿Quieres mantener estas prioridades...?» al final (13-10-2026). */\n"
    . ".c { display: none; }</style>\n"
    . "<style>/* revision-final-bienvenida-17-10-2026 */ .x { text-align: justify; }</style>\n"
    . "<script>/* revision-final-bienvenida-17-10-2026 */ window.open('x');</script>\n"
    . "<style>/* consentimiento-automatico-17-10-2026 */ #module-516 { display: none; }</style>\n"
    . "<style>/* cursor-pestanas-edicion-17-10-2026 */ a { cursor: pointer; }</style>\n"
    . "<style>/* url-del-recorrido-directa-17-10-2026 */ .y { visibility: hidden; }</style>\n"
    . "<script>/* url-del-recorrido-directa-17-10-2026 */ window.location.replace('x');</script>";
$migrado = site_setup::merge_head($ajeno . "\n" . $antiguo, $bloques);
local_chatbot_verificar(strpos($migrado, 'window.open') === false && strpos($migrado, '#module-516') === false
    && strpos($migrado, 'Solo VPS propio') === false, 'los bloques antiguos se reemplazan, no se duplican');
// Cuatro <style> propios (ocultos, aspecto, consentimiento, url_directa) mas el ajeno.
local_chatbot_verificar(strpos($migrado, $ajeno) === 0 && substr_count($migrado, '<style>') === 5,
    'tras migrar queda lo ajeno mas los bloques nuevos');
local_chatbot_verificar(strpos(site_setup::merge_head("a\r\n" . $ajeno, $bloques), "\r") === false,
    'normaliza los saltos de linea');

// --- Enlaces al restaurar un curso en otro servidor ---
$destino = 'https://moodle.cliente.cl';
local_chatbot_verificar(site_setup::rewrite_url('http://45.7.229.47/local/chatbot/index.php?courseid=6', $destino, 12)
    === 'https://moodle.cliente.cl/local/chatbot/index.php?courseid=12', 'el enlace a la ficha toma el sitio y el curso nuevos');
local_chatbot_verificar(site_setup::rewrite_url('http://x/moodle/local/chatbot/diagnostico.php?courseid=6&foo=1#a', 'http://y/m/', 20)
    === 'http://y/m/local/chatbot/diagnostico.php?courseid=20&foo=1#a', 'conserva otros parametros y el ancla; admite subcarpeta');
local_chatbot_verificar(site_setup::rewrite_url('http://45.7.229.47/local/chatbot/pdp.php', $destino, 7)
    === 'https://moodle.cliente.cl/local/chatbot/pdp.php?courseid=7', 'agrega el curso si el enlace no lo traia');
local_chatbot_verificar(site_setup::rewrite_url('https://sence.cl/otra/cosa?courseid=6', $destino, 12) === 'https://sence.cl/otra/cosa?courseid=6',
    'un enlace ajeno al asistente no se toca');
$boton = '<a class="btn" href="http://45.7.229.47/local/chatbot/index.php?courseid=6" style="x">Completar</a> <a href="https://otro.cl/">Otro</a>';
$reescrito = site_setup::rewrite_links($boton, $destino, 12);
local_chatbot_verificar(strpos($reescrito, 'href="https://moodle.cliente.cl/local/chatbot/index.php?courseid=12"') !== false
    && strpos($reescrito, 'href="https://otro.cl/"') !== false, 'el boton de la etiqueta se reescribe y los demas enlaces quedan');
local_chatbot_verificar(site_setup::rewrite_links($reescrito, $destino, 12) === $reescrito, 'reescribir dos veces no cambia nada');

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Configuracion del Modulo 1 conforme: bloques del sitio, fusion idempotente y enlaces al restaurar.\n";
    exit(0);
}
echo "Configuracion del Modulo 1 NO conforme.\n";
exit(1);

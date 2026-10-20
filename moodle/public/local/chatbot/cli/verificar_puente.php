<?php
/**
 * Verificador del puente entre el asistente y el curso.
 *
 * Comprueba las funciones puras (reconocimiento de pasos, diferencias de estado y
 * regla de acceso) y la coherencia estructural: que los cinco pasos existan, que
 * cada uno apunte a una pagina real del plugin, que sus textos esten traducidos y
 * que los observadores que mantienen el puente esten registrados.
 *
 * Uso: php verificar_puente.php
 *
 * @package local_chatbot
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/lib/completionlib.php');

use local_chatbot\course_bridge;

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

// --- Los cinco pasos del recorrido ---
local_chatbot_verificar(count(course_bridge::STEPS) === 5, 'el puente cubre los cinco pasos del recorrido');
foreach (['perfil', 'diagnostico', 'pdp', 'proyecto', 'cierre'] as $paso) {
    local_chatbot_verificar(isset(course_bridge::STEPS[$paso]), "existe el paso {$paso}");
}
foreach (course_bridge::STEPS as $paso => $def) {
    local_chatbot_verificar(file_exists($CFG->dirroot . '/public' . $def['path'])
        || file_exists($CFG->dirroot . $def['path']),
        "el paso {$paso} apunta a una pagina que existe ({$def['path']})");
    local_chatbot_verificar(strpos(get_string($def['label'], 'local_chatbot'), '[[') === false,
        "el paso {$paso} tiene nombre traducido");
    local_chatbot_verificar(strpos(get_string('bridge_desc_' . $paso, 'local_chatbot'), '[[') === false,
        "el paso {$paso} tiene descripcion traducida");
}

// --- Reconocimiento del paso por el codigo de la actividad ---
foreach (['M1-PASO-PERFIL' => 'perfil', 'M1-PASO-DIAG' => 'diagnostico', 'M1-PASO-PDP' => 'pdp',
        'M1-PASO-PROY' => 'proyecto', 'M1-PASO-CIERRE' => 'cierre',
        'm1-paso-perfil' => 'perfil', '  M1-PASO-PDP  ' => 'pdp'] as $codigo => $esperado) {
    local_chatbot_verificar(course_bridge::step_for_code($codigo) === $esperado,
        "codigo '{$codigo}' se reconoce como paso {$esperado}");
}
foreach (['', 'U1-LF-01', 'M1-PASO', 'M1-PASO-OTRO', 'PASO-PERFIL'] as $ajeno) {
    local_chatbot_verificar(course_bridge::step_for_code($ajeno) === '',
        "codigo '{$ajeno}' no se confunde con un paso del puente");
}
// Revision final de Bienvenida y Perfilamiento (17-10-2026): el consentimiento se marca solo, sin ser etapa.
local_chatbot_verificar(course_bridge::is_consent_code(' m1-consentimiento ') && !course_bridge::is_consent_code('M1-PASO-PERFIL'),
    'la actividad del consentimiento se reconoce por su codigo');
local_chatbot_verificar(course_bridge::step_for_code('M1-CONSENTIMIENTO') === '',
    'el consentimiento no es una etapa de la ruta');

// --- Diferencias entre el recorrido real y lo que muestra el curso ---
$todos = ['perfil' => true, 'diagnostico' => true, 'pdp' => false, 'proyecto' => false, 'cierre' => false];
$curso = ['perfil' => COMPLETION_INCOMPLETE, 'diagnostico' => COMPLETION_COMPLETE,
    'pdp' => COMPLETION_COMPLETE, 'proyecto' => COMPLETION_INCOMPLETE];
$cambios = course_bridge::pending_changes($todos, $curso);
local_chatbot_verificar(($cambios['perfil'] ?? null) === COMPLETION_COMPLETE,
    'un paso hecho que el curso no refleja se marca como completo');
local_chatbot_verificar(!isset($cambios['diagnostico']),
    'un paso hecho que el curso ya refleja no se vuelve a escribir');
local_chatbot_verificar(($cambios['pdp'] ?? null) === COMPLETION_INCOMPLETE,
    'un paso marcado en el curso sin estar hecho se revierte');
local_chatbot_verificar(!isset($cambios['proyecto']),
    'un paso no hecho y no marcado se deja como esta');
local_chatbot_verificar(!isset($cambios['cierre']),
    'un paso sin actividad en el curso no genera cambios');
local_chatbot_verificar(course_bridge::pending_changes($todos, []) === [],
    'sin actividades del puente, no hay nada que sincronizar');
local_chatbot_verificar(course_bridge::pending_changes(
    ['perfil' => true], ['perfil' => COMPLETION_COMPLETE_PASS]) === [],
    'un aprobado cuenta como completo y no se revierte');

// --- Regla de acceso encadenado ---
$regla = json_decode(course_bridge::availability_after(42), true);
local_chatbot_verificar(($regla['c'][0]['cm'] ?? 0) === 42 && ($regla['c'][0]['type'] ?? '') === 'completion',
    'la regla de acceso exige completar la actividad indicada');
local_chatbot_verificar(($regla['c'][0]['e'] ?? -1) === COMPLETION_COMPLETE,
    'la regla de acceso exige el estado completo');

// --- Observadores que mantienen el puente ---
$observers = [];
include(__DIR__ . '/../db/events.php');
$registrados = array_column($observers, 'eventname');
foreach (['\core\event\course_viewed', '\core\event\course_module_completion_updated'] as $evento) {
    local_chatbot_verificar(in_array($evento, $registrados, true), "observador registrado: {$evento}");
}

// --- El asistente no crea ni borra actividades del curso ---
// El puente escribe finalizacion, nunca estructura: crear actividades es tarea de
// la herramienta de preparacion, que corre una persona con permisos.
$fuente = file_get_contents(__DIR__ . '/../classes/course_bridge.php');
foreach (['create_module', 'add_moduleinfo', 'course_delete_module', 'delete_records'] as $api) {
    local_chatbot_verificar(stripos($fuente, $api) === false,
        "el puente no invoca {$api}: no modifica la estructura del curso");
}

// --- Las cinco paginas del recorrido usan el puente ---
foreach (['index.php', 'diagnostico.php', 'pdp.php', 'proyecto.php', 'cierre.php'] as $pagina) {
    $codigo = file_get_contents(__DIR__ . '/../' . $pagina);
    local_chatbot_verificar(strpos($codigo, 'course_bridge::setup_page') !== false,
        "{$pagina} se prepara con el puente (se abre dentro del curso)");
    local_chatbot_verificar(strpos($codigo, 'course_bridge::sync') !== false,
        "{$pagina} sincroniza el avance con el curso");
}

// --- RU-UX-002: el Coach se abre desde una meta, con esa meta como contexto ---
$pdp = file_get_contents(__DIR__ . '/../pdp.php');
local_chatbot_verificar(strpos($pdp, 'chatbot-goal-coach') !== false,
    'cada meta del plan tiene su boton para conversarla con el Coach');
local_chatbot_verificar(strpos($pdp, "chatbotAsk(consulta, 'coach')") !== false,
    'el boton abre el asistente en modo Coach con la meta como contexto');
local_chatbot_verificar(strpos($pdp, 'data-objetivo="<?php echo s($goal->objetivo); ?>"') !== false,
    'el objetivo viaja escapado en un atributo, no interpolado en el script');
local_chatbot_verificar(strpos(get_string('goal_ask_coach', 'local_chatbot'), '[[') === false,
    'el boton de conversar la meta tiene texto traducido');

// --- Los botones que abren el asistente con una consulta ---
$js = file_get_contents(__DIR__ . '/../js/chatbot-widget.js');
local_chatbot_verificar(strpos($js, 'window.chatbotAsk = function') !== false,
    'chatbotAsk esta definida en el widget (se invocaba sin existir)');
foreach (['window.chatbotOpen =', 'window.chatbotSetMode =', 'window.chatbotClose ='] as $api) {
    local_chatbot_verificar(strpos($js, $api) !== false, "el widget expone {$api}");
}
$diag = file_get_contents(__DIR__ . '/../diagnostico.php');
local_chatbot_verificar(strpos($diag, 'data-enunciado="<?php echo s($item->statement); ?>"') !== false,
    'el boton de consultar concepto lleva el enunciado escapado en un atributo');
local_chatbot_verificar(strpos($diag, 'onclick="chatbotAskConcept(<?php echo json_encode') === false,
    'el enunciado ya no se interpola dentro del onclick, que rompia el atributo');
// Ningun onclick del plugin puede llevar texto interpolado: vuelve a romperlo.
foreach (['index.php', 'diagnostico.php', 'pdp.php', 'proyecto.php', 'cierre.php'] as $pagina) {
    $codigo = file_get_contents(__DIR__ . '/../' . $pagina);
    local_chatbot_verificar(preg_match('/onclick="[^"]*<\?php[^>]*\$/m', $codigo) !== 1,
        "{$pagina}: ningun onclick interpola contenido variable");
}

// --- Aviso de espera en las acciones que llaman al modelo ---
local_chatbot_verificar(file_exists(__DIR__ . '/../js/espera.js'), 'existe el script del aviso de espera');
$puente = file_get_contents(__DIR__ . '/../classes/course_bridge.php');
local_chatbot_verificar(strpos($puente, 'js/espera.js') !== false,
    'las paginas del recorrido cargan el aviso de espera');
foreach (['diagnostico.php' => 'espera_matriz', 'pdp.php' => 'espera_metas',
        'proyecto.php' => 'espera_proyecto'] as $pagina => $clave) {
    $codigo = file_get_contents(__DIR__ . '/../' . $pagina);
    local_chatbot_verificar(strpos($codigo, $clave) !== false,
        "{$pagina}: la accion lenta avisa que esta trabajando");
}
$pdp = file_get_contents(__DIR__ . '/../pdp.php');
foreach (['espera_apoyo', 'espera_evidencia'] as $clave) {
    local_chatbot_verificar(strpos($pdp, $clave) !== false, "pdp.php: {$clave} conectado");
}
foreach (['espera_matriz', 'espera_metas', 'espera_apoyo', 'espera_evidencia', 'espera_proyecto'] as $clave) {
    foreach ([$clave, $clave . '_larga'] as $cadena) {
        local_chatbot_verificar(strpos(get_string($cadena, 'local_chatbot'), '[[') === false,
            "texto traducido: {$cadena}");
    }
}

// --- Invitacion al paso siguiente ---
$todos = ['perfil' => true, 'diagnostico' => true, 'pdp' => true, 'proyecto' => false, 'cierre' => false];
local_chatbot_verificar(course_bridge::next_step('pdp', $todos) === 'proyecto',
    'con el plan validado, el paso siguiente es el proyecto');
local_chatbot_verificar(course_bridge::next_step('perfil', $todos) === 'proyecto',
    'se salta lo ya hecho y apunta al primer paso pendiente');
local_chatbot_verificar(course_bridge::next_step('proyecto', $todos) === '',
    'no invita a seguir desde un paso que todavia no esta hecho');
local_chatbot_verificar(course_bridge::next_step('cierre', array_fill_keys(array_keys($todos), true)) === '',
    'con el recorrido completo no queda paso siguiente');
local_chatbot_verificar(course_bridge::next_step('inventado', $todos) === '',
    'un paso desconocido no propone nada');
foreach (array_keys(course_bridge::STEPS) as $paso) {
    foreach (["next_title_{$paso}", "next_body_{$paso}", "next_go_{$paso}"] as $cadena) {
        local_chatbot_verificar(strpos(get_string($cadena, 'local_chatbot'), '[[') === false,
            "texto traducido: {$cadena}");
    }
}
foreach (['index.php', 'diagnostico.php', 'pdp.php', 'proyecto.php', 'cierre.php'] as $pagina) {
    $codigo = file_get_contents(__DIR__ . '/../' . $pagina);
    local_chatbot_verificar(strpos($codigo, 'next_step_card') !== false,
        "{$pagina}: invita al paso siguiente");
}

// --- El sitio no es un curso: SITEID llega como cadena ---
local_chatbot_verificar((int) SITEID === 1, 'SITEID vale 1 al convertirlo a entero');
local_chatbot_verificar(1 !== SITEID,
    'SITEID no es identico al entero 1: por eso la comparacion estricta no servia');
$lib = file_get_contents(__DIR__ . '/../lib.php');
local_chatbot_verificar(strpos($lib, '$courseid === SITEID') === false,
    'el widget no compara el curso con SITEID de forma estricta');
local_chatbot_verificar(strpos($lib, '(int) $courseid === (int) SITEID') !== false,
    'el widget compara el curso con SITEID convirtiendo ambos a entero');
foreach (['classes/course_bridge.php', 'classes/observer.php'] as $archivo) {
    $codigo = file_get_contents(__DIR__ . '/../' . $archivo);
    local_chatbot_verificar(strpos($codigo, '=== SITEID') === false && strpos($codigo, '!== SITEID') === false,
        "{$archivo}: no compara con SITEID de forma estricta");
}
local_chatbot_verificar($DB->count_records('local_chatbot_profile', ['courseid' => 1]) === 0,
    'no quedan perfiles guardados bajo el curso del sitio');
local_chatbot_verificar($DB->count_records('local_chatbot_interaction', ['courseid' => 1]) === 0,
    'no quedan interacciones guardadas bajo el curso del sitio');

// --- Nombres de las etapas: con la ruta de 6, los del documento del cliente (stage6_*) ---
local_chatbot_verificar(course_bridge::step_name_key('pdp', true) === 'stage6_pdp'
    && course_bridge::step_name_key('pdp') === 'journey_step_pdp',
    'la ruta de 6 usa los nombres del documento y la de 5 conserva los suyos');
$nombresdoc = [];
foreach (\local_chatbot\journey::ROUTE6 as $clave) {
    $nombresdoc[] = get_string(course_bridge::step_name_key($clave, true), 'local_chatbot');
}
local_chatbot_verificar(current_language() !== 'es' || $nombresdoc === array_values(\local_chatbot\journey::NAMES6),
    'la barra en español dice lo mismo que el contexto del Tutor: ' . implode(' · ', $nombresdoc));

printf("Casos verificados: %d | Fallidos: %d\n", $casos, $fallidos);

if ($fallidos === 0) {
    echo "Puente conforme: el recorrido del asistente y el curso convergen.\n";
    exit(0);
}
echo "Puente NO conforme: revisar classes/course_bridge.php antes de desplegar.\n";
exit(1);

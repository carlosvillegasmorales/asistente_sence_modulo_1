<?php
/**
 * Verifica la libreria de conocimientos del Modulo 3 (ambito m3): los once documentos versionados en deploy/libreria_m3/ y su
 * enrutamiento por tarea (la validacion de la ficha, m3f1, las etapas 1 a 7, m3e1 a m3e7, y las etapas 9 a 14 de la evaluacion ex
 * post, m3x9 a m3x14).
 *
 * Los cuatro primeros son los documentos que entrego Interfases el 06-11-2026 (checklist, ficha, guia de llenado y ejemplo de
 * llenado) y los tres ultimos son los de referencia del Modulo 2 (Marco de Cualificaciones, Manual EDECOMP y Guia de Planes
 * Formativos), con sus marcas de ruta reasignadas a las tareas del Modulo 3. Los cuatro ultimos (08 a 11) son los de la Fase 4
 * (evaluacion ex post) que entrego Interfases el 07-11-2026: checklist, ficha, guia y ejemplo de llenado. Cada tarea debe recibir su material dentro del
 * presupuesto, sin omitir secciones y sin dejar marcas a la vista del modelo; la consolidacion (etapa 8) no usa libreria.
 *
 * Uso:  php cli/verificar_libreria_m3.php
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/kb_router.php');
require_once(__DIR__ . '/../classes/kb_store.php');
require_once(__DIR__ . '/../classes/ux.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/implementacion.php');
require_once(__DIR__ . '/../classes/diseno.php');
require_once(__DIR__ . '/../classes/calidad.php');
require_once(__DIR__ . '/../classes/expost.php');

use local_chatbot\expost;
use local_chatbot\implementacion;
use local_chatbot\kb_router;
use local_chatbot\kb_store;
use local_chatbot\ux;

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

// --- Las tareas del Modulo 3 y el ambito ---
local_chatbot_verificar(kb_router::TAREAS_M3 === ['m3f1', 'm3e1', 'm3e2', 'm3e3', 'm3e4', 'm3e5', 'm3e6', 'm3e7', 'm3x9', 'm3x10', 'm3x11', 'm3x12', 'm3x13', 'm3x14'],
    'las tareas con librería del Módulo 3 son la validación y las etapas 1 a 7 y las etapas 9 a 14 de la evaluación ex post (las consolidaciones no usan librería)');
local_chatbot_verificar(!in_array('m3e8', kb_router::all_tasks(), true) && count(array_intersect(kb_router::TAREAS_M3, kb_router::TAREAS)) === 0
    && count(kb_router::all_tasks()) === count(kb_router::TAREAS) + count(kb_router::TAREAS_M3),
    'las tareas de los dos módulos van en listas separadas y no se pisan');
local_chatbot_verificar(kb_store::AMBITO_M3 === 'm3' && in_array('m3', kb_store::ambitos(), true) && kb_store::normalize_ambito('m3') === 'm3',
    'la librería del Módulo 3 vive en el ámbito m3');
local_chatbot_verificar((ux::DEFAULTS['libreriam3'] ?? null) === 1 && (ux::DEFAULTS['libreriaruta'] ?? null) === 1
    && implementacion::PROMPT_VERSION === 'M3-F1-checklist-v1',
    'interruptor libreriam3 encendido por defecto y versión de prompt del Módulo 3');

// Una marca de ruta del Modulo 3 se reconoce; una desconocida no.
$docs = [['nombre' => 'Prueba', 'version' => 1, 'contenido' => "# Prueba {ruta: m3f1}\n\n## Solo validación {ruta: m3f1}\nA.\n\n## Solo etapa 4 {ruta: m3e4}\nB.\n\n## De otro módulo {ruta: e4}\nC.\n"]];
$f1 = kb_router::select($docs, 'm3f1', null)['texto'];
$e4 = kb_router::select($docs, 'm3e4', null)['texto'];
local_chatbot_verificar(strpos($f1, 'A.') !== false && strpos($f1, 'B.') === false && strpos($f1, 'C.') === false
    && strpos($e4, 'B.') !== false && strpos($e4, 'A.') === false && strpos($e4, 'C.') === false,
    'el enrutador reparte las secciones entre las tareas del Módulo 3 sin mezclarlas con las del 2');
local_chatbot_verificar(strpos(kb_router::select($docs, 'e4', null)['texto'], 'C.') !== false
    && strpos(kb_router::select($docs, 'e4', null)['texto'], 'B.') === false, 'las tareas del Módulo 2 siguen funcionando');
local_chatbot_verificar(strpos(kb_router::select($docs, 'm3e8', null)['texto'], '=== DOCUMENTO: Prueba') === 0,
    'una tarea sin librería (la etapa 8) recibe la librería completa sin enrutar, como cualquier tarea desconocida');

// --- Los siete archivos reales de deploy/libreria_m3 (si la carpeta esta presente en este arbol) ---
$dirlib = __DIR__ . '/../../../../../deploy/libreria_m3';
$archivos = is_dir($dirlib) ? (glob($dirlib . '/*.md') ?: []) : [];
sort($archivos);
if ($archivos) {
    local_chatbot_verificar(count($archivos) === 11, 'deploy/libreria_m3 contiene los 11 documentos Markdown');
    local_chatbot_verificar(array_map('basename', $archivos) === ['01_checklist_validacion.md', '02_ficha_registro.md', '03_guia_llenado.md',
        '04_ejemplo_llenado.md', '05_marco_cualificaciones.md', '06_manual_edecomp.md', '07_guia_planes_formativos.md',
        '08_checklist_expost.md', '09_ficha_expost.md', '10_guia_llenado_expost.md', '11_ejemplo_llenado_expost.md'],
        'los documentos son el checklist, la ficha, la guía, el ejemplo, los tres de referencia y los cuatro de la evaluación ex post');
    $reales = [];
    foreach ($archivos as $ruta) {
        $par = kb_store::library_file((string) file_get_contents($ruta));
        local_chatbot_verificar($par !== null && kb_store::answer_key_markers($par[1]) === [],
            basename($ruta) . ': título H1 válido y sin claves de evaluación');
        local_chatbot_verificar(strpos((string) file_get_contents($ruta), "\xEF\xBF\xBD") === false, basename($ruta) . ': sin caracteres dañados');
        if ($par !== null) {
            $reales[] = ['nombre' => $par[0], 'contenido' => $par[1], 'version' => 1];
        }
    }
    // Sin marcas de ruta del Modulo 2: todas se reasignaron.
    $m2 = false;
    foreach ($archivos as $ruta) {
        if (preg_match('/\{ruta:[^}]*\b(?:fase1|e[1-8])\b[^}]*\}/i', (string) file_get_contents($ruta))) {
            $m2 = true;
        }
    }
    local_chatbot_verificar(!$m2, 'ninguna marca de ruta quedó con tareas del Módulo 2');

    // El checklist y la ficha de la libreria son los instrumentos que usa el codigo: si se cambia uno, hay que cambiar el otro.
    $lib01 = str_replace("\r\n", "\n", (string) file_get_contents($dirlib . '/01_checklist_validacion.md'));
    $desfasados = [];
    $total_items = 0;
    foreach (implementacion::checklist() as $seccion) {
        foreach ($seccion['items'] as $id => $texto) {
            $total_items++;
            $linea = '- ' . $texto . (in_array($id, implementacion::CRITICOS, true) ? ' — CRÍTICO' : '') . "\n";
            if (strpos($lib01, $linea) === false) {
                $desfasados[] = $id;
            }
        }
    }
    local_chatbot_verificar($total_items === 34 && $desfasados === [],
        '01_checklist_validacion.md trae los ' . $total_items . ' ítems del código con su marca CRÍTICO (desfasados: ' . implode(', ', $desfasados) . ')');
    $lib02 = str_replace("\r\n", "\n", (string) file_get_contents($dirlib . '/02_ficha_registro.md'));
    $sin_titulo = [];
    foreach (implementacion::sections() as $n => $seccion) {
        if (strpos($lib02, '### SECCIÓN ' . $n . '. ' . $seccion['titulo'] . "\n") === false) {
            $sin_titulo[] = $n;
        }
    }
    local_chatbot_verificar($sin_titulo === [], '02_ficha_registro.md trae las 6 secciones de la ficha del código (faltan: ' . implode(', ', $sin_titulo) . ')');

    // El checklist y la ficha de la evaluacion ex post tambien son los instrumentos del codigo.
    $lib08 = str_replace("\r\n", "\n", (string) file_get_contents($dirlib . '/08_checklist_expost.md'));
    $desfasados = [];
    $total_items = 0;
    foreach (expost::checklist() as $seccion) {
        foreach ($seccion['items'] as $id => $texto) {
            $total_items++;
            if (strpos($lib08, '- ' . $texto . (in_array($id, expost::CRITICOS, true) ? ' — CRÍTICO' : '') . "\n") === false) {
                $desfasados[] = $id;
            }
        }
    }
    local_chatbot_verificar($total_items === 25 && $desfasados === [],
        '08_checklist_expost.md trae los ' . $total_items . ' ítems del código con su marca CRÍTICO (desfasados: ' . implode(', ', $desfasados) . ')');
    $lib09 = str_replace("\r\n", "\n", (string) file_get_contents($dirlib . '/09_ficha_expost.md'));
    $sin_titulo = [];
    foreach (expost::sections() as $n => $seccion) {
        if (strpos($lib09, '### SECCIÓN ' . $n . '. ' . $seccion['titulo'] . "\n") === false) {
            $sin_titulo[] = $n;
        }
    }
    local_chatbot_verificar($sin_titulo === [], '09_ficha_expost.md trae las 7 secciones de la ficha del código (faltan: ' . implode(', ', $sin_titulo) . ')');

    $completa = kb_router::select($reales, '', null, PHP_INT_MAX);
    echo "Librería Módulo 3 completa (sin enrutar): {$completa['caracteres']} caracteres ({$completa['secciones']} secciones)\n";
    echo sprintf("  %-6s | %10s | %9s\n", 'Tarea', 'Caracteres', 'Secciones');
    echo '  ' . str_repeat('-', 32) . "\n";
    $porTarea = [];
    foreach (kb_router::TAREAS_M3 as $tarea) {
        $s = kb_router::select($reales, $tarea, null);
        $porTarea[$tarea] = $s['texto'];
        echo sprintf("  %-6s | %10d | %9d\n", $tarea, $s['caracteres'], $s['secciones']);
        local_chatbot_verificar($s['caracteres'] > 0 && $s['caracteres'] <= kb_router::PRESUPUESTO && $s['omitidas'] === 0
            && strpos($s['texto'], '{ruta') === false && strpos($s['texto'], '{nivel') === false,
            "librería real en '{$tarea}': dentro de PRESUPUESTO, 0 omitidas y sin marcas");
        for ($nivel = 1; $nivel <= 5; $nivel++) {
            $sn = kb_router::select($reales, $tarea, $nivel);
            local_chatbot_verificar($sn['caracteres'] <= kb_router::PRESUPUESTO && $sn['omitidas'] === 0,
                "librería real en '{$tarea}' con nivel {$nivel}: dentro de PRESUPUESTO y 0 omitidas");
        }
    }
    // Cada tarea trae lo que su trabajo necesita.
    local_chatbot_verificar(strpos($porTarea['m3f1'], 'Checklist de validación de la ficha de registro de la implementación') !== false
        && strpos($porTarea['m3f1'], 'CRÍTICO') !== false && strpos($porTarea['m3f1'], 'Ejemplo de llenado') !== false
        && strpos($porTarea['m3f1'], 'Guía de llenado') !== false || strpos($porTarea['m3f1'], 'GUÍA DE LLENADO') !== false,
        'la validación de la ficha (m3f1) recibe el checklist, la ficha, la guía y el ejemplo de llenado');
    local_chatbot_verificar(strpos($porTarea['m3e4'], 'Checklist de validación') === false && strpos($porTarea['m3e6'], 'Checklist de validación') === false,
        'el checklist solo va en la validación de la ficha');
    local_chatbot_verificar(strpos($porTarea['m3e2'], 'Criterios de decisión del tipo de ajuste') !== false
        && strpos($porTarea['m3e3'], 'Criterios de decisión del tipo de ajuste') !== false && strpos($porTarea['m3e1'], 'Pregunta 3.1') !== false,
        'las etapas de análisis (1 a 3) reciben la guía con los criterios de decisión del tipo de ajuste');
    // La evaluacion ex post (Fase 4): cada tarea recibe lo suyo y nada de la Fase 1 ni de las referencias.
    local_chatbot_verificar(strpos($porTarea['m3x10'], 'Checklist de validación de la ficha de evaluación ex post') !== false
        && strpos($porTarea['m3x10'], 'CRÍTICO') !== false && strpos($porTarea['m3x10'], 'EJEMPLO DE LLENADO') !== false
        && strpos($porTarea['m3x10'], 'Subsección 2.2. Pendientes de la experiencia inicial') !== false,
        'la validación de la ficha ex post (m3x10) recibe el checklist, la ficha, la guía y el ejemplo de llenado');
    local_chatbot_verificar(strpos($porTarea['m3x11'], 'Checklist de validación') === false && strpos($porTarea['m3x11'], 'Ejemplo de llenado') === false
        && strpos($porTarea['m3x11'], 'Ficha de evaluación ex post') !== false && strpos($porTarea['m3x11'], '¿Qué es la evaluación ex post') !== false,
        'las etapas de análisis de la Fase 4 reciben la ficha y el marco de la evaluación ex post, sin el checklist ni el ejemplo');
    local_chatbot_verificar(strpos($porTarea['m3x9'], 'Guía de llenado') !== false && strpos($porTarea['m3x9'], 'Subsección 3.1') !== false,
        'la preparación de la precarga (m3x9) recibe la ficha y la guía, con las reglas de la sección 3');
    $fase1 = true;
    foreach (['m3f1', 'm3e1', 'm3e2', 'm3e3', 'm3e4', 'm3e5', 'm3e6', 'm3e7'] as $t) {
        $fase1 = $fase1 && strpos($porTarea[$t], 'Evaluación Ex Post - Interfases') === false;
    }
    local_chatbot_verificar($fase1, 'los documentos de la evaluación ex post no viajan en los pedidos de las Fases 1 a 3');
    $ex = true;
    foreach (['m3x9', 'm3x10', 'm3x11', 'm3x12', 'm3x13', 'm3x14'] as $t) {
        $ex = $ex && strpos($porTarea[$t], 'Guía de Llenado Ficha Registro Implementación') === false
            && strpos($porTarea[$t], 'Marco de Cualificaciones') === false;
    }
    local_chatbot_verificar($ex, 'los pedidos de la Fase 4 no llevan la guía de la Fase 1 ni los documentos de referencia');
    local_chatbot_verificar($porTarea['m3e6'] !== $porTarea['m3f1'] && mb_strlen($porTarea['m3e6']) > mb_strlen($porTarea['m3e7']),
        'la experiencia de continuidad (m3e6) recibe más librería que la síntesis (m3e7)');
}

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Librería del Módulo 3 conforme: once documentos válidos y enrutados por tarea dentro del presupuesto.\n";
}
exit($fallidos === 0 ? 0 : 1);

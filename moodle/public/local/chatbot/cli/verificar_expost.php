<?php
/**
 * Verifica la Fase 4 del Modulo 3 (evaluacion ex post, etapas 9 a 15 del «Prompt Complementario Fase 4 - Evaluacion Ex Post» de
 * Interfases, 07-11-2026).
 *
 * La ficha de evaluacion ex post y su checklist son los del cliente: 25 items en 8 secciones, 3 de ellos CRITICO (suma por nivel,
 * no regresion y coherencia global). Lo que es calculo lo hace el codigo, no el modelo: el logro final, el principio de no
 * regresion, los pendientes, la cobertura evaluativa y los movimientos de nivel. La condicion de la validacion la fija la regla del
 * cliente, la Fase 4 solo se activa con las Fases 1 a 3 completas y la experiencia de continuidad implementada, y no se entra a
 * una etapa sin aceptar la anterior ni sin la ficha ex post validada.
 *
 * El caso de prueba es el ejemplo de llenado del cliente («Comunicación efectiva»): logro inicial 82, 64 y 73 %, un criterio
 * pendiente que se resuelve con 77 % y logro final 95, 91, 95 y 77 %.
 *
 * Uso:  php cli/verificar_expost.php
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/diseno.php');
require_once(__DIR__ . '/../classes/diagrama.php');
require_once(__DIR__ . '/../classes/documento_final.php');
require_once(__DIR__ . '/../classes/implementacion.php');
require_once(__DIR__ . '/../classes/calidad.php');
require_once(__DIR__ . '/../classes/expost.php');

use local_chatbot\calidad;
use local_chatbot\expost;
use local_chatbot\implementacion;

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

/**
 * @param string $estado
 * @param string|null $contenido
 * @param array $extra
 * @return stdClass
 */
function local_chatbot_fila(string $estado, ?string $contenido = null, array $extra = []): stdClass {
    return (object) ($extra + ['estado' => $estado, 'contenido' => $contenido, 'informe' => null, 'huella' => '']);
}

// ------------------------------------------------------------------------------------------
// El caso del cliente: ficha de la implementacion (Fase 1) y diseño.
// ------------------------------------------------------------------------------------------
$criterios = ['Redacta mensajes profesionales con claridad, coherencia y corrección ortográfica.',
    'Adapta el registro lingüístico al contexto y al interlocutor.',
    'Responde por escrito a consultas laborales de manera respetuosa, oportuna y pertinente.',
    'Estructura mensajes orales breves según el propósito comunicativo.'];
// Niveles de la experiencia inicial (Insuficiente, Básico, Adecuado, Avanzado), con 22 presentes.
$inicial = [[0, 4, 14, 4], [2, 6, 12, 2], [1, 5, 13, 3]];
$raw = ['plan' => 'Habilidades transversales para el trabajo, código PF-0245.', 'modulo' => 'Módulo 1: Comunicación para el trabajo.',
    'experiencia' => 'Elaboración de correos profesionales con criterios de calidad.', 'facilitador' => 'Marta Ramírez Fuentes.',
    'fechas' => '22 de marzo de 2026.', 'inscritos' => '24', 'presentes' => '22'];
foreach ($inicial as $i => $n) {
    $k = $i + 1;
    $raw['criterio' . $k] = $criterios[$i];
    $raw['ev' . $k] = 'Evidencia observada.';
    [$raw['ins' . $k], $raw['bas' . $k], $raw['ade' . $k], $raw['ava' . $k]] = $n;
    $raw['lg' . $k] = (string) round(100 * ($n[2] + $n[3]) / 22);
}
$impl = implementacion::clean($raw);
$ref = implementacion::design_reference(['plan' => $raw['plan'], 'modulo' => $raw['modulo'], 'criterio1' => $criterios[0],
    'criterio2' => $criterios[1], 'criterio3' => $criterios[2], 'criterio4' => $criterios[3]], $raw['experiencia']);
$pre = expost::preload($impl, $ref, 'Comunicación escrita y oral en situaciones laborales: nivelación y profundización.', '15 de mayo de 2026');

// Ficha ex post del ejemplo del cliente.
$e = ['fechas_cont' => '29 de marzo de 2026.', 'presentes_cont' => '22', 'evaluados' => '22',
    'v331' => '4', 'v332' => '4', 'v333' => '3', 'v334' => '3', 'v335' => '3', 'v336' => '4',
    'fund33' => 'Las estrategias favorecieron el logro: el promedio de los criterios 1 a 3 subió de 73 % a 94 %.',
    'f4_enc' => '1', 'f4_obs' => '1', 'n_enc' => '20', 'v42' => '4', 'v43' => '3', 'v44' => '4', 'v45' => '4', 'v46' => '3', 'v47' => '4',
    'datos4' => 'Promedios de la encuesta (escala de 1 a 4): satisfacción general 3,7.', 'mas48' => 'Los ejemplos de correos reales.',
    'crit49' => 'Más tiempo para redactar.',
    'op51' => 'parcial', 'f5_auto' => '1', 'f5_prac' => '1', 'momento5' => '6 semanas.', 'n_seg5' => '14', 'n_apl5' => '10', 'grado54' => '3',
    'ejemplos5' => 'Redacción de correos a clientes.', 'factores5' => 'Facilitó la práctica laboral en comercio.',
    'v61' => '3', 'v62' => '2', 'v63' => '3', 'fund6' => 'Nivelación: de las 18 situaciones, 14 alcanzaron el nivel Adecuado.',
    'r71' => 'Incorporar el ejercicio comparativo fue acertado.', 'r72' => 'Asignaría más tiempo a la redacción.',
    'r73' => 'El apoyo entre pares funcionó mejor de lo esperado.', 'r74' => 'Diagnosticar la experiencia laboral del grupo.',
    'pr4' => 'si', 'pc4' => 'Se trabajó con una simulación telefónica.', 'cm1' => 'Tres de las cuatro personas en nivelación alcanzaron el nivel Adecuado.'];
$cierre = [[0, 1, 15, 6, '95'], [0, 2, 18, 2, '91'], [0, 1, 18, 3, '95'], [1, 4, 14, 3, '77']];
foreach ($cierre as $i => $n) {
    $k = $i + 1;
    [$e['ins' . $k], $e['bas' . $k], $e['ade' . $k], $e['ava' . $k], $e['lg' . $k]] = $n;
}
for ($j = 1; $j <= 4; $j++) {
    $e['d' . $j . '_val'] = '3';
    $e['d' . $j . '_ev'] = 'Evidencia concreta ' . $j;
    $e['e' . $j . '_val'] = '4';
    $e['e' . $j . '_ev'] = 'Evidencia del instrumento ' . $j;
    $e['bp' . $j . '_dec'] = $j === 4 ? 'descarta' : 'confirma';
    $e['bp' . $j . '_fund'] = 'Fundamentación ' . $j;
}
$data = expost::clean($e);
$etq = ['didacticas' => [['etiqueta' => 'Taller práctico – demostración guiada', 'exp' => 'inicial'],
    ['etiqueta' => 'Trabajo por trayectorias con apoyo entre pares', 'exp' => 'continuidad']],
    'evaluativas' => [['etiqueta' => 'Rúbrica – correo profesional (criterios 1 y 2)', 'exp' => 'inicial'],
        ['etiqueta' => 'Lista de cotejo – simulación telefónica (criterio 4)', 'exp' => 'continuidad']],
    'practicas' => ['Demostración guiada antes de la práctica autónoma', 'Ejercicio comparativo de registros', 'Entrega de la rúbrica antes de la tarea',
        'Trabajo individual para la redacción']];

// --- Las etapas y los textos del prompt ---
local_chatbot_verificar(expost::ETAPA_PRECARGA === 9 && expost::ETAPA_VALIDACION === 10 && expost::CONSOLIDACION === 15
    && expost::ETAPAS === [11, 12, 13, 14] && array_keys(expost::etapas()) === [11, 12, 13, 14]
    && array_keys(expost::todas()) === [11, 12, 13, 14, 15], 'la Fase 4 son las etapas 9 a 15: precarga, validación, cuatro hojas y consolidación');
local_chatbot_verificar(array_keys(expost::titulos()) === range(9, 15) && expost::titulos()[11] === 'Resultados del ciclo formativo'
    && expost::titulos()[12] === 'Análisis metacognitivo del proceso' && expost::titulos()[13] === 'Sistematización de prácticas exitosas'
    && expost::titulos()[14] === 'Recomendaciones para una nueva ejecución' && expost::titulos()[15] === 'Consolidación',
    'los títulos de las etapas son los del prompt');
local_chatbot_verificar(expost::ACTIVACION_MENSAJE === 'La evaluación ex post se realiza al cierre del ciclo formativo. Para iniciarla, deben estar completas las '
    . 'Fases 1 a 3 y debe haberse implementado la experiencia de aprendizaje de continuidad.', 'el mensaje de activación es textual del prompt');
$bv = expost::welcome();
local_chatbot_verificar(count($bv) === 2 && strpos($bv[0], 'Bienvenido/a a la evaluación ex post. Cerraremos el ciclo formativo de tu experiencia de aprendizaje:') === 0
    && strpos($bv[1], 'Ya preparé tu ficha de evaluación ex post con los datos de las fases anteriores. Complétala en línea; puedes apoyarte en la guía de llenado y en el ejemplo.') === 0
    && substr($bv[1], -1) === '.' && strpos($bv[1], 'la revisaré y validaré antes de iniciar el análisis') !== false, 'la bienvenida de la Fase 4 es textual del prompt');
$e11 = expost::etapas()[11];
local_chatbot_verificar(count($e11['incluye']) === 8 && count(expost::etapas()[12]['incluye']) === 5 && count(expost::etapas()[13]['incluye']) === 8
    && count(expost::etapas()[14]['incluye']) === 8, 'cada etapa pide lo que el prompt dice (8, 5, 7 + descartadas y 5 ámbitos + evidencia, 7.4 y cierre)');

// --- El checklist es el del cliente ---
$cl = expost::checklist();
local_chatbot_verificar(array_keys($cl) === range(1, 8) && count(expost::checklist_ids()) === 25
    && array_map('count', array_column($cl, 'items')) === [2, 4, 4, 3, 4, 3, 3, 2], 'el checklist tiene 25 ítems en 8 secciones (2, 4, 4, 3, 4, 3, 3 y 2)');
local_chatbot_verificar(expost::CRITICOS === ['c2_1', 'c2_2', 'c8_1'] && count(array_diff(expost::CRITICOS, expost::checklist_ids())) === 0
    && count(array_diff(expost::POR_CALCULO, expost::checklist_ids())) === 0, 'son CRÍTICO la suma por nivel, la no regresión y la coherencia global');
local_chatbot_verificar(expost::checklist_texts()['c2_2'] === 'Se respeta el principio de no regresión: en ningún criterio disminuye el número de participantes en niveles '
    . 'Adecuado o Avanzado respecto de la experiencia inicial' && expost::checklist_texts()['c2_3'] === 'El logro final (%) está bien calculado: (Adecuado + Avanzado) ÷ evaluados '
    . 'al cierre × 100' && expost::checklist_texts()['c8_2'] === 'Los datos permiten realizar el análisis metacognitivo: trayectoria del ciclo, resultados, lecciones aprendidas, '
    . 'prácticas exitosas y recomendaciones', 'el texto de los ítems es textual del documento del cliente');

// --- La ficha y la limpieza de lo que llega ---
$sec = expost::sections();
local_chatbot_verificar(array_keys($sec) === range(1, 7) && $sec[2]['titulo'] === 'Logro final de los aprendizajes' && $sec[6]['titulo'] === 'Efectividad de los ajustes',
    'la ficha tiene siete secciones con los títulos del cliente');
$k = expost::field_keys();
local_chatbot_verificar(isset($k['ins1'], $k['lg12'], $k['pr4'], $k['d6_nombre'], $k['e5_exp'], $k['bp4_dec'], $k['f4_enc'], $k['r74']) && !isset($k['d1_nombre'])
    && $k['f4_enc'] === expost::T_CHECK && expost::options('d1_val') === expost::scale(true) && expost::options('pr3') === expost::answers()
    && expost::options('grado54') === expost::scale(false) && expost::options('evaluados') === null, 'los campos y sus alternativas se reconocen');
$c = expost::clean(['evaluados' => ' 22 ', 'lg1' => '95,5', 'lg2' => '120', 'lg3' => '0', 'pr1' => 'quizas', 'pr2' => 'parcial', 'f4_enc' => 'on', 'f4_obs' => 'x',
    'd1_val' => '5', 'd2_val' => '4', 'ins1' => '-3', 'r71' => str_repeat('a', 5000), 'otro' => 'no cuenta']);
local_chatbot_verificar($c['evaluados'] === '22' && $c['lg1'] === '95.5' && $c['lg2'] === '' && $c['lg3'] === '0' && $c['pr1'] === '' && $c['pr2'] === 'parcial'
    && $c['f4_enc'] === '1' && $c['f4_obs'] === '' && $c['d1_val'] === '' && $c['d2_val'] === '4' && $c['ins1'] === '' && mb_strlen($c['r71']) === implementacion::LARGO_PARRAFO
    && !isset($c['otro']), 'se limpia lo que llega: números, porcentajes, alternativas, casillas y largos acotados');

// --- Etapa 9: la precarga ---
local_chatbot_verificar(count($pre['criterios']) === 4 && $pre['diseno_total'] === 4 && $pre['trabajados_ini'] === 3
    && $pre['criterios'][3]['pendiente'] === true && $pre['criterios'][3]['inicial'] === null && $pre['criterios'][0]['pendiente'] === false,
    'la tabla 2.1 trae todos los criterios del diseño y deja en blanco el logro inicial del pendiente');
local_chatbot_verificar($pre['criterios'][0]['inicial']['logro'] === 81.8 && $pre['criterios'][1]['inicial']['logro'] === 63.6 && $pre['criterios'][2]['inicial']['logro'] === 72.7
    && $pre['criterios'][1]['inicial']['ins'] === 2 && $pre['criterios'][1]['inicial']['presentes'] === 22, 'el logro inicial sale de la ficha de la implementación (82, 64 y 73 %)');
local_chatbot_verificar($pre['s1']['plan'] === $raw['plan'] && $pre['s1']['exp_continuidad'] !== '' && $pre['s1']['presentes_ini'] === 22 && $pre['s1']['fechas_ini'] === '22 de marzo de 2026.'
    && $pre['s1']['facilitador'] === 'Marta Ramírez Fuentes.' && $pre['fecha'] === '15 de mayo de 2026', 'la identificación se precarga desde las fases anteriores');
$extra = $impl;
$extra['criterio5'] = 'Un criterio que el diseño no declara.';
$extra['ev5'] = 'x';
[$extra['ins5'], $extra['bas5'], $extra['ade5'], $extra['ava5']] = [1, 1, 10, 10];
$pre2 = expost::preload(implementacion::clean($extra), $ref, '', 'hoy');
local_chatbot_verificar(count($pre2['criterios']) === 5 && $pre2['criterios'][4]['pendiente'] === false && $pre2['criterios'][4]['inicial'] !== null && $pre2['s1']['exp_continuidad'] === '',
    'un criterio de la ficha que el diseño no recoge también se informa, con su logro inicial');
local_chatbot_verificar(expost::continuity_name("1. NOMBRE DE LA EXPERIENCIA DE APRENDIZAJE DE CONTINUIDAD\nComunicación escrita y oral: nivelación y profundización\n2. PROPÓSITO") === 'Comunicación escrita y oral: nivelación y profundización'
    && expost::continuity_name("Nombre de la experiencia de continuidad: Taller de cierre (7 palabras).") === 'Taller de cierre'
    && expost::continuity_name('Sin ese dato') === '', 'el nombre de la experiencia de continuidad se lee de la etapa 6');
$h1 = expost::preload_fingerprint($impl, 5, 'd', [5 => 'a', 6 => 'b']);
local_chatbot_verificar($h1 === expost::preload_fingerprint($impl, 5, 'd', [6 => 'b', 5 => 'a']) && $h1 !== expost::preload_fingerprint($impl, 5, 'd', [5 => 'a', 6 => 'c'])
    && $h1 !== expost::preload_fingerprint($impl, 6, 'd', [5 => 'a', 6 => 'b']), 'la huella de la precarga cambia si cambia lo que la alimenta');

// --- Etiquetas: hasta 10 palabras, hasta 4 por tabla ---
$json = json_encode(['didacticas' => [['etiqueta' => 'Taller práctico con demostración guiada – foco en la redacción de correos profesionales claros', 'experiencia' => 'inicial'],
    ['etiqueta' => 'Trabajo en parejas – nivelación', 'experiencia' => 'Continuidad'], ['etiqueta' => 'trabajo en parejas – nivelación', 'experiencia' => 'continuidad'],
    ['etiqueta' => 'A', 'experiencia' => 'inicial'], ['etiqueta' => 'B'], ['etiqueta' => 'C']],
    'evaluativas' => [['etiqueta' => 'Rúbrica – correo (criterios 1 y 2)', 'experiencia' => 'inicial']], 'practicas' => ['Demostración guiada', 'Rúbrica antes de la tarea', '']],
    JSON_UNESCAPED_UNICODE);
$l = expost::parse_labels($json);
local_chatbot_verificar($l !== null && count($l['didacticas']) === 4 && count(explode(' ', $l['didacticas'][0]['etiqueta'])) === 10 && $l['didacticas'][1]['exp'] === 'continuidad'
    && $l['didacticas'][3]['exp'] === 'inicial' && $l['evaluativas'][0]['exp'] === 'inicial' && $l['practicas'] === ['Demostración guiada', 'Rúbrica antes de la tarea'],
    'las etiquetas respetan el prompt: máximo 10 palabras, 4 por tabla, sin repetidas y con su experiencia');
local_chatbot_verificar(expost::parse_labels('no es json') === null && expost::parse_labels('{"didacticas": [], "evaluativas": [], "practicas": []}') === null
    && expost::parse_labels("```json\n{\"practicas\": [\"Una\"]}\n```")['practicas'] === ['Una'], 'una respuesta ilegible o vacía no sirve; el JSON dentro de un bloque de código sí');

// --- Los cálculos del caso del cliente ---
$filas = expost::rows($data, $pre);
local_chatbot_verificar(count($filas) === 4 && array_column($filas, 'logro') === [95.5, 90.9, 95.5, 77.3] && array_column($filas, 'suma') === [22, 22, 22, 22]
    && array_column($filas, 'suma_ok') === [true, true, true, true] && array_column($filas, 'logro_ok') === [true, true, true, true],
    'el logro final recalculado es (Adecuado + Avanzado) ÷ evaluados × 100: 95, 91, 95 y 77 %');
$r = expost::results($data, $pre);
local_chatbot_verificar($r['filas'][0]['tipo'] === 'trabajado' && $r['filas'][3]['tipo'] === 'pendiente_resuelto' && $r['filas'][0]['variacion'] === 13.7
    && $r['filas'][3]['inicial'] === null && $r['filas'][3]['final'] === 77.3, 'la variación en puntos porcentuales se calcula; el pendiente resuelto no tiene logro inicial');
local_chatbot_verificar($r['filas'][0]['nivelacion'] === ['mov' => 3, 'tot' => 4] && $r['filas'][1]['nivelacion'] === ['mov' => 6, 'tot' => 8]
    && $r['filas'][2]['nivelacion'] === ['mov' => 5, 'tot' => 6] && $r['filas'][0]['profundizacion'] === 2 && $r['filas'][1]['profundizacion'] === 0,
    'la nivelación coincide con el ejemplo del cliente (3 de 4, 6 de 8 y 5 de 6) y la profundización con sus 2 personas');
local_chatbot_verificar($r['nivelacion'] === ['mov' => 14, 'tot' => 18] && $r['profundizacion'] === 2, 'la nivelación total es 14 de 18 situaciones, como en el ejemplo');
local_chatbot_verificar($r['promedio_inicial'] === 72.7 && $r['promedio_final'] === 94.0 && $r['promedio_pendientes'] === 77.3, 'logro promedio inicial 73 % y final 94 %, como la fundamentación del ejemplo');
local_chatbot_verificar($r['cobertura_inicial'] === 75.0 && $r['cobertura_final'] === 100.0 && $r['con_niveles_inicial'] === 3 && $r['con_niveles_final'] === 4 && $r['persisten'] === []
    && $r['aviso'] === null, 'la cobertura evaluativa pasa de 75 % a 100 % y no quedan pendientes');
$txt = expost::results_text($r);
local_chatbot_verificar(strpos($txt, 'DATOS CALCULADOS POR LA PLATAFORMA') === 0 && strpos($txt, 'Nivelación: 3 de 4 personas en nivelación') !== false
    && strpos($txt, 'pendiente resuelto (marcado «Sí»)') !== false && strpos($txt, 'No quedan pendientes sin resolver') !== false, 'los resultados viajan al modelo como datos calculados');

// Pendiente que persiste: «Parcialmente» o «No» quedan sin niveles y se informan como pendientes.
$dp = expost::normalize_pending(expost::clean(['pr4' => 'parcial'] + $e), $pre);
local_chatbot_verificar($dp['ins4'] === '' && $dp['lg4'] === '' && $dp['ade1'] === '15', 'los pendientes «Parcialmente» o «No» quedan sin niveles; los demás criterios no se tocan');
$rp = expost::results($dp, $pre);
local_chatbot_verificar($rp['filas'][3]['tipo'] === 'pendiente_persiste' && $rp['filas'][3]['final'] === null && $rp['persisten'][0]['k'] === 4 && $rp['cobertura_final'] === 75.0
    && $rp['promedio_pendientes'] === null && strpos(expost::results_text($rp), 'PENDIENTE QUE PERSISTE') !== false,
    'un pendiente que persiste se informa como tal, no cuenta en la cobertura ni en el logro');
local_chatbot_verificar(expost::normalize_pending(expost::clean(['pr4' => 'si'] + $e), $pre)['ade4'] === '14', 'con «Sí» los niveles del pendiente se conservan');

// --- Verificaciones de la plataforma ---
$ok = expost::system_checks($data, $pre);
local_chatbot_verificar(array_keys($ok['items']) === ['c1_2', 'c2_1', 'c2_2', 'c2_3', 'c2_4', 'c5_2']
    && array_unique(array_column($ok['items'], 'estado')) === [implementacion::CUMPLE], 'el ejemplo del cliente cumple todos los ítems que resuelve el código');
$mal = $e;
$mal['ade2'] = '17';
$s = expost::system_checks(expost::clean($mal), $pre);
local_chatbot_verificar($s['items']['c2_1']['estado'] === implementacion::NO_CUMPLE && strpos($s['items']['c2_1']['observacion'], 'criterio 2 (suma 21, evaluados 22)') !== false
    && in_array('c2_1', expost::CRITICOS, true), 'una suma por nivel distinta de los evaluados es ❌ en un ítem CRÍTICO');
$mal = $e;
[$mal['ade1'], $mal['ava1'], $mal['bas1'], $mal['ins1']] = [10, 2, 6, 4];
$s = expost::system_checks(expost::clean($mal), $pre);
local_chatbot_verificar($s['items']['c2_2']['estado'] === implementacion::NO_CUMPLE && strpos($s['items']['c2_2']['observacion'], 'criterio 1') !== false
    && $s['items']['c2_1']['estado'] === implementacion::CUMPLE, 'si disminuyen las personas en Adecuado o Avanzado se viola la no regresión (CRÍTICO)');
$mal = $e;
$mal['lg3'] = '90';
$s = expost::system_checks(expost::clean($mal), $pre);
local_chatbot_verificar($s['items']['c2_3']['estado'] === implementacion::PARCIAL && strpos($s['items']['c2_3']['observacion'], 'criterio 3: la ficha dice 90 % y corresponde 95,5 %') !== false
    && $s['items']['c2_1']['estado'] === implementacion::CUMPLE, 'un logro final mal calculado se recalcula y se registra como observación (⚠, no crítico)');
$mal = $e;
$mal['pr4'] = 'no';
$s = expost::system_checks(expost::clean($mal), $pre);
local_chatbot_verificar($s['items']['c2_4']['estado'] === implementacion::NO_CUMPLE && strpos($s['items']['c2_4']['observacion'], 'marcado «No» pero con niveles') !== false,
    'un pendiente marcado «No» con niveles registrados es incoherente');
$mal = $e;
$mal['pr4'] = 'si';
[$mal['ins4'], $mal['bas4'], $mal['ade4'], $mal['ava4']] = ['', '', '', ''];
$s = expost::system_checks(expost::clean($mal), $pre);
local_chatbot_verificar($s['items']['c2_4']['estado'] === implementacion::NO_CUMPLE && strpos($s['items']['c2_4']['observacion'], 'marcado «Sí» pero sin niveles') !== false,
    'un pendiente marcado «Sí» sin niveles es incoherente');
$mal = $e;
$mal['n_apl5'] = '15';
$s = expost::system_checks(expost::clean($mal), $pre);
local_chatbot_verificar($s['items']['c5_2']['estado'] === implementacion::NO_CUMPLE, 'quienes aplicaron lo aprendido no pueden superar a quienes tienen información');
$mal = $e;
$mal['n_seg5'] = '30';
$s = expost::system_checks(expost::clean($mal), $pre);
local_chatbot_verificar($s['items']['c5_2']['estado'] === implementacion::NO_CUMPLE, 'quienes tienen información no pueden superar a los evaluados al cierre');
$no = expost::system_checks(expost::clean(['op51' => 'no'] + $e), $pre);
local_chatbot_verificar($no['items']['c5_2']['estado'] === implementacion::CUMPLE, 'sin oportunidad de transferencia no se exigen los números del seguimiento');
$mal = $e;
$mal['evaluados'] = '50';
$s = expost::system_checks(expost::clean($mal), $pre);
local_chatbot_verificar($s['items']['c1_2']['estado'] === implementacion::PARCIAL, 'los evaluados al cierre no pueden superar la suma de los presentes de las dos experiencias');
$mal = $e;
$mal['evaluados'] = '10';
local_chatbot_verificar(expost::system_checks(expost::clean($mal), $pre)['items']['c1_2']['estado'] === implementacion::PARCIAL, 'ni quedar por debajo del mayor de los dos');
$sinpend = expost::system_checks($data, ['criterios' => array_slice($pre['criterios'], 0, 3)] + $pre);
local_chatbot_verificar(!isset($sinpend['items']['c2_4']) && in_array('No hay criterios pendientes de la experiencia inicial.', $sinpend['hechos'], true),
    'sin pendientes el ítem de pendientes no se evalúa por cálculo');
local_chatbot_verificar(expost::form_warnings($data, $pre) === [] && count(expost::form_warnings(expost::clean(['ade2' => '17'] + $e), $pre)) === 2,
    'los avisos antes de validar salen de las mismas reglas fijas');

// --- Control de entrada ---
local_chatbot_verificar(expost::missing_inputs($data, $pre, $etq) === [], 'la ficha del ejemplo del cliente supera el control de entrada');
$f = expost::missing_inputs(expost::clean([]), $pre, $etq);
local_chatbot_verificar(count($f) > 40 && in_array('Sección 1: Fecha(s) de la experiencia de continuidad', $f, true)
    && in_array('Sección 1: N.º de participantes evaluados al cierre del ciclo', $f, true) && in_array('Tabla 2.1, criterio 1: N.º de participantes en nivel Avanzado', $f, true)
    && in_array('Subsección 2.2, criterio 4: ¿se trabajó y evaluó en la experiencia de continuidad?', $f, true) && in_array('Subsección 3.3: Fundamentación', $f, true)
    && in_array('Subsección 6.4, buena práctica 4: fundamentación', $f, true) && in_array('7.4 Recomendaciones para una nueva ejecución del curso', $f, true),
    'una ficha vacía pide cada campo obligatorio con su sección');
local_chatbot_verificar(!in_array('Tabla 2.1, criterio 4: logro final (%)', expost::missing_inputs(expost::clean(['pr4' => 'parcial'] + $e), $pre, $etq), true)
    && !array_filter(expost::missing_inputs(expost::clean(['pr4' => 'no', 'ins4' => '', 'bas4' => '', 'ade4' => '', 'ava4' => '', 'lg4' => ''] + $e), $pre, $etq)),
    'los pendientes «Parcialmente» o «No» no piden niveles');
$f = expost::missing_inputs(expost::clean(['pr4' => 'si', 'ins4' => '', 'lg4' => ''] + $e), $pre, $etq);
local_chatbot_verificar(in_array('Tabla 2.1, criterio 4: N.º de participantes en nivel Insuficiente', $f, true) && in_array('Tabla 2.1, criterio 4: logro final (%)', $f, true),
    'un pendiente marcado «Sí» sí pide sus niveles');
local_chatbot_verificar(expost::missing_inputs(expost::clean(['f4_enc' => '', 'n_enc' => ''] + $e), $pre, $etq) === []
    && in_array('Sección 4: N.º de participantes que respondieron la encuesta', expost::missing_inputs(expost::clean(['n_enc' => ''] + $e), $pre, $etq), true),
    'el número de encuestados solo se pide si se aplicó una encuesta');
local_chatbot_verificar(count(array_filter(expost::missing_inputs(expost::clean(['f4_enc' => '', 'f4_obs' => '', 'f4_otra' => ''] + $e), $pre, $etq),
    static function(string $x): bool {
        return strpos($x, '4.1 Fuente') === 0;
    })) === 1 && in_array('4.1 Otra fuente (indica cuál)', expost::missing_inputs(expost::clean(['f4_otra' => '1'] + $e), $pre, $etq), true),
    'se pide al menos una fuente de la reacción, y cuál si es «Otra»');
$f = expost::missing_inputs(expost::clean(['op51' => 'no', 'motivo5' => '', 'momento5' => '', 'n_seg5' => '', 'n_apl5' => '', 'grado54' => '', 'ejemplos5' => '', 'factores5' => '',
    'f5_auto' => '', 'f5_prac' => ''] + $e), $pre, $etq);
local_chatbot_verificar($f === ['5.1 Motivo por el que no hubo oportunidad de transferencia'], 'con «No» en 5.1 solo se pide el motivo');
$f = expost::missing_inputs(expost::clean(['op51' => 'si', 'momento5' => '', 'ejemplos5' => '', 'f5_auto' => '', 'f5_prac' => ''] + $e), $pre, $etq);
local_chatbot_verificar(in_array('5.2 Fuente de la información sobre la transferencia (marca al menos una)', $f, true) && in_array('5.3 Momento de la recogida de información', $f, true)
    && in_array('5.4 Ejemplos de aplicación', $f, true), 'con «Sí» o «Parcialmente» se piden las fuentes, el alcance, los ejemplos y los factores');
$f = expost::missing_inputs(expost::clean(['d5_nombre' => 'Mi estrategia'] + $e), $pre, $etq);
local_chatbot_verificar(in_array('Subsección 3.1, fila libre 1: experiencia', $f, true) && in_array('Subsección 3.1, fila libre 1: efectividad', $f, true)
    && !in_array('Subsección 3.1, fila libre 2: nombre', $f, true), 'una fila libre sin usar es opcional; si se usa, se completa entera');
local_chatbot_verificar(expost::missing_inputs($data, $pre, ['didacticas' => [], 'evaluativas' => [], 'practicas' => []]) === []
    && count(expost::missing_inputs(expost::clean(['exp_cont' => ''] + $e), ['s1' => ['exp_continuidad' => '']] + $pre, $etq)) === 1,
    'sin etiquetas precargadas no se exige nada de esas tablas; sin el nombre precargado de la continuidad se pide');

// --- Regla de decisión ---
$items = [];
foreach (expost::checklist_ids() as $id) {
    $items[$id] = ['estado' => implementacion::CUMPLE, 'observacion' => '', 'propuesta' => ''];
}
local_chatbot_verificar(expost::decide($items) === implementacion::APROBADO && expost::decision_rule($items) === 'todo_cumple' && expost::critical_failures($items) === [],
    'todos los ítems en ✔ es Aprobado');
$a = $items;
$a['c3_2']['estado'] = implementacion::PARCIAL;
local_chatbot_verificar(expost::decide($a) === implementacion::OBSERVACIONES && expost::decision_rule($a) === 'no_criticos', '⚠ en un ítem no crítico es Aprobado con observaciones');
$a = $items;
$a['c4_2']['estado'] = implementacion::NO_CUMPLE;
local_chatbot_verificar(expost::decide($a) === implementacion::OBSERVACIONES, '❌ en un ítem no crítico tampoco rechaza la ficha: los resultados desfavorables son hallazgos');
foreach (expost::CRITICOS as $id) {
    $a = $items;
    $a[$id]['estado'] = implementacion::NO_CUMPLE;
    $b = $items;
    $b[$id]['estado'] = implementacion::PARCIAL;
    local_chatbot_verificar(expost::decide($a) === implementacion::NO_APROBADO && expost::decision_rule($a) === 'critico_no' && expost::decide($b) === implementacion::NO_APROBADO
        && expost::decision_rule($b) === 'critico_parcial' && expost::critical_failures($b) === [$id], "❌ o ⚠ en el ítem CRÍTICO {$id} es No aprobado");
    $b[$id]['forma'] = true;
    local_chatbot_verificar(expost::decide($b) === implementacion::OBSERVACIONES && expost::decision_rule($b) === 'forma',
        "una diferencia de forma en {$id} (por ejemplo, un error de tipeo) solo da Aprobado con observaciones");
}
local_chatbot_verificar(expost::decide([]) === implementacion::NO_APROBADO, 'un informe sin ítems no aprueba');
local_chatbot_verificar(array_keys(expost::unresolved_items($a)) === [array_key_last($a) === 'c8_2' ? 'c8_1' : 'c8_1'] || count(expost::unresolved_items($a)) === 1,
    'el registro de observaciones no resueltas reúne los ítems en ⚠ o ❌');
$calc = expost::apply_system_checks($items, ['c2_1' => ['estado' => 'no_cumple', 'observacion' => 'x', 'propuesta' => 'y'],
    'c3_1' => ['estado' => 'no_cumple', 'observacion' => 'z', 'propuesta' => 'w']]);
local_chatbot_verificar($calc['c2_1']['estado'] === 'no_cumple' && $calc['c2_1']['origen'] === 'calculo' && $calc['c3_1']['estado'] === 'cumple',
    'el resultado del código reemplaza al del modelo en los ítems que calcula y solo en esos');

// --- Respuesta del modelo en la validación ---
$resp = ['items' => [], 'debilidades' => ['a', 'b'], 'sugerencias' => ['c']];
foreach (expost::checklist_ids() as $id) {
    $resp['items'][$id] = ['estado' => 'cumple', 'observacion' => '', 'propuesta' => 'no debería quedar', 'forma' => true];
}
$resp['items']['c8_1'] = ['estado' => 'parcial', 'observacion' => 'tipeo', 'propuesta' => 'corrige', 'forma' => 'true'];
$rep = expost::parse_report(json_encode($resp, JSON_UNESCAPED_UNICODE));
local_chatbot_verificar($rep !== null && count($rep['items']) === 25 && $rep['items']['c1_1']['propuesta'] === '' && !isset($rep['items']['c1_1']['forma'])
    && $rep['items']['c8_1']['forma'] === true && $rep['debilidades'] === ['a', 'b'], 'se lee el informe: la propuesta solo si no cumple y «forma» solo en un crítico ⚠');
unset($resp['items']['c5_4']);
local_chatbot_verificar(expost::parse_report(json_encode($resp)) === null && expost::parse_report('no json') === null, 'si falta un ítem el informe no sirve: el checklist va entero o no va');

// --- Los pedidos al modelo ---
$p = expost::build_validation_prompt(['Plan Formativo'], true, false);
local_chatbot_verificar(strpos($p, 'ETAPA 10, VALIDACIÓN DE LA FICHA EX POST') !== false && strpos($p, 'c2_2: Se respeta el principio de no regresión') !== false
    && strpos($p, 'c2_1: En cada criterio, la suma de participantes por nivel es igual al número de participantes evaluados al cierre del ciclo [CRÍTICO]') !== false
    && strpos($p, 'REGLA DE DECISIÓN DE LA ETAPA 10') !== false && substr_count($p, '[CRÍTICO]') >= 3 && strpos($p, 'Principio de no regresión') !== false
    && strpos($p, 'no es motivo') === false || strpos($p, 'nunca motivo de rechazo') !== false, 'la validación lleva el checklist completo, la regla de decisión y los principios de la fase');
local_chatbot_verificar(strpos($p, 'c1_2, c2_1, c2_2, c2_3, c2_4 y c5_2') !== false && strpos($p, '"items"') !== false, 'le dice al modelo qué ítems resuelve la plataforma y en qué formato responder');
$pl = expost::build_labels_prompt(['diseno' => 'DISEÑO', 'implementacion' => 'FICHA', 'previas' => [5 => 'Buenas prácticas'], 'adjuntos' => ['Plan Formativo'], 'conlibreria' => true, 'conbusqueda' => false]);
local_chatbot_verificar(strpos($pl, 'ETAPA 9: PREPARACIÓN DE LA FICHA EX POST') !== false && strpos($pl, 'una etiqueta por estrategia, no por actividad') !== false
    && strpos($pl, 'No precargues instrumentos omitidos') !== false && strpos($pl, '«tipo de estrategia – foco principal»') !== false
    && strpos($pl, '«instrumento – evidencia (criterios)»') !== false && strpos($pl, 'Máximo 4 etiquetas por tabla') !== false && strpos($pl, 'máximo 10 palabras') !== false
    && strpos($pl, 'PRODUCTO ACEPTADO DE LA ETAPA 5') !== false, 'la etapa 9 lleva las reglas de las etiquetas del prompt');
$ctx = ['ficha' => 'FICHA EX POST', 'datos' => expost::results_text($r), 'condicion' => 'aprobado', 'informe' => ['items' => []], 'implementacion' => 'IMPL', 'diseno' => 'DISEÑO',
    'previas' => [5 => 'E5', 11 => 'E11'], 'adjuntos' => [], 'actual' => '', 'ajustes' => '', 'conlibreria' => false, 'conbusqueda' => false];
foreach ([11, 12, 13, 14] as $n) {
    $pe = expost::build_prompt($n, $ctx);
    local_chatbot_verificar(strpos($pe, 'TAREA: ETAPA ' . $n . ': ' . mb_strtoupper(expost::etapas()[$n]['titulo'])) !== false && strpos($pe, 'DATOS CALCULADOS POR LA PLATAFORMA') !== false
        && strpos($pe, 'PRINCIPIOS DE LA FASE 4') !== false && strpos($pe, 'Principio de no regresión') !== false && strpos($pe, 'PRODUCTO ACEPTADO DE LA ETAPA 11') !== false
        && strpos($pe, 'FICHA DE EVALUACIÓN EX POST (VALIDADA EN LA ETAPA 10)') !== false && strpos($pe, 'Empieza directamente con el título «## Etapa ' . $n . ': ') !== false
        && strpos($pe, 'No elabores una nueva versión del diseño') !== false && strpos($pe, 'FICHA DE REGISTRO DE LA IMPLEMENTACIÓN') !== false,
        "el pedido de la etapa {$n} lleva los datos calculados, los principios de la fase y los productos previos");
}
local_chatbot_verificar(strpos(expost::build_prompt(14, $ctx), 'insumos para el diagnóstico de una nueva ejecución y no reemplazan ese diagnóstico') !== false
    && strpos(expost::build_prompt(12, $ctx), 'acertada, parcialmente acertada o no acertada') !== false
    && strpos(expost::build_prompt(11, $ctx), 'Los movimientos de nivel se estiman') !== false, 'las indicaciones propias de cada etapa vienen del prompt');
local_chatbot_verificar(strpos(expost::build_prompt(11, ['ajustes' => 'Más corto', 'actual' => 'ACTUAL'] + $ctx), 'AJUSTES QUE PIDE LA PERSONA') !== false
    && strpos(expost::build_prompt(11, $ctx), 'AJUSTES QUE PIDE LA PERSONA') === false, 'los ajustes de la persona se piden solo cuando los hay');
$pc = expost::build_consolidation_prompt([10 => '<h2>V</h2>', 11 => '<p>a</p>', 12 => '<p>b</p>', 13 => '<p>c</p>', 14 => '<p>d</p>']);
local_chatbot_verificar(strpos($pc, 'ETAPA 15: CONSOLIDACIÓN (OBLIGATORIA)') !== false && strpos($pc, '"en extenso"') !== false && strpos($pc, 'de la 10 a la 14') !== false
    && strpos($pc, 'PRODUCTO ACEPTADO DE LA ETAPA 10 (Validación de la ficha ex post)') !== false, 'la consolidación pide el documento en extenso, sin repetir campos comunes');
local_chatbot_verificar(strpos(expost::to_text($data, $pre, $etq), 'Marta') === false && strpos(expost::to_text($data, $pre, $etq), '(registrado)') !== false
    && strpos(expost::to_text($data, $pre, $etq), 'Taller práctico – demostración guiada [precargada] | Experiencia: Inicial | Efectividad: 3 – Alta') !== false
    && strpos(expost::to_text($data, $pre, $etq), 'Se descarta') !== false, 'la ficha viaja como texto, sin el nombre de la persona facilitadora');

// --- Activación y acceso ---
local_chatbot_verificar(expost::activation(false, true) === 'fases' && expost::activation(false, false) === 'fases' && expost::activation(true, false) === 'continuidad'
    && expost::activation(true, true) === null, 'la Fase 4 solo se activa con las Fases 1 a 3 completas y la continuidad implementada');
local_chatbot_verificar(expost::can_open(11, true, []) && !expost::can_open(11, false, []) && !expost::can_open(12, true, []) && expost::can_open(12, true, [11])
    && !expost::can_open(14, true, [11, 12]) && expost::can_open(15, true, [11, 12, 13, 14]) && !expost::can_open(10, true, [11]) && !expost::can_open(16, true, [11, 12, 13, 14]),
    'no se abre una etapa sin la ficha ex post validada ni sin aceptar la anterior');
local_chatbot_verificar(expost::current_stage([]) === 11 && expost::current_stage([11, 12]) === 13 && expost::current_stage([11, 12, 13, 14]) === 15
    && expost::current_stage(range(11, 15)) === 15, 'la etapa en que toca trabajar es la primera sin aceptar');
local_chatbot_verificar(expost::is_phase4(9) && expost::is_phase4(15) && !expost::is_phase4(8) && !expost::is_phase4(16)
    && expost::max_seconds(15) === expost::CONSOLIDACION_MAX && expost::max_seconds(11) === implementacion::PROCESO_MAX
    && calidad::max_seconds(15) === calidad::CONSOLIDACION_MAX, 'las etapas de la Fase 4 se reconocen y la consolidación tiene más tiempo');

// --- Revisión del producto, consolidación y documento ---
$prod11 = '<h2>Etapa 11</h2><h3>1. Tabla de logro por criterio</h3><p>x</p><h3>2. Logro promedio inicial y final</h3><h3>3. Cobertura evaluativa al cierre</h3>'
    . '<h3>4. Movimientos de nivel</h3><h3>5. Efectividad de las estrategias didácticas y evaluativas</h3><h3>6. Reacción de las personas participantes</h3>'
    . '<h3>7. Transferencia al contexto laboral</h3><h3>8. Efectividad de los ajustes</h3><h3>Registro de vacíos de información</h3>'
    . '<h3>Verificación de calidad de la etapa</h3><h3>Referencias consultadas</h3>';
local_chatbot_verificar(expost::missing_items(11, $prod11) === [] && expost::missing_items(11, '') === [], 'un producto completo no tiene faltantes (y uno vacío no se revisa)');
$falta = expost::missing_items(11, str_replace(['<h3>3. Cobertura evaluativa al cierre</h3>', '<h3>Referencias consultadas</h3>'], '', $prod11));
local_chatbot_verificar(in_array('Cobertura evaluativa', $falta, true) && in_array('Referencias consultadas', $falta, true) && count($falta) === 2, 'se detecta lo que pide la etapa y no aparece');
$cons = '<h1>Etapa 10: Validación</h1><h1>Etapa 11: Resultados</h1><h1>Etapa 12: Análisis</h1><h1>Etapa 13: Prácticas</h1><h1>Etapa 14: Recomendaciones</h1>';
local_chatbot_verificar(expost::missing_items(15, $cons) === [] && count(expost::missing_items(15, '<h1>Etapa 10: V</h1><h1>Etapa 12: A</h1>')) === 3,
    'la consolidación debe traer las partes 10 a 14');
$filas = [11 => local_chatbot_fila(calidad::ACEPTADO, 'A'), 12 => local_chatbot_fila(calidad::ACEPTADO, 'B'), 13 => local_chatbot_fila(calidad::BORRADOR, 'C')];
$hu = expost::stages_fingerprint($filas, 'v1');
local_chatbot_verificar($hu === expost::stages_fingerprint($filas, 'v1') && $hu !== expost::stages_fingerprint($filas, 'v2')
    && $hu !== expost::stages_fingerprint([13 => local_chatbot_fila(calidad::ACEPTADO, 'C')] + $filas, 'v1')
    && expost::accepted($filas, 'v1') === [11, 12], 'la huella de la consolidación cambia si cambia una etapa aceptada o la validación; solo cuentan las aceptadas');
$filas[13] = local_chatbot_fila(calidad::ACEPTADO, 'C');
$filas[14] = local_chatbot_fila(calidad::ACEPTADO, 'D');
$huella = expost::stages_fingerprint($filas, 'v1');
$filas[15] = local_chatbot_fila(calidad::ACEPTADO, 'DOC', ['huella' => $huella]);
local_chatbot_verificar(expost::accepted($filas, 'v1') === [11, 12, 13, 14, 15], 'la consolidación confirmada cuenta si las etapas no cambiaron');
$filas[12] = local_chatbot_fila(calidad::ACEPTADO, 'B2');
local_chatbot_verificar(expost::accepted($filas, 'v1') === [11, 12, 13, 14], 'si cambia una etapa después de confirmarla, hay que consolidar de nuevo');
local_chatbot_verificar(expost::accepted($filas, 'v9') === [11, 12, 13, 14] && !in_array(15, expost::accepted($filas, 'v1'), true), 'y también si cambia la validación');
[$mostrado, $porasistente] = expost::consolidation_shown(null, '<h2>Etapa 10: V</h2>', [11 => '<h2>Etapa 11: R</h2>', 14 => '<h2>Etapa 14: Rec</h2>'], 'h');
local_chatbot_verificar($porasistente === false && strpos($mostrado, 'Etapa 10') < strpos($mostrado, 'Etapa 11') && strpos($mostrado, 'Etapa 11') < strpos($mostrado, 'Etapa 14')
    && substr_count($mostrado, '<hr>') === 3, 'sin consolidación del asistente se muestra el documento completo por código, en orden');
[$mostrado, $porasistente] = expost::consolidation_shown(local_chatbot_fila(calidad::BORRADOR, '<h1>Etapa 10</h1><p>del asistente</p>', ['huella' => 'h', 'promptversion' => 'M3-F4-expost-v1']),
    '<h2>x</h2>', [], 'h');
local_chatbot_verificar($porasistente === true && strpos($mostrado, 'del asistente') !== false, 'con la consolidación del asistente al día se muestra esa');
[$mostrado, $porasistente] = expost::consolidation_shown(local_chatbot_fila(calidad::BORRADOR, '<h1>Vieja</h1>', ['huella' => 'otra']), '<h2>Etapa 10: V</h2>', [], 'h');
local_chatbot_verificar($porasistente === false && strpos($mostrado, 'Vieja') === false, 'una consolidación de etapas anteriores no se usa');

$informe = ['items' => $items, 'debilidades' => ['Falta precisión'], 'sugerencias' => ['Precisar'], 'regla' => 'todo_cumple'];
$informe['items']['c3_2'] = ['estado' => 'parcial', 'observacion' => 'Sin datos', 'propuesta' => 'Agrega datos'];
$vh = expost::validation_html('observaciones', $informe);
local_chatbot_verificar(strpos($vh, '<h2>Etapa 10: Validación de la ficha ex post</h2>') === 0 && substr_count($vh, '<table>') === 8 && strpos($vh, '(CRÍTICO)') !== false
    && strpos($vh, '⚠ Aprobado con observaciones') !== false && strpos($vh, '<h3>3. Principales debilidades</h3>') !== false && strpos($vh, '<h3>4. Sugerencias de mejora</h3>') !== false
    && strpos($vh, '<h3>5. Registro de observaciones no resueltas</h3>') !== false && strpos($vh, 'Sin datos Cómo corregirlo: Agrega datos') !== false,
    'el informe de la etapa 10 trae las cinco salidas obligatorias, una tabla por sección y los ítems CRÍTICO marcados');
$rs = expost::report_summary('observaciones', $informe);
local_chatbot_verificar(strpos($rs, 'RESULTADO DE LA VALIDACIÓN DE LA FICHA EX POST (ETAPA 10): ⚠ Aprobado con observaciones') === 0 && strpos($rs, 'c3_2') !== false
    && strpos($rs, 'Falta precisión') !== false, 'el resumen para las etapas 11 y 12 incluye el registro de observaciones no resueltas');
$cv = expost::final_cover($pre, $data, '9 de noviembre de 2026');
local_chatbot_verificar($cv['etiqueta'] === 'Evaluación ex post del ciclo formativo' && $cv['titulo'] === 'Elaboración de correos profesionales con criterios de calidad'
    && count($cv['lineas']) === 7 && $cv['lineas'][0][0] === 'Plan formativo' && $cv['lineas'][6] === ['Fecha del informe', '9 de noviembre de 2026'], 'la portada del documento final trae el plan, las experiencias y las fechas');

// El Word de la consolidacion es un documento valido (con el diseño del documento final del cliente).
if (class_exists('ZipArchive')) {
    $ruta = sys_get_temp_dir() . '/expost_' . getmypid() . '.docx';
    $doc = local_chatbot\diseno::normalize_product($vh . $prod11);
    $hecho = \local_chatbot\documento_final::build($ruta, $cv, $doc, __DIR__ . '/../pix/logo_interfases.png');
    $zip = new ZipArchive();
    $abierto = $hecho && $zip->open($ruta) === true;
    $xml = $abierto ? (string) $zip->getFromName('word/document.xml') : '';
    if ($abierto) {
        $zip->close();
    }
    @unlink($ruta);
    $dom = new DOMDocument();
    local_chatbot_verificar($abierto && $dom->loadXML($xml) && strpos($xml, 'Elaboración de correos profesionales con criterios de calidad') !== false && strpos($xml, 'Validación de la ficha ex post') !== false,
        'el documento final de la Fase 4 es un Word válido, con portada y las partes del informe');
}

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Fase 4 del Módulo 3 conforme: ficha y checklist textuales, precarga, cálculos del código, control de entrada, regla de decisión y acceso por etapas.\n";
}
exit($fallidos === 0 ? 0 : 1);

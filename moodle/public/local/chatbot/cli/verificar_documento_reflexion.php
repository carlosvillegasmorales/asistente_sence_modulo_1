<?php
/**
 * Verificador del documento operativo de reflexion sobre la practica (Modulo 3, Fase 4, salida de la Etapa 15; propuesta de
 * Interfases «Documento Operativo de Reflexion sobre la Practica - Evaluacion Ex Post»). Usa el caso del ejemplo de llenado
 * del cliente (Comunicacion efectiva). Solo funciones puras: corre sin base de datos.
 *
 * Uso: php local/chatbot/cli/verificar_documento_reflexion.php
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/ux.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/diseno.php');
require_once(__DIR__ . '/../classes/diagrama.php');
require_once(__DIR__ . '/../classes/recurso_export.php');
require_once(__DIR__ . '/../classes/documento_final.php');
require_once(__DIR__ . '/../classes/centro_recursos.php');
require_once(__DIR__ . '/../classes/documento_operativo.php');
require_once(__DIR__ . '/../classes/implementacion.php');
require_once(__DIR__ . '/../classes/calidad.php');
require_once(__DIR__ . '/../classes/expost.php');
require_once(__DIR__ . '/../classes/documento_continuidad.php');
require_once(__DIR__ . '/../classes/documento_reflexion.php');

use local_chatbot\calidad;
use local_chatbot\documento_reflexion as dr;
use local_chatbot\expost;
use local_chatbot\implementacion;
use local_chatbot\ux;

$total = 0;
$fallidos = 0;

/**
 * @param bool $condicion
 * @param string $descripcion
 */
function local_chatbot_verificar(bool $condicion, string $descripcion): void {
    global $fallidos, $total;
    $total++;
    if (!$condicion) {
        echo "  FALLA  {$descripcion}\n";
        $fallidos++;
    }
}

$tiene = static fn(string $t, string $b): bool => strpos($t, $b) !== false;

// --- El caso del ejemplo de llenado del cliente ---
$criterios = ['Redacta mensajes profesionales con claridad, coherencia y corrección ortográfica.',
    'Adapta el registro lingüístico al contexto y al interlocutor.',
    'Responde por escrito a consultas laborales de manera respetuosa, oportuna y pertinente.',
    'Estructura mensajes orales breves según el propósito comunicativo.'];
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
$pre = expost::preload($impl, $ref, 'Comunicación escrita y oral en situaciones laborales: nivelación y profundización.',
    '15 de mayo de 2026');
$e = ['fechas_cont' => '29 de marzo de 2026.', 'presentes_cont' => '22', 'evaluados' => '22', 'v42' => '4',
    'datos4' => 'Encuesta respondida por 20 de 22 participantes: satisfacción general 3,7 de 4. El 95 % recomendaría el curso.',
    'op51' => 'parcial', 'n_seg5' => '14', 'n_apl5' => '10', 'pr4' => 'si',
    'r74' => 'Diagnosticar la experiencia laboral del grupo.'];
foreach ([[0, 1, 15, 6, '95'], [0, 2, 18, 2, '91'], [0, 1, 18, 3, '95'], [1, 4, 14, 3, '77']] as $i => $n) {
    $k = $i + 1;
    [$e['ins' . $k], $e['bas' . $k], $e['ade' . $k], $e['ava' . $k], $e['lg' . $k]] = $n;
}
$data = expost::clean($e);
$r = expost::results($data, $pre);

// --- 1. Textos y fila interna ---
local_chatbot_verificar(ux::DEFAULTS['docreflexionm3'] === 1 && dr::ETAPA === 17 && dr::ETAPA !== calidad::OPERATIVO
        && !expost::is_phase4(dr::ETAPA),
    'el documento tiene su interruptor encendido y se guarda como fila interna 17, fuera de las etapas de la Fase 4');
$filasCal = [15 => (object) ['estado' => 'aceptado', 'huella' => 'x', 'contenido' => 'c'],
    17 => (object) ['estado' => 'aceptado', 'huella' => '', 'contenido' => 'doc']];
local_chatbot_verificar(!in_array(17, calidad::accepted($filasCal), true) && !in_array(17, expost::accepted($filasCal), true),
    'la fila del documento no cuenta como etapa aceptada en las Fases 2 a 4');
local_chatbot_verificar(dr::ETIQUETA === 'DOCUMENTO OPERATIVO · REFLEXIÓN SOBRE LA PRÁCTICA'
        && dr::SECCIONES[9] === 'Mi plan de desarrollo como persona facilitadora' && count(dr::SECCIONES) === 9
        && dr::MARCA_DIFERENCIA === '[REVISAR: diferencia con la ficha ex post]',
    'rótulo, nueve secciones y marca de diferencia textuales de la propuesta');

// --- 2. Sección 1 con los datos de la ficha ex post ---
$c = dr::cycle_data($data, $pre, $r, calidad::AJUSTE_REMEDIALES);
local_chatbot_verificar($tiene($c['campos']['Plan formativo y módulo'], 'Habilidades transversales para el trabajo, código PF-0245 · '
        . 'Módulo 1: Comunicación para el trabajo')
        && $c['campos']['Experiencia inicial'] === 'Elaboración de correos profesionales con criterios de calidad · 22 de marzo de 2026 · '
            . '22 participantes presentes'
        && $tiene($c['campos']['Experiencia de continuidad'], '29 de marzo de 2026 · 22 participantes presentes')
        && $c['campos']['Tipo de ajuste decidido'] === calidad::adjustment_name(calidad::AJUSTE_REMEDIALES)
        && $c['campos']['Participantes evaluados al cierre'] === '22',
    'sección 1: plan y módulo, experiencias con fechas y presentes, tipo de ajuste y evaluados, desde la ficha');
local_chatbot_verificar(count($c['criterios']) === 4 && $c['criterios'][0][1] === '81,8 %' && $c['criterios'][0][2] === '95,5 %'
        && $c['criterios'][0][3] === '+ 13,7 puntos' && $c['promedio'][3] === '+ 21,3 puntos' && $c['criterios'][3][1] === '— (pendiente)' && $c['criterios'][3][2] === '77,3 %'
        && $c['promedio'][1] === '72,7 %' && $c['promedio'][2] === '94 %',
    'sección 1: logro inicial, final y variación por criterio y promedio calculados por la plataforma');
local_chatbot_verificar($c['indicadores']['Cobertura evaluativa'] === '75 % al inicio · 100 % al cierre'
        && $c['indicadores']['Nivelación'] === '14 de 18 situaciones alcanzaron el nivel Adecuado'
        && $c['indicadores']['Profundización'] === '2 personas pasaron de Adecuado a Avanzado'
        && $c['indicadores']['Transferencia al trabajo'] === '10 de 14 personas con seguimiento aplicaron lo aprendido'
        && $tiene($c['indicadores']['Reacción de las personas participantes'], 'Satisfacción general: 4 – Muy alta · Encuesta respondida por 20 de 22')
        && $tiene($c['indicadores']['Brechas que persisten'], '9 situación(es) bajo el nivel Adecuado'),
    'sección 1: cobertura, nivelación 14 de 18, profundización 2, reacción, transferencia 10 de 14 y brechas, como en el ejemplo');
$sinOport = dr::cycle_data(expost::clean(['op51' => 'no', 'motivo5' => 'Participantes sin empleo al momento del seguimiento. Otro.']
    + $e), $pre, $r, null);
local_chatbot_verificar($sinOport['indicadores']['Transferencia al trabajo'] === 'Sin oportunidad de aplicación: Participantes sin '
        . 'empleo al momento del seguimiento.' && $sinOport['campos']['Tipo de ajuste decidido'] === '',
    'sección 1: sin oportunidad de transferencia se indica el motivo');
$s1 = dr::section1_html($sinOport);
local_chatbot_verificar($tiene($s1, '<h1>SECCIÓN 1 · El ciclo en una página</h1>') && $tiene($s1, '[REVISAR: dato no registrado en la ficha]')
        && substr_count($s1, '<table') === 3 && $tiene($s1, '<th>Variación</th>'),
    'sección 1: tres tablas de la propuesta; un dato que falta en la ficha queda con [REVISAR]');

// --- 3. Lo que fija el código en las secciones 2 a 8 ---
$modelo = '<h1>SECCIÓN 2 · Trayectoria del ciclo</h1><table><tr><th>Momento</th><th>Lo que ocurrió</th><th>Efecto en los aprendizajes</th></tr>'
    . '<tr><td>Diseño</td><td>x</td><td>y</td></tr></table>'
    . '<h1>SECCIÓN 3 · Decisiones clave</h1><table><tr><th>Decisión y momento</th><th>Evidencia y resultado</th><th>Calificación</th>'
    . '<th>Fundamentación</th><th>Mi lectura</th></tr><tr><td>Diseño · demostración</td><td>ev</td><td>Acertada</td><td>f</td>'
    . '<td>Creo que fue bueno</td></tr></table>'
    . '<h1>SECCIÓN 4 · Mi reflexión y la evidencia</h1><table><tr><th>Relación</th><th>Aspecto</th><th>Lo que muestra la evidencia</th>'
    . '<th>Mi comentario</th></tr><tr><td>Coincide con mi reflexión</td><td>a</td><td>d</td><td>texto inventado</td></tr></table>'
    . '<h1>SECCIÓN 5 · Lecciones aprendidas</h1><table><tr><th>Ámbito</th><th>Lección</th><th>¿Cómo la aplicaré en mi práctica?</th></tr>'
    . '<tr><td>Diseño</td><td>l</td><td>Así</td></tr></table>'
    . '<h1>SECCIÓN 6 · Prácticas que funcionaron</h1><table><tr><th>Práctica 1</th><th>Demostración guiada</th></tr>'
    . '<tr><td>Qué resolvió</td><td>q</td></tr><tr><td>¿La mantendré?</td><td>Sí</td></tr></table>'
    . '<h1>SECCIÓN 7 · Preguntas para la reflexión</h1><p><strong>Pregunta 1:</strong> ¿Qué?</p><p><strong>Pregunta 2:</strong> ¿Cómo?</p>'
    . '<h2>SECCIÓN 8 · Hacia una nueva ejecución</h2><h2>8.1 Recomendaciones por ámbito</h2><table><tr><th>Ámbito</th>'
    . '<th>Recomendación</th><th>Evidencia del ciclo</th><th>Prioridad</th><th>Considerar</th></tr><tr><td>Diagnóstico</td>'
    . '<td>r</td><td>e</td><td>Alta</td><td>Sí</td></tr></table><p><em>Origen: 14.1 a 14.5.</em></p>';
$doc = dr::assemble_html($modelo, $c);
$secs = dr::split_sections($doc);
local_chatbot_verificar(array_keys($secs) === range(1, 9), 'el documento trae las nueve secciones en orden');
local_chatbot_verificar(!$tiene($doc, 'Creo que fue bueno') && !$tiene($doc, 'texto inventado') && !$tiene($doc, '<td>Así</td>')
        && !$tiene($doc, '<td>Sí</td></tr></table>') && $tiene($secs[8], '<td>☐</td>') && $tiene($secs[3], '<td>Acertada</td>'),
    'las columnas de la persona facilitadora (Mi lectura, Mi comentario, ¿Cómo la aplicaré?, ¿La mantendré?) quedan en blanco y '
        . '«Considerar» con ☐');
local_chatbot_verificar($tiene($secs[3], '<th data-peso="' . dr::PESO_BLANCA . '">Mi lectura</th>')
        && $tiene($secs[5], 'data-peso="' . dr::PESO_BLANCA . '">¿Cómo la aplicaré') && !$tiene($secs[8], 'data-peso'),
    'las columnas para escribir a mano piden un ancho mínimo en el Word («Considerar» no)');
local_chatbot_verificar($tiene($secs[6], dr::TEXTO_S6) && $tiene($secs[7], dr::TEXTO_S7) && $tiene($secs[8], dr::TEXTO_S8)
        && $tiene($secs[9], dr::TEXTO_S9) && substr_count($secs[7], 'chatbot-respuesta') === 2,
    'textos fijos de las secciones 6 a 9 y un recuadro de respuesta bajo cada pregunta');
local_chatbot_verificar(substr_count($secs[9], '<tr>') === 4 && $tiene($secs[9], '<th>Apoyo que necesito</th>')
        && !$tiene($doc, 'Origen: 14.1') && $tiene($doc, '<h1>SECCIÓN 8 · Hacia una nueva ejecución</h1>')
        && substr_count($doc, '<table') === substr_count($doc, 'data-orientacion="v"'),
    'sección 9 en blanco (tres filas), sin notas «Origen» y todas las tablas en páginas verticales');
local_chatbot_verificar(dr::answer_spaces(dr::answer_spaces($secs[7])) === dr::answer_spaces($secs[7]),
    'el recuadro de respuesta no se duplica');
$incompleto = dr::assemble_html('<h1>SECCIÓN 2 · Trayectoria del ciclo</h1><p>x</p>', $c);
local_chatbot_verificar($tiene($incompleto, 'SECCIÓN 5 · Lecciones aprendidas</h1><p>[REVISAR: sección no extraída del informe '
        . 'consolidado]'), 'una sección que el asistente no entregó queda con [REVISAR]');

// --- 4. Prompt ---
$p = dr::build_prompt(['etapas' => [11 => 'RESULTADOS', 12 => 'METACOGNITIVO', 13 => 'PRÁCTICAS', 14 => 'RECOMENDACIONES'],
    'ficha' => 'FICHA EX POST', 'datos' => expost::results_text($r), 'ajuste' => 'Acciones remediales']);
local_chatbot_verificar($tiene($p, 'no agregues juicios, hallazgos ni recomendaciones, y no cambies logros, niveles ni valoraciones')
        && $tiene($p, dr::MARCA_DIFERENCIA) && $tiene($p, '| Decisión y momento | Evidencia y resultado | Calificación | Fundamentación | Mi lectura |')
        && $tiene($p, '## 8.2 Mis recomendaciones y su respaldo') && $tiene($p, 'No escribas la Sección 1 ni la Sección 9')
        && $tiene($p, 'DATOS CALCULADOS POR LA PLATAFORMA') && $tiene($p, 'METACOGNITIVO') && $tiene($p, 'Acciones remediales'),
    'el prompt pide una extracción de las Etapas 11 a 14 con los datos de la ficha, las tablas de la propuesta y sin juicios nuevos');

// --- 5. Versiones y Word ---
$etapas = [];
foreach ([11, 12, 13, 14] as $n) {
    $etapas[$n] = (object) ['estado' => 'aceptado', 'contenido' => 'etapa ' . $n];
}
$f1 = dr::source_fingerprint('val', $etapas, 'acciones_remediales');
$etapas[12]->contenido = 'otra';
local_chatbot_verificar($f1 !== dr::source_fingerprint('val', $etapas, 'acciones_remediales')
        && $f1 === dr::source_fingerprint('val', array_replace($etapas, [12 => (object) ['estado' => 'aceptado', 'contenido' => 'etapa 12']]),
            'acciones_remediales'),
    'una versión nueva solo si cambian las Etapas 11 a 14, la ficha o el tipo de ajuste');
$meta = dr::word_meta($pre, $data, '20 de mayo de 2026', 2);
local_chatbot_verificar($meta['titulo'] === 'Elaboración de correos profesionales con criterios de calidad'
        && $meta['subtitulo'] === 'Cuaderno de trabajo de la persona facilitadora · Ciclo formativo: 22 de marzo de 2026 y 29 de '
            . 'marzo de 2026 · Informe ex post: 20 de mayo de 2026 · versión 2'
        && $meta['etiqueta'] === dr::ETIQUETA && $meta['estilo'] === 'operativo',
    'cabecera del Word: experiencia inicial, cuaderno de trabajo, ciclo formativo, fecha del informe y versión');
$ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'local_chatbot_reflexion_test_' . uniqid('', true) . '.docx';
$logo = __DIR__ . '/../pix/logo_interfases.png';
local_chatbot_verificar(dr::build_docx($ruta, $meta, $doc, is_readable($logo) ? $logo : '') && is_file($ruta) && filesize($ruta) > 2000,
    'build_docx() escribe el Word');
if (is_file($ruta)) {
    $zip = new \ZipArchive();
    $xml = $zip->open($ruta) === true ? (string) $zip->getFromName('word/document.xml') : '';
    $zip->close();
    local_chatbot_verificar($tiene($xml, 'El ciclo en una página') && $tiene($xml, 'Mi plan de desarrollo como persona facilitadora')
            && $tiene($xml, '14 de 18 situaciones') && $tiene($xml, '☐') && !$tiene($xml, 'w:orient="landscape"'),
        'el Word trae las secciones, los datos de la ficha y las casillas, todo en páginas verticales');
    @unlink($ruta);
}

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Documento operativo de reflexión sobre la práctica (Módulo 3, Fase 4) conforme.\n";
    exit(0);
}
echo "Documento de reflexión NO conforme: revisar classes/documento_reflexion.php.\n";
exit(1);

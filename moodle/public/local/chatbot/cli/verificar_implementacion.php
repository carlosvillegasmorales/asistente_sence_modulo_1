<?php
/**
 * Verifica la Fase 1 del Modulo 3: la ficha de registro de la implementacion y su validacion con el checklist.
 *
 * Insumos del cliente (06-11-2026): la ficha, la guia de llenado, el ejemplo de llenado («Comunicacion efectiva»), el
 * checklist y el Prompt Maestro del Asistente de Aseguramiento de la Calidad de los Aprendizajes. La ficha y el checklist
 * van textuales; el control de entrada detiene la validacion si falta lo esencial; lo aritmetico (presentes, suma por nivel,
 * logro %, tiempos del diseño) lo decide el codigo y no el modelo; y la condicion final nunca es mas laxa que la regla del
 * checklist (un incumplimiento critico no se aprueba).
 *
 * El caso de prueba es la ficha del ejemplo del cliente: debe pasar el control de entrada y sus calculos deben dar los
 * numeros del propio ejemplo (82 %, 64 % y 73 %).
 *
 * Uso:  php cli/verificar_implementacion.php
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/implementacion.php');

use local_chatbot\exante;
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
 * La ficha del ejemplo de llenado del cliente («Elaboración de correos profesionales con criterios de calidad»).
 *
 * @return array Ficha limpia.
 */
function local_chatbot_ejemplo(): array {
    return implementacion::clean([
        'plan' => 'Habilidades transversales para el trabajo, código PF-0245.',
        'modulo' => 'Módulo 1: Comunicación para el trabajo.',
        'experiencia' => 'Elaboración de correos profesionales con criterios de calidad.',
        'facilitador' => 'Marta Ramírez Fuentes.', 'fechas' => '22 de marzo de 2026.', 'horario' => '09:00 a 12:00 h.',
        'inscritos' => '24', 'presentes' => '22', 'tiempo_total' => '360', 'tiempo_asignado' => '180',
        'tiempo_utilizado' => '180',
        'aprendizaje' => 'Comunicar información de manera clara, respetuosa y pertinente en contextos laborales, adaptando el '
            . 'lenguaje al interlocutor y al propósito comunicativo.',
        'criterio1' => 'Redacta mensajes profesionales con claridad, coherencia y corrección ortográfica.',
        'criterio2' => 'Adapta el registro lingüístico al contexto y al interlocutor.',
        'criterio3' => 'Responde por escrito a consultas laborales de manera respetuosa, oportuna y pertinente.',
        'no_trabajados' => 'El diseño incluye los 4 criterios del aprendizaje esperado comprometidos en el plan formativo y se '
            . 'trabajaron 3. Criterio 4, «Estructura mensajes orales breves según el propósito comunicativo»: no se trabajó '
            . 'porque la simulación telefónica prevista (actividad 4, 20 minutos) se omitió por falta de tiempo.',
        'val31' => '3', 'com31' => 'Incorporación: se agregó un ejercicio de 20 minutos. Omisión: la actividad 4 no se realizó.',
        'val32' => '3', 'com32' => 'Omisión: el criterio 4 no se trabajó (ver 2.2).',
        'val33' => '4', 'com33' => 'Sin cambios: taller práctico con demostración guiada.',
        'val34' => '3', 'com34' => 'Omisión: no se aplicó la lista de cotejo de autoevaluación por falta de tiempo.',
        'ev1' => 'Correo profesional (producto), actividad 2, evaluado con rúbrica. 22 participantes.',
        'ins1' => '0', 'bas1' => '4', 'ade1' => '14', 'ava1' => '4', 'lg1' => '82',
        'cm1' => 'La mayoría estructuró el correo con saludo, cuerpo y despedida. 4 participantes presentaron errores.',
        'ev2' => 'Análisis de correos, actividad 1, y correo, actividad 2, con rúbrica. 22 participantes.',
        'ins2' => '2', 'bas2' => '6', 'ade2' => '12', 'ava2' => '2', 'lg2' => '64',
        'cm2' => '8 participantes usaron lenguaje demasiado informal o ambiguo para el interlocutor.',
        'ev3' => 'Respuesta escrita a una consulta de cliente (producto), actividad 3, con rúbrica. 22 participantes.',
        'ins3' => '1', 'bas3' => '5', 'ade3' => '13', 'ava3' => '3', 'lg3' => '73',
        'cm3' => 'La mayoría respondió con cortesía. 6 participantes omitieron datos clave.',
        'val51' => '3', 'com51' => 'El aula fue adecuada. Fue necesario reorganizar las mesas.',
        'val52' => '4', 'com52' => 'El proyector funcionó correctamente.',
        'val53' => '4', 'com53' => 'Las guías de trabajo estuvieron disponibles y fueron pertinentes.',
        'val54' => '4', 'com54' => 'Participación activa y generalizada.',
        'val61' => 'alto',
        'fund61' => 'En el criterio 1, 18 de 22 participantes (82 %) alcanzaron nivel Adecuado o Avanzado según la rúbrica.',
        'pert62' => 'La demostración guiada al inicio fue clave.',
        'dific63' => 'El tiempo asignado a la actividad 2 resultó insuficiente.',
    ]);
}

/**
 * El diseño del Modulo 2 al que corresponde el ejemplo.
 *
 * @return array design_reference().
 */
function local_chatbot_referencia(): array {
    $e = local_chatbot_ejemplo();
    return implementacion::design_reference(['plan' => $e['plan'], 'modulo' => $e['modulo'], 'aprendizaje' => $e['aprendizaje'],
        'criterio1' => $e['criterio1'], 'criterio2' => $e['criterio2'], 'criterio3' => $e['criterio3'],
        'criterio4' => 'Estructura mensajes orales breves según el propósito comunicativo.', 'tiempo_total' => '360',
        'tiempo_experiencia' => '180'], $e['experiencia']);
}

// --- La ficha es la del documento del cliente ---
$secciones = implementacion::sections();
local_chatbot_verificar(array_keys($secciones) === range(1, 6), 'la ficha tiene 6 secciones en orden');
local_chatbot_verificar(count($secciones[1]['campos']) === 11, 'la sección 1 tiene 8 campos de identificación y los 3 tiempos de 1.1');
local_chatbot_verificar(count($secciones[2]['campos']) === 17, 'la sección 2 tiene el aprendizaje esperado, 15 criterios y el campo 2.2');
local_chatbot_verificar(count($secciones[3]['campos']) === 8 && count($secciones[5]['campos']) === 8,
    'las secciones 3 y 5 tienen cuatro valoraciones con su comentario cada una');
local_chatbot_verificar(count($secciones[6]['campos']) === 4, 'la sección 6 tiene 6.1, su fundamentación, 6.2 y 6.3');
local_chatbot_verificar($secciones[4]['campos'] === [], 'la sección 4 va en dos tablas (4.1 y 4.2), no como campos sueltos');
local_chatbot_verificar(count(implementacion::table_fields()) === 15 * 7 + 5 * 4,
    'la tabla 4.1 tiene 15 filas de 7 celdas y la 4.2 cinco filas de 4');
local_chatbot_verificar(implementacion::scale() === ['1' => '1 – Muy bajo', '2' => '2 – Bajo', '3' => '3 – Alto', '4' => '4 – Muy alto'],
    'la escala es 1 = Muy bajo a 4 = Muy alto');
foreach ([3 => ['val31', 'val32', 'val33', 'val34'], 5 => ['val51', 'val52', 'val53', 'val54']] as $n => $ids) {
    foreach ($ids as $id) {
        $campo = implementacion::field($id);
        local_chatbot_verificar($campo !== null && count($campo['descriptores']) === 4 && $campo['opciones'] === implementacion::scale(),
            "la valoración {$campo['num']} trae la escala y los cuatro descriptores de la guía");
    }
}
local_chatbot_verificar(array_keys(implementacion::field('val61')['opciones']) === ['muy_alto', 'alto', 'medio', 'bajo'],
    '6.1 se valora Muy alto, Alto, Medio o Bajo');
local_chatbot_verificar(implementacion::field('val31')['label'] === 'Las actividades realizadas se ajustaron a la secuencia instruccional diseñada'
    && implementacion::field('val34')['label'] === 'Se aplicó el plan de evaluación diseñado (momentos, evidencias e instrumentos)'
    && implementacion::field('val54')['label'] === 'Clima de aula (participación, respeto y colaboración)',
    'los rótulos de las valoraciones son los de la ficha');
local_chatbot_verificar(array_keys(implementacion::NIVELES) === ['ins', 'bas', 'ade', 'ava']
    && array_values(implementacion::NIVELES) === ['Insuficiente', 'Básico', 'Adecuado', 'Avanzado']
    && count(implementacion::level_help()) === 4, 'los cuatro niveles de desempeño son los de la guía');
local_chatbot_verificar(array_keys(implementacion::change_types()) === ['Omisión', 'Modificación', 'Sustitución', 'Incorporación'],
    'los tipos de cambio son los de la guía: omisión, modificación, sustitución e incorporación');
local_chatbot_verificar(implementacion::field('tiempo_total')['subtitulo'] === '1.1 Tiempos de la experiencia de aprendizaje'
    && strpos(implementacion::field('no_trabajados')['subtitulo'], '2.2 Criterios previstos para la experiencia que no se trabajaron') === 0,
    'las subsecciones 1.1 y 2.2 llevan su título');
$claves = implementacion::field_keys();
foreach (['plan', 'presentes', 'tiempo_utilizado', 'criterio15', 'no_trabajados', 'com54', 'val61', 'fund61', 'ev15', 'lg1',
        'ob5_comentario', 'ins3', 'ava15'] as $clave) {
    local_chatbot_verificar(isset($claves[$clave]), "la ficha guarda «{$clave}»");
}

// --- El checklist es el del documento del cliente ---
$checklist = implementacion::checklist();
local_chatbot_verificar(array_keys($checklist) === range(1, 8), 'el checklist tiene las 8 secciones');
local_chatbot_verificar(array_map('count', array_column($checklist, 'items')) === [5, 5, 3, 6, 3, 4, 5, 3],
    'cada sección del checklist tiene los ítems del documento (5, 5, 3, 6, 3, 4, 5, 3)');
$ids = implementacion::checklist_ids();
local_chatbot_verificar(count($ids) === 34 && count(array_unique($ids)) === 34, 'el checklist tiene 34 ítems sin repetir');
local_chatbot_verificar(implementacion::CRITICOS === ['c1_2', 'c1_4', 'c2_1', 'c2_2', 'c4_1', 'c4_2', 'c4_3', 'c8_2'],
    'los CRÍTICO son los ocho que marca el documento (1.2, 1.4, 2.1, 2.2, 4.1, 4.2, 4.3 y 8.2)');
local_chatbot_verificar(!array_diff(implementacion::CRITICOS, $ids) && !array_diff(implementacion::POR_CALCULO, $ids),
    'los CRÍTICO y los ítems por cálculo existen en el checklist');
$textos = implementacion::checklist_texts();
local_chatbot_verificar($textos['c1_2'] === 'El plan formativo, el módulo y la experiencia de aprendizaje corresponden a los del diseño instruccional validado'
    && $textos['c4_3'] === 'En cada criterio, la suma de participantes por nivel es igual al número de participantes presentes'
    && $textos['c4_4'] === 'El logro (%) está bien calculado: (Adecuado + Avanzado) ÷ presentes × 100'
    && $textos['c8_2'] === 'La información es coherente globalmente: la ficha registra la implementación de la experiencia del diseño validado y los datos de las distintas secciones no se contradicen entre sí'
    && $textos['c3_2'] === 'Toda valoración 1 (muy bajo) o 2 (bajo) tiene un comentario'
    && $textos['c7_5'] === 'Si el tiempo utilizado difiere del asignado, la diferencia se explica',
    'los ítems conservan el texto del documento del cliente');
local_chatbot_verificar($checklist[7]['titulo'] === 'Registro de cambios en la implementación' && $checklist[8]['titulo'] === 'Validación global',
    'las secciones 7 y 8 se llaman como en el documento');

// --- Limpieza de lo que llega del formulario ---
$vacia = implementacion::clean([]);
local_chatbot_verificar(count($vacia) === count($claves) && !array_filter($vacia, static function($v) {
    return $v !== '';
}), 'una ficha vacía trae todas las claves, sin valores');
$n = implementacion::clean(['presentes' => '22', 'inscritos' => '12.5', 'tiempo_total' => 'abc', 'tiempo_utilizado' => '00180',
    'ins1' => '-3', 'bas1' => ' 4 ']);
local_chatbot_verificar($n['presentes'] === '22' && $n['inscritos'] === '' && $n['tiempo_total'] === ''
    && $n['tiempo_utilizado'] === '180' && $n['ins1'] === '' && $n['bas1'] === '4', 'los números son enteros no negativos');
$p = implementacion::clean(['lg1' => '82', 'lg2' => '81,8', 'lg3' => '100', 'lg4' => '101', 'lg5' => '0', 'lg6' => '12.345',
    'lg7' => 'mucho', 'lg8' => '63.60']);
local_chatbot_verificar($p['lg1'] === '82' && $p['lg2'] === '81.8' && $p['lg3'] === '100' && $p['lg4'] === ''
    && $p['lg5'] === '0' && $p['lg6'] === '' && $p['lg7'] === '' && $p['lg8'] === '63.6',
    'el logro admite coma o punto, hasta 100 y dos decimales');
$u = implementacion::clean(['val31' => '4', 'val32' => '7', 'val61' => 'medio', 'val62' => 'x', 'val33' => 3]);
local_chatbot_verificar($u['val31'] === '4' && $u['val32'] === '' && $u['val61'] === 'medio' && $u['val33'] === '3',
    'las valoraciones solo admiten las opciones de la ficha');
$t = implementacion::clean(['plan' => str_repeat('a', 400), 'aprendizaje' => str_repeat('b', 5000), 'fechas' => "  hoy\r\n  "]);
local_chatbot_verificar(mb_strlen($t['plan']) === implementacion::LARGO_TEXTO && mb_strlen($t['aprendizaje']) === implementacion::LARGO_PARRAFO
    && $t['fechas'] === 'hoy', 'los textos se acotan y se recortan');
$otras = implementacion::clean(['plan' => 'x', 'clave_desconocida' => 'y']);
local_chatbot_verificar(!isset($otras['clave_desconocida']), 'las claves desconocidas no se guardan');

// --- Control de entrada ---
$ej = local_chatbot_ejemplo();
local_chatbot_verificar(implementacion::missing_inputs($ej, true) === [], 'la ficha del ejemplo del cliente pasa el control de entrada');
local_chatbot_verificar(implementacion::criteria_count($ej) === 3 && implementacion::criteria_indexes($ej) === [1, 2, 3],
    'el ejemplo registra tres criterios de evaluación');
local_chatbot_verificar(count(implementacion::missing_inputs($ej, false)) === 1
    && strpos(implementacion::missing_inputs($ej, false)[0], 'Diseño de la experiencia implementada') === 0,
    'sin diseño seleccionado no se valida: se pide el diseño');
$falta = implementacion::missing_inputs(implementacion::clean([]), true);
$todas = implode(' | ', $falta);
foreach (['Sección 1: Plan formativo (nombre y código)', 'Sección 1: N.º de participantes presentes', '1.1 Tiempo total disponible',
        '1.1 Tiempo efectivamente utilizado', '2.1 Aprendizaje esperado', '2.1 Criterios de evaluación (al menos uno)',
        '2.2 Criterios no trabajados y motivo', '6.1 En qué medida', '6.1 Fundamentación', '6.2 Aspectos del diseño',
        '6.3 Elementos del diseño'] as $esperado) {
    local_chatbot_verificar(strpos($todas, $esperado) !== false, "una ficha vacía pide: {$esperado}");
}
local_chatbot_verificar(strpos($todas, '3.1 Las actividades') !== false && strpos($todas, '5.4 Clima de aula') !== false,
    'una ficha vacía pide las ocho valoraciones de las secciones 3 y 5');
local_chatbot_verificar(strpos($todas, 'Comentario') === false, 'sin valoración no se pide comentario');
local_chatbot_verificar(strpos($todas, 'Tabla 4.1') === false, 'sin criterios no hay filas de la tabla 4.1 que pedir');
$sinfila = $ej;
$sinfila['ev2'] = '';
$sinfila['ade3'] = '';
$sinfila['lg1'] = '';
$m = implode(' | ', implementacion::missing_inputs($sinfila, true));
local_chatbot_verificar(strpos($m, 'Tabla 4.1, criterio 2: evidencias observadas') !== false
    && strpos($m, 'Tabla 4.1, criterio 3: N.º de participantes en nivel Adecuado') !== false
    && strpos($m, 'Tabla 4.1, criterio 1: logro (%)') !== false, 'cada criterio necesita su fila completa en la tabla 4.1');
$conbaja = $ej;
$conbaja['val32'] = '2';
$conbaja['com32'] = '';
$conbaja['val52'] = '1';
$conbaja['com52'] = '';
$m = implode(' | ', implementacion::missing_inputs($conbaja, true));
local_chatbot_verificar(strpos($m, '3.2 Comentario (obligatorio con valoración 1 o 2)') !== false
    && strpos($m, '5.2 Comentario (obligatorio con valoración 1 o 2)') !== false, 'una valoración 1 o 2 exige comentario');
$altasincomentario = $ej;
$altasincomentario['com33'] = '';
$altasincomentario['com54'] = '';
local_chatbot_verificar(implementacion::missing_inputs($altasincomentario, true) === [],
    'el comentario de una valoración 3 o 4 es opcional');
$sinopcionales = $ej;
foreach (range(1, implementacion::OBSERVACIONES_FILAS) as $j) {
    $sinopcionales['ob' . $j . '_quien'] = '';
}
local_chatbot_verificar(implementacion::missing_inputs($sinopcionales, true) === [], 'la subsección 4.2 es opcional');
$solouno = $ej;
foreach (['criterio2', 'criterio3'] as $c) {
    $solouno[$c] = '';
}
local_chatbot_verificar(implementacion::missing_inputs($solouno, true) === [] && implementacion::criteria_count($solouno) === 1,
    'basta un criterio trabajado, y solo se piden las filas de los criterios registrados');

// --- Calculos de la ficha: los numeros del ejemplo del cliente ---
$perf = implementacion::performance($ej);
local_chatbot_verificar(count($perf) === 3 && array_column($perf, 'logro') === [81.8, 63.6, 72.7],
    'el logro recalculado del ejemplo es 81,8 %, 63,6 % y 72,7 % (82, 64 y 73 con redondeo)');
local_chatbot_verificar(array_column($perf, 'suma') === [22, 22, 22] && !in_array(false, array_column($perf, 'suma_ok'), true)
    && !in_array(false, array_column($perf, 'logro_ok'), true) && !in_array(false, array_column($perf, 'completa'), true),
    'en el ejemplo la suma por nivel es igual a los presentes y el logro declarado está bien calculado');
$t = implementacion::times($ej);
local_chatbot_verificar($t['total'] === 360 && $t['utilizado'] === 180 && $t['restante'] === 180 && $t['estado'] === 'ok',
    'tiempo restante = total − utilizado = 180 minutos');
local_chatbot_verificar(implementacion::times(implementacion::clean(['tiempo_total' => '180', 'tiempo_utilizado' => '180']))['estado'] === 'cero'
    && implementacion::times(implementacion::clean(['tiempo_total' => '180', 'tiempo_utilizado' => '200']))['restante'] === -20
    && implementacion::times(implementacion::clean(['tiempo_total' => '180', 'tiempo_utilizado' => '200']))['estado'] === 'negativo'
    && implementacion::times(implementacion::clean(['tiempo_total' => '180']))['estado'] === 'sin_datos',
    'sin tiempo restante (cero o negativo) o sin datos, el estado lo dice');
local_chatbot_verificar(implementacion::pct(81.8) === '81,8 %' && implementacion::pct(64.0) === '64 %' && implementacion::pct(0.0) === '0 %',
    'los porcentajes se escriben como los lee una persona');

// --- Verificaciones de la plataforma (autoridad del codigo) ---
$ref = local_chatbot_referencia();
$sc = implementacion::system_checks($ej, $ref);
local_chatbot_verificar(array_keys($sc['items']) === ['c1_3', 'c1_4', 'c4_1', 'c4_3', 'c4_4']
    && array_unique(array_column($sc['items'], 'estado')) === [implementacion::CUMPLE],
    'el ejemplo del cliente cumple los cinco ítems que resuelve el código');
local_chatbot_verificar(count($sc['hechos']) >= 5 && strpos(implode("\n", $sc['hechos']), 'Criterio 2:') !== false,
    'las verificaciones dejan una línea de hechos por criterio para el modelo');
$mal = $ej;
$mal['ade2'] = '10';
$sc = implementacion::system_checks($mal, $ref);
local_chatbot_verificar($sc['items']['c4_3']['estado'] === implementacion::NO_CUMPLE
    && strpos($sc['items']['c4_3']['observacion'], 'criterio 2 (suma 20, presentes 22)') !== false
    && $sc['items']['c4_4']['estado'] === implementacion::PARCIAL,
    'si la suma por nivel no es igual a los presentes: c4_3 no cumple con el detalle, y el logro declarado queda mal calculado');
$logro = $ej;
$logro['lg1'] = '90';
$sc = implementacion::system_checks($logro, $ref);
local_chatbot_verificar($sc['items']['c4_3']['estado'] === implementacion::CUMPLE && $sc['items']['c4_4']['estado'] === implementacion::PARCIAL
    && strpos($sc['items']['c4_4']['observacion'], 'criterio 1: la ficha dice 90 % y corresponde 81,8 %') !== false,
    'un logro mal calculado se recalcula con los datos de la tabla y queda como observación (⚠, no crítico)');
$tol = $ej;
$tol['lg1'] = '81';
local_chatbot_verificar(implementacion::system_checks($tol, $ref)['items']['c4_4']['estado'] === implementacion::CUMPLE,
    'el redondeo de la persona (81 en vez de 81,8) se acepta');
$pres = $ej;
$pres['presentes'] = '30';
local_chatbot_verificar(implementacion::system_checks($pres, $ref)['items']['c1_3']['estado'] === implementacion::NO_CUMPLE,
    'presentes mayor que inscritos: c1_3 no cumple');
$pres['presentes'] = '0';
local_chatbot_verificar(implementacion::system_checks($pres, $ref)['items']['c1_3']['estado'] === implementacion::NO_CUMPLE
    && strpos(implementacion::system_checks($pres, $ref)['items']['c1_3']['observacion'], 'mayor que cero') !== false,
    'cero presentes: c1_3 no cumple');
$tiempos = $ej;
$tiempos['tiempo_asignado'] = '170';
$sc = implementacion::system_checks($tiempos, $ref);
local_chatbot_verificar($sc['items']['c1_4']['estado'] === implementacion::NO_CUMPLE
    && strpos($sc['items']['c1_4']['observacion'], 'tiempo asignado a la experiencia es 170 minutos en la ficha y 180 en el diseño') !== false,
    'tiempos distintos a los del diseño: c1_4 (CRÍTICO) no cumple y dice cuáles');
local_chatbot_verificar(!isset(implementacion::system_checks($ej, null)['items']['c1_4']),
    'sin diseño de referencia, el código no decide c1_4');
$sinref = $ref;
$sinref['tiempo_asignado'] = null;
local_chatbot_verificar(!isset(implementacion::system_checks($ej, $sinref)['items']['c1_4']),
    'si el diseño no declara sus tiempos, c1_4 queda para el modelo');
$avisos = implementacion::form_warnings($mal);
local_chatbot_verificar(count($avisos) >= 1 && strpos(implode(' ', $avisos), 'criterio 2') !== false,
    'los avisos antes de validar señalan la suma por nivel');
local_chatbot_verificar(implementacion::form_warnings($ej) === [], 'el ejemplo del cliente no tiene avisos');
$sinrestante = $ej;
$sinrestante['tiempo_utilizado'] = '360';
local_chatbot_verificar(strpos(implode(' ', implementacion::form_warnings($sinrestante)), 'no queda tiempo restante') !== false,
    'se avisa cuando no queda tiempo restante para acciones remediales');

// --- Comparacion con el diseño ---
local_chatbot_verificar(implementacion::similarity('Módulo 1: Comunicación', 'modulo 1 comunicacion') === 1.0
    && implementacion::similarity('uno dos tres cuatro', 'uno dos tres cinco') === 0.75
    && implementacion::similarity('', 'algo') === 0.0, 'el parecido normaliza tildes y signos; 1 es idéntico');
local_chatbot_verificar(implementacion::similarity_label(1.0) === 'textual' && implementacion::similarity_label(0.93) === 'casi'
    && implementacion::similarity_label(0.7) === 'parecida' && implementacion::similarity_label(0.2) === 'distinta',
    'el parecido se dice textual, casi, parecida o distinta');
$cmp = implementacion::match_criteria([1 => $ej['criterio1'], 2 => $ej['criterio2'], 3 => 'Otra cosa que no esta en el diseño'],
    $ref['criterios']);
local_chatbot_verificar($cmp['ficha'][1]['diseno'] === 1 && $cmp['ficha'][2]['diseno'] === 2 && $cmp['ficha'][3]['diseno'] === null
    && $cmp['sin_ficha'] === [3, 4] && $cmp['ficha'][1]['etiqueta'] === 'textual',
    'cada criterio de la ficha se une al del diseño que corresponde; los que no recoge son pendientes por estructura');
$hechos = implode("\n", implementacion::reference_facts($ej, $ref));
local_chatbot_verificar(strpos($hechos, 'Plan formativo de la ficha frente al del diseño: idéntico') !== false
    && strpos($hechos, 'El diseño declara 4 criterio(s)') !== false && strpos($hechos, 'la ficha registra 3 en 2.1') !== false
    && strpos($hechos, 'Criterios del diseño que ninguno de 2.1 recoge: 4') !== false,
    'los hechos comparan plan, módulo, experiencia, aprendizaje y criterios con el diseño');
$distinto = $ej;
$distinto['plan'] = 'Otro plan formativo cualquiera';
local_chatbot_verificar(strpos(implode("\n", implementacion::reference_facts($distinto, $ref)), 'Plan formativo de la ficha frente al del diseño: distinto') !== false,
    'un plan que no corresponde se informa como distinto');
$r = implementacion::design_reference(['plan' => ' P ', 'criterio1' => 'a', 'criterio3' => 'c', 'tiempo_total' => '360', 'tiempo_experiencia' => 'x'], 'Exp');
local_chatbot_verificar($r['plan'] === 'P' && $r['criterios'] === ['a', 'c'] && $r['tiempo_total'] === 360 && $r['tiempo_asignado'] === null
    && $r['experiencia'] === 'Exp', 'la referencia del diseño se arma desde su ficha de diagnóstico');

// --- La ficha como texto para el modelo ---
$texto = implementacion::to_text($ej);
local_chatbot_verificar(strpos($texto, 'Marta') === false && strpos($texto, '(registrado)') !== false,
    'el nombre de la persona facilitadora no viaja al modelo');
local_chatbot_verificar(strpos($texto, 'SECCIÓN 4. Desempeño de las personas participantes') !== false
    && strpos($texto, 'Insuficiente 0; Básico 4; Adecuado 14; Avanzado 4') !== false
    && strpos($texto, 'Logro (%) declarado en la ficha: 82 %') !== false && strpos($texto, '(opcional, sin información)') !== false,
    'la tabla 4.1 va por criterio con sus niveles y la 4.2 vacía se marca como opcional');
local_chatbot_verificar(strpos($texto, '- Valoración') === false && strpos($texto, '3.1. Las actividades realizadas') !== false
    && strpos($texto, 'Comentario de 3.1:') !== false && strpos($texto, '3 – Alto') !== false,
    'las valoraciones van con su número, su escala y su comentario');
local_chatbot_verificar(strpos($texto, 'Criterio de evaluación 4') === false, 'los criterios sin texto no viajan');
$vacio = implementacion::to_text(implementacion::clean([]));
local_chatbot_verificar(substr_count($vacio, '(sin respuesta)') >= 20, 'lo que no se respondió se marca «(sin respuesta)»');

// --- Huellas ---
$h1 = implementacion::fingerprint($ej, 10, 'd1');
local_chatbot_verificar($h1 === implementacion::fingerprint($ej, 10, 'd1') && $h1 !== implementacion::fingerprint($ej, 11, 'd1')
    && $h1 !== implementacion::fingerprint($ej, 10, 'd2') && $h1 !== implementacion::fingerprint($mal, 10, 'd1'),
    'la huella cambia si cambia la ficha, el diseño elegido o el contenido del diseño');
local_chatbot_verificar(implementacion::design_fingerprint(['a' => 1], [2 => 'x']) !== implementacion::design_fingerprint(['a' => 1], [2 => 'y']),
    'la huella del diseño cambia con sus etapas');

// --- Disponibilidad del diseño ---
local_chatbot_verificar(implementacion::design_availability([1, 2, 3, 4, 5, 6], true) === [true, '']
    && implementacion::design_availability([1, 2, 3, 4, 5, 6, 7, 8], true) === [true, '']
    && implementacion::design_availability([1, 2, 3, 4, 5], true) === [false, 'incompleto']
    && implementacion::design_availability([1, 2, 3, 4, 5, 6], false) === [false, 'sinplan'],
    'un diseño se puede evaluar con sus seis etapas aceptadas y su plan formativo; si no, se informa por qué');

// --- Bienvenida y regla critica (textuales del Prompt Maestro) ---
$bienvenida = implementacion::welcome();
local_chatbot_verificar(count($bienvenida) === 3 && $bienvenida[0] === 'Bienvenido/a, soy tu asistente de aseguramiento de la calidad de los aprendizajes.'
    && strpos($bienvenida[1], 'diseñar la experiencia de aprendizaje de continuidad.') !== false
    && strpos($bienvenida[2], 'Comienza seleccionando, del listado de tus diseños guardados') === 0
    && strpos($bienvenida[2], 'la revisaré y validaré antes de iniciar el análisis.') !== false,
    'la bienvenida es la del Prompt Maestro');
local_chatbot_verificar(strpos(implementacion::REGLA_CRITICA, 'Para utilizar este asistente, primero debes seleccionar el diseño') === 0
    && strpos(implementacion::REGLA_CRITICA, 'Este paso es obligatorio dentro del proceso.') !== false,
    'la regla crítica es la del Prompt Maestro');
local_chatbot_verificar(implementacion::ROL === 'Asistente de Aseguramiento de la Calidad de los Aprendizajes',
    'el rol del Módulo 3 es «Asistente de Aseguramiento de la Calidad de los Aprendizajes»');

// --- El informe del modelo ---
$items = [];
foreach ($ids as $id) {
    $items[$id] = ['estado' => 'cumple', 'observacion' => '', 'propuesta' => '', 'forma' => false];
}
$json = static function(array $items, array $extra = []): string {
    return json_encode(['items' => $items] + $extra + ['debilidades' => ['d1'], 'sugerencias' => ['s1']], JSON_UNESCAPED_UNICODE);
};
$informe = implementacion::parse_report($json($items));
local_chatbot_verificar($informe !== null && count($informe['items']) === 34 && $informe['debilidades'] === ['d1']
    && $informe['sugerencias'] === ['s1'], 'un informe con los 34 ítems se lee completo');
local_chatbot_verificar(implementacion::parse_report("```json\n" . $json($items) . "\n```") !== null
    && implementacion::parse_report('Aquí va: ' . $json($items) . ' fin') !== null, 'el informe se lee aunque venga entre bloques de código o con texto alrededor');
$falta = $items;
unset($falta['c6_3']);
local_chatbot_verificar(implementacion::parse_report($json($falta)) === null && implementacion::parse_report('no es json') === null
    && implementacion::parse_report(null) === null, 'si falta un ítem o no es JSON, el informe no sirve');
$raro = $items;
$raro['c2_3']['estado'] = 'tal vez';
local_chatbot_verificar(implementacion::parse_report($json($raro)) === null, 'un estado que no se entiende invalida el informe');
$conestados = $items;
$conestados['c2_3'] = ['estado' => '⚠ Cumple parcialmente', 'observacion' => 'obs', 'propuesta' => 'prop'];
$conestados['c1_2'] = ['estado' => 'parcial', 'observacion' => 'typo', 'propuesta' => 'p', 'forma' => true];
$conestados['c5_1'] = ['estado' => 'parcial', 'observacion' => 'o', 'propuesta' => 'p', 'forma' => true];
$conestados['c3_1'] = ['estado' => 'cumple', 'observacion' => 'ok', 'propuesta' => 'no debiera quedar'];
$i2 = implementacion::parse_report($json($conestados))['items'];
local_chatbot_verificar($i2['c2_3']['estado'] === 'parcial' && $i2['c2_3']['propuesta'] === 'prop' && !empty($i2['c1_2']['forma'])
    && empty($i2['c5_1']['forma']) && $i2['c3_1']['propuesta'] === '',
    'la diferencia de forma solo cuenta en un crítico «parcial»; un ítem que cumple no lleva propuesta');
$aplicado = implementacion::apply_system_checks($i2, ['c4_3' => ['estado' => 'no_cumple', 'observacion' => 'suma', 'propuesta' => 'corrige'],
    'c2_3' => ['estado' => 'no_cumple', 'observacion' => 'no debiera pisar', 'propuesta' => '']]);
local_chatbot_verificar($aplicado['c4_3']['estado'] === 'no_cumple' && $aplicado['c4_3']['origen'] === 'calculo'
    && $aplicado['c2_3']['estado'] === 'parcial', 'el cálculo del código reemplaza al modelo solo en los ítems por cálculo');

// --- Regla de decision ---
$todo = array_map(static function($i) {
    return ['estado' => 'cumple'];
}, array_flip($ids));
local_chatbot_verificar(implementacion::decide($todo) === implementacion::APROBADO && implementacion::decision_rule($todo) === 'todo_cumple',
    'todos los ítems en ✔: aprobado');
$d = $todo;
$d['c2_3']['estado'] = 'parcial';
local_chatbot_verificar(implementacion::decide($d) === implementacion::OBSERVACIONES && implementacion::decision_rule($d) === 'no_criticos',
    '⚠ en un ítem no crítico: aprobado con observaciones');
$d = $todo;
$d['c4_2']['estado'] = 'no_cumple';
local_chatbot_verificar(implementacion::decide($d) === implementacion::NO_APROBADO && implementacion::decision_rule($d) === 'critico_no'
    && implementacion::critical_failures($d) === ['c4_2'], '❌ en un crítico: no aprobado');
$d = $todo;
$d['c2_1']['estado'] = 'parcial';
local_chatbot_verificar(implementacion::decide($d) === implementacion::NO_APROBADO && implementacion::decision_rule($d) === 'critico_parcial',
    '⚠ en un crítico (sin ser de forma): no aprobado hasta corregir la ficha');
$d['c2_1']['forma'] = true;
local_chatbot_verificar(implementacion::decide($d) === implementacion::OBSERVACIONES && implementacion::decision_rule($d) === 'forma'
    && implementacion::critical_failures($d) === [], '⚠ en un crítico por diferencia de forma: aprobado con observaciones');
$d = $todo;
$d['c8_2']['estado'] = 'no_cumple';
$d['c1_4']['estado'] = 'parcial';
local_chatbot_verificar(implementacion::critical_failures($d) === ['c1_4', 'c8_2'], 'los críticos que detienen salen en el orden del checklist');
$sinitem = $todo;
unset($sinitem['c4_1']);
local_chatbot_verificar(implementacion::decide($sinitem) === implementacion::NO_APROBADO, 'un ítem crítico sin evaluar no se aprueba');
local_chatbot_verificar(implementacion::can_advance('aprobado') && implementacion::can_advance('observaciones')
    && !implementacion::can_advance('no_aprobado') && !implementacion::can_advance(null), 'solo se avanza con Aprobado o Aprobado con observaciones');
$d = $todo;
$d['c2_3'] = ['estado' => 'parcial', 'observacion' => 'falta motivo'];
$d['c4_4'] = ['estado' => 'no_cumple', 'observacion' => ''];
$pend = implementacion::unresolved_items($d);
local_chatbot_verificar(array_keys($pend) === ['c2_3', 'c4_4'] && $pend['c2_3']['seccion'] === 2 && $pend['c2_3']['observacion'] === 'falta motivo',
    'el registro de observaciones no resueltas trae los ítems ⚠ y ❌ con su sección');
$resumen = implementacion::report_summary('observaciones', ['items' => $d, 'debilidades' => ['una debilidad'], 'sugerencias' => []]);
local_chatbot_verificar(strpos($resumen, '⚠ Aprobado con observaciones') !== false && strpos($resumen, 'c2_3') !== false
    && strpos($resumen, 'una debilidad') !== false, 'el resumen de la validación sirve de insumo a las etapas');
local_chatbot_verificar(strpos(implementacion::report_summary('aprobado', ['items' => $todo]), 'no dejó observaciones no resueltas') !== false,
    'sin observaciones, el resumen lo dice');

// --- El prompt de validacion (Etapa 0 del Prompt Maestro) ---
$pv = implementacion::build_prompt(['Plan Formativo'], true, true);
local_chatbot_verificar(strpos($pv, 'Eres el Asistente de Aseguramiento de la Calidad de los Aprendizajes') === 0
    && strpos($pv, 'ETAPA 0, VALIDACIÓN DE LA FICHA DE REGISTRO') !== false, 'la validación habla como el asistente del Módulo 3 y aplica la Etapa 0');
local_chatbot_verificar(count(array_filter($ids, static function($id) use ($pv) {
    return strpos($pv, '   ' . $id . ': ') !== false;
})) === 34 && preg_match_all('/^   c\d_\d: .* \[CRÍTICO\]$/mu', $pv) === 8,
    'el prompt lista los 34 ítems y marca exactamente 8 CRÍTICO');
local_chatbot_verificar(strpos($pv, 'ya pasó el control de entrada') !== false && strpos($pv, 'no la incluyas en tu respuesta') !== false
    && strpos($pv, 'cambio de implementación no declarado') !== false && strpos($pv, 'Los ítems c1_3, c1_4, c4_1, c4_3 y c4_4 los calcula la plataforma') !== false,
    'el prompt trae la regla de decisión, los cambios no declarados y los ítems que calcula la plataforma');
local_chatbot_verificar(strpos($pv, 'FUENTES DE CONOCIMIENTO:') !== false && strpos($pv, 'usa preferentemente la LIBRERÍA DE CONOCIMIENTOS DEL ASISTENTE DE ASEGURAMIENTO') !== false
    && strpos(implementacion::build_prompt([]), 'no viene cargada') !== false && strpos($pv, 'pedagógico') !== false,
    'el prompt trae la regla de fuentes y la de no usar «pedagogía»');
local_chatbot_verificar(strpos(implementacion::documents_rule(['Plan Formativo', 'Perfil Ocupacional']), 'Se adjunta el Perfil Ocupacional (opcional)') !== false
    && strpos(implementacion::documents_rule(['Plan Formativo']), 'No hay Perfil Ocupacional asociado al diseño, que es opcional') !== false
    && strpos(implementacion::documents_rule([]), 'No se pudo recuperar el Plan Formativo') !== false,
    'el plan formativo es obligatorio y el perfil, opcional, y ambos se recuperan del diseño');
$dt = implementacion::design_text($ref, [2 => 'Producto de la etapa 2', 5 => 'Plan de evaluación']);
local_chatbot_verificar(strpos($dt, 'Plan formativo: Habilidades transversales') !== false && strpos($dt, '1. Redacta mensajes') !== false
    && strpos($dt, '4. Estructura mensajes orales') !== false && strpos($dt, 'ETAPA 2 DEL DISEÑO (Diseño de la experiencia)') !== false
    && strpos($dt, 'ETAPA 5 DEL DISEÑO (Plan de evaluación)') !== false && strpos($dt, 'Producto de la etapa 2') !== false,
    'el diseño viaja con su referencia, sus criterios y los productos de las etapas pedidas');
$reglas = implode("\n", implementacion::knowledge_rules(true, true));
local_chatbot_verificar(strpos($reglas, 'busca información actualizada en la web') !== false && strpos($reglas, 'ejemplo de llenado es ilustrativo') !== false
    && strpos(implode("\n", implementacion::knowledge_rules(false, false)), 'debe verificar') !== false,
    'la regla de fuentes cambia con la librería y la búsqueda web');
$partes = implementacion::request_parts('PROMPT', [['text' => 'ADJ']], 'LIB', true);
$partes2 = implementacion::request_parts('PROMPT', [['text' => 'ADJ']], 'LIB', false);
local_chatbot_verificar($partes[1]['text'] === 'PROMPT' && strpos($partes[0]['text'], 'LIBRERÍA DE CONOCIMIENTOS DEL ASISTENTE DE ASEGURAMIENTO') === 0
    && end($partes2)['text'] !== 'PROMPT' && count(implementacion::request_parts('P', [], '', true)) === 1,
    'la librería va primero (o al final) y solo si hay documentos');

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Fase 1 del Módulo 3 conforme: ficha y checklist textuales, control de entrada, cálculos del código y regla de decisión.\n";
}
exit($fallidos === 0 ? 0 : 1);

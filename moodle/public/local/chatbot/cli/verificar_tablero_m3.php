<?php
/**
 * Verifica el calculo del tablero del Modulo 3 («Propuesta de Dashboard · Módulo 3: indicadores, vistas y uso», Interfases):
 * hechos de cada evaluacion, indicadores acumulados A a F, calidad del agente (G), los ocho avisos automaticos con sus plazos y
 * las cinco alertas de la administracion del asistente.
 *
 * El caso de prueba es el ejemplo de llenado del cliente («Comunicación efectiva»): logro inicial 82, 64 y 73 %, un criterio del
 * diseño sin trabajar (cobertura 75 %), logro final 95, 91, 95 y 77 %.
 *
 * Uso:  php cli/verificar_tablero_m3.php
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
require_once(__DIR__ . '/../classes/tablero_m2.php');
require_once(__DIR__ . '/../classes/tablero_m3.php');

use local_chatbot\calidad;
use local_chatbot\expost;
use local_chatbot\implementacion;
use local_chatbot\tablero_m3;

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

const DIA = 86400;
const T0 = 1798000000;

/**
 * Items del checklist de una validacion: todos ✔ salvo los indicados.
 *
 * @param string[] $ids Items del checklist.
 * @param array $cambios id => estado.
 * @return array
 */
function local_chatbot_items(array $ids, array $cambios = []): array {
    $items = [];
    foreach ($ids as $id) {
        $items[$id] = ['estado' => $cambios[$id] ?? implementacion::CUMPLE, 'observacion' => ''];
    }
    return $items;
}

// ------------------------------------------------------------------------------------------
// El caso del cliente.
// ------------------------------------------------------------------------------------------
$criterios = ['Redacta mensajes profesionales con claridad, coherencia y corrección ortográfica.',
    'Adapta el registro lingüístico al contexto y al interlocutor.',
    'Responde por escrito a consultas laborales de manera respetuosa, oportuna y pertinente.',
    'Estructura mensajes orales breves según el propósito comunicativo.'];
$inicial = [[0, 4, 14, 4], [2, 6, 12, 2], [1, 5, 13, 3]];
$raw = ['plan' => 'Habilidades transversales para el trabajo, código PF-0245.', 'modulo' => 'Módulo 1: Comunicación para el trabajo.',
    'experiencia' => 'Elaboración de correos profesionales con criterios de calidad.', 'facilitador' => 'Marta Ramírez Fuentes.',
    'fechas' => '22 de marzo de 2026.', 'inscritos' => '24', 'presentes' => '22', 'tiempo_total' => '360', 'tiempo_asignado' => '180',
    'tiempo_utilizado' => '180', 'val31' => '4', 'val32' => '4', 'val33' => '3', 'val34' => '3'];
foreach ($inicial as $i => $n) {
    $k = $i + 1;
    $raw['criterio' . $k] = $criterios[$i];
    $raw['ev' . $k] = 'Evidencia observada.';
    [$raw['ins' . $k], $raw['bas' . $k], $raw['ade' . $k], $raw['ava' . $k]] = $n;
    $raw['lg' . $k] = (string) round(100 * ($n[2] + $n[3]) / 22);
}
$impl = implementacion::clean($raw);
$ref = implementacion::design_reference(['plan' => $raw['plan'], 'modulo' => $raw['modulo'], 'criterio1' => $criterios[0],
    'criterio2' => $criterios[1], 'criterio3' => $criterios[2], 'criterio4' => $criterios[3], 'tiempo_total' => '360',
    'tiempo_experiencia' => '180'], $raw['experiencia']);
$pre = expost::preload($impl, $ref, 'Comunicación escrita y oral en situaciones laborales: nivelación y profundización.', '15 de mayo de 2026');
$e = ['fechas_cont' => '29 de marzo de 2026.', 'presentes_cont' => '22', 'evaluados' => '22',
    'v42' => '4', 'v43' => '3', 'v44' => '4', 'v45' => '4', 'v46' => '3', 'v47' => '4', 'n_seg5' => '14', 'n_apl5' => '10'];
$cierre = [[0, 1, 15, 6, '95'], [0, 2, 18, 2, '91'], [0, 1, 18, 3, '95'], [1, 4, 14, 3, '77']];
foreach ($cierre as $i => $n) {
    $k = $i + 1;
    [$e['ins' . $k], $e['bas' . $k], $e['ade' . $k], $e['avs' . $k]] = [$n[0], $n[1], $n[2], $n[3]];
    $e['ava' . $k] = $n[3];
    $e['lg' . $k] = $n[4];
    if ($k === 4) {
        $e['pr4'] = 'si';
    }
}
for ($j = 1; $j <= 4; $j++) {
    $e['bp' . $j . '_dec'] = $j === 4 ? 'descarta' : 'confirma';
}
$exdata = expost::clean($e);
$etq = ['didacticas' => [], 'evaluativas' => [], 'practicas' => ['p1', 'p2', 'p3', 'p4']];

/**
 * Datos de una evaluacion para facts(), con valores por defecto del caso del cliente.
 *
 * @param array $cambios
 * @return array
 */
function local_chatbot_datos(array $cambios = []): array {
    global $impl, $ref, $pre, $exdata, $etq;
    $ids1 = implementacion::checklist_ids();
    $ids4 = expost::checklist_ids();
    $etapas = [];
    foreach (array_merge(range(1, 8), range(11, 15)) as $n) {
        $etapas[$n] = ['estado' => 'aceptado', 'inicio' => T0 + $n * DIA, 'cierre' => T0 + ($n + 1) * DIA, 'rondas' => $n % 3,
            'modificado' => T0 + ($n + 1) * DIA];
    }
    return $cambios + ['ficha' => ['id' => 1, 'userid' => 10, 'activa' => 1, 'organismo' => 'OTEC Uno', 'plan' => 'Plan A', 'modulo' => 'Módulo 1',
            'etiqueta' => 'Comunicación efectiva', 'puntaje' => 34, 'creada' => T0],
        'data' => $impl, 'ref' => $ref,
        'validaciones' => [['id' => 1, 'estado' => 'listo', 'condicion' => implementacion::NO_APROBADO, 'time' => T0 + 2 * DIA,
            'items' => local_chatbot_items($ids1, ['c1_2' => implementacion::NO_CUMPLE, 'c4_3' => implementacion::PARCIAL, 'c4_4' => implementacion::PARCIAL])],
            ['id' => 2, 'estado' => 'listo', 'condicion' => implementacion::OBSERVACIONES, 'time' => T0 + 5 * DIA,
                'items' => local_chatbot_items($ids1, ['c2_3' => implementacion::PARCIAL])]],
        'envios' => ['impl' => [['completo' => false, 'faltantes' => ['Sección 1: Horario', 'Sección 2: Criterios'], 'time' => T0 + 1 * DIA],
            ['completo' => true, 'faltantes' => [], 'time' => T0 + 4 * DIA]],
            'expost' => [['completo' => true, 'faltantes' => [], 'time' => T0 + 20 * DIA]]],
        'etapas' => $etapas, 'aceptadas' => range(1, 8), 'aceptadas4' => range(11, 15),
        'ajuste' => calidad::AJUSTE_REMEDIALES, 'minimo' => calidad::AJUSTE_REMEDIALES,
        'continuidad_html' => '<table><tr><th>Momento</th><th>Minutos</th></tr><tr><td>Inicio</td><td>30</td></tr><tr><td>Total</td><td>150</td></tr></table>',
        'expost' => ['data' => $exdata, 'pre' => $pre, 'etq' => $etq, 'continuidad' => true, 'tiempo' => T0 + 18 * DIA, 'modificado' => T0 + 21 * DIA],
        'expost_vals' => [['id' => 7, 'estado' => 'listo', 'condicion' => implementacion::APROBADO, 'time' => T0 + 21 * DIA,
            'items' => local_chatbot_items($ids4)]],
        'encuestas' => [3 => null, 4 => null], 'primer_acceso' => T0 - 5 * DIA, 'vistas' => ['guia' => T0 - 2 * DIA, 'ejemplo' => T0 - DIA],
        'actividad' => T0 + 22 * DIA];
}

// --- Funciones de apoyo ---
local_chatbot_verificar(tablero_m3::median([3, 1, 2]) === 2.0 && tablero_m3::mean([1, 2]) === 1.5 && tablero_m3::pct(1, 3) === 33
    && tablero_m3::pct(1, 0) === null && tablero_m3::days(T0, T0 + 36 * 3600) === 1.5, 'mediana, promedio, porcentaje y días como en el Módulo 2');
local_chatbot_verificar(tablero_m3::period_start('mes', T0) === T0 - 30 * DIA && tablero_m3::period_start('todo', T0) === 0
    && tablero_m3::period_start('anio', T0) === T0 - 365 * DIA, 'periodos del filtro: semana, mes, semestre, año y todo');
local_chatbot_verificar(tablero_m3::fab_view(false, true, true) === null && tablero_m3::fab_view(true, true, false) === 'acumulada'
    && tablero_m3::fab_view(true, false, true) === 'persona' && tablero_m3::fab_view(true, false, false) === null,
    'el botón flotante lleva al acumulado, a «Mi avance» o a ninguna parte');

// --- Textos de la propuesta ---
$avisos = tablero_m3::avisos();
local_chatbot_verificar(array_keys($avisos) === ['registro', 'correccion', 'critico', 'brecha', 'tiempo', 'cierre', 'detenido', 'encuesta'],
    'los ocho avisos de la propuesta, en su orden');
local_chatbot_verificar($avisos['brecha']['cuando'] === 'Algún criterio con logro inferior a 30 % o un pendiente vinculado al desempeño central.'
    && $avisos['encuesta']['sugerencia'] === 'Responder la encuesta (3 minutos) para mejorar el asistente.'
    && $avisos['tiempo']['sugerencia'] === 'Revisar la priorización de brechas con el asistente.', 'textos de los avisos, textuales');
local_chatbot_verificar(array_keys(tablero_m3::alertas()) === ['global', 'pregunta', 'acuerdo', 'errores', 'respuesta'], 'las cinco alertas de la administración');

// --- Encuesta breve ---
local_chatbot_verificar(array_keys(tablero_m3::survey(3)) === ['p1', 'p2', 'p3', 'p4', 'p5', 'p6']
    && array_keys(tablero_m3::survey(4)) === ['p1', 'p2', 'p3', 'p5', 'p6'], 'la pregunta 4 es solo de la Fase 3');
local_chatbot_verificar(tablero_m3::survey(3)['p5']['texto'] !== tablero_m3::survey(4)['p5']['texto']
    && strpos(tablero_m3::survey(4)['p5']['texto'], 'recomendaciones') !== false, 'la pregunta 5 cambia de texto según la fase');
$ok3 = ['p1' => '5', 'p2' => 4, 'p3' => 3, 'p4' => 5, 'p5' => 4, 'p6' => 5, 'p7' => 'no', 'p8' => '  Muy   útil  '];
local_chatbot_verificar(tablero_m3::clean_survey($ok3, 3) === ['p1' => 5, 'p2' => 4, 'p3' => 3, 'p4' => 5, 'p5' => 4, 'p6' => 5, 'p7' => 'no', 'p8' => 'Muy útil'],
    'encuesta completa de la Fase 3: enteros de 1 a 5, p7 y comentario limpio');
local_chatbot_verificar(tablero_m3::clean_survey(array_diff_key($ok3, ['p4' => 1]), 3) === null && tablero_m3::clean_survey(array_diff_key($ok3, ['p4' => 1]), 4) !== null,
    'falta la pregunta 4: no vale en la Fase 3 pero sí en la Fase 4');
local_chatbot_verificar(tablero_m3::clean_survey(['p1' => 6] + $ok3, 3) === null && tablero_m3::clean_survey(['p2' => 2.5] + $ok3, 3) === null
    && tablero_m3::clean_survey(['p7' => 'tal vez'] + $ok3, 3) === null, 'fuera de escala, decimal o p7 que no es sí/no: no se guarda');
local_chatbot_verificar(tablero_m3::clean_survey(['p7' => 'si'] + $ok3, 3) === null
    && tablero_m3::clean_survey(['p7' => 'si', 'p7tipo' => 'calculo'] + $ok3, 3)['p7tipo'] === 'calculo'
    && !isset(tablero_m3::clean_survey(['p7tipo' => 'calculo'] + $ok3, 3)['p7tipo']), 'con errores detectados hay que decir el tipo; sin errores no se guarda');

// --- Fechas y minutos ---
local_chatbot_verificar(tablero_m3::parse_date('22 de marzo de 2026.', 2026) === gmmktime(12, 0, 0, 3, 22, 2026)
    && tablero_m3::parse_date('22 y 23 de marzo de 2026', 2026) === gmmktime(12, 0, 0, 3, 23, 2026)
    && tablero_m3::parse_date('Del 28 de febrero al 2 de marzo, 2026', 2026) === gmmktime(12, 0, 0, 3, 2, 2026)
    && tablero_m3::parse_date('05/04/2026', 2025) === gmmktime(12, 0, 0, 4, 5, 2026) && tablero_m3::parse_date('2026-04-05', 2025) === gmmktime(12, 0, 0, 4, 5, 2026),
    'la fecha de término se lee en varios formatos y es la última que menciona el texto');
local_chatbot_verificar(tablero_m3::parse_date('el martes', 2026) === null && tablero_m3::parse_date('31 de febrero de 2026', 2026) === null
    && tablero_m3::parse_date('', 2026) === null, 'lo que no es una fecha no se estima');
local_chatbot_verificar(tablero_m3::continuity_minutes('<table><tr><th>Momento</th><th>Minutos</th></tr><tr><td>Total</td><td>150 min</td></tr></table>') === 150
    && tablero_m3::continuity_minutes('<p>El tiempo total de la experiencia es de 140 minutos.</p>') === 140
    && tablero_m3::continuity_minutes('<table><tr><th>Momento</th><th>Minutos</th></tr><tr><td>Inicio</td><td>30</td></tr></table>') === null
    && tablero_m3::continuity_minutes('') === null, 'los minutos de la continuidad salen de la fila «Total» o de la frase «tiempo total»; si no, no se estiman');

// --- Hechos de una evaluacion completa ---
$h = tablero_m3::facts(local_chatbot_datos());
local_chatbot_verificar($h['f1']['decision_primera'] === implementacion::NO_APROBADO && $h['f1']['decision_final'] === implementacion::OBSERVACIONES
    && $h['f1']['ciclos_aprobacion'] === 2 && $h['fase1_ok'] && $h['f1']['correccion_pendiente'] === null, 'Etapa 0: primera decisión, final y ciclos hasta la aprobación');
local_chatbot_verificar($h['f1']['envios'] === 2 && $h['f1']['primer_envio_completo'] === false && $h['f1']['envios_incompletos'] === 1
    && $h['f1']['faltantes'] === ['Sección 1: Horario' => 1, 'Sección 2: Criterios' => 1], 'envíos al control de entrada y campos faltantes');
local_chatbot_verificar($h['f1']['val_criticos'] === [['c1_2', 'c4_3'], []] && $h['f1']['criticos_obs'] === [], 'ítems CRÍTICO observados por validación y en la última');
local_chatbot_verificar($h['f1']['correcciones'] === [2.0], 'tiempo de corrección: del ❌ al reenvío (2 días)');
local_chatbot_verificar($h['primer_envio'] === T0 + DIA && $h['fecha_fin'] === gmmktime(12, 0, 0, 3, 22, 2026), 'primer envío y fecha de término de la implementación');
local_chatbot_verificar($h['logros'] === ['total' => 3, 'recalculados' => 0], 'logros declarados bien calculados: ninguno recalculado');
$a = $h['analisis'];
local_chatbot_verificar($a['promedio'] === 72.7 && $a['trabajados'] === 3 && $a['diseno'] === 4 && $a['cobertura'] === 75.0 && $a['pendientes'] === 1
    && $a['brechas'] === ['alta' => 0, 'media' => 0, 'baja' => 3] && $a['relevantes'] === 0 && $a['restante'] === 180, 'análisis: logro promedio 72,7 %, cobertura 75 %, tres brechas bajas y 180 minutos restantes');
local_chatbot_verificar($a['minimo'] === calidad::AJUSTE_REMEDIALES && $a['determinantes'] === [], 'con acciones remediales como mínimo no hay condición determinante');
local_chatbot_verificar($h['fidelidad'] === 3.5, 'fidelidad: promedio de las valoraciones 3.1 a 3.4');
local_chatbot_verificar($h['continuidad_min'] === 150 && $h['uso_tiempo'] === 83 && $h['tiempo_excede'] === false, 'uso del tiempo restante: 150 de 180 minutos (83 %)');
local_chatbot_verificar($h['fase2_ok'] && $h['fase3_ok'] && $h['fase4_ok'] && $h['etapa'] === 16 && $h['duracion'] === 8.0, 'ciclo completo: etapa 16 y 8 días del primer envío a la consolidación');
local_chatbot_verificar($h['expost_ok'] && $h['f4']['decision_final'] === implementacion::APROBADO && $h['f4']['envios'] === 1, 'ficha ex post aprobada con su envío');
$c = $h['cierre'];
local_chatbot_verificar($c['inicial'] === 72.7 && $c['final'] === 94.0 && $c['ganancia'] === 21.3 && $c['por_criterio'][3]['tipo'] === 'pendiente_resuelto' && $c['por_criterio'][3]['final'] === 77.3,
    'cierre: logro de los criterios trabajados de 72,7 % a 94 % (ganancia de 21,3 puntos); el pendiente resuelto no entra en el promedio');
local_chatbot_verificar($c['reaccion'] === 3.7 && $c['transferencia'] === 71 && $c['bp_propuestas'] === 4 && $c['bp_confirmadas'] === 3, 'reacción 3,7; transferencia 10 de 14 (71 %); buenas prácticas 3 de 4');
local_chatbot_verificar($c['bajo_adecuado'] >= 0 && $c['persisten'] === 0 && is_int($c['profundizacion']), 'brechas persistentes y profundización con enteros');
local_chatbot_verificar($h['vista_guia'] === T0 - 2 * DIA && $h['vista_ejemplo'] === T0 - DIA && $h['primer_acceso'] === T0 - 5 * DIA, 'uso de la guía y el ejemplo y primer acceso');

// --- Etapa en que esta ---
$d = local_chatbot_datos(['aceptadas' => [1, 2, 3, 4], 'aceptadas4' => [], 'expost' => null, 'expost_vals' => []]);
$h2 = tablero_m3::facts($d);
local_chatbot_verificar($h2['etapa'] === 5 && !$h2['fase3_ok'] && $h2['cierre'] === null, 'con las etapas 1 a 4 aceptadas, está en la etapa 5');
$d = local_chatbot_datos(['validaciones' => []]);
local_chatbot_verificar(tablero_m3::facts($d)['etapa'] === 0, 'sin ficha de registro aprobada, está en la Etapa 0');
$d = local_chatbot_datos(['aceptadas4' => [11, 12], 'expost_vals' => [['id' => 7, 'estado' => 'listo', 'condicion' => implementacion::NO_APROBADO, 'time' => T0 + 21 * DIA,
    'items' => local_chatbot_items(expost::checklist_ids(), ['c2_1' => implementacion::NO_CUMPLE])]]]);
$h3 = tablero_m3::facts($d);
local_chatbot_verificar($h3['etapa'] === 10 && $h3['f4']['criticos_obs'] === ['c2_1'] && $h3['cierre'] === null && $h3['f4']['correccion_pendiente'] === T0 + 21 * DIA,
    'ficha ex post no aprobada: en la Etapa 10, con el ítem CRÍTICO c2_1 observado y la corrección pendiente');

// --- Decision y condicion determinante ---
$bajo = $raw;
foreach ([1, 2, 3] as $k) {
    $bajo['ade' . $k] = 2;
    $bajo['ava' . $k] = 1;
    $bajo['ins' . $k] = 9;
    $bajo['bas' . $k] = 10;
    $bajo['lg' . $k] = '14';
}
$d = local_chatbot_datos(['data' => implementacion::clean($bajo), 'ajuste' => calidad::AJUSTE_PARCIAL]);
$hb = tablero_m3::facts($d);
local_chatbot_verificar($hb['analisis']['minimo'] === calidad::AJUSTE_TOTAL && $hb['analisis']['determinantes'] === ['logro', 'juicio'] || $hb['analisis']['determinantes'] === ['logro']
    || in_array('logro', $hb['analisis']['determinantes'], true), 'con logro bajo, la condición determinante es el logro');
local_chatbot_verificar($hb['analisis']['brechas']['alta'] === 3, 'tres brechas de criticidad alta (logro de 13,6 %)');

// --- Avisos ---
$plazos = ['registro' => 7, 'correccion' => 7, 'cierre' => 7, 'detenido' => 7];
$tipos = static function(array $avisos): array {
    return array_column($avisos, 'tipo');
};
local_chatbot_verificar($tipos(tablero_m3::notices($h, T0 + 23 * DIA, $plazos)) === ['encuesta', 'encuesta'],
    'ciclo completo y sin encuesta: solo los avisos de la encuesta pendiente de las Fases 3 y 4');
$conencuesta = $h;
$conencuesta['encuestas'] = [3 => ['p1' => 5], 4 => ['p1' => 5]];
local_chatbot_verificar(tablero_m3::notices($conencuesta, T0 + 23 * DIA, $plazos) === [], 'con las dos encuestas respondidas no queda ningún aviso');
$nuevo = tablero_m3::facts(local_chatbot_datos(['validaciones' => [], 'envios' => ['impl' => [], 'expost' => []], 'aceptadas' => [], 'aceptadas4' => [], 'etapas' => [],
    'expost' => null, 'expost_vals' => [], 'actividad' => T0]));
local_chatbot_verificar($nuevo['registro_pendiente'] === gmmktime(12, 0, 0, 3, 22, 2026) && $tipos(tablero_m3::notices($nuevo, $nuevo['registro_pendiente'] + 8 * DIA, $plazos)) === ['registro']
    && tablero_m3::notices($nuevo, $nuevo['registro_pendiente'] + 6 * DIA, $plazos) === [], '«Registro pendiente»: se activa pasado el plazo desde el término de la implementación');
$pendiente = $h3;
local_chatbot_verificar(in_array('correccion', $tipos(tablero_m3::notices($pendiente, T0 + 29 * DIA, $plazos)), true)
    && !in_array('correccion', $tipos(tablero_m3::notices($pendiente, T0 + 27 * DIA, $plazos)), true), '«Corrección pendiente»: pasado el plazo del ❌ sin reenviar');
local_chatbot_verificar(in_array('critico', $tipos(tablero_m3::notices($pendiente, T0 + 22 * DIA, $plazos)), true), '«Ítem CRÍTICO observado»: con ⚠ o ❌ en un ítem CRÍTICO de la última validación');
local_chatbot_verificar(in_array('brecha', $tipos(tablero_m3::notices($hb, T0 + 22 * DIA, $plazos)), true) && !in_array('brecha', $tipos(tablero_m3::notices($h, T0 + 22 * DIA, $plazos)), true),
    '«Brecha de criticidad alta»: un criterio con logro inferior a 30 %');
$largo = tablero_m3::facts(local_chatbot_datos(['continuidad_html' => '<p>Tiempo total: 200 minutos.</p>']));
local_chatbot_verificar($largo['tiempo_excede'] && in_array('tiempo', $tipos(tablero_m3::notices($largo, T0 + 22 * DIA, $plazos)), true), '«Tiempo restante insuficiente»: 200 minutos sobre 180');
$sin4 = tablero_m3::facts(local_chatbot_datos(['aceptadas4' => [], 'expost' => null, 'expost_vals' => [], 'envios' => ['impl' => [['completo' => true, 'faltantes' => [], 'time' => T0 + DIA]], 'expost' => []],
    'encuestas' => [3 => ['p1' => 5], 4 => null]]));
$cons3 = $sin4['consolidacion3'];
local_chatbot_verificar(in_array('cierre', $tipos(tablero_m3::notices($sin4, $cons3 + 8 * DIA, $plazos)), true) && !in_array('cierre', $tipos(tablero_m3::notices($sin4, $cons3 + 6 * DIA, $plazos)), true),
    '«Cierre del ciclo pendiente»: Fase 3 consolidada y la Fase 4 sin iniciar pasado el plazo');
$parado = tablero_m3::facts(local_chatbot_datos(['aceptadas' => [1, 2, 3], 'aceptadas4' => [], 'expost' => null, 'expost_vals' => [], 'actividad' => T0 + 10 * DIA]));
local_chatbot_verificar(in_array('detenido', $tipos(tablero_m3::notices($parado, T0 + 20 * DIA, $plazos)), true) && !in_array('detenido', $tipos(tablero_m3::notices($parado, T0 + 12 * DIA, $plazos)), true),
    '«Proceso detenido»: sin avanzar de etapa pasado el plazo');
local_chatbot_verificar(!in_array('detenido', $tipos(tablero_m3::notices($pendiente, T0 + 40 * DIA, $plazos)), true), 'una corrección pendiente ya tiene su aviso: no se suma «Proceso detenido»');

// --- Envio de avisos ---
$avs = tablero_m3::notices($h, T0 + 23 * DIA, $plazos);
$envio = tablero_m3::due($avs, [], T0 + 23 * DIA, $plazos);
local_chatbot_verificar(count($envio) === 2 && tablero_m3::due($avs, ['encuesta-3-1' => T0, 'encuesta-4-1' => T0], T0 + 90 * DIA, $plazos) === [],
    'un aviso de una vez se envía una sola vez');
$rep = tablero_m3::notices($pendiente, T0 + 29 * DIA, $plazos);
$clave = array_values(array_filter($rep, static function($x) {
    return $x['tipo'] === 'correccion';
}))[0]['clave'];
local_chatbot_verificar(tablero_m3::due($rep, [$clave => T0 + 28 * DIA], T0 + 29 * DIA, $plazos) !== [] && count(array_filter(tablero_m3::due($rep, [$clave => T0 + 28 * DIA], T0 + 29 * DIA, $plazos),
    static function($x) use ($clave) {
        return $x['clave'] === $clave;
    })) === 0 && count(array_filter(tablero_m3::due($rep, [$clave => T0 + 20 * DIA], T0 + 29 * DIA, $plazos), static function($x) use ($clave) {
        return $x['clave'] === $clave;
    })) === 1, 'los avisos de plazo se repiten solo cuando pasó su plazo desde el último envío');
local_chatbot_verificar(tablero_m3::record(['viejo' => T0], $envio, $avs, T0 + 23 * DIA) === ['encuesta-3-1' => T0 + 23 * DIA, 'encuesta-4-1' => T0 + 23 * DIA],
    'el registro de avisos conserva solo los asuntos que siguen activos');
local_chatbot_verificar(strpos(tablero_m3::notice_detail($rep[0]), 'Etapa') !== false || strpos(tablero_m3::notice_detail($rep[0]), 'ítems') !== false, 'cada aviso tiene su detalle');

// --- Indicadores acumulados ---
$otro = tablero_m3::facts(local_chatbot_datos(['ficha' => ['id' => 2, 'userid' => 11, 'activa' => 1, 'organismo' => 'OTEC Dos', 'plan' => 'Plan A', 'modulo' => 'Módulo 1',
    'etiqueta' => 'Otra', 'puntaje' => 20, 'creada' => T0], 'data' => implementacion::clean($bajo), 'ajuste' => calidad::AJUSTE_TOTAL, 'minimo' => calidad::AJUSTE_TOTAL,
    'expost' => null, 'expost_vals' => [], 'aceptadas4' => [], 'vistas' => [], 'envios' => ['impl' => [['completo' => true, 'faltantes' => [], 'time' => T0 + DIA]], 'expost' => []],
    'validaciones' => [['id' => 5, 'estado' => 'listo', 'condicion' => implementacion::APROBADO, 'time' => T0 + 2 * DIA, 'items' => local_chatbot_items(implementacion::checklist_ids())]]]));
$r = tablero_m3::program([1 => $h, 2 => $otro], [10, 11, 12], 4);
local_chatbot_verificar($r['inscritos'] === 3 && $r['evaluaciones'] === 2 && $r['a']['con_ficha'] === 2 && $r['a']['tasa_inicio'] === 50 && $r['a']['uso_guia_ejemplo'] === 50
    && $r['a']['personas_entrega'] === 2, 'A: tasa de inicio 2 de 4 diseños y uso de la guía y el ejemplo en 1 de 2 personas');
local_chatbot_verificar($r['a']['oportunidad'] !== null && $r['a']['con_oportunidad'] === 2, 'A: oportunidad del registro, en mediana de días');
local_chatbot_verificar($r['b']['f1']['fichas_envio'] === 2 && $r['b']['f1']['primer_intento'] === 50 && $r['b']['f1']['envios_incompletos'] === 1
    && $r['b']['f1']['faltantes']['Sección 1: Horario']['pct'] === 100 && $r['b']['f1']['validadas'] === 2 && $r['b']['f1']['ciclos_promedio'] === 1.5,
    'B: fichas completas al primer intento, campos faltantes y ciclos hasta la aprobación (1,5)');
local_chatbot_verificar($r['b']['f1']['primera'][implementacion::NO_APROBADO] === 1 && $r['b']['f1']['final'][implementacion::OBSERVACIONES] === 1
    && $r['b']['f1']['items_criticos']['c1_2']['n'] === 1 && $r['b']['f1']['criticos'] === 33 && $r['b']['f4']['validadas'] === 1 && $r['b']['f4']['final'][implementacion::APROBADO] === 1,
    'B: decisión en el primer ciclo y al cierre, e ítems CRÍTICO por ítem, para la Etapa 0 y la Etapa 10');
local_chatbot_verificar($r['b']['recalculados']['total'] === 6 && $r['b']['recalculados']['n'] === 0, 'B: ningún logro recalculado sobre 6 criterios');
local_chatbot_verificar($r['c']['ciclos'] === 2 && $r['c']['tramos'] === ['menor30' => 1, '30a59' => 0, '60a89' => 1, '90mas' => 0] && $r['c']['brechas']['alta'] === 3
    && $r['c']['con_brecha_alta'] === 50 && $r['c']['pendientes'] === 2 && $r['c']['cobertura_menor'] === 100, 'C: logro por tramos, brechas por criticidad, pendientes y cobertura');
local_chatbot_verificar($r['d']['con_decision'] === 2 && $r['d']['tipos'][calidad::AJUSTE_REMEDIALES]['n'] === 1 && $r['d']['tipos'][calidad::AJUSTE_TOTAL]['pct'] === 50
    && $r['d']['determinantes']['logro']['n'] === 1, 'D: tipo de ajuste decidido y condición determinante');
local_chatbot_verificar($r['d']['aprobadas'] === 2 && $r['d']['embudo'][1]['n'] === 2 && $r['d']['embudo'][15]['pct'] === 100 && $r['d']['duracion_total'] === 8.0
    && $r['d']['uso_tiempo'] === 83.0, 'D: avance por etapa, duración total y uso del tiempo restante');
local_chatbot_verificar($r['e']['ciclos'] === 1 && $r['e']['logro_inicial'] === 72.7 && $r['e']['logro_final'] === 94.0 && $r['e']['ganancia'] === 21.3 && $r['e']['transferencia'] === 71.0
    && $r['e']['bp']['pct'] === 75, 'E: cierre del ciclo, ganancia, transferencia y buenas prácticas confirmadas (3 de 4)');
local_chatbot_verificar($r['f']['tramos']['alto']['promedio'] === 72.7 && $r['f']['tramos']['importantes']['n'] === 1 && $r['f']['ajustes'][calidad::AJUSTE_REMEDIALES]['promedio'] === 21.3
    && $r['f']['ajustes'][calidad::AJUSTE_TOTAL]['n'] === 0, 'F: logro inicial según el puntaje del diseño y ganancia según el tipo de ajuste');
$vacio = tablero_m3::program([], [], 0);
local_chatbot_verificar($vacio['a']['tasa_inicio'] === null && $vacio['c']['logro_promedio'] === null && $vacio['e']['ganancia'] === null, 'sin evaluaciones no hay ningún indicador inventado');
local_chatbot_verificar(count(tablero_m3::in_period([1 => $h, 2 => $otro], T0 + 30 * DIA)) === 0 && count(tablero_m3::in_period([1 => $h, 2 => $otro], T0 + 22 * DIA)) === 2
    && count(tablero_m3::in_period([1 => $h], 0)) === 1, 'el filtro de período deja las evaluaciones con actividad dentro de él');

// --- Calidad del agente ---
$enc = [
    ['fase' => 3, 'ajuste' => calidad::AJUSTE_PARCIAL, 'respuestas' => ['p1' => 5, 'p2' => 4, 'p3' => 5, 'p4' => 5, 'p5' => 4, 'p6' => 5, 'p7' => 'no']],
    ['fase' => 3, 'ajuste' => calidad::AJUSTE_PARCIAL, 'respuestas' => ['p1' => 4, 'p2' => 4, 'p3' => 4, 'p4' => 2, 'p5' => 3, 'p6' => 4, 'p7' => 'si', 'p7tipo' => 'calculo', 'p8' => 'Un cálculo no cuadró']],
    ['fase' => 4, 'ajuste' => null, 'respuestas' => ['p1' => 5, 'p2' => 5, 'p3' => 4, 'p5' => 5, 'p6' => 5, 'p7' => 'no']],
];
$g = tablero_m3::agent($enc, [3 => 2, 4 => 2]);
local_chatbot_verificar($g['respondidas'] === 3 && $g['respuesta']['3'] === 100 && $g['respuesta']['4'] === 50 && $g['respuesta']['total'] === 75, 'G: tasa de respuesta por fase y total');
local_chatbot_verificar($g['claridad'] === 4.7 && $g['precision']['0'] === 4.0 && $g['precision']['10'] === 5.0 && $g['fidelidad'] === 4.3 && $g['ahorro'] === 4.7,
    'G: claridad, precisión por etapa (0 y 10), fidelidad y ahorro de tiempo');
local_chatbot_verificar($g['acuerdo'][calidad::AJUSTE_PARCIAL] === ['n' => 2, 'pct' => 50] && $g['acuerdo'][calidad::AJUSTE_TOTAL]['pct'] === null, 'G: acuerdo con la decisión por tipo de ajuste');
local_chatbot_verificar($g['aplicabilidad'] === ['3' => 3.5, '4' => 5.0] && $g['errores']['n'] === 1 && $g['errores']['pct'] === 33 && $g['errores']['tipos']['calculo'] === 1
    && $g['comentarios'] === 1, 'G: aplicabilidad por momento, errores por tipo y comentarios');
local_chatbot_verificar($g['indice'] === 4.3, 'G: índice de calidad = promedio de las preguntas 1 a 6');

// --- Alertas ---
$al = tablero_m3::alerts($g, 4.5);
$tp = array_column($al, 'tipo');
local_chatbot_verificar($tp === ['acuerdo', 'errores'], 'con estas encuestas hay desacuerdo con la decisión (50 %) y errores (33 %)');
$bajo = tablero_m3::agent([['fase' => 3, 'ajuste' => calidad::AJUSTE_TOTAL, 'respuestas' => ['p1' => 2, 'p2' => 3, 'p3' => 3, 'p4' => 5, 'p5' => 3, 'p6' => 3, 'p7' => 'no']]], [3 => 4, 4 => 0]);
$tb = array_column(tablero_m3::alerts($bajo, null), 'tipo');
local_chatbot_verificar($tb === ['global', 'pregunta', 'respuesta'], 'índice menor que 3,5, pregunta bajo 3,0 y tasa de respuesta menor que 50 %');
local_chatbot_verificar(!in_array('global', array_column(tablero_m3::alerts($g, 4.7), 'tipo'), true)
    && in_array('global', array_column(tablero_m3::alerts($g, 4.9), 'tipo'), true), 'un índice alto alerta solo si cayó 0,5 puntos o más respecto del período anterior (4,9 a 4,3 sí; 4,7 a 4,3 no)');
local_chatbot_verificar(tablero_m3::alerts(tablero_m3::agent([], [3 => 0, 4 => 0]), null) === [], 'sin encuestas ni consolidaciones no hay alertas');
$tres = [];
for ($i = 0; $i < 3; $i++) {
    $tres[] = ['fase' => 4, 'ajuste' => null, 'respuestas' => ['p1' => 5, 'p2' => 5, 'p3' => 5, 'p5' => 5, 'p6' => 5, 'p7' => 'si', 'p7tipo' => 'regla']];
}
for ($i = 0; $i < 27; $i++) {
    $tres[] = ['fase' => 4, 'ajuste' => null, 'respuestas' => ['p1' => 5, 'p2' => 5, 'p3' => 5, 'p5' => 5, 'p6' => 5, 'p7' => 'no']];
}
$ge = tablero_m3::agent($tres, [3 => 0, 4 => 30]);
local_chatbot_verificar(array_column(tablero_m3::alerts($ge, null), 'tipo') === ['errores'], 'un mismo tipo de error en 3 encuestas alerta aunque no pase del 10 %');

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Tablero del Módulo 3 conforme: indicadores A a G, ocho avisos y cinco alertas de la propuesta.\n";
}
exit($fallidos === 0 ? 0 : 1);

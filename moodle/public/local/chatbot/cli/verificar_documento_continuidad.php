<?php
/**
 * Verifica el Documento operativo de la experiencia de continuidad (Modulo 3, salida de la Etapa 8, M1-v1.33.0):
 * vigencia, versiones y huella de las fuentes; lectura de los minutos de la secuencia; un anexo por instrumento desde la
 * Etapa 6 (y los recuperativos desde el diseño del Modulo 2); lo que fija el codigo despues de la extraccion (datos
 * generales, tablas en blanco, transcripcion textual, filas fijas, marcas de tiempo, estado del repertorio); el prompt de
 * las secciones 1 a 7 y el armado real del Word.
 *
 * Los productos de prueba imitan la estructura de un caso real (Etapa 6 con «9. Instrumentos de evaluación ajustados…» y
 * «9.N. Instrumento N»), sin sus datos.
 *
 * Uso:  php cli/verificar_documento_continuidad.php
 */

require_once(__DIR__ . '/_puro.php');
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
require_once(__DIR__ . '/../classes/tablero_m3.php');
require_once(__DIR__ . '/../classes/documento_continuidad.php');

use local_chatbot\calidad;
use local_chatbot\centro_recursos;
use local_chatbot\documento_continuidad as dc;
use local_chatbot\documento_operativo;
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

// --- Productos de prueba ---------------------------------------------------------------------------------------------
$e6 = '<h2>Etapa 6: Diseño de la experiencia de aprendizaje de continuidad</h2>'
    . '<h3>1. Nombre de la experiencia de aprendizaje de continuidad</h3><p>Comunicación laboral efectiva: nivelación y desempeño</p>'
    . '<h3>2. Propósito</h3><p>Superar las brechas de los criterios 1 y 2 e incorporar los pendientes.</p>'
    . '<h3>6. Secuencia instruccional</h3>'
    . '<h4>6.1. Fase de Inicio (30 minutos)</h4><table><tr><th>Actividad</th><th>Minutos</th></tr><tr><td>Encuadre</td><td>30</td></tr></table>'
    . '<h4>6.2. Fase de Desarrollo (110 minutos)</h4><table><tr><th>Sub-bloque</th><th>Duración</th></tr>'
    . '<tr><td>2.1 Producción escrita</td><td>60 min</td></tr><tr><td>Total del desarrollo</td><td>110 min</td></tr></table>'
    . '<h4>6.4. Balance temporal de la secuencia instruccional</h4>'
    . '<table><tr><th>Fase / Momento</th><th>Duración</th><th>Foco</th></tr><tr><td>Fase 1: Inicio</td><td>30 min</td><td>Encuadre</td></tr>'
    . '<tr><td>Fase 2: Desarrollo</td><td>110 min</td><td>Aplicación</td></tr><tr><td>Fase 3: Cierre</td><td>40 min</td><td>Acreditación</td></tr>'
    . '<tr><td>TOTAL DE LA EXPERIENCIA</td><td>180 min</td><td>Cumple el tiempo restante.</td></tr></table>'
    . '<h3>7. Materiales de apoyo</h3><p>Guía de reescritura y tarjetas situacionales.</p>'
    . '<h3>8. Plan de evaluación de la experiencia de continuidad</h3><table><tr><th>Criterio</th><th>Instrumento</th></tr>'
    . '<tr><td>C1</td><td>Instrumento 1</td></tr></table>'
    . '<h3>9. Instrumentos de evaluación ajustados, redactados y listos para utilizar</h3>'
    . '<p>PRESENTACION QUE NO VA EN LOS ANEXOS.</p>'
    . '<h4>9.1. Instrumento 1: Rúbrica analítica de acreditación integral</h4><p>Puntaje total: 16 puntos. Estándar mínimo: 12 puntos (75 %).</p>'
    . '<h5>Matriz de la rúbrica</h5><table><tr><th>Criterio</th><th>Insuficiente (1)</th><th>Avanzado (4)</th></tr>'
    . '<tr><td>C1</td><td>Omite partes del mensaje.</td><td>Estructura impecable.</td></tr></table>'
    . '<h4>9.2. Instrumento 2: Lista de cotejo formativa para coevaluación</h4><p>Ítem 1: usa un asunto preciso. ☐ Sí ☐ No</p>'
    . '<h4>9.3. Instrumento 3: Pauta de observación conductual</h4><p>Registro de hechos observables.</p>'
    . '<h3>Registro de vacíos de información y propuestas de solución</h3><p>Sin vacíos.</p>'
    . '<h3>Verificación de calidad de la etapa</h3><p>Verificado.</p>'
    . '<h3>Referencias consultadas</h3><ul><li>Plan formativo</li></ul>';

$m2e6 = '<h2>Etapa 6: Instrumentos evaluativos</h2>'
    . '<h3>1. Encuadre normativo y marco curricular</h3><p>Aprendizaje esperado y criterios.</p>'
    . '<h3>2. Relación de instrumentos y articulación con el Plan de Evaluación (Etapa 5)</h3>'
    . '<table><tr><th>N.º</th><th>Instrumento</th></tr><tr><td>1</td><td>Rúbrica</td></tr></table>'
    . '<h3>3. Instrumentos evaluativos redactados para implementación</h3>'
    . '<h4>3.1. Instrumento 1: Rúbrica socioformativa analítica</h4><p>Rúbrica original.</p>'
    . '<h4>3.2. Instrumento 2: Lista de cotejo formativa</h4><p>Lista original.</p>'
    . '<h4>3.3. Instrumento 3: Prueba aplicada basada en caso laboral</h4><h5>Cuadernillo de la prueba</h5><p>Caso incidental N.º 2.</p>'
    . '<h5>Pauta de corrección</h5><p>Clave: alternativa B.</p>'
    . '<h3>4. Detección de vacíos de información y medidas de aseguramiento de calidad</h3><p>NO DEBE QUEDAR EN LOS ANEXOS.</p>'
    . '<h3>5. Verificación de calidad de la etapa</h3><p>Verificado.</p>';

$aprendizaje = 'Comunicar información de manera clara, respetuosa y pertinente en contextos laborales, adaptando el lenguaje al '
    . 'interlocutor y al propósito comunicativo.';
$criterios = ['Redacta mensajes profesionales con claridad, coherencia y corrección ortográfica.',
    'Adapta el registro lingüístico al contexto y al interlocutor.',
    'Comunica instrucciones sin ambigüedad, asegurando comprensión del receptor.',
    'Mantiene trato respetuoso y cordial en interacciones laborales.'];

$secciones = <<<'HTML'
<h1>SECCIÓN 1 · Datos generales</h1>
<table><thead><tr><th>Campo</th><th>Información</th></tr></thead><tbody>
<tr><td>Organismo ejecutor</td><td>[Nombre del organismo ejecutor]</td></tr>
<tr><td><strong>Plan formativo y módulo</strong></td><td>Plan X</td></tr>
<tr><td>Experiencia inicial</td><td>Correos</td></tr>
<tr><td>Experiencia de continuidad</td><td>[Comunicación laboral efectiva: nivelación y desempeño]</td></tr>
<tr><td>Duración</td><td>180 minutos, de un tiempo restante de 150 minutos (total disponible − tiempo utilizado)</td></tr>
<tr><td>Participantes</td><td>22 · nivelación: 8 · profundización: 14</td></tr>
<tr><td>Modalidad y espacios</td><td>Aula con mesas modulares</td></tr>
<tr><td>Conducción</td><td>1 persona facilitadora</td></tr>
<tr><td>Fechas y horario</td><td>Por definir</td></tr>
</tbody></table>
<p><em>Origen: Etapas 0, 3 y 6 del informe consolidado.</em></p>
<h1>SECCIÓN 2 · Punto de partida</h1>
<h2>2.1 Brechas y pendientes que atiende la continuidad</h2>
<table><thead><tr><th>Criterio de evaluación</th><th>Logro inicial</th><th>Criticidad</th><th>Nivelación</th><th>Profundiz.</th><th>Acción de la continuidad</th></tr></thead><tbody>
<tr><td>1. Redacta mensajes profesionales</td><td>81,8 %</td><td>Baja</td><td>4</td><td>18</td><td>Acción 1 · Desarrollo</td></tr>
<tr><td>Pendientes</td><td>—</td><td>Alta</td><td>—</td><td>—</td><td>Criterios 3 y 4 en ambas trayectorias</td></tr>
</tbody></table>
<h1>SECCIÓN 3 · Aprendizaje esperado y criterios de evaluación</h1>
<table><thead><tr><th>Aprendizaje esperado</th></tr></thead><tbody><tr><td>Comunicar información de manera clara y respetuosa.</td></tr></tbody></table>
<table><thead><tr><th>Criterio de evaluación</th><th>Se trabaja en</th><th>Trayectoria</th><th>Se evalúa con</th></tr></thead><tbody>
<tr><td>C1: Redacta mensajes profesionales con claridad y coherencia.</td><td>Desarrollo</td><td>Nivelación</td><td>Instrumento 1</td></tr>
<tr><td>Adapta el registro lingüístico al contexto</td><td>Desarrollo</td><td>Ambas</td><td>Instrumento 1</td></tr>
</tbody></table>
<h1>SECCIÓN 4 · Organización de las trayectorias</h1>
<h2>4.2 Asignación de participantes (a completar por la persona facilitadora)</h2>
<table><thead><tr><th>Participante</th><th>Trayectoria</th><th>Criterios por nivelar o rol</th><th>Dupla o tutor/a</th></tr></thead><tbody>
<tr><td>Ejemplo: participante 1</td><td>Nivelación</td><td>C1</td><td>Participante 2</td></tr>
</tbody></table>
<h1>SECCIÓN 5 · Secuencia instruccional</h1>
<h2>5.1 Vista general</h2>
<table><thead><tr><th>Fase o bloque</th><th>Minutos</th><th>Nivelación</th><th>Profundización</th><th>Recuperativa</th><th>Criterios y evidencias</th></tr></thead><tbody>
<tr><td>Inicio: encuadre</td><td>30</td><td>Analiza el caso</td><td>Analiza el caso</td><td>—</td><td>C1 a C4</td></tr>
<tr><td>Desarrollo: bloque A</td><td>110</td><td>Reescribe con plantilla</td><td>Resuelve contingencias</td><td>—</td><td>C1 a C3</td></tr>
<tr><td>Cierre: plenario</td><td>40</td><td>Demuestra</td><td>Demuestra</td><td>—</td><td>C1 a C4</td></tr>
<tr><td>Total</td><td>180</td><td></td><td></td><td></td><td></td></tr>
</tbody></table>
<h2>5.2 Distribución por jornada (a completar por la persona facilitadora)</h2>
<table><thead><tr><th>Jornada</th><th>Fecha</th><th>Horario</th><th>Fases o bloques</th><th>Observaciones (espacio, apoyo, recuperativos)</th></tr></thead><tbody>
<tr><td>Jornada 1</td><td>12-12-2026</td><td>09:00 a 12:00</td><td>Inicio y desarrollo</td><td></td></tr>
</tbody></table>
<h1>SECCIÓN 6 · Materiales y recursos</h1>
<h2>6.1 Materiales instruccionales</h2>
<table><thead><tr><th>Código</th><th>Material</th><th>Categoría</th><th>Origen y versión</th><th>Bloque y trayectoria</th><th>Estado</th><th>Listo</th></tr></thead><tbody>
<tr><td>M1</td><td>Guía de caso</td><td>Reutilizado</td><td>M1 v1</td><td>Inicio · ambas</td><td>Pendiente</td><td></td></tr>
<tr><td>M3-C</td><td>Plantillas ajustadas</td><td>Ajustado</td><td>M3 v1</td><td>Desarrollo · nivelación</td><td>Disponible en el repertorio</td><td></td></tr>
<tr><td>MC1</td><td>Tarjetas situacionales</td><td>Nuevo</td><td>—</td><td>Desarrollo · ambas</td><td>Pendiente</td><td>[ ]</td></tr>
</tbody></table>
<h2>6.2 Recursos del entorno formativo (organismo ejecutor)</h2>
<table><thead><tr><th>Recurso</th><th>Cantidad</th><th>Bloque</th><th>Verificado</th></tr></thead><tbody>
<tr><td>Proyector multimedia</td><td>1</td><td>Inicio</td><td></td></tr>
</tbody></table>
<h1>SECCIÓN 7 · Plan de evaluación sintético</h1>
<h2>7.1 Instrumentos</h2>
<table><thead><tr><th>Código</th><th>Instrumento</th><th>Tipo</th><th>Criterios</th><th>Aplica a</th><th>Momento</th><th>Puntaje</th><th>Umbral</th></tr></thead><tbody>
<tr><td>Instrumento 1</td><td>Rúbrica analítica</td><td>Sumativo ajustado</td><td>1 a 4</td><td>Todas</td><td>Cierre</td><td>16</td><td>12 (75 %)</td></tr>
<tr><td>Instrumento 3</td><td>Prueba aplicada (diseño)</td><td>Recuperativo</td><td>1 y 2</td><td>Personas ausentes</td><td>Desarrollo</td><td>20</td><td>15 (75 %)</td></tr>
<tr><td>IN9</td><td>Prueba que no está en el diseño</td><td>Recuperativo</td><td>3</td><td>Personas ausentes</td><td>Cierre</td><td>10</td><td>6 (60 %)</td></tr>
</tbody></table>
<h2>7.2 Regla de aprobación y registro del logro final</h2>
<table><thead><tr><th>Condiciones</th></tr></thead><tbody>
<tr><td>Aprueba con 12 de 16 puntos en la rúbrica y ningún criterio en el nivel 1.</td></tr>
<tr><td>Los instrumentos recuperativos se califican con la regla del diseño validado, sin cambios.</td></tr>
</tbody></table>
<h2>7.3 Equivalencia con los niveles de la ficha ex post</h2>
<table><thead><tr><th>Nivel</th><th>Instrumento 1</th><th>Condición</th></tr></thead><tbody>
<tr><td>Avanzado</td><td>15 a 16</td><td>90 % a 100 %</td></tr>
<tr><td>Adecuado</td><td>12 a 14</td><td>75 % a 89 %</td></tr>
<tr><td>Básico</td><td>10 a 11</td><td>60 % a 74 %</td></tr>
<tr><td>Insuficiente</td><td>0 a 9</td><td>Menos de 60 % o condición crítica vulnerada</td></tr>
</tbody></table>
<h1>ANEXOS · Instrumentos que el asistente no debía escribir</h1><p>Duplicado del modelo.</p>
HTML;

$fichaM3 = ['plan' => 'Habilidades transversales para el trabajo, código PF-0245', 'modulo' => 'Módulo 1: Comunicación para el trabajo',
    'experiencia' => 'Elaboración de correos profesionales', 'fechas' => '22 de marzo de 2026', 'inscritos' => '24',
    'presentes' => '22', 'tiempo_total' => '360', 'tiempo_utilizado' => '210', 'aprendizaje' => $aprendizaje];
$fichaM2 = ['organismo' => 'OTEC Capacita Norte'];

// --- 1. Vigencia, versiones y fuentes --------------------------------------------------------------------------------
local_chatbot_verificar(dc::ETAPA === calidad::OPERATIVO && !expost::is_phase4(dc::ETAPA) && dc::ETAPA > expost::CONSOLIDACION,
    'la fila interna (etapa 16) no choca con las etapas 1 a 8 ni con las de la Fase 4 (9 a 15)');
local_chatbot_verificar(calidad::max_seconds(calidad::OPERATIVO) === dc::MAX_SECONDS,
    'calidad::max_seconds() da al documento su propio tiempo máximo');
$filaOp = (object) ['etapa' => dc::ETAPA, 'generacion' => implementacion::LISTO, 'estado' => calidad::ACEPTADO,
    'huella' => 'h8', 'rondas' => 2, 'contenido' => '<h1>SECCIÓN 1 · Datos generales</h1><p>x</p>',
    'informe' => json_encode(['fuente' => 'f1'])];
local_chatbot_verificar(dc::is_current($filaOp, 'h8') && !dc::is_current($filaOp, 'otra') && !dc::is_current(null, 'h8')
    && !dc::is_current((object) (['generacion' => implementacion::ERROR] + (array) $filaOp), 'h8'),
    'vigente solo listo, con contenido y con la huella de la consolidación de hoy');
local_chatbot_verificar(dc::version_number($filaOp) === 2 && dc::version_number(null) === 1
    && dc::version_number((object) ['rondas' => 0]) === 1, 'número de versión desde rondas (1, 2, 3…)');
local_chatbot_verificar(dc::same_source($filaOp, 'f1') && !dc::same_source($filaOp, 'f2')
    && !dc::same_source((object) (['generacion' => implementacion::PENDIENTE] + (array) $filaOp), 'f1'),
    'same_source(): el documento listo sirve para las mismas fuentes y no para otras');

$filas = [];
foreach (range(1, 7) as $n) {
    $filas[$n] = (object) ['estado' => calidad::ACEPTADO, 'contenido' => '<p>Etapa ' . $n . '</p>', 'huella' => null, 'informe' => null];
}
$filas[8] = (object) ['estado' => calidad::ACEPTADO, 'contenido' => 'x', 'huella' => calidad::stages_fingerprint($filas, 'val'),
    'informe' => null];
$filas[calidad::OPERATIVO] = $filaOp;
local_chatbot_verificar(calidad::accepted($filas, 'val') === [1, 2, 3, 4, 5, 6, 7, 8],
    'calidad::accepted() no cuenta la fila del documento operativo como etapa');
$f0 = dc::source_fingerprint('val', $filas, calidad::AJUSTE_REMEDIALES, 'd');
$filas7 = $filas;
$filas7[7] = (object) ['estado' => calidad::ACEPTADO, 'contenido' => '<p>Otra síntesis</p>'];
$filas6 = $filas;
$filas6[6] = (object) ['estado' => calidad::ACEPTADO, 'contenido' => '<p>Otra continuidad</p>'];
local_chatbot_verificar($f0 === dc::source_fingerprint('val', $filas7, calidad::AJUSTE_REMEDIALES, 'd')
    && $f0 !== dc::source_fingerprint('val', $filas6, calidad::AJUSTE_REMEDIALES, 'd')
    && $f0 !== dc::source_fingerprint('val', $filas, calidad::AJUSTE_PARCIAL, 'd')
    && $f0 !== dc::source_fingerprint('otra', $filas, calidad::AJUSTE_REMEDIALES, 'd'),
    'versión nueva solo si cambia lo que extrae (continuidad, ajuste, validación); la síntesis no cuenta');

// --- 2. Minutos de la secuencia --------------------------------------------------------------------------------------
local_chatbot_verificar(calidad::sequence_minutes($e6) === 180 && tablero_m3::continuity_minutes($e6) === 180,
    'sequence_minutes(): columna «Duración» y fila «TOTAL DE LA EXPERIENCIA», la mayor sobre el total de un bloque');
local_chatbot_verificar(calidad::sequence_minutes('<table><tr><th>Momento</th><th>Minutos</th></tr><tr><td>Total</td><td>150 min</td></tr></table>') === 150
    && calidad::sequence_minutes('<p>El tiempo total de la experiencia es de 140 minutos.</p>') === 140
    && calidad::sequence_minutes('<table><tr><th>Momento</th><th>Minutos</th></tr><tr><td>Inicio</td><td>30</td></tr></table>') === null
    && calidad::sequence_minutes('') === null, 'sequence_minutes(): sin fila «Total» ni frase «tiempo total» no se estima');

// --- 3. Instrumentos: seccion, bloques y numero ----------------------------------------------------------------------
$seccion = dc::instruments_section($e6);
local_chatbot_verificar(strpos($seccion, 'Instrumento 1: Rúbrica analítica') !== false && strpos($seccion, 'Registro de vacíos') === false
    && strpos($seccion, 'Referencias consultadas') === false && strpos($seccion, 'Plan de evaluación') === false
    && strpos($seccion, 'Instrumentos de evaluación ajustados') === false,
    'instruments_section(): la sección 9 de la Etapa 6, sin su título, sin el plan de evaluación ni los cierres');
$bloques = dc::split_instruments($seccion);
local_chatbot_verificar(count($bloques) === 3 && $bloques[0]['titulo'] === 'Instrumento 1: Rúbrica analítica de acreditación integral'
    && $bloques[2]['numero'] === '3' && $bloques[0]['nivel'] === 4 && strpos($bloques[0]['contenido'], 'Matriz de la rúbrica') !== false
    && strpos($bloques[0]['contenido'], 'Omite partes del mensaje.') !== false
    && strpos(implode('', array_column($bloques, 'contenido')), 'PRESENTACION QUE NO VA') === false,
    'split_instruments(): un bloque por «9.N. Instrumento N», sin la numeración, con todo su contenido y sin la presentación');
$porCodigo = dc::split_instruments('<h4>INS-03-R · Lista de cotejo ajustada</h4><p>A</p><h5>Pauta</h5><p>B</p><h4>INS-04-R · Escala</h4><p>C</p>');
$porTipo = dc::split_instruments('<p>intro</p><h4>Rúbrica analítica</h4><p>A</p><h4>Lista de cotejo de coevaluación</h4><p>B</p>');
local_chatbot_verificar(count($porCodigo) === 2 && $porCodigo[0]['titulo'] === 'INS-03-R · Lista de cotejo ajustada'
    && count($porTipo) === 2 && dc::split_instruments('<h4>Orientaciones</h4><p>x</p>') === [],
    'split_instruments(): reconoce títulos por código («INS-03-R») y, si no hay otros, por tipo de instrumento');
local_chatbot_verificar(dc::split_instruments('<p>' . str_repeat('Indicadores del instrumento sin título propio. ', 10) . '</p>'
        . '<h5>Pauta de corrección</h5><p>Clave.</p>') === []
    && dc::split_instruments('<p>Rúbrica sin título.</p><h5>Escala</h5><p>1 a 4</p>') === [],
    'split_instruments(): por tipo no separa si antes hay texto de fondo ni toma un subtítulo de una palabra («Escala»)');
local_chatbot_verificar(dc::instrument_number('Instrumento 5') === '5' && dc::instrument_number('IN5') === '5'
    && dc::instrument_number('INS-05') === '5' && dc::instrument_number('Inst. 4a') === '4a'
    && dc::instrument_number('Instrumento N.º 2: Rúbrica') === '2' && dc::instrument_number('Prueba escrita') === null,
    'instrument_number(): número de un instrumento en un código o título');
$m2bloques = dc::design_instruments($m2e6);
local_chatbot_verificar(count($m2bloques) === 3 && $m2bloques[2]['numero'] === '3'
    && strpos($m2bloques[2]['contenido'], 'Clave: alternativa B.') !== false
    && strpos(implode('', array_column($m2bloques, 'contenido')), 'NO DEBE QUEDAR') === false,
    'design_instruments(): los instrumentos del diseño del Módulo 2, sin el cierre «Detección de vacíos…»');
local_chatbot_verificar(strpos(documento_operativo::strip_stage_closures($m2e6), 'NO DEBE QUEDAR') === false,
    'strip_stage_closures() reconoce también «Detección de vacíos de información…» como cierre (Módulo 2)');

// --- 4. Lo que fija el codigo ----------------------------------------------------------------------------------------
$datos = dc::general_data($fichaM3, $fichaM2, calidad::AJUSTE_REMEDIALES);
local_chatbot_verificar($datos['Organismo ejecutor'] === 'OTEC Capacita Norte'
    && $datos['Plan formativo y módulo'] === 'Habilidades transversales para el trabajo, código PF-0245 · Módulo 1: Comunicación para el trabajo'
    && $datos['Experiencia inicial'] === 'Elaboración de correos profesionales · 22 de marzo de 2026'
    && $datos['Tipo de ajuste decidido'] === 'Acciones remediales' && $datos['Fechas y horario'] === ''
    && dc::general_data([], [], null)['Organismo ejecutor'] === null,
    'general_data(): organismo del diseño, plan · módulo, experiencia · fechas, tipo de ajuste y fechas en blanco');

$estados = documento_operativo::repertoire_status_map([
    (object) ['codigo' => 'M1', 'estado' => centro_recursos::MAT_ENTREGADO],
    (object) ['codigo' => 'M3', 'estado' => centro_recursos::MAT_ENTREGADO],
]);
$doc = dc::assemble_html($secciones, ['etapa6' => $e6, 'm2etapa6' => $m2e6, 'datos' => $datos, 'aprendizaje' => $aprendizaje,
    'criterios' => $criterios, 'restante' => 150, 'min4' => 170, 'min6' => 180, 'estados' => $estados]);

local_chatbot_verificar(preg_match('#<td>Organismo ejecutor</td><td>OTEC Capacita Norte</td>#u', $doc) === 1
    && strpos($doc, 'Habilidades transversales para el trabajo, código PF-0245 · Módulo 1: Comunicación para el trabajo') !== false
    && strpos($doc, 'Elaboración de correos profesionales · 22 de marzo de 2026') !== false
    && preg_match('#<td>Tipo de ajuste decidido</td><td>Acciones remediales</td></tr>\s*<tr><td>Fechas y horario</td><td></td>#u', $doc) === 1
    && strpos($doc, 'Por definir') === false && strpos($doc, 'Origen: Etapas 0') === false,
    'Sección 1: filas de la ficha exactas, «Tipo de ajuste» agregado antes de «Fechas y horario», que queda en blanco; sin notas «Origen»');
local_chatbot_verificar(strpos($doc, 'Ejemplo: participante 1') === false && strpos($doc, '12-12-2026') === false
    && preg_match_all('#<tr><td></td><td></td><td></td><td></td></tr>#', $doc) >= 4
    && strpos($doc, '<td>Jornada 2</td>') !== false,
    'tablas 4.2 y 5.2 en blanco para la persona facilitadora (cuatro filas y dos jornadas)');
local_chatbot_verificar(strpos($doc, '<td>' . $aprendizaje . '</td>') !== false
    && strpos($doc, '<td>C1: ' . $criterios[0] . '</td>') !== false && strpos($doc, '<td>2. ' . $criterios[1] . '</td>') !== false,
    'Sección 3: aprendizaje esperado y criterios textuales del plan, con el rótulo de la Etapa 6 («C1: ») o su número');
local_chatbot_verificar(strpos($doc, '<td>' . dc::FILA_RECUPERATIVOS . '</td>') !== false
    && strpos($doc, '<td>' . dc::FILA_NO_REGRESION . '</td>') !== false
    && strpos($doc, 'regla del diseño validado, sin cambios.</td>') === false
    && preg_match('#Condición</th>.*?</table>\s*<p><em>' . preg_quote(dc::NOTA_FASE4, '#') . '</em></p>#su', $doc) === 1,
    'Sección 7: las dos filas fijas de la regla de aprobación (una corregida, otra agregada) y la nota de uso en la Fase 4');
$fijas = dc::ensure_fixed_texts('<table><tr><th>Condiciones</th></tr><tr><td>Aprueba con 12 puntos.</td></tr>'
    . '<tr><td>Los instrumentos recuperativos se califican según el diseño validado sin cambios.</td></tr>'
    . '<tr><td>Se registra el nivel más alto demostrado en el ciclo.</td></tr></table>');
$mezcla = dc::ensure_fixed_texts('<table><tr><th>Condiciones</th></tr><tr><td>'
    . str_repeat('Aprueba con 12 de 16 puntos y nivel Adecuado en cada criterio evaluado. ', 3)
    . 'Los instrumentos recuperativos se califican con la regla del diseño.</td></tr></table>');
local_chatbot_verificar(strpos($fijas, '<td>' . dc::FILA_RECUPERATIVOS . '</td>') !== false
    && strpos($fijas, '<td>' . dc::FILA_NO_REGRESION . '</td>') !== false && strpos($fijas, 'según el diseño') === false
    && strpos($fijas, '<td>Aprueba con 12 puntos.</td>') !== false && substr_count($fijas, '<td>') === 3
    && strpos($fijas, '<p><em>' . dc::NOTA_FASE4 . '</em></p>') !== false
    && strpos($mezcla, 'Aprueba con 12 de 16 puntos') !== false && strpos($mezcla, '<td>' . dc::FILA_RECUPERATIVOS . '</td>') !== false,
    'filas fijas: una variante se corrige por su frase clave sin duplicar; si viene mezclada con la regla, se agrega aparte');
local_chatbot_verificar(strpos($doc, '[REVISAR: la secuencia suma 180 minutos y supera el tiempo restante de 150 minutos]') !== false
    && strpos($doc, '[REVISAR: diferencia entre etapas — la Etapa 4 distribuye 170 minutos y la secuencia de la Etapa 6 suma 180]') !== false
    && strpos($doc, 'diferencia con la Etapa 6') === false && strpos($doc, 'Tiempo restante: 150 minutos') !== false,
    'tiempos: marca si la secuencia supera el tiempo restante o difiere entre etapas, y la fila Total lleva el tiempo restante');
local_chatbot_verificar(preg_match('#<td>M1</td>.*?<td>Disponible en el repertorio</td><td>☐</td>#su', $doc) === 1
    && preg_match('#<td>M3-C</td>.*?<td>Pendiente</td><td>☐</td>#su', $doc) === 1
    && preg_match('#<td>MC1</td>.*?<td>Pendiente</td><td>☐</td>#su', $doc) === 1
    && preg_match('#<td>Proyector multimedia</td>.*?<td>☐</td>#su', $doc) === 1,
    'Sección 6: estado del repertorio para los reutilizados, «Pendiente» para ajustados y nuevos, y casillas ☐');

$conTotal = dc::time_marks('<table><tr><th>Fase o bloque</th><th>Minutos</th><th>Nivelación</th></tr>'
    . '<tr><td>Inicio</td><td>40</td><td>x</td></tr><tr><td>Cierre</td><td>50</td><td>y</td></tr></table>', 120, null, 90);
local_chatbot_verificar(strpos($conTotal, '<td>Total</td><td>90</td><td>Tiempo restante: 120 minutos</td>') !== false
    && strpos($conTotal, '[REVISAR') === false && dc::sequence_total($conTotal) === 90,
    'time_marks(): agrega la fila Total que falte, con la suma, sin marcas si todo cuadra');
$corregido = dc::time_marks('<table><tr><th>Campo</th><th>Información</th></tr><tr><td>Duración</td><td>180 minutos, de un tiempo '
    . 'restante de 190 minutos (total disponible − tiempo utilizado)</td></tr></table><table><tr><th>Fase o bloque</th><th>Minutos</th>'
    . '<th>Nivelación</th></tr><tr><td>Total</td><td>180</td><td>Tiempo restante: 190 minutos</td></tr></table>', 180, null, 180);
local_chatbot_verificar(strpos($corregido, 'de un tiempo restante de 180 minutos') !== false
    && strpos($corregido, 'Tiempo restante: 180 minutos') !== false && strpos($corregido, '190') === false
    && strpos($corregido, '[REVISAR') === false,
    'time_marks(): el tiempo restante que escribió el asistente se corrige con el dato exacto (fila Total y «Duración»)');
local_chatbot_verificar(strpos(dc::time_marks('<table><tr><th>Fase o bloque</th><th>Minutos</th><th>Nivelación</th></tr>'
    . '<tr><td>Total</td><td>100</td><td></td></tr></table>', 120, null, 110), '[REVISAR: diferencia con la Etapa 6 — su secuencia suma 110 minutos]') !== false,
    'time_marks(): marca cuando el total extraído no coincide con la secuencia de la Etapa 6');

// Orientacion como en la plantilla: la Seccion 5 en paginas horizontales y el resto en verticales; los anexos no se tocan.
local_chatbot_verificar(preg_match('#<table data-orientacion="h"><thead><tr><th>Fase o bloque</th>#u', $doc) === 1
    && preg_match('#<table data-orientacion="h"><thead><tr><th>Jornada</th>#u', $doc) === 1
    && preg_match('#<table data-orientacion="v"><thead><tr><th>Código</th><th>Material</th>#u', $doc) === 1
    && preg_match('#<table data-orientacion="v"><thead><tr><th>Código</th><th>Instrumento</th>#u', $doc) === 1
    && preg_match('#<table data-orientacion="v"><thead><tr><th>Campo</th>#u', $doc) === 1
    && strpos(substr($doc, (int) strpos($doc, '<h1>ANEXOS')), 'data-orientacion') === false,
    'orientation_hints(): Sección 5 horizontal, las demás verticales (también 6.1 y 7.1), anexos con la regla general');

// --- 5. Anexos -------------------------------------------------------------------------------------------------------
local_chatbot_verificar(count(dc::recovery_codes($doc)) === 2 && dc::recovery_codes($doc)[0]['codigo'] === 'Instrumento 3',
    'recovery_codes(): los instrumentos «Recuperativo» de la tabla 7.1');
local_chatbot_verificar(strpos($doc, 'Duplicado del modelo') === false && substr_count($doc, '<h1>ANEXOS · Instrumentos de evaluación</h1>') === 1
    && strpos($doc, '<h2>Anexo 1 · Instrumento 1: Rúbrica analítica de acreditación integral</h2>') !== false
    && strpos($doc, '<h3>Matriz de la rúbrica</h3>') !== false
    && strpos($doc, '<h2>Anexo 3 · Instrumento 3: Pauta de observación conductual</h2>') !== false
    && strpos($doc, 'PRESENTACION QUE NO VA') === false && strpos($doc, 'Sin vacíos.') === false,
    'anexos de la continuidad: uno por instrumento, sin cambios, con los subtítulos internos bajo el anexo');
local_chatbot_verificar(strpos($doc, '<h2>Anexo 4 · Instrumento 3: Prueba aplicada basada en caso laboral · recuperativo</h2><p><em>'
        . dc::NOTA_RECUPERATIVO . '</em></p>') !== false && strpos($doc, 'Clave: alternativa B.') !== false
    && strpos($doc, '<h2>Anexo 5 · IN9 · recuperativo</h2><p>[REVISAR: instrumento recuperativo no encontrado') !== false
    && strpos($doc, 'NO DEBE QUEDAR') === false,
    'anexos recuperativos: el instrumento del diseño del Módulo 2 transcrito sin cambios; si no está, marca [REVISAR]');
local_chatbot_verificar(documento_operativo::review_marks_count($doc) === 3, 'el documento cuenta sus marcas [REVISAR] (3)');
$sinInst = dc::annexes_html('<h3>1. Nombre</h3><p>x</p>', [], []);
$sinTitulos = dc::annexes_html('<h3>9. Instrumentos de evaluación</h3><p>Rúbrica sin título propio.</p><h5>Escala</h5><p>1 a 4</p>', [], []);
local_chatbot_verificar(strpos($sinInst, '[REVISAR: la Etapa 6 no trae los instrumentos') !== false
    && strpos($sinTitulos, '<h2>Anexo 1 · Instrumentos de evaluación de la continuidad</h2><p>Rúbrica sin título propio.</p><h3>Escala</h3>') !== false,
    'anexos sin instrumentos: marca [REVISAR]; instrumentos sin título propio: completos en un solo anexo');

// --- 6. Datos de la cabecera -----------------------------------------------------------------------------------------
local_chatbot_verificar(dc::general_value($doc, 'Experiencia de continuidad') === 'Comunicación laboral efectiva: nivelación y desempeño'
    && dc::sequence_total($doc) === 180, 'general_value() y sequence_total() leen el nombre de la continuidad y su duración');
local_chatbot_verificar(dc::leading_label('C1: Redacta') === 'C1: ' && dc::leading_label('1. Redacta') === '1. '
    && dc::leading_label('Criterio 2: Adapta') === 'Criterio 2: ' && dc::leading_label('Comunica instrucciones') === '',
    'leading_label(): rótulo inicial de un criterio');
$meta = dc::word_meta($datos, 'Comunicación laboral efectiva', calidad::AJUSTE_REMEDIALES, '26 de noviembre de 2026', 2, 150, 180);
local_chatbot_verificar($meta['estilo'] === 'operativo' && $meta['portada'] === false && $meta['etiqueta'] === dc::ETIQUETA
    && $meta['encabezado'] === 'Documento operativo · Experiencia de continuidad'
    && $meta['subtitulo'] === 'Documento de trabajo para la conducción en aula o taller · Tipo de ajuste: acciones remediales · '
        . 'Informe consolidado: 26 de noviembre de 2026 · versión 2'
    && $meta['meta_items'][1]['valor'] === '180 de 150 min' && count($meta['meta_items']) === 4,
    'word_meta(): cabecera, subtítulo y encabezado de página textuales de la propuesta');

// --- 7. Prompt de las secciones 1 a 7 --------------------------------------------------------------------------------
$analisis = ['tiempos' => ['total' => 360, 'utilizado' => 210, 'restante' => 150],
    'criterios' => [['n' => 1, 'criterio' => $criterios[0], 'logro' => 81.8, 'criticidad' => 'baja', 'nivelacion' => 4, 'profundizacion' => 18],
        ['n' => 2, 'criterio' => $criterios[1], 'logro' => 95.0, 'criticidad' => null, 'nivelacion' => 1, 'profundizacion' => 21]],
    'sin_ficha' => [3, 4]];
$prompt = dc::build_prompt(['datos' => $datos, 'ficha' => $fichaM3, 'aprendizaje' => $aprendizaje, 'criterios' => $criterios,
    'analisis' => $analisis, 'ajuste' => calidad::AJUSTE_REMEDIALES, 'fecha' => '26 de noviembre de 2026', 'version' => 1,
    'min4' => 170, 'min6' => 180, 'estados' => $estados, 'instrumentos_m2' => array_column($m2bloques, 'titulo'),
    'etapas' => [2 => '<h2>Etapa 2</h2><p>BRECHAS.</p>', 4 => '<h2>Etapa 4</h2><p>ACCIONES.</p>', 6 => $e6],
    'm2' => [4 => '<p>MATERIALES DEL DISEÑO.</p>', 5 => '<p>REGISTRO DEL DISEÑO.</p>']]);
$titulos = ['# SECCIÓN 1 · Datos generales', '# SECCIÓN 2 · Punto de partida', '## 2.1 Brechas y pendientes que atiende la continuidad',
    '# SECCIÓN 3 · Aprendizaje esperado y criterios de evaluación', '# SECCIÓN 4 · Organización de las trayectorias',
    '## 4.2 Asignación de participantes (a completar por la persona facilitadora)', '# SECCIÓN 5 · Secuencia instruccional',
    '## 5.2 Distribución por jornada (a completar por la persona facilitadora)', '## 5.3 Guion de conducción por bloque',
    '# SECCIÓN 6 · Materiales y recursos', '# SECCIÓN 7 · Plan de evaluación sintético',
    '## 7.3 Equivalencia con los niveles de la ficha ex post'];
$faltan = array_filter($titulos, static function(string $t) use ($prompt): bool {
    return strpos($prompt, $t) === false;
});
local_chatbot_verificar(!$faltan, 'build_prompt(): las siete secciones de la propuesta con sus subtítulos' . ($faltan ? ' (falta: ' . implode(' | ', $faltan) . ')' : ''));
local_chatbot_verificar(strpos($prompt, dc::FILA_RECUPERATIVOS) !== false && strpos($prompt, dc::FILA_NO_REGRESION) !== false
    && strpos($prompt, dc::NOTA_FASE4) !== false && strpos($prompt, '[REVISAR: diferencia entre etapas') !== false
    && strpos($prompt, '[REVISAR: equivalencia no definida]') !== false && strpos($prompt, 'M3-C') !== false
    && strpos($prompt, 'MC1, MC2') !== false && strpos($prompt, 'entre 6 y 10 páginas') !== false,
    'build_prompt(): textos fijos, marcas [REVISAR], reglas de códigos de materiales y extensión');
local_chatbot_verificar(strpos($prompt, 'TIEMPO RESTANTE (total − utilizado) = 150 min') !== false
    && strpos($prompt, '1. ' . $criterios[0]) !== false && strpos($prompt, $aprendizaje) !== false
    && strpos($prompt, 'logro inicial 81,8 %; criticidad baja; requieren nivelación (Insuficiente + Básico): 4') !== false
    && strpos($prompt, 'criticidad sin brecha') !== false && strpos($prompt, 'criterio 3 «' . $criterios[2] . '»') !== false
    && strpos($prompt, 'Organismo ejecutor: OTEC Capacita Norte') !== false && strpos($prompt, 'M1: Disponible en el repertorio') !== false
    && strpos($prompt, 'Instrumento 3: Prueba aplicada basada en caso laboral') !== false,
    'build_prompt(): datos exactos, criterios textuales, datos calculados de la 2.1, repertorio e instrumentos del diseño');
local_chatbot_verificar(strpos($prompt, 'BRECHAS.') !== false && strpos($prompt, 'ACCIONES.') !== false
    && strpos($prompt, 'MATERIALES DEL DISEÑO.') !== false && strpos($prompt, 'REGISTRO DEL DISEÑO.') !== false
    && strpos($prompt, '#### 9.1. Instrumento 1') !== false && strpos($prompt, 'Sin vacíos.') === false
    && strpos($prompt, 'PRODUCTO ACEPTADO DE LA ETAPA 6') < strpos($prompt, 'PRODUCTO ACEPTADO DE LA ETAPA 2'),
    'build_prompt(): Etapa 6 primero (con sus instrumentos) y Etapas 2 y 4, sin las secciones de cierre');

// --- 8. Word real ----------------------------------------------------------------------------------------------------
$ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'local_chatbot_continuidad_test_' . uniqid('', true) . '.docx';
$logo = __DIR__ . '/../pix/logo_interfases.png';
local_chatbot_verificar(dc::build_docx($ruta, $meta, $doc, is_readable($logo) ? $logo : '') && is_file($ruta) && filesize($ruta) > 2000,
    'build_docx() escribe el Word');
if (is_file($ruta)) {
    $zip = new \ZipArchive();
    $abierto = $zip->open($ruta) === true;
    $xml = $abierto ? (string) $zip->getFromName('word/document.xml') : '';
    $cabeceras = $abierto ? (string) $zip->getFromName('word/header1.xml') . (string) $zip->getFromName('word/header2.xml') : '';
    if ($abierto) {
        $zip->close();
    }
    @unlink($ruta);
    $d = new \DOMDocument();
    local_chatbot_verificar($xml !== '' && @$d->loadXML($xml), 'word/document.xml es XML bien formado');
    local_chatbot_verificar(strpos($xml, dc::ETIQUETA) !== false && strpos($xml, 'Comunicación laboral efectiva') !== false
        && strpos($xml, 'versión 2') !== false && strpos($cabeceras, 'Documento operativo · Experiencia de continuidad') !== false,
        'el Word lleva la cabecera de la propuesta y el encabezado de página «Documento operativo · Experiencia de continuidad»');
    local_chatbot_verificar(strpos($xml, 'SECCIÓN 1') !== false && strpos($xml, 'SECCIÓN 7') !== false && strpos($xml, 'ANEXOS') !== false
        && strpos($xml, 'Anexo 4 · Instrumento 3') !== false && strpos($xml, '<w:pageBreakBefore/>') !== false
        && strpos($xml, 'FEF3C7') !== false && strpos($xml, '☐') !== false,
        'el Word lleva las bandas SECCIÓN 1 a 7, ANEXOS en página nueva, las marcas [REVISAR] resaltadas y las casillas ☐');
    $horizontal = (int) strpos($xml, 'w:orient="landscape"');
    local_chatbot_verificar(substr_count($xml, 'w:orient="landscape"') === 1 && strpos($xml, 'Vista general') < $horizontal
        && strpos($xml, 'Materiales instruccionales') > $horizontal,
        'el Word deja en una sola página horizontal la Sección 5 y en vertical las tablas anchas 6.1 y 7.1, como la plantilla');
}

$pasados = $total - $fallidos;
echo "Documento operativo de la continuidad (Módulo 3, Etapa 8): {$pasados}/{$total} comprobaciones correctas.\n";
exit($fallidos > 0 ? 1 : 0);

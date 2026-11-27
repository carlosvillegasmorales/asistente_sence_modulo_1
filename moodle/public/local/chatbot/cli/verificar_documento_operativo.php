<?php
/**
 * Verifica el Documento Operativo de la Experiencia de Aprendizaje (salida de la Etapa 8, M1-v1.31.0):
 * activacion >= 25 puntos, vigencia y versionado, limpieza de cierres no operativos, extraccion integra de
 * instrumentos desde la Etapa 6, sincronizacion en vivo del repertorio del Centro de produccion, marcas [REVISAR],
 * prompt de extraccion (Secciones 1 a 5), aceptacion sin filtrar etapa 9 y armado real de archivo Word (.docx).
 *
 * Uso:  php cli/verificar_documento_operativo.php
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

use local_chatbot\centro_recursos;
use local_chatbot\diseno;
use local_chatbot\documento_operativo;
use local_chatbot\exante;

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

// --- 1. Condicion de activacion (>= 25 puntos) y vigencia/versionado ---
local_chatbot_verificar(
    documento_operativo::can_generate(['total' => 25, 'banda' => ['aprobado' => true, 'texto' => 'Aprobado con ajustes menores']]),
    'habilita el documento operativo con 25 puntos (aprobado)'
);
local_chatbot_verificar(
    documento_operativo::can_generate(['total' => 34, 'banda' => ['aprobado' => true, 'texto' => 'Diseño sólido y listo para implementar']]),
    'habilita el documento operativo con 34 puntos'
);
local_chatbot_verificar(
    !documento_operativo::can_generate(['total' => 24, 'banda' => ['aprobado' => false, 'texto' => 'Requiere ajustes importantes']]),
    'no habilita el documento operativo bajo 25 puntos'
);
local_chatbot_verificar(
    !documento_operativo::can_generate(null),
    'no habilita el documento operativo sin evaluacion vigente'
);

$filaOp = (object) [
    'etapa' => diseno::OPERATIVO,
    'generacion' => exante::LISTO,
    'estado' => diseno::ACEPTADO,
    'huella' => 'abc123',
    'rondas' => 2,
    'contenido' => '<h1>SECCIÓN 1 · Datos generales</h1><p>Contenido.</p>',
];
local_chatbot_verificar(
    documento_operativo::is_current($filaOp, 'abc123'),
    'reconoce documento operativo vigente cuando coincide la huella de consolidacion'
);
local_chatbot_verificar(
    !documento_operativo::is_current($filaOp, 'xyz999'),
    'marca obsoleto el documento operativo si la consolidacion cambio de huella'
);
local_chatbot_verificar(
    documento_operativo::version_number($filaOp) === 2
        && documento_operativo::version_number((object) ['rondas' => 0]) === 1
        && documento_operativo::version_number(null) === 1,
    'calcula el numero de version (1, 2, 3...) desde rondas'
);

// La fila etapa = 9 nunca debe filtrarse en diseno::accepted() ni romper max_seconds().
$filasMix = [
    1 => (object) ['estado' => diseno::ACEPTADO, 'contenido' => 'e1'],
    2 => (object) ['estado' => diseno::ACEPTADO, 'contenido' => 'e2'],
    9 => $filaOp,
];
local_chatbot_verificar(
    diseno::accepted($filasMix) === [1, 2],
    'diseno::accepted() ignora la fila interna etapa = 9 (OPERATIVO)'
);
local_chatbot_verificar(
    diseno::max_seconds(diseno::OPERATIVO) === documento_operativo::MAX_SECONDS,
    'diseno::max_seconds() devuelve el tiempo maximo propio del documento operativo'
);

// --- 2. Limpieza de cierres no operativos y extraccion deterministica de Etapa 6 ---
$etapa6Ejemplo = <<<'HTML'
<h1>Etapa 6: Instrumentos evaluativos</h1>
<h2>Instrumento 1 · IN1: Lista de cotejo de seguridad eléctrica</h2>
<p>Puntaje máximo: 20 puntos · Umbral de aprobación: 15 puntos (75 %).</p>
<table>
  <thead><tr><th>N.º</th><th>Indicador observable</th><th>Sí (2 pts)</th><th>No (0 pts)</th></tr></thead>
  <tbody>
    <tr><td>1</td><td>Verifica ausencia de tensión con multímetro certificado.</td><td>[ ]</td><td>[ ]</td></tr>
    <tr><td>2</td><td>Instala bloqueo y tarjeteado LOTO antes de intervenir.</td><td>[ ]</td><td>[ ]</td></tr>
  </tbody>
</table>
<h2>Registro de ajustes</h2>
<p>Sin ajustes en esta ronda.</p>
<h2>Registro de vacíos de información</h2>
<p>Ninguno.</p>
<h2>Verificación de calidad de la etapa</h2>
<p>Todos los criterios cubiertos.</p>
<h2>Referencias consultadas</h2>
<ul><li>NCh Elec 4/2003</li></ul>
<p><strong>Fuentes web consultadas</strong></p><ul><li>https://ejemplo.cl</li></ul>
HTML;

$anexos = documento_operativo::extract_stage6_instruments($etapa6Ejemplo);
local_chatbot_verificar(
    strpos($anexos, '<h1>ANEXOS · Instrumentos de evaluación</h1>') === 0,
    'extract_stage6_instruments() encabeza con «ANEXOS · Instrumentos de evaluación»'
);
local_chatbot_verificar(
    strpos($anexos, 'Instrumento 1 · IN1: Lista de cotejo de seguridad eléctrica') !== false
        && strpos($anexos, 'Verifica ausencia de tensión con multímetro certificado.') !== false
        && strpos($anexos, 'Umbral de aprobación: 15 puntos (75 %)') !== false,
    'extract_stage6_instruments() conserva integros titulos, items y umbrales de la Etapa 6'
);
local_chatbot_verificar(
    stripos($anexos, 'Etapa 6: Instrumentos') === false
        && stripos($anexos, 'Registro de ajustes') === false
        && stripos($anexos, 'Registro de vacíos') === false
        && stripos($anexos, 'Verificación de calidad') === false
        && stripos($anexos, 'Referencias consultadas') === false
        && stripos($anexos, 'Fuentes web consultadas') === false,
    'extract_stage6_instruments() quita el titulo de etapa y todas las secciones de cierre no operativas'
);

// Si la Etapa 6 traia instrumentos en <h1>, se bajan a <h2> para reservar <h1> al banner ANEXOS.
$anexosH1 = documento_operativo::extract_stage6_instruments('<h1>Instrumento 1: Rúbrica</h1><p>Detalle.</p>');
local_chatbot_verificar(
    strpos($anexosH1, '<h2>Anexo 1 · Instrumento 1: Rúbrica</h2>') !== false
        && substr_count($anexosH1, '<h1>') === 1,
    'extract_stage6_instruments() baja <h1> internos a <h2> dejando un solo <h1> de ANEXOS'
);

// Un anexo por instrumento (propuesta generica del cliente): sin la presentacion ni la matriz de la Etapa 6, y con
// «Anexo N · » en cada titulo de instrumento; los subtitulos internos no se numeran. Caso real del cliente (24-11-2026).
$etapa6Real = '<h2>Etapa 6: Instrumentos evaluativos</h2>'
    . '<h3>1. Presentación y matriz de alineación de instrumentos evaluativos</h3><p>En conformidad con el Plan de Evaluación…</p>'
    . '<h4>Catálogo y matriz de alineación de instrumentos de evaluación</h4><table><tr><td>Inst. 1</td></tr></table>'
    . '<h3>2. Instrumento 1: Prueba Aplicada Basada en Casos</h3><h4>Cuestionario e Instrumento Aplicado</h4><p>Caso 1…</p>'
    . '<h4>Pauta de corrección (Instrumento 1)</h4><p>Alternativa correcta: B.</p>'
    . '<h3>3. Instrumento 2: Lista de Cotejo de Desempeño</h3><p>Indicadores…</p>'
    . '<h3>4. Instrumento 4a: Escala de Coevaluación</h3><p>Escala…</p>'
    . '<h3>Registro de ajustes</h3><p>Sin ajustes.</p>';
$anexosReal = documento_operativo::extract_stage6_instruments($etapa6Real);
local_chatbot_verificar(
    stripos($anexosReal, 'Presentación y matriz') === false && stripos($anexosReal, 'Catálogo y matriz') === false
        && stripos($anexosReal, 'En conformidad con el Plan') === false,
    'extract_stage6_instruments() quita la presentación y la matriz que la Etapa 6 trae antes del primer instrumento'
);
local_chatbot_verificar(
    strpos($anexosReal, '<h3>Anexo 1 · Instrumento 1: Prueba Aplicada Basada en Casos</h3>') !== false
        && strpos($anexosReal, '<h3>Anexo 2 · Instrumento 2: Lista de Cotejo de Desempeño</h3>') !== false
        && strpos($anexosReal, '<h3>Anexo 3 · Instrumento 4a: Escala de Coevaluación</h3>') !== false
        && strpos($anexosReal, '<h4>Pauta de corrección (Instrumento 1)</h4>') !== false
        && strpos($anexosReal, 'Alternativa correcta: B.') !== false
        && stripos($anexosReal, 'Registro de ajustes') === false,
    'extract_stage6_instruments() titula «Anexo N · Instrumento…» cada instrumento, sin numerar subtítulos ni perder contenido'
);
// Caso real (26-11-2026): el cierre de vacios se titulo «4. Detección de vacíos de información y medidas…» y quedaba pegado al
// ultimo anexo.
$anexosDeteccion = documento_operativo::extract_stage6_instruments('<h2>Etapa 6: Instrumentos evaluativos</h2>'
    . '<h3>3. Instrumentos evaluativos redactados</h3><h4>3.1. Instrumento 1: Rúbrica</h4><p>Rúbrica.</p>'
    . '<h3>4. Detección de vacíos de información y medidas de aseguramiento de calidad</h3><p>Vacío detectado.</p>');
local_chatbot_verificar(strpos($anexosDeteccion, 'Anexo 1 · Instrumento 1: Rúbrica') !== false
    && stripos($anexosDeteccion, 'Detección de vacíos') === false && strpos($anexosDeteccion, 'Vacío detectado.') === false,
    'extract_stage6_instruments() quita también el cierre «Detección de vacíos de información…»');
local_chatbot_verificar(documento_operativo::annex_per_instrument('<h3>Rúbrica final</h3><p>x</p>') === '<h3>Rúbrica final</h3><p>x</p>',
    'annex_per_instrument() no toca una Etapa 6 sin títulos «Instrumento N»');
$anexosCompuestos = documento_operativo::annex_per_instrument(
    '<h3>6.1 Matriz de alineación</h3><p>Previo.</p><h3>6.2 Instrumento 1: Rúbrica</h3><p>A</p><h3>6.3. Instrumento 2: Lista</h3><p>B</p>'
);
local_chatbot_verificar(
    stripos($anexosCompuestos, 'Matriz de alineación') === false
        && strpos($anexosCompuestos, '<h3>Anexo 1 · Instrumento 1: Rúbrica</h3>') !== false
        && strpos($anexosCompuestos, '<h3>Anexo 2 · Instrumento 2: Lista</h3>') !== false,
    'annex_per_instrument() reconoce numeración compuesta («6.2 Instrumento 1», «6.3. Instrumento 2»)'
);

// --- 3. Mapa de repertorio y sincronizacion en vivo de 4.1 / 4.2 ---
$recursosDemo = [
    (object) ['codigo' => 'M1', 'estado' => centro_recursos::MAT_ENTREGADO],
    (object) ['codigo' => 'M2-A', 'estado' => centro_recursos::MAT_ENTREGADO],
    (object) ['codigo' => 'M2-B', 'estado' => centro_recursos::MAT_PENDIENTE],
    (object) ['codigo' => 'M3', 'estado' => centro_recursos::MAT_PENDIENTE],
    (object) ['codigo' => 'M9 (ant.)', 'estado' => centro_recursos::MAT_ENTREGADO],
];
$mapa = documento_operativo::repertoire_status_map($recursosDemo);
local_chatbot_verificar(
    ($mapa['M1'] ?? '') === 'Disponible en el repertorio'
        && ($mapa['M2'] ?? '') === 'Parcialmente disponible en el repertorio'
        && ($mapa['M3'] ?? '') === 'Pendiente'
        && !isset($mapa['M9 (ANT.)']),
    'repertoire_status_map() traduce estados de materiales simples y mixtos e ignora (ant.)'
);

$seccionesHtml = <<<'HTML'
<h1>SECCIÓN 1 · Datos generales</h1>
<table>
  <thead><tr><th>Campo</th><th>Información</th></tr></thead>
  <tbody>
    <tr><td>Organismo ejecutor</td><td>OTEC Capacita Norte</td></tr>
    <tr><td>Fechas y horario</td><td></td></tr>
  </tbody>
</table>
<p><em>Origen: Ficha validada y Etapa 2.</em></p>
<h1>SECCIÓN 4 · Materiales y recursos</h1>
<h2>4.1 Materiales instruccionales</h2>
<table>
  <thead><tr><th>Código</th><th>Material</th><th>Fase y actividad</th><th>Formato de salida</th><th>Estado</th><th>Listo</th></tr></thead>
  <tbody>
    <tr><td>M1</td><td>Guía paso a paso LOTO</td><td>Fase 2 · Act. 2</td><td>PDF A4</td><td>Pendiente</td><td></td></tr>
    <tr><td>M2</td><td>Caso práctico y planilla</td><td>Fase 2 · Act. 3</td><td>Word / Excel</td><td>Pendiente</td><td>[ ]</td></tr>
    <tr><td>M3</td><td>Afiche de señalética</td><td>Fase 1 · Act. 1</td><td>PDF A3</td><td>Disponible en el repertorio</td><td></td></tr>
  </tbody>
</table>
<h2>4.2 Recursos del entorno formativo (organismo ejecutor)</h2>
<table>
  <thead><tr><th>Recurso</th><th>Cantidad</th><th>Fase y actividad</th><th>Verificado</th></tr></thead>
  <tbody>
    <tr><td>Multímetro digital certificado</td><td>5 unidades</td><td>Fase 2 · Act. 2</td><td></td></tr>
  </tbody>
</table>
<h1>SECCIÓN 5 · Plan de evaluación sintético</h1>
<p>Control de tiempos [REVISAR: diferencia entre etapas — Etapa 3 indica 45 min y Etapa 5 indica 40 min].</p>
<h1>ANEXOS · Duplicado del modelo</h1>
<p>Esto no deberia quedar porque los anexos salen de Etapa 6.</p>
HTML;

$ensamblado = documento_operativo::assemble_html($seccionesHtml, $etapa6Ejemplo, $mapa);
local_chatbot_verificar(
    strpos($ensamblado, 'Origen: Ficha validada') === false,
    'clean_operative_html() elimina notas editoriales «Origen: ...»'
);
local_chatbot_verificar(
    strpos($ensamblado, 'Duplicado del modelo') === false
        && strpos($ensamblado, '<h1>ANEXOS · Instrumentos de evaluación</h1>') !== false,
    'assemble_html() reemplaza cualquier anexo generado por el modelo con la extraccion integra de Etapa 6'
);
local_chatbot_verificar(
    preg_match('#<td>M1</td>.*?<td>Disponible en el repertorio</td><td>☐</td>#su', $ensamblado) === 1
        && preg_match('#<td>M2</td>.*?<td>Parcialmente disponible en el repertorio</td><td>☐</td>#su', $ensamblado) === 1
        && preg_match('#<td>M3</td>.*?<td>Pendiente</td><td>☐</td>#su', $ensamblado) === 1
        && preg_match('#<td>Multímetro digital certificado</td>.*?<td>☐</td>#su', $ensamblado) === 1,
    'sync_repertoire_status() actualiza en vivo la columna Estado (4.1) y asegura casillas ☐ en Listo (4.1) y Verificado (4.2)'
);
local_chatbot_verificar(
    documento_operativo::review_marks_count($ensamblado) === 1,
    'review_marks_count() contabiliza las marcas [REVISAR: ...] preservadas'
);

// --- 4. Prompt de extraccion (Secciones 1 a 5) ---
$fichaDemo = exante::clean([
    'organismo' => 'OTEC Capacita Norte',
    'plan' => 'PF-2026 Instalaciones Eléctricas Domiciliarias',
    'modulo' => 'Módulo 2: Seguridad y maniobras de bloqueo',
    'nivel' => 'Nivel 3',
    'participantes' => '15 personas',
    'tiempo_total' => '360',
    'tiempo_experiencia' => '240',
    'aprendizaje' => 'Aplicar procedimientos de bloqueo y etiquetado LOTO en tableros de baja tensión según normativa vigente.',
    'criterio1' => 'Identifica energías peligrosas antes de intervenir un tablero.',
    'criterio2' => 'Ejecuta el procedimiento de bloqueo y verificación de tensión cero.',
    'criterio3' => 'Registra el cierre seguro de la maniobra en bitácora.',
]);
$evalDemo = [
    'total' => 33,
    'banda' => ['aprobado' => true, 'clave' => 'solido', 'texto' => 'Diseño sólido y listo para implementar'],
    'fecha' => 1764000000,
];
$prompt = documento_operativo::build_prompt(
    $fichaDemo,
    [
        1 => '<h1>Etapa 1: Análisis diagnóstico</h1><p>NO DEBE ENTRAR AL PROMPT.</p>',
        2 => '<h1>Etapa 2: Diseño</h1><p>Experiencia: Bloqueo seguro en tableros.</p>',
        3 => '<h1>Etapa 3: Secuencia</h1><p>Fase 1 Inicio (60 min).</p>',
        4 => '<h1>Etapa 4: Materiales</h1><p>M1 Guía LOTO.</p>',
        5 => '<h1>Etapa 5: Evaluación</h1><p>IN1 Lista de cotejo.</p>',
        6 => $etapa6Ejemplo,
    ],
    $evalDemo,
    '24 de noviembre de 2026',
    1,
    $mapa
);
local_chatbot_verificar(
    strpos($prompt, '# SECCIÓN 1 · Datos generales') !== false
        && strpos($prompt, '# SECCIÓN 2 · Aprendizaje esperado y criterios de evaluación') !== false
        && strpos($prompt, '# SECCIÓN 3 · Secuencia instruccional') !== false
        && strpos($prompt, '## 3.2 Distribución por jornada (a completar por la persona facilitadora)') !== false
        && strpos($prompt, '# SECCIÓN 4 · Materiales y recursos') !== false
        && strpos($prompt, '# SECCIÓN 5 · Plan de evaluación sintético') !== false
        && strpos($prompt, '## 5.3 Equivalencia con los niveles de la ficha de registro') !== false
        && strpos($prompt, 'Uso en el Módulo 3: con esta tabla la persona facilitadora asigna el nivel') !== false,
    'build_prompt() incluye las 5 secciones exactas, campos en blanco y nota de enlace al Modulo 3'
);
local_chatbot_verificar(
    strpos($prompt, 'Aplicar procedimientos de bloqueo y etiquetado LOTO en tableros de baja tensión según normativa vigente.') !== false
        && strpos($prompt, 'Identifica energías peligrosas antes de intervenir un tablero.') !== false
        && strpos($prompt, 'M1: Disponible en el repertorio') !== false
        && strpos($prompt, 'NO DEBE ENTRAR AL PROMPT') === false
        && strpos($prompt, '[REVISAR: diferencia entre etapas') !== false,
    'build_prompt() transcribe AE y criterios de la ficha, incluye estados del repertorio, omite Etapa 1 y exige marca [REVISAR]'
);

// Guion breve (propuesta generica: «acción breve», tarea por código), para no pasar de 12 paginas: en el caso del cliente
// las secciones 1 a 5 ocuparon 13 paginas, 6 de ellas en el guion (24-11-2026).
local_chatbot_verificar(
    strpos($prompt, 'va solo el código de la tarea') !== false
        && strpos($prompt, 'máximo 15 palabras') !== false
        && strpos($prompt, 'No omitas tareas ni cambies minutos para acortar') !== false,
    'build_prompt() pide el guion con código de tarea y acciones breves, sin omitir tareas ni cambiar minutos'
);
// --- 5. Generacion real del archivo Word (.docx) editable ---
$meta = documento_operativo::word_meta(
    $fichaDemo,
    'Bloqueo seguro en tableros de baja tensión',
    $evalDemo,
    '24 de noviembre de 2026',
    2
);
local_chatbot_verificar(
    ($meta['estilo'] ?? '') === 'operativo'
        && ($meta['portada'] ?? true) === false
        && ($meta['etiqueta'] ?? '') === 'DOCUMENTO OPERATIVO · EXPERIENCIA DE APRENDIZAJE'
        && strpos((string) ($meta['subtitulo'] ?? ''), '33 / 36') !== false
        && strpos((string) ($meta['subtitulo'] ?? ''), 'versión 2') !== false,
    'word_meta() construye la cabecera y metadatos exactos de la especificacion'
);

$ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'local_chatbot_operativo_test_' . uniqid('', true) . '.docx';
$logo = __DIR__ . '/../pix/logo_interfases.png';
$okDocx = documento_operativo::build_docx($ruta, $meta, $ensamblado, is_readable($logo) ? $logo : '');
local_chatbot_verificar($okDocx && is_file($ruta) && filesize($ruta) > 2000, 'build_docx() escribe un archivo .docx valido');

if (is_file($ruta)) {
    $zip = new \ZipArchive();
    $abierto = $zip->open($ruta) === true;
    $docXml = $abierto ? (string) $zip->getFromName('word/document.xml') : '';
    if ($abierto) {
        $zip->close();
    }
    @unlink($ruta);

    $dom = new \DOMDocument();
    $xmlValido = $docXml !== '' && @$dom->loadXML($docXml);
    local_chatbot_verificar($xmlValido, 'word/document.xml del documento operativo es XML bien formado');
    local_chatbot_verificar(
        strpos($docXml, 'DOCUMENTO OPERATIVO · EXPERIENCIA DE APRENDIZAJE') !== false
            && strpos($docXml, 'Bloqueo seguro en tableros de baja tensión') !== false
            && strpos($docXml, 'versión 2') !== false,
        'el Word operativo lleva la cabecera institucional con titulo, puntaje y version'
    );
    local_chatbot_verificar(
        strpos($docXml, 'SECCIÓN 1') !== false
            && strpos($docXml, 'SECCIÓN 4') !== false
            && strpos($docXml, 'SECCIÓN 5') !== false
            && strpos($docXml, 'ANEXOS') !== false
            && strpos($docXml, 'Instrumentos de evaluación') !== false,
        'el Word operativo renderiza las bandas de SECCIÓN y ANEXOS'
    );
    local_chatbot_verificar(
        strpos($docXml, '<w:pageBreakBefore/>') !== false
            && strpos($docXml, 'FEF3C7') !== false
            && strpos($docXml, '☐') !== false,
        'el Word operativo fuerza salto de pagina en ANEXOS, resalta [REVISAR] en ambar (FEF3C7) y muestra casillas ☐'
    );
}

$pasados = $total - $fallidos;
echo "Documento operativo (Etapa 8): {$pasados}/{$total} comprobaciones correctas.\n";
exit($fallidos > 0 ? 1 : 0);


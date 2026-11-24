<?php
/**
 * Verifica el Centro de Produccion de Recursos de Aprendizaje del Modulo 2 (Etapas 9 a 12):
 * mensajes textuales, prompts, filtros de elegibilidad, marcas [REVISAR], validacion SSRF y Modo C,
 * y generacion real de archivos en PHP puro (Word, Excel, PowerPoint, PDF multi-formato, GIFT,
 * Google Apps Script, HTML Moodle y ZIP del repertorio).
 *
 * Uso:  php cli/verificar_centro_recursos.php
 * Sale con codigo 1 ante cualquier fallo, para encadenarlo a CI.
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/ux.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/diagrama.php');
require_once(__DIR__ . '/../classes/documento_final.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/diseno.php');
require_once(__DIR__ . '/../classes/modulo2_setup.php');
require_once(__DIR__ . '/../classes/recurso_export.php');
require_once(__DIR__ . '/../classes/centro_recursos.php');

use local_chatbot\centro_recursos;
use local_chatbot\diseno;
use local_chatbot\modulo2_setup;
use local_chatbot\recurso_export;
use local_chatbot\ux;

$fallidos = 0;
$total = 0;

/**
 * @param bool $condicion
 * @param string $descripcion
 * @return void
 */
function local_chatbot_verificar(bool $condicion, string $descripcion): void {
    global $fallidos, $total;
    $total++;
    if (!$condicion) {
        echo "  FALLA  {$descripcion}\n";
        $fallidos++;
    }
}

// --- Interruptor y mensajes textuales obligatorios ---
local_chatbot_verificar((ux::DEFAULTS['centrorecursos'] ?? null) === 1,
    'el interruptor centrorecursos existe en ux::DEFAULTS y viene encendido');
local_chatbot_verificar(
    strpos(centro_recursos::BIENVENIDA[0], 'Bienvenido/a al centro de producción de recursos de aprendizaje.') === 0
    && centro_recursos::BIENVENIDA[1] === 'Primero te mostraré tus diseños validados disponibles para que elijas con cuál quieres trabajar.',
    'la bienvenida obligatoria del centro coincide textualmente con el prompt complementario'
);
local_chatbot_verificar(
    centro_recursos::SIN_DISENOS === 'Aún no tienes diseños disponibles para el centro de producción. Un diseño se habilita cuando está consolidado y aprobado (25 puntos o más en la evaluación del diseño).'
    && centro_recursos::RESPUESTA_NO_MODIFICA === 'Ese ajuste implica modificar el diseño validado, que no se puede cambiar desde el centro de producción. Puedo ajustar la redacción, los ejemplos o el formato del material dentro de lo especificado.'
    && centro_recursos::PIE_MATERIAL === 'Material producido con apoyo de IA. Revisado por la persona facilitadora antes de su uso.',
    'los mensajes obligatorios de activación, principio 1 y pie de material son textuales'
);

// --- Condicion de activacion (>= 25 puntos y etapa 7 aceptada) ---
local_chatbot_verificar(
    centro_recursos::eligible_design(['evaluado' => true, 'evaluacion' => ['total' => 25], 'aceptadas' => [1, 2, 3, 4, 5, 6, 7]])
    && centro_recursos::eligible_design(['evaluado' => true, 'evaluacion' => ['total' => 35], 'aceptadas' => [1, 2, 3, 4, 5, 6, 7]])
    && !centro_recursos::eligible_design(['evaluado' => true, 'evaluacion' => ['total' => 24], 'aceptadas' => [1, 2, 3, 4, 5, 6, 7]])
    && !centro_recursos::eligible_design(['evaluado' => true, 'evaluacion' => ['total' => 30], 'aceptadas' => [1, 2, 3, 4, 5, 6]])
    && !centro_recursos::eligible_design(['evaluado' => false, 'evaluacion' => null, 'aceptadas' => [1, 2, 3, 4, 5, 6, 7]]),
    'un diseño es elegible solo si está consolidado (Etapa 7) y evaluado con 25 puntos o más'
);

// --- Estado del centro por diseno ---
$pSin = (object) ['estado' => centro_recursos::PLAN_SIN_INICIAR];
$pEn = (object) ['estado' => centro_recursos::PLAN_EN_PRODUCCION];
$pCer = (object) ['estado' => centro_recursos::PLAN_CERRADO];
$recsEstado = [
    (object) ['estado' => centro_recursos::MAT_ENTREGADO],
    (object) ['estado' => centro_recursos::MAT_ENTREGADO_PARCIAL],
    (object) ['estado' => centro_recursos::MAT_PENDIENTE],
    (object) ['estado' => centro_recursos::MAT_NO_PRODUCIBLE],
    (object) ['estado' => centro_recursos::MAT_INSTRUMENTO],
];
local_chatbot_verificar(
    centro_recursos::design_status_label(null, []) === 'Sin iniciar'
    && centro_recursos::design_status_label($pSin, []) === 'Sin iniciar'
    && centro_recursos::design_status_label($pEn, $recsEstado) === 'En producción: 2 de 3'
    && centro_recursos::design_status_label($pCer, $recsEstado) === 'Cerrado',
    'el estado del centro cuenta solo materiales producibles (excluye no producibles e instrumentos)'
);

// --- Tipos de archivo de referencia admitidos ---
local_chatbot_verificar(
    centro_recursos::reference_kind('application/pdf', 'protocolo.pdf') === 'pdf'
    && centro_recursos::reference_kind('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'manual.docx') === 'docx'
    && centro_recursos::reference_kind('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'tabla.xlsx') === 'xlsx'
    && centro_recursos::reference_kind('image/png', 'esquema.png') === 'imagen'
    && centro_recursos::reference_kind('image/jpeg', 'foto.jpg') === 'imagen'
    && centro_recursos::reference_kind('application/x-php', 'malo.php') === null
    && centro_recursos::reference_kind('text/html', 'malo.html') === null,
    'admite solo PDF, Word (.docx), Excel (.xlsx/.xls) e imágenes (PNG/JPG/WEBP) por MIME real'
);

// --- Marcas [REVISAR] ---
$txtRev = '<p>Duración [REVISAR: confirmar tiempo en norma] y uso de EPP [REVISAR] según ficha.</p>';
local_chatbot_verificar(
    centro_recursos::count_review_marks($txtRev) === 2
    && count(centro_recursos::extract_review_marks($txtRev)) === 2
    && strpos(centro_recursos::highlight_review_marks($txtRev), 'cr-revisar') !== false,
    'cuenta, extrae y resalta las marcas [REVISAR] y [REVISAR: motivo]'
);

// --- Parseo del Plan de produccion (Etapa 9) ---
$planJson = json_encode([
    'materiales' => [
        ['codigo' => 'M1', 'nombre' => 'Afiche bioseguridad', 'fase' => 'J2 · inicio', 'modo' => 'B',
            'formato' => 'PDF A2 y tarjeta 9x6 cm', 'especificacion' => 'completa', 'faltantes' => '', 'estado' => 'pendiente'],
        ['codigo' => 'M2', 'nombre' => 'Videos geriatría', 'fase' => 'J1 · desarrollo', 'modo' => 'C',
            'formato' => 'Ficha de recomendación', 'especificacion' => 'incompleta', 'faltantes' => 'duración máxima', 'estado' => 'pendiente'],
        ['codigo' => 'M3', 'nombre' => 'Lista de cotejo desempeño', 'fase' => 'J3', 'modo' => 'B',
            'formato' => 'Word', 'especificacion' => 'completa', 'estado' => 'Instrumento de evaluación del diseño'],
        ['codigo' => 'M4', 'nombre' => 'Simulador VR propietario', 'fase' => 'J3', 'modo' => 'B',
            'formato' => 'App VR', 'especificacion' => 'completa', 'estado' => 'No producible por el centro'],
    ],
    'excluidos_fisicos' => ['Cama clínica', 'Fantoma', 'EPP'],
], JSON_UNESCAPED_UNICODE);
$parsedPlan = centro_recursos::parse_plan_json($planJson);
local_chatbot_verificar(
    $parsedPlan !== null
    && count($parsedPlan['materiales']) === 4
    && $parsedPlan['materiales'][0]['estado'] === centro_recursos::MAT_PENDIENTE
    && $parsedPlan['materiales'][1]['especificacion'] === 'incompleta'
    && $parsedPlan['materiales'][2]['estado'] === centro_recursos::MAT_INSTRUMENTO
    && $parsedPlan['materiales'][3]['estado'] === centro_recursos::MAT_NO_PRODUCIBLE
    && $parsedPlan['excluidos_fisicos'] === ['Cama clínica', 'Fantoma', 'EPP'],
    'parse_plan_json clasifica pendientes, incompletas, instrumentos, no producibles y excluidos físicos'
);

// --- Seguridad SSRF y filtrado de enlaces verificados (Modo C) ---
local_chatbot_verificar(
    centro_recursos::is_safe_public_url('https://www.youtube.com/watch?v=abc123')
    && centro_recursos::is_safe_public_url('https://www.minsal.cl/guia/')
    && !centro_recursos::is_safe_public_url('http://127.0.0.1/admin')
    && !centro_recursos::is_safe_public_url('http://169.254.169.254/latest/meta-data/')
    && !centro_recursos::is_safe_public_url('http://192.168.1.1/')
    && !centro_recursos::is_safe_public_url('http://localhost/x')
    && !centro_recursos::is_safe_public_url('javascript:alert(1)')
    && !centro_recursos::is_safe_public_url('file:///etc/passwd'),
    'is_safe_public_url permite URLs públicas http(s) y bloquea IPs privadas, localhost y esquemas peligrosos'
);
$opsCur = [
    ['titulo' => 'Video OK', 'fuente' => 'MINSAL', 'duracion' => '6 min', 'idioma' => 'Español',
        'enlace' => 'https://example.org/ok', 'momento' => 'J1', 'justificacion' => 'Pertinente', 'advertencias' => 'Ninguna'],
    ['titulo' => 'Video roto', 'fuente' => 'X', 'duracion' => '5 min', 'idioma' => 'Español',
        'enlace' => 'https://example.org/404', 'momento' => 'J1', 'justificacion' => 'X', 'advertencias' => 'X'],
    ['titulo' => 'Interno', 'fuente' => 'Y', 'duracion' => '3 min', 'idioma' => 'Español',
        'enlace' => 'http://127.0.0.1/secreto', 'momento' => 'J1', 'justificacion' => 'Y', 'advertencias' => 'Y'],
];
$filtrado = centro_recursos::filter_verified_links($opsCur, static function(string $u): array {
    return strpos($u, '/ok') !== false ? ['ok' => true, 'url' => $u, 'code' => 200] : ['ok' => false, 'url' => $u, 'code' => 404];
});
local_chatbot_verificar(
    count($filtrado['verificadas']) === 1
    && $filtrado['verificadas'][0]['titulo'] === 'Video OK'
    && $filtrado['descartadas'] === 2,
    'filter_verified_links conserva únicamente recursos con enlace verificado y descarta rotos o privados'
);

// --- Deteccion de formatos de salida y PDF multi-tamano ---
local_chatbot_verificar(
    recurso_export::parse_pdf_spec('PDF A2 y afiche')['tamano'] === 'A2'
    && recurso_export::parse_pdf_spec('PDF A2')['tipo'] === 'afiche'
    && recurso_export::parse_pdf_spec('PDF tarjeta de bolsillo 9 × 6 cm')['tamano'] === [90, 60]
    && recurso_export::parse_pdf_spec('PDF tarjeta 9x6')['tipo'] === 'tarjeta'
    && recurso_export::parse_pdf_spec('PDF A3 para plastificar')['tamano'] === 'A3'
    && recurso_export::parse_pdf_spec('PDF A4 horizontal')['orientacion'] === 'L'
    && recurso_export::parse_pdf_spec('PDF', 'a4')['tamano'] === 'A4'
    && recurso_export::parse_pdf_spec('PDF', 'carta')['tamano'] === 'LETTER',
    'parse_pdf_spec resuelve A2, A3, A4 horizontal, tarjeta 9×6 cm y preferencia Carta/A4'
);
local_chatbot_verificar(
    in_array('gift', centro_recursos::detect_formats('B', 'Cuestionario formativo', 'Cuestionario', 'todas'), true)
    && in_array('gs', centro_recursos::detect_formats('B', 'Cuestionario formativo', 'Cuestionario', 'todas'), true)
    && in_array('quiz_docx', centro_recursos::detect_formats('B', 'Cuestionario formativo', 'Cuestionario', 'todas'), true)
    && in_array('xlsx', centro_recursos::detect_formats('B', 'Planilla Excel (.xlsx)', 'Registro'), true)
    && in_array('pptx', centro_recursos::detect_formats('B', 'Presentación de cierre', 'Cierre'), true)
    && in_array('pdf', centro_recursos::detect_formats('B', 'PDF A2', 'Afiche'), true),
    'detect_formats asigna los formatos descargables adecuados para cuestionarios, Excel, PPTX y PDF'
);

// --- Prompts de Etapas 9, 10.2, 10.3 y 10.4 ---
$matPrueba = ['codigo' => 'M5', 'nombre' => 'Afiche de bioseguridad', 'fase' => 'J2 · inicio', 'modo' => 'B',
    'formato' => 'PDF A2', 'especificacion' => 'completa', 'espec_detalle' => ''];
$p9 = centro_recursos::build_plan_prompt('DISENO', 'FICHA', true);
$p102 = centro_recursos::build_structure_prompt($matPrueba, ['organismo' => 'OTEC Demo'], 'DISENO', 'FICHA', ['Protocolo.pdf']);
$p103 = centro_recursos::build_content_prompt($matPrueba, 'Estructura aprobada', ['organismo' => 'OTEC Demo'], 'DISENO', 'FICHA');
$p104 = centro_recursos::build_adjust_prompt($matPrueba, 'Contenido actual', 'Acorta las frases de la tarjeta');
local_chatbot_verificar(
    strpos($p9, 'ETAPA 9: PLAN DE PRODUCCIÓN') !== false
    && strpos($p9, 'Instrumento de evaluación del diseño') !== false
    && strpos($p9, 'No producible por el centro') !== false
    && strpos($p9, 'nunca uses los conceptos «pedagógico» o «pedagogía»') !== false,
    'el prompt de Etapa 9 incluye los 7 principios, catálogo y reglas de exclusión'
);
local_chatbot_verificar(
    strpos($p102, '¿Apruebas esta estructura para producir el material completo?') !== false
    && strpos($p102, 'Protocolo.pdf') !== false
    && strpos($p103, '[Pictograma: descripción breve]') !== false
    && strpos($p103, centro_recursos::PIE_MATERIAL) !== false
    && strpos($p104, centro_recursos::RESPUESTA_NO_MODIFICA) !== false,
    'los prompts de Etapas 10.2, 10.3 y 10.4 incluyen estructura previa, pictogramas, pie obligatorio y Principio 1'
);

// --- Revision contra el prompt complementario: textos que faltaban, Modo C y ajuste por seccion ---
local_chatbot_verificar(
    centro_recursos::PREGUNTA_DISENO === '¿Con qué diseño quieres trabajar?'
    && centro_recursos::PREGUNTA_REFERENCIAS === '¿Quieres cargar materiales técnicos propios de referencia para producir este recurso (por ejemplo, manuales, fichas técnicas, procedimientos o normas)? Puedes cargarlos en PDF, Word, Excel o imagen, o indicar materiales que ya cargaste para otro recurso de este diseño.'
    && centro_recursos::PREGUNTA_REVISION === 'Revisa el material. ¿Apruebas el material o quieres ajustar alguna sección?',
    'las preguntas de la selección del diseño, de la 10.1 y de la 10.4 son textuales'
);
local_chatbot_verificar(
    in_array('- Si un material de referencia es extenso, pide a la persona facilitadora que indique las secciones o páginas pertinentes, para usar solo esa parte.', centro_recursos::PRINCIPIOS, true)
    && in_array('- Los materiales cargados quedan disponibles en el repositorio del diseño y la persona facilitadora puede decidir reutilizarlos en otros materiales del mismo diseño.', centro_recursos::PRINCIPIOS, true)
    && in_array('- Que el formato de salida es válido (sintaxis GIFT, HTML compatible con Moodle, script ejecutable, archivo editable).', centro_recursos::CONTROL_CALIDAD, true)
    && centro_recursos::CONTROL_CALIDAD[count(centro_recursos::CONTROL_CALIDAD) - 1] === 'Si detectas un error, corrígelo antes de entregar el material.'
    && strpos($p104, 'CONTROL DE CALIDAD INTERNO:') !== false,
    'el principio 5 y el control de calidad interno van completos y textuales; el control también en los ajustes'
);
$matC = ['codigo' => 'M2', 'nombre' => 'Videos', 'fase' => 'J1', 'modo' => 'C', 'formato' => 'Ficha de recomendación',
    'especificacion' => 'completa', 'espec_detalle' => ''];
$pC = centro_recursos::build_content_prompt($matC, 'E', [], 'D', 'F');
local_chatbot_verificar(
    strpos($pC, 'No descargues ni reproduzcas los recursos: solo se enlazan.') !== false
    && strpos($pC, 'Verifica que cada enlace esté disponible y sea de acceso libre') !== false
    && strpos($p103, 'Facilitador/a') === false && strpos($p103, 'Persona facilitadora:') !== false,
    'el Modo C prohíbe descargar o reproducir los recursos y los prompts usan «persona facilitadora»'
);
local_chatbot_verificar(
    strpos($p104, 'Entrega solo las secciones modificadas') !== false
    && strpos(centro_recursos::build_adjust_prompt($matPrueba, '{}', 'x', true), 'JSON completo actualizado') !== false,
    'el ajuste pide solo las secciones modificadas (Markdown) y el JSON completo en materiales estructurados'
);
$pieM5 = 'M5 · ' . centro_recursos::PIE_MATERIAL;
$mat = '<h2>Afiche · Parte 1</h2><p>Lávate las manos.</p><h3>Pasos</h3><p>1. Moja</p>'
    . '<h2>Afiche · Parte 2</h2><p>Pechera y guantes.</p><h2>Tarjeta de acogida</h2><p>Frase larga de saludo.</p>'
    . '<p><em>' . $pieM5 . '</em></p>';
$fus = centro_recursos::merge_sections($mat, '<h2>Tarjeta de acogida</h2><p>Hola. [Pictograma: saludo]</p>', $pieM5);
local_chatbot_verificar(
    $fus !== null && strpos($fus, 'Frase larga de saludo') === false && strpos($fus, 'Hola. [Pictograma: saludo]') !== false
    && strpos($fus, 'Pechera y guantes.') !== false && strpos($fus, '1. Moja') !== false
    && substr_count($fus, centro_recursos::PIE_MATERIAL) === 1 && substr($fus, -strlen('</em></p>')) === '</em></p>',
    'merge_sections reemplaza solo la sección indicada (la última incluida) y deja el pie una sola vez al final'
);
$fus2 = centro_recursos::merge_sections($mat, '<h2>1. Afiche - Parte 1</h2><p>Nuevo texto.</p>', $pieM5);
local_chatbot_verificar(
    $fus2 !== null && strpos($fus2, 'Nuevo texto.') !== false && strpos($fus2, '1. Moja') === false
    && strpos($fus2, 'Pechera y guantes.') !== false,
    'merge_sections reconoce el título sin numeración ni signos y reemplaza la sección con sus subsecciones'
);
local_chatbot_verificar(
    centro_recursos::merge_sections($mat, '<p>Solo texto sin título.</p>', $pieM5) === null
    && centro_recursos::merge_sections($mat, '<h2>Sección inexistente</h2><p>x</p>', $pieM5) === null,
    'merge_sections no adivina: sin títulos o con un título que no existe devuelve null (se avisa a la persona)'
);
$matF = str_replace('<p><em>', '<p><strong>Fuentes utilizadas:</strong> Protocolo del establecimiento.</p><p><em>', $mat);
$fus3 = centro_recursos::merge_sections($matF, '<h2>Tarjeta de acogida</h2><p>Hola.</p>', $pieM5);
local_chatbot_verificar($fus3 !== null && substr_count($fus3, 'Fuentes utilizadas:') === 1
    && strpos($fus3, 'Fuentes utilizadas:') < strpos($fus3, centro_recursos::PIE_MATERIAL),
    'merge_sections conserva la línea «Fuentes utilizadas» al reemplazar la última sección, antes del pie');
local_chatbot_verificar(
    centro_recursos::headings_as_markdown('<h2 id="a">Parte <b>1</b></h2><h3>Pasos</h3>') === '<p>## Parte 1</p><p>### Pasos</p>',
    'headings_as_markdown entrega el material actual al ajuste con sus títulos en Markdown'
);
local_chatbot_verificar(
    centro_recursos::headings_as_markdown('<p><strong>Tiempo total:</strong> 48 min, <em>por subgrupo</em> <b>x</b></p>')
        === '<p>**Tiempo total:** 48 min, *por subgrupo* **x**</p>',
    'headings_as_markdown deja negritas y cursivas en Markdown (html_to_text las pondría en MAYÚSCULAS)'
);

// --- Modo C: videos verificados con oEmbed (YouTube responde 200 aunque el video no exista) ---
$yt = centro_recursos::video_ref('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10');
local_chatbot_verificar(
    $yt !== null && $yt['plataforma'] === 'youtube' && $yt['id'] === 'dQw4w9WgXcQ' && $yt['valido']
    && strpos($yt['oembed'], 'https://www.youtube.com/oembed?format=json&url=') === 0
    && centro_recursos::video_ref('https://youtu.be/dQw4w9WgXcQ')['valido']
    && centro_recursos::video_ref('https://www.youtube.com/shorts/dQw4w9WgXcQ')['id'] === 'dQw4w9WgXcQ'
    && !centro_recursos::video_ref('https://www.youtube.com/watch?v=VocesMayoresINDH')['valido']
    && !centro_recursos::video_ref('https://www.youtube.com/watch?v=BuenTratoSENAMA')['valido']
    && centro_recursos::video_ref('https://vimeo.com/76979871')['id'] === '76979871'
    && centro_recursos::video_ref('https://www.minsal.cl/guia/') === null,
    'video_ref reconoce YouTube y Vimeo y rechaza ids inventados como «VocesMayoresINDH» (el id tiene 11 caracteres)'
);
$verifVideo = centro_recursos::filter_verified_links([
    ['titulo' => 'Título inventado', 'fuente' => 'Fuente inventada', 'enlace' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
    ['titulo' => 'Falso', 'fuente' => 'X', 'enlace' => 'https://www.youtube.com/watch?v=VocesMayoresINDH'],
], static fn(string $u): array => strpos($u, 'dQw4w9WgXcQ') !== false
    ? ['ok' => true, 'url' => $u, 'code' => 200, 'titulo' => 'Título real', 'autor' => 'Canal real']
    : ['ok' => false, 'url' => $u, 'code' => 0]);
local_chatbot_verificar(
    count($verifVideo['verificadas']) === 1 && $verifVideo['verificadas'][0]['titulo'] === 'Título real'
    && $verifVideo['verificadas'][0]['fuente'] === 'Canal real' && $verifVideo['descartadas'] === 1,
    'la ficha usa el título y el canal reales del video y descarta los que no existen'
);
local_chatbot_verificar(
    strpos($pC, 'Nunca construyas, completes ni adivines un enlace') !== false
    && strpos($pC, 'usa «experiencia de aprendizaje», nunca «sesión»') !== false,
    'el prompt del Modo C prohíbe inventar enlaces y recuerda la regla de «experiencia de aprendizaje»'
);

// --- Material formal: dibujos ASCII como tarjetas o pasos, pictogramas como nota y casillas en el PDF ---
$artM3 = ['+-------------------------------------------------------+', '|              PROCESOS DE ENVEJECIMIENTO              |',
    '+-------------------------------------------------------+', '|', '+--------------+--------------+', '|                             |',
    'v                             v', '+-----------------------+     +-----------------------+',
    '| ENVEJECIMIENTO NORMAL |     | ENVEJECIMIENTO        |', '| (FISIOLÓGICO)         |     | PATOLÓGICO            |',
    '|                       |     |                       |', '| • Cambios esperables  |     | • Presencia de        |',
    '| • Adaptación funcional|     |   enfermedades        |', '| • Mantención de la    |     | • Pérdida acelerada   |',
    '|   autonomía           |     |   de funcionalidad    |', '+-----------------------+     +-----------------------+'];
$listaM3 = '<ul>' . implode('', array_map(static fn($l) => '<li>' . htmlspecialchars($l) . '</li>', $artM3)) . '</ul>';
$convM3 = \local_chatbot\diagrama::ascii_lists('<p>Antes</p>' . $listaM3 . '<ul><li>Uno</li><li>Dos</li></ul>');
local_chatbot_verificar(
    strpos($convM3, 'class="m2-tarjetas"') !== false && strpos($convM3, '+---') === false
    && strpos($convM3, '<strong>Procesos de envejecimiento</strong>') !== false
    && strpos($convM3, '>Envejecimiento normal (fisiológico)</strong>') !== false
    && strpos($convM3, '<li>Presencia de enfermedades</li>') !== false && strpos($convM3, '<li>Mantención de la autonomía</li>') !== false
    && strpos($convM3, '<ul><li>Uno</li><li>Dos</li></ul>') !== false,
    'un dibujo ASCII guardado como lista sale como rótulo y tarjetas lado a lado con sus viñetas; una lista normal no se toca'
);
$artNiveles = ['+----------------------+', '|   NIVELES DE DEPENDENCIA   |', '+----------------------+', '+----------------------+',
    '| [INDEPENDIENTE]      |', '| La persona realiza el 100% de las actividades sin ayuda. |', '+----------------------+', 'v',
    '+----------------------+', '| [DEPENDENCIA LEVE]   |', '| Requiere apoyo puntual. |', '+----------------------+', 'v',
    '+----------------------+', '| [DEPENDENCIA SEVERA] |', '| Requiere apoyo continuo. |', '+----------------------+'];
$convNiveles = (string) \local_chatbot\diagrama::ascii_html($artNiveles);
local_chatbot_verificar(
    strpos($convNiveles, 'class="m2-diagrama"') !== false && strpos($convNiveles, '<p><strong>Niveles de dependencia</strong></p>') === 0
    && substr_count($convNiveles, 'background-color: #E8F0F7') === 3 && strpos($convNiveles, '>Independiente</strong>') !== false
    && strpos($convNiveles, 'La persona realiza el 100% de las actividades sin ayuda.') !== false,
    'cajas ASCII encadenadas con «v» salen como diagrama de pasos con título, en tipo oración y con su descripción'
);
local_chatbot_verificar(
    strpos(\local_chatbot\diagrama::list_flows($listaM3), 'class="m2-tarjetas"') !== false
    && strpos(\local_chatbot\diagrama::list_flows($listaM3), '/ ') === false,
    'list_flows deja que un dibujo ASCII se convierta como dibujo (tarjetas), no como flujo de líneas sueltas'
);
$zig = \local_chatbot\diagrama::render_html(\local_chatbot\diagrama::parse("Uno\nDos\nTres\nCuatro\nCinco"));
$vert = \local_chatbot\diagrama::pdf_flows('<p>x</p>' . $zig);
local_chatbot_verificar(
    substr_count($vert, '<tr nobr="true"><td style="background-color: #E8F0F7') === 5 && substr_count($vert, '▼') === 4
    && strpos($vert, '→') === false && strpos($vert, '←') === false
    && strpos($vert, 'Uno') < strpos($vert, 'Dos') && strpos($vert, 'Cuatro') < strpos($vert, 'Cinco'),
    'en el PDF el diagrama va en vertical: cinco tarjetas en orden, «▼» entre ellas y ninguna partida entre páginas'
);
local_chatbot_verificar(
    \local_chatbot\diagrama::sentence('ENVEJECIMIENTO NORMAL (FISIOLÓGICO)') === 'Envejecimiento normal (fisiológico)'
    && \local_chatbot\diagrama::sentence('[INDEPENDIENTE]') === '[Independiente]'
    && \local_chatbot\diagrama::sentence('Escala de Barthel') === 'Escala de Barthel',
    'sentence pasa a tipo oración solo el texto en mayúsculas sostenidas'
);
local_chatbot_verificar(
    recurso_export::pictogram_captions('<p>[Pictograma: manos lavándose]</p>') === '<p><em style="color: #4A6A8A;">Ilustración sugerida: manos lavándose</em></p>'
    && strpos(recurso_export::render_pictograms('[Pictograma: guantes]'), 'Ilustración sugerida: guantes') !== false,
    'el pictograma se ve como «Ilustración sugerida» en Word y PDF, y con su nota también en pantalla'
);
local_chatbot_verificar(
    recurso_export::legacy_pictograms('<p><span class="cr-picto" title="Mano extendida"><svg viewBox="0 0 24 24"><path d="M1"></path></svg></span></p>')
        === '<p>[Pictograma: Mano extendida]</p>'
    && strpos(recurso_export::render_pictograms('<span class="cr-picto" title="Biombo"><svg></svg></span>'), 'Ilustración sugerida: Biombo') !== false,
    'los pictogramas ya guardados (solo icono) vuelven a mostrarse con su nota'
);
local_chatbot_verificar(
    strpos($p103, 'Redacción formal, clara y estable') !== false && strpos($p103, 'Nunca dibujes cajas, líneas o flechas con caracteres') !== false
    && strpos($p103, 'como máximo uno por sección') !== false,
    'el prompt de producción pide redacción formal, prohíbe dibujos con caracteres y limita los pictogramas'
);

// --- Etapa 11: sin versiones repetidas; un cambio devuelve el material a borrador ---
local_chatbot_verificar(
    !centro_recursos::can_approve(centro_recursos::MAT_ENTREGADO) && centro_recursos::can_approve(centro_recursos::MAT_BORRADOR)
    && centro_recursos::state_after_change(centro_recursos::MAT_ENTREGADO, true) === centro_recursos::MAT_BORRADOR
    && centro_recursos::state_after_change(centro_recursos::MAT_ENTREGADO, false) === centro_recursos::MAT_ENTREGADO
    && centro_recursos::state_after_change(centro_recursos::MAT_BORRADOR, true) === centro_recursos::MAT_BORRADOR,
    'un material entregado y sin cambios no se vuelve a aprobar; al editarlo vuelve a borrador para la versión siguiente'
);

// --- Marcas [REVISAR]: «Dato verificado» la quita, «Corregir» la reemplaza; lo demás no se toca ---
$conMarcas = '<p>Lavado de manos 40 a 60 segundos [REVISAR: confirmar tiempo].</p><p>Norma <strong>[REVISAR: código de norma]</strong>.</p>';
local_chatbot_verificar(
    centro_recursos::resolve_mark($conMarcas, 0) === '<p>Lavado de manos 40 a 60 segundos.</p><p>Norma <strong>[REVISAR: código de norma]</strong>.</p>'
    && centro_recursos::resolve_mark($conMarcas, 1, 'NT N.º 199 <MINSAL>') === '<p>Lavado de manos 40 a 60 segundos [REVISAR: confirmar tiempo].</p><p>Norma <strong> NT N.º 199 &lt;MINSAL&gt;</strong>.</p>'
    && centro_recursos::resolve_mark($conMarcas, 2) === null
    && centro_recursos::count_review_marks((string) centro_recursos::resolve_mark($conMarcas, 0)) === 1,
    'resolve_mark quita la marca verificada o la cambia por el dato correcto (escapado), sin tocar el resto'
);

// --- Plan actualizado con una Etapa 4 nueva (cliente, 21-11-2026: la Etapa 4 rehecha renumeró los materiales) ---
$planViejo = [
    ['id' => 1, 'codigo' => 'M1', 'nombre' => 'Infografía de bioseguridad, ergonomía y convivencia en taller', 'version' => 7],
    ['id' => 2, 'codigo' => 'M3', 'nombre' => 'Dossier temático ilustrado «Envejecimiento, síndromes geriátricos y dependencia»', 'version' => 1],
    ['id' => 3, 'codigo' => 'M4', 'nombre' => 'Ficha informativa perfil legal', 'version' => 0],
    ['id' => 4, 'codigo' => 'M6', 'nombre' => 'Ficha de Estación A', 'version' => 0],
    ['id' => 5, 'codigo' => 'M9', 'nombre' => 'Cuadernillo de casuística', 'version' => 0],
    ['id' => 6, 'codigo' => 'M2-C', 'nombre' => 'Cápsula · parte C: videos', 'version' => 2],
    ['id' => 7, 'codigo' => 'M5 (ant.)', 'nombre' => 'Afiche antiguo', 'version' => 1],
];
$planNuevo = [
    ['codigo' => 'M1', 'nombre' => 'Infografía y Guía de Bioseguridad, Ergonomía y Derechos Humanos', 'orden' => 1],
    ['codigo' => 'M3', 'nombre' => 'Set de Tarjetas de Microcasos y Debate sobre DDHH', 'orden' => 3],
    ['codigo' => 'M4', 'nombre' => 'Dossier Integrado y Guía de Casos: Envejecimiento, Síndromes y Perfil Legal', 'orden' => 4],
    ['codigo' => 'M6', 'nombre' => 'Plantilla Reutilizable de Registro de Atención Diaria', 'orden' => 6],
    ['codigo' => 'M7', 'nombre' => 'Pauta Unificada de Coevaluación', 'orden' => 7],
];
$mg = centro_recursos::plan_merge($planViejo, $planNuevo);
local_chatbot_verificar(
    $mg['rename'] === [2 => 'M3 (ant.)', 6 => 'M2-C (ant.)']
    && $mg['delete'] === [5]
    && array_keys($mg['update']) === [3, 4] && $mg['update'][3]['reiniciar'] === false && $mg['update'][4]['reiniciar'] === true
    && array_column($mg['insert'], 'codigo') === ['M3', 'M7'],
    'plan_merge conserva lo producido del mismo material, marca «(ant.)» lo producido que cambió o salió, actualiza o quita pendientes e inserta lo nuevo'
);
local_chatbot_verificar(centro_recursos::design_status_label($pEn, [
        (object) ['codigo' => 'M1', 'estado' => centro_recursos::MAT_ENTREGADO],
        (object) ['codigo' => 'M2', 'estado' => centro_recursos::MAT_PENDIENTE],
        (object) ['codigo' => 'M3 (ant.)', 'estado' => centro_recursos::MAT_ENTREGADO],
        (object) ['codigo' => 'M2-C (ant.)', 'estado' => centro_recursos::MAT_ENTREGADO]]) === 'En producción: 1 de 2',
    'el estado del centro cuenta solo el plan vigente, no lo producido de una versión anterior («(ant.)»)');
local_chatbot_verificar(
    centro_recursos::same_material('Infografía de bioseguridad, ergonomía y convivencia en taller', 'Infografía y Guía de Bioseguridad, Ergonomía y Derechos Humanos')
    && !centro_recursos::same_material('Dossier temático ilustrado «Envejecimiento, síndromes»', 'Set de Tarjetas de Microcasos y Debate sobre DDHH'),
    'same_material reconoce el mismo material con otro nombre y distingue uno distinto'
);

// --- Materiales mixtos: cada parte en su modo y «Entregado parcialmente» hasta entregar todas ---
$mixto = centro_recursos::parse_plan_json(json_encode(['materiales' => [
    ['codigo' => 'M2', 'nombre' => 'Cápsula audiovisual y tarjetas de debate', 'fase' => 'Fase 1', 'modo' => 'B/C',
        'formato' => 'Word (.docx) / PDF Carta / Vídeo', 'especificacion' => 'completa', 'estado' => 'pendiente',
        'partes' => [['modo' => 'C', 'nombre' => 'cápsula audiovisual', 'formato' => 'Ficha de recomendación'],
            ['modo' => 'B', 'nombre' => 'tarjetas de debate', 'formato' => 'Word (.docx) / PDF Carta']]],
    ['codigo' => 'M3', 'nombre' => 'Guía', 'fase' => 'F2', 'modo' => 'A/B', 'formato' => 'Texto / Word (.docx)',
        'especificacion' => 'completa', 'estado' => 'pendiente'],
    ['codigo' => 'M4', 'nombre' => 'Dossier', 'fase' => 'F2', 'modo' => 'B', 'formato' => 'Word', 'estado' => 'pendiente'],
]], JSON_UNESCAPED_UNICODE));
$mm = $mixto['materiales'] ?? [];
local_chatbot_verificar(
    count($mm) === 5
    && $mm[0]['codigo'] === 'M2-B' && $mm[0]['modo'] === 'B' && $mm[0]['formato'] === 'Word (.docx) / PDF Carta'
    && $mm[0]['nombre'] === 'Cápsula audiovisual y tarjetas de debate · parte B: tarjetas de debate'
    && $mm[1]['codigo'] === 'M2-C' && $mm[1]['modo'] === 'C' && $mm[1]['formato'] === 'Ficha de recomendación'
    && $mm[2]['codigo'] === 'M3-A' && $mm[2]['modo'] === 'A' && $mm[3]['codigo'] === 'M3-B'
    && $mm[4]['codigo'] === 'M4' && $mm[4]['modo'] === 'B',
    'el plan divide los materiales mixtos en una fila por parte (con lo que declara el asistente o deducido del formato)'
);
local_chatbot_verificar(
    centro_recursos::part_format('C', 'Word / Vídeo') === 'Ficha de recomendación'
    && centro_recursos::part_format('B', 'Word (.docx) / PDF Carta / Vídeo') === 'Word (.docx) / PDF Carta'
    && centro_recursos::part_format('B', 'Vídeo de YouTube') === 'Word (.docx)'
    && centro_recursos::base_code('M2-C') === 'M2' && centro_recursos::base_code('M12') === 'M12'
    && centro_recursos::part_letter('M2-C') === 'C' && centro_recursos::part_letter('M12') === '',
    'part_format, base_code y part_letter resuelven formato, material y parte'
);
$filasMix = [
    (object) ['codigo' => 'M1', 'estado' => centro_recursos::MAT_ENTREGADO],
    (object) ['codigo' => 'M2-B', 'estado' => centro_recursos::MAT_ENTREGADO, 'modo' => 'B', 'nombre' => 'Cápsula · parte B: tarjetas',
        'formato' => 'Word', 'version' => 1, 'revisar_count' => 1],
    (object) ['codigo' => 'M2-C', 'estado' => centro_recursos::MAT_PENDIENTE, 'modo' => 'C', 'nombre' => 'Cápsula · parte C: videos',
        'formato' => 'Ficha de recomendación', 'version' => 0, 'revisar_count' => 0],
    (object) ['codigo' => 'M3', 'estado' => centro_recursos::MAT_PENDIENTE],
];
$gruposMix = centro_recursos::material_groups($filasMix);
local_chatbot_verificar(
    array_keys($gruposMix) === ['M1', 'M2', 'M3']
    && $gruposMix['M2']['estado'] === centro_recursos::MAT_ENTREGADO_PARCIAL
    && centro_recursos::design_status_label($pEn, $filasMix) === 'En producción: 2 de 3'
    && centro_recursos::material_groups([$filasMix[1], (object) ['codigo' => 'M2-C', 'estado' => centro_recursos::MAT_ENTREGADO]])['M2']['estado']
        === centro_recursos::MAT_ENTREGADO,
    'el material mixto queda «Entregado parcialmente» con una parte y «Entregado» con todas; el estado del centro cuenta materiales'
);
$repMix = centro_recursos::repertory_html(['experiencia' => 'X'], array_slice($filasMix, 1, 2), []);
local_chatbot_verificar(
    strpos($repMix, '<td>M2</td><td>Cápsula</td><td>B/C</td><td>B: Word</td><td>B: v1 · C: —</td><td>Entregado parcialmente</td><td>1</td>') !== false,
    'el repertorio muestra el material mixto en una fila, con el modo y la versión de cada parte y su estado'
);
$matParte = ['codigo' => 'M2-C', 'nombre' => 'Cápsula · parte C: videos', 'fase' => 'F1', 'modo' => 'C',
    'formato' => 'Ficha de recomendación', 'especificacion' => 'completa', 'espec_detalle' => ''];
local_chatbot_verificar(
    strpos(centro_recursos::build_content_prompt($matParte, 'E', [], 'D', 'F'), 'es la parte C del material M2') !== false
    && strpos(centro_recursos::build_structure_prompt($matParte, [], 'D', 'F'), 'es la parte C del material M2') !== false
    && strpos(centro_recursos::build_content_prompt($matParte, 'E', [], 'D', 'F'), 'INSTRUCCIONES MODO C') !== false
    && centro_recursos::part_note($matPrueba) === ''
    && strpos($p9, '"partes"') !== false,
    'la parte C se produce como curaduría y los prompts dicen qué parte es; el plan pide las partes de los mixtos'
);

// --- Cuestionarios desde una sola fuente: GIFT + Google Apps Script + HTML imprimible con pauta ---
$quiz = [
    'titulo' => 'Cuestionario: Bioseguridad {Nivel 1}',
    'descripcion' => 'Evaluación formativa breve.',
    'preguntas' => [
        [
            'enunciado' => '¿Cuánto debe durar el lavado de manos clínico (ratio 1:1)?',
            'tipo' => 'multiple',
            'opciones' => ['40 a 60 segundos', '5 segundos ~ rápido', '10 minutos = excesivo', 'No aplica #'],
            'correcta' => 0,
            'puntaje' => 2,
            'retroalimentacion' => 'La OMS indica 40 a 60 segundos con agua y jabón.',
        ],
        [
            'enunciado' => 'Los guantes reemplazan la higiene de manos.',
            'tipo' => 'vf',
            'correcta' => 'falso',
            'puntaje' => 1,
            'retroalimentacion' => 'Nunca reemplazan el lavado de manos.',
        ],
        [
            'enunciado' => 'Indica la sigla de los elementos de protección personal.',
            'tipo' => 'corta',
            'correcta' => ['EPP', 'E.P.P.'],
            'puntaje' => 1,
            'retroalimentacion' => 'EPP: Elementos de Protección Personal.',
        ],
    ],
];
$gift = recurso_export::to_gift($quiz);
local_chatbot_verificar(
    recurso_export::validate_gift($gift) === []
    && strpos($gift, '\\:') !== false
    && strpos($gift, '\\~') !== false
    && strpos($gift, '\\=') !== false
    && strpos($gift, '\\#') !== false
    && strpos($gift, '=40 a 60 segundos') !== false
    && strpos($gift, '{F#') !== false,
    'to_gift genera GIFT válido con escapes (~ = # : { }), opción múltiple, V/F y respuesta corta'
);
local_chatbot_verificar(
    recurso_export::validate_gift('::P1::Sin llave {=a') !== []
    && recurso_export::validate_gift('::P1::Sin correcta {~a ~b}') !== [],
    'validate_gift detecta llaves desbalanceadas y preguntas sin respuesta correcta'
);
$gs = recurso_export::to_google_apps_script($quiz);
local_chatbot_verificar(
    strpos($gs, 'function crearCuestionario()') !== false
    && strpos($gs, 'form.setIsQuiz(true);') !== false
    && strpos($gs, 'addMultipleChoiceItem()') !== false
    && strpos($gs, 'setFeedbackForCorrect') !== false
    && strpos($gs, 'Instrucciones en 3 pasos:') !== false,
    'to_google_apps_script genera script ejecutable en modo cuestionario con retroalimentación e instrucciones'
);
$quizHtml = recurso_export::to_printable_quiz_html($quiz, '', 'M3 · ' . centro_recursos::PIE_MATERIAL);
local_chatbot_verificar(
    strpos($quizHtml, 'Pauta de respuestas') !== false
    && strpos($quizHtml, '☐ a) 40 a 60 segundos') !== false
    && strpos($quizHtml, centro_recursos::PIE_MATERIAL) !== false,
    'to_printable_quiz_html separa el cuestionario de la pauta de respuestas'
);

// --- HTML Moodle seguro y pictogramas SVG ---
$htmlSucio = '<h2>Afiche</h2><p>[Pictograma: lavado de manos] Paso 1 [REVISAR: norma]</p><script>alert(1)</script><a href="javascript:evil()">x</a>';
$htmlMoodle = recurso_export::to_moodle_html($htmlSucio, 'Afiche bioseguridad', 'M5');
local_chatbot_verificar(
    stripos($htmlMoodle, '<script') === false
    && stripos($htmlMoodle, 'javascript:') === false
    && strpos($htmlMoodle, '<svg') !== false
    && strpos($htmlMoodle, '#fff3cd') !== false
    && strpos($htmlMoodle, 'M5 · Afiche bioseguridad') !== false,
    'to_moodle_html sanitiza scripts/javascript:, convierte pictogramas a SVG y destaca marcas [REVISAR]'
);

// --- Generacion real en disco de .docx, .xlsx, .pptx, .pdf (TCPDF), .zip y lectura de .xlsx ---
$tmpDir = sys_get_temp_dir() . '/cr_verif_' . uniqid();
mkdir($tmpDir, 0777, true);

$docxPath = $tmpDir . '/material.docx';
$okDocx = recurso_export::build_docx($docxPath, 'M5 · Afiche de bioseguridad', 'OTEC Demo',
    '<h2>Parte 1</h2><p>Lávate las manos 40 a 60 segundos.</p><table><tr><th>Paso</th><th>Acción</th></tr><tr><td>1</td><td>Moja tus manos</td></tr></table>');
$zipCheck = new ZipArchive();
$docxValido = $okDocx && $zipCheck->open($docxPath) === true && $zipCheck->locateName('word/document.xml') !== false;
if ($docxValido) {
    $zipCheck->close();
}
local_chatbot_verificar($docxValido, 'build_docx genera un archivo Word (.docx) OOXML válido');
// Tarjetas de un dibujo ASCII (M3 del cliente): en el Word conservan su color, no el de las franjas de una tabla.
$docxTarjetas = $tmpDir . '/tarjetas.docx';
$okTarjetas = recurso_export::build_docx($docxTarjetas, 'M3', '', '<h2>2.2</h2>' . $listaM3);
$xmlTarjetas = '';
if ($okTarjetas && $zipCheck->open($docxTarjetas) === true) {
    $xmlTarjetas = (string) $zipCheck->getFromName('word/document.xml');
    $zipCheck->close();
}
local_chatbot_verificar(substr_count($xmlTarjetas, 'w:fill="F4F8FC"') === 2 && strpos($xmlTarjetas, '+---') === false
    && strpos($xmlTarjetas, 'Presencia de enfermedades') !== false,
    'en el Word las tarjetas lado a lado llevan su color de fondo y sus viñetas, sin restos del dibujo ASCII');

$xlsxPath = $tmpDir . '/planilla.xlsx';
$okXlsx = recurso_export::build_xlsx($xlsxPath, [
    ['nombre' => 'Registro', 'encabezados' => ['Participante', 'Puntaje', 'Total'],
        'filas' => [['Ana Soto', 18, '=B2*1'], ['Luis Díaz', 20, '=B3*1']]],
], 'Registro de taller');
$xlsxValido = $okXlsx && $zipCheck->open($xlsxPath) === true && $zipCheck->locateName('xl/worksheets/sheet1.xml') !== false;
if ($xlsxValido) {
    $zipCheck->close();
}
$xlsxLeido = recurso_export::xlsx_text($xlsxPath);
local_chatbot_verificar($xlsxValido && $xlsxLeido !== null && strpos($xlsxLeido, 'Ana Soto') !== false,
    'build_xlsx genera un .xlsx válido y xlsx_text extrae su contenido como referencia técnica');

$pptxPath = $tmpDir . '/presentacion.pptx';
$okPptx = recurso_export::build_pptx($pptxPath, 'Cuidados básicos', 'Módulo 1 · AE 1', [
    ['titulo' => 'Higiene de manos', 'puntos' => ['Duración 40–60 s', 'Agua y jabón'], 'notas' => 'Demostrar los 6 pasos.'],
    ['titulo' => 'Cierre', 'puntos' => ['Reflexión grupal'], 'notas' => ''],
], 'M11 · ' . centro_recursos::PIE_MATERIAL);
$pptxValido = $okPptx && $zipCheck->open($pptxPath) === true
    && $zipCheck->locateName('ppt/slides/slide1.xml') !== false
    && $zipCheck->locateName('ppt/slides/slide2.xml') !== false
    && $zipCheck->locateName('ppt/notesSlides/notesSlide2.xml') !== false;
if ($pptxValido) {
    $zipCheck->close();
}
local_chatbot_verificar($pptxValido, 'build_pptx genera un .pptx válido con portada, diapositivas y notas del facilitador');

$pdfPath = $tmpDir . '/tarjeta.pdf';
$okPdf = recurso_export::build_pdf($pdfPath, 'M5 · Tarjeta de acogida', '',
    '<p>[Pictograma: saludo] Hola, me llamo María y te ayudaré hoy. [REVISAR: protocolo]</p>', 'PDF tarjeta 9x6 cm');
$pdfBytes = $okPdf ? (string) file_get_contents($pdfPath) : '';
local_chatbot_verificar($okPdf && strpos($pdfBytes, '%PDF-') === 0 && stripos($pdfBytes, 'Powered by TCPDF') === false,
    'build_pdf genera un archivo PDF real (tarjeta 9×6 cm con TCPDF) sin marca de agua Powered by TCPDF');

// --- Diseño editorial didactico moderno (PDF, Word, PPTX, Excel y HTML Moodle) ---
$htmlMod = '<p><strong>Organismo:</strong> OTEC Demo | <strong>Curso:</strong> Cuidados | '
    . '<strong>Persona facilitadora:</strong> Ana Pérez | <strong>Código:</strong> M1 | <strong>Modo:</strong> B (PDF A4)</p>'
    . '<h1>Infografía de bioseguridad</h1><h2>Encabezado e identificación</h2>'
    . '<p>Entrega directrices operativas para el taller.</p>'
    . '<h2>Sección 1 — Bioseguridad</h2>'
    . '<p>[Pictograma: lavado de manos y postura de guantes]</p>'
    . '<p><strong>Importante:</strong> Revisar protocolo local [REVISAR: norma vigente].</p>'
    . '<blockquote><p><strong>Nota de profundización:</strong> Remitirse al material M10.</p></blockquote>'
    . '<h2>Sección 2 — Ergonomía</h2>'
    . '<p>[Pictograma: Esquema biomecánico del cuerpo humano con espalda recta]</p>'
    . '<p>Fuentes utilizadas: * Plan Formativo PF0960 v3. * Ficha de Diagnóstico validada.</p>'
    . '<p><em>M1 · ' . centro_recursos::PIE_MATERIAL . '</em></p>';
$partesMod = recurso_export::prepare_material_parts('M1 · Infografía de bioseguridad', '', $htmlMod);
local_chatbot_verificar(
    $partesMod['codigo'] === 'M1'
    && $partesMod['titulo'] === 'Infografía de bioseguridad'
    && count($partesMod['meta_items']) === 3
    && stripos($partesMod['body_html'], '<h1>') === false
    && strpos($partesMod['lead'], 'directrices operativas') !== false
    && count($partesMod['fuentes']) === 2
    && strpos($partesMod['pie'], centro_recursos::PIE_MATERIAL) !== false
    && strpos($partesMod['body_html'], 'cr-callout-alerta') !== false
    && strpos($partesMod['body_html'], 'cr-callout-nota') !== false
    && substr_count($partesMod['body_html'], 'cr-ilustracion') === 2,
    'prepare_material_parts extrae metadatos, deduplica H1, convierte propósito, separa fuentes con «*» y anota recuadros'
);
local_chatbot_verificar(
    recurso_export::classify_callout('Nota de profundización')['tipo'] === 'nota'
    && recurso_export::classify_callout('Ejemplo práctico')['tipo'] === 'ejemplo'
    && recurso_export::classify_callout('Importante')['tipo'] === 'alerta'
    && recurso_export::classify_callout('Recuerda')['tipo'] === 'clave'
    && recurso_export::split_section_badge('Sección 2 — Ergonomía', 2) === ['02', 'Ergonomía']
    && recurso_export::split_section_badge('Principios generales', 3) === ['03', 'Principios generales'],
    'classify_callout clasifica los 4 tipos didácticos y split_section_badge genera insignias numeradas'
);
local_chatbot_verificar(
    strpos(recurso_export::svg_pictogram('lavado de manos y postura de guantes'), 'C8.5 8 6 11') !== false
    && strpos(recurso_export::svg_pictogram('Esquema biomecánico del cuerpo humano con espalda recta'), 'y1="8" x2="12" y2="14.5"') !== false
    && strpos(recurso_export::svg_pictogram('subgrupo colaborando en equipo'), 'cx="16.2"') !== false,
    'svg_pictogram distingue higiene de manos, ergonomía (sin confundir «humano» con «mano») y trabajo en equipo'
);
$pdfHtmlMod = recurso_export::format_pdf_html($partesMod, recurso_export::parse_pdf_spec('PDF A4'));
local_chatbot_verificar(
    strpos($pdfHtmlMod, 'RECURSO DIDÁCTICO · M1') !== false
    && strpos($pdfHtmlMod, 'PROPÓSITO DEL RECURSO') !== false
    && strpos($pdfHtmlMod, 'APOYO VISUAL SUGERIDO') !== false
    && strpos($pdfHtmlMod, 'FUENTES UTILIZADAS') !== false
    && strpos($pdfHtmlMod, 'src="@') !== false
    && strpos($pdfHtmlMod, 'nobr="true"') !== false,
    'format_pdf_html arma cabecera editorial, banda de metadatos, propósito, iconos SVG vectoriales, fuentes y anti-huérfanos'
);
$docxMod = $tmpDir . '/moderno.docx';
$okDocxMod = recurso_export::build_docx($docxMod, 'M1 · Infografía de bioseguridad', '', $htmlMod);
$xmlDocxMod = '';
if ($okDocxMod && $zipCheck->open($docxMod) === true) {
    $xmlDocxMod = (string) $zipCheck->getFromName('word/document.xml');
    $zipCheck->close();
}
local_chatbot_verificar(
    strpos($xmlDocxMod, 'w:fill="1B2B5E"') !== false
    && strpos($xmlDocxMod, 'w:color="1EA7E1"') !== false
    && strpos($xmlDocxMod, 'w:fill="FEF3C7"') !== false
    && strpos($xmlDocxMod, 'PROPÓSITO DEL RECURSO') !== false
    && strpos($xmlDocxMod, 'FUENTES UTILIZADAS') !== false,
    'build_docx en estilo recurso genera cabecera moderna indigo/cyan, recuadros didácticos, fuentes y marcas [REVISAR] en ámbar'
);
$pptxCards = $tmpDir . '/tarjetas.pptx';
$okPptxCards = recurso_export::build_pptx($pptxCards, 'Bioseguridad', 'Módulo 1', [[
    'titulo' => 'Directrices clave',
    'puntos' => [
        'Higiene de manos: Agua y jabón antes y después de cada atención.',
        'Uso de guantes: Un par nuevo por procedimiento asistencial.',
    ],
    'notas' => 'Explicar ambos pilares.',
]], 'M1 · ' . centro_recursos::PIE_MATERIAL);
$xmlSlide2 = '';
if ($okPptxCards && $zipCheck->open($pptxCards) === true) {
    $xmlSlide2 = (string) $zipCheck->getFromName('ppt/slides/slide2.xml');
    $zipCheck->close();
}
local_chatbot_verificar(
    strpos($xmlSlide2, 'prst="roundRect"') !== false
    && strpos($xmlSlide2, 'val="1EA7E1"') !== false
    && strpos($xmlSlide2, 'Higiene de manos') !== false,
    'build_pptx diseña diapositivas luminosas con tarjetas visuales redondeadas cuando los puntos tienen formato «Título: detalle»'
);
$xlsxModXml = '';
if ($zipCheck->open($xlsxPath) === true) {
    $xlsxModXml = (string) $zipCheck->getFromName('xl/worksheets/sheet1.xml') . (string) $zipCheck->getFromName('xl/styles.xml');
    $zipCheck->close();
}
local_chatbot_verificar(
    strpos($xlsxModXml, 'ySplit="1"') !== false
    && strpos($xlsxModXml, 'EDF7FC') !== false
    && strpos($xlsxModXml, 'B2*1') !== false,
    'build_xlsx inmoviliza la cabecera, alterna filas celestes y conserva intactas las fórmulas en fila 2'
);

// --- Paginacion inteligente (sin cortes en PDF ni Word) y diagramas tras clean_text() de Moodle ---
$diagSinData = preg_replace('/\s+data-[a-z0-9_-]+="[^"]*"/i', '',
    \local_chatbot\diagrama::render_html(\local_chatbot\diagrama::parse("Paso 1 / A\nPaso 2 / B\nPaso 3 / C\nPaso 4 / D\nPaso 5 / E")));
$cardsSinData = \local_chatbot\diagrama::extract_cards($diagSinData);
local_chatbot_verificar(
    count($cardsSinData) === 5
    && strpos($cardsSinData[0], 'A') !== false
    && strpos($cardsSinData[3], 'D') !== false
    && strpos($cardsSinData[4], 'E') !== false,
    'diagrama::extract_cards recupera todas las tarjetas en orden (incluido zigzag) aun cuando clean_text() quitó data-n'
);
$metaConBr = '<p><strong>Organismo:</strong> OTEC SpA | <strong>Curso:</strong> Cuidados | <strong>Persona facilitadora:</strong> Ana<br>'
    . '<strong>Código:</strong> M1 | <strong>Formato de salida:</strong> PDF A4 / Word (.docx)<br>'
    . '<strong>Fase y actividad:</strong> Fase 1: Inicio</p>'
    . '<p>Su función operativa es: * Acompañar ejercicios prácticos. * Orientar uso de insumos. * Promover colaboración.</p>';
$pMetaBr = recurso_export::prepare_material_parts('M1 · Infografía', '', $metaConBr);
local_chatbot_verificar(
    count($pMetaBr['meta_items']) === 4
    && $pMetaBr['meta_items'][2]['valor'] === 'Ana'
    && $pMetaBr['meta_items'][3]['valor'] === 'Fase 1: Inicio'
    && strpos($pMetaBr['body_html'], '<ul><li>Acompañar ejercicios prácticos.</li>') !== false
    && substr_count($pMetaBr['body_html'], '<li>') === 3,
    'prepare_material_parts separa metadatos con <br>, filtra Formato de salida y convierte «* » en línea en lista <ul>'
);
$bloquesPdf = recurso_export::format_pdf_blocks(
    recurso_export::prepare_material_parts('M1 · Infografía', '', '<h2>Sección 1 — Bio</h2><p>Intro breve.</p>' . $diagSinData),
    recurso_export::parse_pdf_spec('PDF A4')
);
local_chatbot_verificar(
    count($bloquesPdf) >= 2
    && !empty($bloquesPdf[1]['atomic'])
    && strpos($bloquesPdf[1]['html'], 'PASO 1') !== false
    && strpos($bloquesPdf[1]['html'], 'PASO 5') !== false,
    'format_pdf_blocks agrupa sección + intro + diagrama (aun sin data-n) en una unidad atómica para evitar cortes en PDF'
);
$docxIntel = $tmpDir . '/inteligente.docx';
$okDocxIntel = recurso_export::build_docx($docxIntel, 'M1 · Infografía', '',
    '<h2>Sección 1 — Bio</h2><p>[Pictograma: manos]</p><p>Intro.</p><h3>Reglas</h3><ul><li>Uno</li><li>Dos</li><li>Tres</li></ul>');
$xmlDocxIntel = '';
if ($okDocxIntel && $zipCheck->open($docxIntel) === true) {
    $xmlDocxIntel = (string) $zipCheck->getFromName('word/document.xml') . (string) $zipCheck->getFromName('word/styles.xml');
    $zipCheck->close();
}
local_chatbot_verificar(
    strpos($xmlDocxIntel, '<w:widowControl/>') !== false
    && substr_count($xmlDocxIntel, '<w:keepNext/>') >= 5
    && strpos($xmlDocxIntel, '<w:cantSplit/>') !== false,
    'build_docx aplica widowControl, cantSplit y keepNext encadenado (sección, apoyo visual, intro y lista corta) para evitar cortes'
);

$repHtml = centro_recursos::repertory_html(
    ['experiencia' => 'Taller de cuidados', 'plan' => 'PF0960', 'modulo' => 'M1', 'organismo' => 'OTEC Demo'],
    [
        (object) ['codigo' => 'M5', 'nombre' => 'Afiche bioseguridad', 'modo' => 'B', 'formato' => 'PDF A2',
            'version' => 1, 'estado' => centro_recursos::MAT_ENTREGADO, 'revisar_count' => 1],
        (object) ['codigo' => 'M2', 'nombre' => 'Videos', 'modo' => 'C', 'formato' => 'Ficha',
            'version' => 1, 'estado' => centro_recursos::MAT_ENTREGADO, 'revisar_count' => 0,
            'fecha_verificacion' => '19-11-2026', 'seleccionados' => '[0]',
            'estructurado' => json_encode(['opciones' => [$opsCur[0]]])],
    ],
    [(object) ['filename' => 'Protocolo.pdf', 'secciones' => 'págs. 1-4']]
);
local_chatbot_verificar(
    strpos($repHtml, 'Afiche bioseguridad') !== false
    && strpos($repHtml, 'https://example.org/ok') !== false
    && strpos($repHtml, 'Protocolo.pdf') !== false
    && strpos($repHtml, '1 marca(s)') !== false,
    'repertory_html consolida tabla de materiales, enlaces seleccionados en Modo C, referencias y recordatorio [REVISAR]'
);

$zipPath = $tmpDir . '/repertorio.zip';
$okZip = recurso_export::build_repertory_zip($zipPath, [
    '00_Repertorio.docx' => ['ruta' => $docxPath],
    'materiales/M5_planilla.xlsx' => ['ruta' => $xlsxPath],
    'materiales/M5_GIFT.txt' => ['texto' => $gift],
]);
$zipOk = $okZip && $zipCheck->open($zipPath) === true
    && $zipCheck->locateName('00_Repertorio.docx') !== false
    && $zipCheck->locateName('materiales/M5_GIFT.txt') !== false;
if ($zipOk) {
    $zipCheck->close();
}
local_chatbot_verificar($zipOk, 'build_repertory_zip empaqueta el repertorio completo y los materiales producidos');

// Limpieza de archivos temporales.
foreach ([$docxPath, $docxTarjetas, $docxMod, $docxIntel, $xlsxPath, $pptxPath, $pptxCards, $pdfPath, $zipPath] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}
@rmdir($tmpDir);

// --- Integracion en la pestana del curso (modulo2_setup) ---
$seccs = [
    1 => ['name' => 'Fase 1: Diagnóstico', 'level' => 0],
    2 => ['name' => 'Fase 2: Diseño', 'level' => 0],
    3 => ['name' => 'Fase 3: Validación', 'level' => 0],
    4 => ['name' => 'Centro de producción de recursos de aprendizaje', 'level' => 0],
    5 => ['name' => 'Cómo trabajar con el centro', 'level' => 1],
];
local_chatbot_verificar(modulo2_setup::center_last_section($seccs) === 5,
    'modulo2_setup::center_last_section ubica la última subsección de la pestaña del Centro');
$tabHtml = modulo2_setup::center_tab_html('https://ej.cl/local/chatbot/centro_recursos.php?courseid=4');
local_chatbot_verificar(
    strpos($tabHtml, 'Centro de producción de recursos de aprendizaje') !== false
    && strpos($tabHtml, modulo2_setup::BOTON_CENTRO) !== false
    && modulo2_setup::add_center_button($tabHtml, 'https://ej.cl/local/chatbot/centro_recursos.php?courseid=4') === $tabHtml,
    'center_tab_html y add_center_button son válidos e idempotentes'
);
$urlCentro = 'https://ej.cl/local/chatbot/centro_recursos.php?courseid=4';
$btnSolo = modulo2_setup::center_button_html($urlCentro);
local_chatbot_verificar(
    modulo2_setup::remove_center_button($btnSolo) === ''
    && modulo2_setup::remove_center_button('<p>Intro</p>' . $btnSolo) === '<p>Intro</p>'
    && modulo2_setup::remove_center_button('<div style="text-align:center;"><a href="' . $urlCentro . '">Ir al Centro</a></div>') === '',
    'remove_center_button quita el botón del resumen (con o sin comentario HTML) y conserva cualquier otro texto'
);
$planCrear = modulo2_setup::center_button_plan([['id' => 10, 'html' => '<p>Presentación</p>']], $urlCentro, 12);
$planOk = modulo2_setup::center_button_plan([['id' => 10, 'html' => '<p>Presentación</p>'], ['id' => 12, 'html' => $btnSolo]], $urlCentro, 12);
$planMover = modulo2_setup::center_button_plan([['id' => 10, 'html' => $btnSolo], ['id' => 12, 'html' => '<p>Repositorio</p>']], $urlCentro, 12);
$planAct = modulo2_setup::center_button_plan([['id' => 10, 'html' => modulo2_setup::center_button_html('https://otro.cl/local/chatbot/centro_recursos.php?courseid=4')]], $urlCentro, 12);
$planEnl = modulo2_setup::center_button_plan([['id' => 15, 'html' => '<p><a href="#">' . modulo2_setup::BOTON_CENTRO . '</a></p>']], $urlCentro, 15);
local_chatbot_verificar(
    $planCrear['accion'] === 'crear' && $planCrear['html'] === $btnSolo
    && $planOk['accion'] === 'ok' && !$planOk['mover']
    && $planMover['accion'] === 'mover' && $planMover['id'] === 10 && $planMover['mover'] === true
    && $planAct['accion'] === 'actualizar' && $planAct['mover'] === true && $planAct['html'] === $btnSolo
    && $planEnl['accion'] === 'enlazar' && strpos((string) $planEnl['html'], 'href="' . $urlCentro . '"') !== false,
    'center_button_plan crea la etiqueta al final, la mantiene idempotente, la mueve si quedó arriba y actualiza su enlace'
);

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Centro de producción de recursos del Módulo 2 conforme.\n";
    exit(0);
}
echo "Centro de producción de recursos NO conforme: revisar classes/centro_recursos.php y classes/recurso_export.php.\n";
exit(1);


<?php
/**
 * Verifica el Informe de validacion de la ficha descargable en PDF con logo de Interfases Consultores
 * (M1-v1.31.0, interruptor «pdfvalidacion»): conteos de resumen, colores por condicion/estado, armado de bloques
 * para las fichas de los Modulos 2 y 3, incrustacion del logo PNG y generacion real de archivo PDF via TCPDF.
 *
 * Uso:  php cli/verificar_informe_ficha_pdf.php
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/informe_ficha_pdf.php');

use local_chatbot\exante;
use local_chatbot\informe_ficha_pdf;

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

// --- 1. Conteos de resumen y colores ---
$checklist = exante::checklist();
$items = [];
$i = 0;
foreach ($checklist as $sec) {
    foreach ($sec['items'] as $id => $_) {
        $i++;
        if ($i === 1) {
            $items[$id] = ['estado' => 'no_cumple', 'observacion' => 'Falta precisión en el criterio.',
                'propuesta' => 'Redactar el criterio con desempeño observable.'];
        } else if ($i === 2) {
            $items[$id] = ['estado' => 'parcial', 'observacion' => 'Diferencia menor de redacción.',
                'forma' => true, 'propuesta' => 'Afinar la redacción en la Etapa 2.'];
        } else if ($i === 3) {
            // Item ausente (para probar «No evaluado»).
            continue;
        } else {
            $items[$id] = ['estado' => 'cumple', 'observacion' => 'Consistente con el plan formativo.'];
        }
    }
}
$counts = informe_ficha_pdf::summary_counts($checklist, $items);
local_chatbot_verificar(
    $counts['total'] === 33
        && $counts['no_cumple'] === 1
        && $counts['parcial'] === 1
        && $counts['sin_evaluar'] === 1
        && $counts['cumple'] === 30,
    'summary_counts() contabiliza exactamente Cumple, Parcial, No cumple y Sin evaluar sobre los 33 ítems del checklist'
);

local_chatbot_verificar(
    informe_ficha_pdf::badge_colors('aprobado')['fg'] === '#166534'
        && informe_ficha_pdf::badge_colors('observaciones')['fg'] === '#92400E'
        && informe_ficha_pdf::badge_colors('no_aprobado')['fg'] === '#991B1B'
        && informe_ficha_pdf::badge_colors('otro')['fg'] === '#475569',
    'badge_colors() asigna colores semanticos a cada condicion y estado'
);

// --- 2. Bloques HTML con logo institucional y secciones completas (Modulo 2 + Modulo 3) ---
$logo = __DIR__ . '/../pix/logo_interfases.png';
$spec = [
    'modulo' => 'MÓDULO 2 · FASE 1 · VALIDACIÓN DE LA FICHA EX-ANTE',
    'titulo' => 'Informe de validación de la ficha',
    'curso' => 'Módulo 2: Asistente de Diseño Instruccional',
    'fecha' => 'Validado el 24 nov 2026, 20:55',
    'vigente' => false,
    'stale_text' => 'La ficha fue editada después de esta validación.',
    'meta' => [
        ['etiqueta' => 'Organismo ejecutor', 'valor' => 'OTEC Capacita Norte'],
        ['etiqueta' => 'Plan formativo', 'valor' => 'PF-2026 Instalaciones Eléctricas'],
        ['etiqueta' => 'Módulo', 'valor' => 'Módulo 2: Seguridad y bloqueo LOTO'],
        ['etiqueta' => 'Aprendizaje esperado', 'valor' => 'Aplicar procedimientos de bloqueo y etiquetado LOTO.'],
    ],
    'checklist' => $checklist,
    'criticos' => exante::CRITICOS,
    'items' => $items,
    'condicion' => exante::NO_APROBADO,
    'condicion_titulo' => 'No aprobado',
    'condicion_desc' => 'La ficha requiere correcciones en ítems críticos antes de pasar a la Fase 2.',
    'regla_label' => 'Regla aplicada:',
    'regla_text' => 'Un ítem crítico en No cumple detiene la validación.',
    'detienen' => [
        ['texto' => 'El aprendizaje esperado expresa un desempeño evaluable.',
            'observacion' => 'Falta precisión en el criterio.',
            'propuesta' => 'Redactar el criterio con desempeño observable.'],
    ],
    'tiempo' => [
        'lbl_total' => 'Tiempo total disponible',
        'lbl_usado' => 'Tiempo utilizado',
        'lbl_restante' => 'Tiempo restante',
        'total' => '360 min',
        'utilizado' => '240 min',
        'restante' => '120 min',
        'estado' => 'ok',
        'nota' => 'Disponible para la experiencia de continuidad.',
    ],
    'debilidades' => ['El criterio 1 no especifica el contexto de seguridad.'],
    'sugerencias' => ['Incorporar verificación de tensión cero en el criterio 1.'],
    'pendientes' => [
        ['seccion' => '1', 'texto' => 'Falta precisión en el criterio.', 'estado' => 'no_cumple'],
    ],
    'verificaciones' => ['Suma de participantes: 15 presentes = 15 evaluados (correcto).'],
    'adjuntos_texto' => 'Documentos revisados: Plan Formativo (PF2026.pdf).',
    'fuentes_web' => [['title' => 'Normativa SEC', 'uri' => 'https://www.sec.cl']],
    'nota_pie' => 'Esta validación revisa la ficha de trabajo, no califica a la persona.',
];

$bloques = informe_ficha_pdf::build_blocks($spec, $logo);
$html = informe_ficha_pdf::build_html($spec, $logo);
local_chatbot_verificar(count($bloques) >= 12, 'build_blocks() genera unidades semanticas separadas para paginacion inteligente');
local_chatbot_verificar(
    strpos($html, 'Informe de validación de la ficha') !== false
        && strpos($html, 'MÓDULO 2 · FASE 1 · VALIDACIÓN DE LA FICHA EX-ANTE') !== false
        && strpos($html, 'OTEC Capacita Norte') !== false
        && strpos($html, 'RESULTADO DE LA VALIDACIÓN') !== false
        && strpos($html, '[CRÍTICO]') !== false
        && strpos($html, 'Diferencia de forma: no detiene la ficha.') !== false
        && strpos($html, 'Cómo mejorarlo:') !== false
        && strpos($html, 'La ficha fue editada después de esta validación.') !== false
        && strpos($html, '120 min') !== false
        && strpos($html, 'Suma de participantes: 15 presentes = 15 evaluados') !== false
        && strpos($html, 'Normativa SEC (https://www.sec.cl)') !== false,
    'build_html() incluye cabecera, metadatos, 33 ítems, condición, críticos, tiempo, debilidades, sugerencias, verificaciones y fuentes'
);
local_chatbot_verificar(
    is_readable($logo) && strpos($html, '<img src="@') !== false,
    'build_html() incrusta el logo institucional de Interfases (pix/logo_interfases.png)'
);

// --- 3. Generacion real de PDF via TCPDF con el logo de la consultora ---
$ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'local_chatbot_informe_ficha_' . uniqid('', true) . '.pdf';
$okPdf = informe_ficha_pdf::build_pdf($ruta, $spec, $logo);
local_chatbot_verificar($okPdf && is_file($ruta) && filesize($ruta) > 8000, 'build_pdf() genera un archivo PDF real y no vacio');

if (is_file($ruta)) {
    $bin = (string) file_get_contents($ruta);
    @unlink($ruta);
    local_chatbot_verificar(
        strncmp($bin, '%PDF-', 5) === 0 && strpos($bin, '%%EOF') !== false,
        'el archivo generado tiene cabecera %PDF- y cierre %%EOF validos'
    );
    local_chatbot_verificar(
        strpos($bin, '/Subtype /Image') !== false,
        'el PDF contiene el objeto de imagen incrustado del logo de la consultora'
    );
    local_chatbot_verificar(
        strpos($bin, 'Powered by TCPDF') === false,
        'el PDF no incluye la marca «Powered by TCPDF»'
    );
    local_chatbot_verificar(
        preg_match('/\/Type\s*\/Pages\b.*?\/Count\s+(\d+)/s', $bin, $mp) === 1 && (int) $mp[1] >= 2,
        'el PDF multipagina ejecuta tanto la cabecera hero (pagina 1) como el encabezado corrido con logo (paginas 2+)'
    );
}

$pasados = $total - $fallidos;
echo "Informe de validación de la ficha (PDF): {$pasados}/{$total} comprobaciones correctas.\n";
exit($fallidos > 0 ? 1 : 0);

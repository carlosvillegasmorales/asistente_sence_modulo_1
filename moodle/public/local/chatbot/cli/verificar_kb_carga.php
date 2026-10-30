<?php
/**
 * Verifica la carga de documentos a la base de conocimiento (RU-KB-001).
 *
 * Desde que la extracción del texto ocurre en una tarea y no dentro del request
 * web, el desenlace de una carga ya no se le cuenta a nadie en el momento: se
 * guarda y se muestra después. Por eso importa que la clasificación del
 * resultado sea correcta —un documento con claves de evaluación no puede
 * terminar incorporado por ninguna vía— y que cada motivo de fallo se traduzca
 * a una explicación fiel, en vez de culpar al PDF cuando el problema fue el
 * tiempo de espera.
 *
 * Uso:  php cli/verificar_kb_carga.php
 * Sale con código 1 ante cualquier caso fallido, para encadenarlo a CI.
 */

define('MOODLE_INTERNAL', true);

require_once(__DIR__ . '/../classes/kb_store.php');

use local_chatbot\kb_store;

$fallidos = 0;

/**
 * @param bool $condicion
 * @param string $descripcion
 * @return void
 */
function local_chatbot_verificar(bool $condicion, string $descripcion): void {
    global $fallidos;
    if (!$condicion) {
        echo "  FALLA  {$descripcion}\n";
        $fallidos++;
    }
}

// --- Clasificación del resultado de una extracción ---
$casos = [
    [
        'desc' => 'texto limpio se incorpora',
        'texto' => "Documento de Lectura\n\nLa evidencia es el producto observable del desempeño.",
        'error' => '',
        'estado' => 'listo',
    ],
    [
        'desc' => 'RU-KB-001: texto con alternativa correcta se rechaza',
        'texto' => "Pregunta 3. ¿Qué distingue evidencia de instrumento?\nAlternativa correcta: C",
        'error' => '',
        'estado' => 'rechazado',
    ],
    [
        'desc' => 'RU-KB-001: texto con clave se rechaza',
        'texto' => "Ítem 7 del quiz formativo.\nClave: B",
        'error' => '',
        'estado' => 'rechazado',
    ],
    [
        'desc' => 'RU-KB-001: marca de solo configuración se rechaza',
        'texto' => "SOLO CONFIGURACIÓN MOODLE · retroalimentación por alternativa",
        'error' => '',
        'estado' => 'rechazado',
    ],
    [
        'desc' => 'corte por tiempo de espera no se confunde con un PDF ilegible',
        'texto' => null,
        'error' => 'transport_error',
        'estado' => 'error',
        'motivo' => 'timeout',
    ],
    [
        'desc' => 'error del proveedor se conserva tal cual',
        'texto' => null,
        'error' => 'API key not valid',
        'estado' => 'error',
        'motivo' => 'API key not valid',
    ],
    [
        // Le pasa a los documentos oficiales publicados (EDECOMP, Marco de
        // Cualificaciones): el modelo no reproduce material publicado, y decirle
        // a quien administra que "el PDF no es legible" lo manda a arreglar algo
        // que está perfecto.
        'desc' => 'negativa a reproducir material publicado no se confunde con un PDF ilegible',
        'texto' => null,
        'error' => '',
        'finish' => 'RECITATION',
        'estado' => 'error',
        'motivo' => 'recitacion',
    ],
    [
        'desc' => 'la negativa se reconoce sin importar mayúsculas',
        'texto' => null,
        'error' => '',
        'finish' => 'recitation',
        'estado' => 'error',
        'motivo' => 'recitacion',
    ],
    [
        'desc' => 'un corte normal con texto válido sigue incorporándose',
        'texto' => 'Sección 1. Criterio de desempeño observable.',
        'error' => '',
        'finish' => 'STOP',
        'estado' => 'listo',
    ],
    [
        'desc' => 'sin texto y sin error es un fallo de extracción',
        'texto' => null,
        'error' => '',
        'estado' => 'error',
        'motivo' => 'sin_texto',
    ],
    [
        'desc' => 'texto en blanco no se toma por extracción válida',
        'texto' => "   \n  ",
        'error' => '',
        'estado' => 'error',
        'motivo' => 'sin_texto',
    ],
];

foreach ($casos as $caso) {
    $resultado = kb_store::classify_extraction($caso['texto'], $caso['error'], $caso['finish'] ?? '');
    local_chatbot_verificar($resultado['estado'] === $caso['estado'],
        "{$caso['desc']}: se esperaba '{$caso['estado']}' y se obtuvo '{$resultado['estado']}'");

    if (isset($caso['motivo'])) {
        local_chatbot_verificar($resultado['motivo'] === $caso['motivo'],
            "{$caso['desc']}: motivo esperado '{$caso['motivo']}', obtenido '{$resultado['motivo']}'");
    }
}

// El rechazo por claves tiene que nombrar qué marca se encontró: es lo que le
// permite a quien administra sanear el documento y volver a subirlo.
$conclave = kb_store::classify_extraction('La respuesta correcta: D explica el criterio.');
local_chatbot_verificar($conclave['estado'] === 'rechazado' && $conclave['motivo'] !== '',
    'el rechazo por claves informa la marca encontrada');

// --- Traducción del motivo guardado a la explicación que ve la persona ---
$traducciones = [
    ['claves:alternativa correcta', 'managekb_error_answerkeys', 'alternativa correcta'],
    ['timeout', 'managekb_error_timeout', null],
    ['recitacion', 'managekb_error_recitation', null],
    ['sin_archivo', 'managekb_error_nofile', null],
    ['sin_texto', 'managekb_error_noextract', null],
    ['', 'managekb_error_noextract', null],
    ['quota exceeded', 'managekb_error_detail', 'quota exceeded'],
];

foreach ($traducciones as [$statusmsg, $claveesperada, $aesperado]) {
    $descripcion = kb_store::describe_failure($statusmsg);
    local_chatbot_verificar($descripcion['clave'] === $claveesperada,
        "motivo '{$statusmsg}' debería explicarse con {$claveesperada}, y usa {$descripcion['clave']}");
    local_chatbot_verificar($descripcion['a'] === $aesperado,
        "motivo '{$statusmsg}': parámetro esperado '" . var_export($aesperado, true) .
        "', obtenido '" . var_export($descripcion['a'], true) . "'");
}

// Un motivo nulo (fila sin statusmsg) no puede reventar la explicación.
$nulo = kb_store::describe_failure(null);
local_chatbot_verificar($nulo['clave'] === 'managekb_error_noextract',
    'un motivo vacío se explica como fallo de extracción');

// --- Estados de la carga ---
local_chatbot_verificar(kb_store::STATUS_PENDING !== kb_store::STATUS_READY
    && kb_store::STATUS_READY !== kb_store::STATUS_ERROR,
    'los estados de carga son distinguibles entre sí');

$total = 0;
foreach ($casos as $caso) {
    $total += isset($caso['motivo']) ? 2 : 1;
}
$total += 1 + (count($traducciones) * 2) + 1 + 1;

// --- Ambitos de la base de conocimiento (30-10-2026): Tutor del Modulo 1 y Asistente de Diseño Instruccional ---
local_chatbot_verificar(kb_store::ambitos() === ['m1', 'm2'], 'hay dos ámbitos: m1 (Tutor) y m2 (Asistente de Diseño Instruccional)');
local_chatbot_verificar(kb_store::normalize_ambito('m2') === 'm2' && kb_store::normalize_ambito('m1') === 'm1'
    && kb_store::normalize_ambito('otro') === 'm1' && kb_store::normalize_ambito(null) === 'm1',
    'un ámbito desconocido o vacío cae en el del Módulo 1 (los documentos anteriores siguen con el Tutor)');

// --- Libreria versionada del Modulo 2 (deploy/libreria_m2, cargada con cli/cargar_libreria.php) ---
local_chatbot_verificar(kb_store::library_file("\xEF\xBB\xBF# Guía de llenado\r\n\r\nTexto.\r\n")
    === ['Guía de llenado', "# Guía de llenado\n\nTexto."], 'el nombre del documento es su título «# ...»');
local_chatbot_verificar(kb_store::library_file("Texto sin título\n# Tarde") === null
    && kb_store::library_file("## Subtítulo\nx") === null && kb_store::library_file('') === null,
    'sin título en la primera línea el archivo no se carga');
$dirlib = __DIR__ . '/../../../../../deploy/libreria_m2';
$libreria = is_dir($dirlib) ? glob($dirlib . '/*.md') : [];
$nombreslib = [];
foreach ($libreria as $ruta) {
    $doc = kb_store::library_file((string) file_get_contents($ruta));
    local_chatbot_verificar($doc !== null && kb_store::answer_key_markers($doc[1]) === [],
        basename($ruta) . ': tiene título y no trae marcas de claves de evaluación');
    $nombreslib[] = $doc[0] ?? '';
}
// La carpeta no viaja al servidor con el plugin: si no esta (CI del plugin solo), esta parte no aplica.
local_chatbot_verificar(!$libreria || $nombreslib === [
    'Checklist Validación Ficha Diagnóstico Ex Ante - Interfases',
    'Ficha Diagnóstico Ex Ante - Trabajo de Campo - Interfases',
    'Guía Llenado Ficha Diagnóstico Ex Ante - Interfases',
    'Ejemplo de Llenado - Ficha Diagnóstico Ex Ante - Cuidados Personas Mayores',
    'Marco de Cualificaciones Técnico-Profesional (MCTP) - MINEDUC 2017',
    'Manual Estrategias Didácticas y de Evaluación para el Desarrollo de Competencias (EDECOMP) - 2021',
    'Guía de Elaboración de Planes Formativos - SENCE y ChileValora',
    'Rúbrica Validación Técnica Diseño - Interfases',
], 'la librería del Módulo 2 trae los ocho documentos del cliente en orden 01…08');
$total += 2 + 2 + count($libreria) + 1;
printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);

if ($fallidos === 0) {
    echo "Carga de la base de conocimiento conforme a RU-KB-001.\n";
    echo "  Un documento con claves de evaluación no se incorpora por ninguna vía,\n";
    echo "  y un corte por tiempo de espera se explica como tal, no como PDF ilegible.\n";
    exit(0);
}

echo "Carga NO conforme: revisar classes/kb_store.php antes de desplegar.\n";
exit(1);

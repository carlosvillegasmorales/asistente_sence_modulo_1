<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Modulo 2, Centro de Produccion de Recursos de Aprendizaje (Etapas 9 a 12).
 *
 * Implementa la Especificacion Funcional, la Guia de Uso y el Prompt Complementario de Interfases (19-11-2026):
 *  - Funciona de manera independiente al proceso de diseno: lista todos los disenos validados (>= 25 pts, etapa 7
 *    confirmada) de la persona facilitadora, trabajando uno a la vez con su propio plan y repositorio.
 *  - Etapa 9: Plan de produccion a partir de la Etapa 4 (tambien en disenos anteriores sin modos declarados).
 *  - Etapa 10: Carga opcional y reutilizable de materiales tecnicos de referencia (PDF, Word, Excel, imagen),
 *    estructura aprobada (10.2), produccion segun Modo A/B/C (10.3) y ajustes por seccion (10.4).
 *  - Etapa 11: Entrega en el formato de salida y registro versionado (v1, v2...) con conteo de marcas [REVISAR].
 *  - Etapa 12: Cierre del centro y repertorio descargable (.zip + indice) exclusivo para la persona facilitadora.
 *
 * Todo lo que no consulta BD es funcion pura y lo ejercita cli/verificar_centro_recursos.php.
 */
class centro_recursos {

    /** @var string Version del prompt complementario del Centro de Produccion. */
    const PROMPT_VERSION = 'M2-CR-v2';

    /** Titulo de la seccion de referencias web que arma la plataforma al final del material. */
    const WEB_REFERENCIAS = 'Referencias consultadas';

    /** @var string Area de archivos en Moodle File API para materiales tecnicos de referencia. */
    const FILEAREA_REF = 'centro_ref';

    /** @var int Tamano maximo de cada archivo de referencia (10 MB, igual que exante::ADJUNTO_MAX). */
    const REF_MAX_BYTES = 10485760;

    /** @var int Maximo de archivos de referencia activos por material. */
    const REF_MAX_ACTIVOS = 5;

    /** @var int Segundos antes de dar por caida una generacion en curso. */
    const PROCESO_MAX = 360;

    /** Estados del plan. */
    const PLAN_SIN_INICIAR = 'sin_iniciar';
    const PLAN_EN_PRODUCCION = 'en_produccion';
    const PLAN_CERRADO = 'cerrado';

    /** Estados de un material en el repositorio. */
    const MAT_PENDIENTE = 'pendiente';
    const MAT_ESTRUCTURA = 'estructura';
    const MAT_BORRADOR = 'borrador';
    const MAT_ENTREGADO = 'entregado';
    const MAT_ENTREGADO_PARCIAL = 'entregado_parcial';
    const MAT_NO_PRODUCIBLE = 'no_producible';
    const MAT_INSTRUMENTO = 'instrumento';

    /** Fases de generacion asincrona de un material. */
    const GEN_ESTRUCTURA = 'estructura';
    const GEN_CONTENIDO = 'contenido';
    const GEN_AJUSTE = 'ajuste';

    /** @var string Mensaje textual obligatorio cuando la persona no tiene disenos disponibles (>= 25 pts). */
    const SIN_DISENOS = 'Aún no tienes diseños disponibles para el centro de producción. Un diseño se habilita cuando '
        . 'está consolidado y aprobado (25 puntos o más en la evaluación del diseño).';

    /** @var string[] Parrafos textuales de la Bienvenida obligatoria del Centro. */
    const BIENVENIDA = [
        'Bienvenido/a al centro de producción de recursos de aprendizaje. Aquí produciremos, uno a la vez, los '
            . 'materiales instruccionales de tu diseño validado, tal como fueron especificados. Para cada material te '
            . 'propondré primero su estructura y, cuando la apruebes, el material completo. Tú revisas cada propuesta y '
            . 'puedes pedir ajustes. Los materiales se entregan listos para copiar y pegar, en código HTML o como archivo '
            . 'descargable, para usarlos en Moodle, en otra plataforma o en formato impreso con las personas participantes.',
        'Primero te mostraré tus diseños validados disponibles para que elijas con cuál quieres trabajar.',
    ];

    /** @var string Respuesta textual obligatoria del Principio 1 ante pedidos que alteran el diseno validado. */
    const RESPUESTA_NO_MODIFICA = 'Ese ajuste implica modificar el diseño validado, que no se puede cambiar desde el '
        . 'centro de producción. Puedo ajustar la redacción, los ejemplos o el formato del material dentro de lo '
        . 'especificado.';

    /** @var string Pie obligatorio de todo material producido. */
    const PIE_MATERIAL = 'Material producido con apoyo de IA. Revisado por la persona facilitadora antes de su uso.';

    /** @var string Cierres textuales de las etapas 9, 11 y 12. */
    const CIERRE_ETAPA_9 = 'Este es el plan de producción de tu diseño. ¿Con qué material quieres comenzar?';
    const CIERRE_ETAPA_11 = 'El material quedó registrado. ¿Quieres producir otro material del plan, trabajar con otro '
        . 'diseño o cerrar el centro de producción?';
    const CIERRE_ETAPA_12 = 'Cerramos el centro de producción para este diseño. Puedes volver cuando quieras para '
        . 'producir los materiales pendientes, una nueva versión de un material o los materiales de otro diseño validado.';

    /** @var string Preguntas textuales del prompt complementario: seleccion del diseno, 10.1 y 10.4. */
    const PREGUNTA_DISENO = '¿Con qué diseño quieres trabajar?';
    const PREGUNTA_REFERENCIAS = '¿Quieres cargar materiales técnicos propios de referencia para producir este recurso '
        . '(por ejemplo, manuales, fichas técnicas, procedimientos o normas)? Puedes cargarlos en PDF, Word, Excel o imagen, '
        . 'o indicar materiales que ya cargaste para otro recurso de este diseño.';
    const PREGUNTA_REVISION = 'Revisa el material. ¿Apruebas el material o quieres ajustar alguna sección?';

    /** @var string[] Los 7 principios textuales del Prompt Complementario del Centro de Produccion. */
    const PRINCIPIOS = [
        'PRINCIPIOS DEL CENTRO:',
        '1. NO MODIFICAS EL DISEÑO VALIDADO',
        '- Produces cada material con los contenidos, la secuencia, la extensión, la estructura y el formato especificados '
            . 'en el diseño.',
        '- No puedes cambiar el diseño ni proponer, sugerir o inducir cambios en él: no comentes que un material, una '
            . 'actividad o un criterio podría mejorarse, ampliarse o reemplazarse.',
        '- Si la persona facilitadora solicita un ajuste que implica cambiar el diseño (por ejemplo, agregar un criterio, '
            . 'cambiar una actividad o un tiempo), responde exactamente: "' . self::RESPUESTA_NO_MODIFICA . '"',
        '2. REVISIÓN POR LA PERSONA FACILITADORA',
        'La persona facilitadora revisa y aprueba la estructura y el contenido de cada material. No avanzas a la '
            . 'siguiente etapa ni al siguiente material sin su aprobación.',
        '3. EXACTITUD TÉCNICA',
        '- Usa solo valores técnicos, normativos y de seguridad que provengan del diseño, del plan formativo, del perfil '
            . 'ocupacional o de los materiales de referencia cargados por la persona facilitadora.',
        '- Si un contenido técnico, normativo o de seguridad no proviene de esas fuentes, márcalo en el lugar exacto con '
            . 'la marca: [REVISAR: motivo]. Ejemplo: [REVISAR: valor de capacidad de carga no informado en el diseño].',
        '- Nunca inventes normas, códigos, valores límite, capacidades ni procedimientos de seguridad.',
        '- ' . exante::REGLA_FUENTES_CHILE,
        '- Los casos, nombres, empresas y datos que crees son ficticios y deben declararse como tales en el material.',
        '4. ECONOMÍA DE RECURSOS DE IA',
        '- Propón la estructura antes de producir el contenido completo.',
        '- Produce un material por turno.',
        '- Ajusta solo las secciones observadas: no regeneres el material completo.',
        '- Reutiliza los contenidos ya producidos en otros materiales del mismo diseño, remitiendo a ellos en lugar de '
            . 'repetirlos.',
        '- Genera las versiones impresa y digital de un material desde una sola fuente.',
        '- Ajusta la extensión al tiempo de uso previsto en el diseño.',
        '- No agregues explicaciones, introducciones ni contenidos que no estén especificados.',
        '5. MATERIALES DE REFERENCIA DE LA PERSONA FACILITADORA',
        '- Úsalos solo para desarrollar los contenidos especificados en el diseño: no amplían ni modifican los '
            . 'contenidos, la extensión ni el alcance del material.',
        '- Si un material de referencia contradice el diseño o el plan formativo, sigue el diseño y señala la diferencia '
            . 'con la marca [REVISAR: diferencia con el material de referencia «nombre»].',
        '- Si un material de referencia no se relaciona con el material que se está produciendo, indícalo y no lo uses.',
        '- No reproduzcas pasajes extensos en forma textual: sintetiza y adapta al grupo, e indica la fuente al final '
            . 'del material.',
        '- Si contienen datos personales o información confidencial de una empresa, no los traslades al material: usa '
            . 'datos ficticios equivalentes.',
        '- Si un material de referencia es extenso, pide a la persona facilitadora que indique las secciones o páginas '
            . 'pertinentes, para usar solo esa parte.',
        '- Los materiales cargados quedan disponibles en el repositorio del diseño y la persona facilitadora puede decidir '
            . 'reutilizarlos en otros materiales del mismo diseño.',
        '6. LENGUAJE',
        '- Usa lenguaje inclusivo con perspectiva de género.',
        '- Usa terminología de formación de personas adultas y formación laboral; nunca uses los conceptos «pedagógico» '
            . 'o «pedagogía».',
        '- Usa «experiencia de aprendizaje», nunca «sesión», para referirte a la experiencia diseñada.',
        '- Adecua el nivel de lectura a las características del grupo registradas en la ficha de diagnóstico.',
        '7. SIN CONEXIÓN CON EL LMS DEL ORGANISMO EJECUTOR',
        'No cargas ni publicas recursos en ninguna plataforma. Entregas cada material listo para copiar y pegar, en código '
            . 'HTML o como archivo descargable, para que la persona facilitadora lo configure en el Moodle del organismo '
            . 'ejecutor, en otra plataforma o lo use en formato impreso o digital con las personas participantes.',
    ];

    /** @var string[] Control de calidad interno del Centro. */
    const CONTROL_CALIDAD = [
        'CONTROL DE CALIDAD INTERNO:',
        'Antes de entregar cada material, verifica:',
        '- Que corresponde a la especificación del diseño y a la estructura aprobada.',
        '- Que no propone ni induce cambios en el diseño.',
        '- Que los contenidos técnicos, normativos y de seguridad provienen de las fuentes autorizadas o llevan la marca [REVISAR].',
        '- Que los materiales de referencia se usaron solo para los contenidos especificados, sin reproducir pasajes extensos ni datos personales o confidenciales, y que sus fuentes están indicadas.',
        '- Que los casos y datos ficticios están declarados como tales.',
        '- Que el formato de salida es válido (sintaxis GIFT, HTML compatible con Moodle, script ejecutable, archivo editable).',
        '- En Modo C, que los enlaces están verificados y cumplen los criterios de selección.',
        '- Que se respetan las reglas de lenguaje.',
        'Si detectas un error, corrígelo antes de entregar el material.',
    ];

    // ------------------------------------------------------------------------------------------------------------
    // Funciones puras (sin BD).
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Catalogo de capacidades reales habilitadas en la plataforma (sin servicios externos de Azure).
     *
     * @param bool $conbusqueda Si la busqueda web esta disponible para Modo C.
     * @return string
     */
    public static function catalog_text(bool $conbusqueda = true): string {
        $l = [
            'CATÁLOGO DE CAPACIDADES DE PRODUCCIÓN DE LA PLATAFORMA:',
            '- Modo A (Producción completa con IA): texto estructurado para copiar y pegar, guías, casos, fichas técnicas, '
                . 'glosarios, guiones, diagramas en bloques «diagrama» y código HTML limpio compatible con el editor de Moodle.',
            '- Modo B (Producción con IA y conversión local en la plataforma):',
            '  · Documentos Word editables (.docx) con formato institucional.',
            '  · Planillas y plantillas Excel editables (.xlsx) con varias hojas y fórmulas básicas.',
            '  · Presentaciones editables (.pptx) con plantilla institucional y notas para la persona facilitadora.',
            '  · Documentos PDF imprimibles (.pdf) en tamaño Carta, A4, A4 horizontal, A3, A2 o tarjetas 9 × 6 cm, con '
                . 'pictogramas vectoriales simples ([Pictograma: descripción]); no produce ilustraciones artísticas ni fotografías.',
            '  · Cuestionarios desde una sola fuente: archivo GIFT (.txt UTF-8) para Moodle, script de Google Apps Script '
                . '(.gs) para Google Forms y versión imprimible en Word (.docx) con pauta de respuestas por separado.',
            $conbusqueda
                ? '- Modo C (Curaduría de recursos existentes): búsqueda en internet habilitada (Google Search grounding) '
                    . 'con verificación HTTP automática de enlaces en la plataforma (hasta 5 opciones de libre acceso).'
                : '- Modo C (Curaduría de recursos existentes): búsqueda en internet deshabilitada en este servidor; '
                    . 'clasifica materiales que exijan búsqueda web como «No producible por el centro».',
            '- Exclusiones: recursos físicos (equipos, herramientas, insumos, EPP, espacios) no entran al plan; '
                . 'instrumentos de evaluación del diseño (Etapa 6) se marcan «Instrumento de evaluación del diseño» y se excluyen.',
        ];
        return implode("\n", $l);
    }

    /**
     * Si un diseno cumple la condicion de activacion del Centro: consolidado (Etapa 7) y evaluado con >= 25 puntos.
     *
     * @param array $estadoRuta Resultado de ruta_m2::state_for().
     * @return bool
     */
    public static function eligible_design(array $estadoRuta): bool {
        $eval = $estadoRuta['evaluacion'] ?? null;
        return !empty($estadoRuta['evaluado']) && is_array($eval)
            && (int) ($eval['total'] ?? 0) >= diseno::APROBADO_DESDE
            && in_array(diseno::CONSOLIDACION, (array) ($estadoRuta['aceptadas'] ?? []), true);
    }

    /**
     * Tipo de archivo de referencia admitido por su contenido real.
     *
     * @param string $mime
     * @param string $nombre
     * @return string|null 'pdf', 'docx', 'xlsx', 'imagen' o null si no se admite.
     */
    public static function reference_kind(string $mime, string $nombre): ?string {
        $ext = mb_strtolower(pathinfo($nombre, PATHINFO_EXTENSION), 'UTF-8');
        if ($ext === 'pdf' && $mime === 'application/pdf') {
            return 'pdf';
        }
        if ($ext === 'docx' && in_array($mime, ['application/zip', 'application/octet-stream',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
            return 'docx';
        }
        if (in_array($ext, ['xlsx', 'xls'], true) && in_array($mime, ['application/zip', 'application/octet-stream',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel'], true)) {
            return 'xlsx';
        }
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)
                && in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return 'imagen';
        }
        return null;
    }

    /**
     * Extrae todas las marcas [REVISAR] o [REVISAR: motivo] de un texto o HTML.
     *
     * @param string $texto
     * @return string[]
     */
    public static function extract_review_marks(string $texto): array {
        $plano = html_entity_decode(strip_tags($texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/\[REVISAR(?:\s*:\s*[^\]]+)?\]/u', $plano, $m);
        return $m[0] ?? [];
    }

    /**
     * Numero exacto de marcas [REVISAR] en un texto o HTML.
     *
     * @param string $texto
     * @return int
     */
    public static function count_review_marks(string $texto): int {
        return count(self::extract_review_marks($texto));
    }

    /**
     * Resalta visualmente las marcas [REVISAR: ...] y renderiza pictogramas SVG en el HTML de pantalla.
     *
     * @param string $html
     * @return string
     */
    public static function highlight_review_marks(string $html): string {
        $html = recurso_export::render_pictograms($html, 22);
        return (string) preg_replace_callback('/\[REVISAR(?:\s*:\s*[^\]]+)?\]/u', static function(array $m): string {
            return '<span class="badge bg-warning text-dark cr-revisar">'
                . htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8') . '</span>';
        }, $html);
    }

    /**
     * Detecta si un material corresponde a un cuestionario por su nombre o formato.
     *
     * @param string $nombre
     * @param string $formato
     * @return bool
     */
    public static function is_quiz_material(string $nombre, string $formato): bool {
        $t = mb_strtolower($nombre . ' ' . $formato, 'UTF-8');
        return preg_match('/cuestionario|quiz|\bgift\b|google\s*forms|preguntas\s+formativas/u', $t) === 1;
    }

    /**
     * Determina los formatos de descarga disponibles para un material segun su modo y especificacion.
     *
     * @param string $modo 'A', 'B', 'C' o combinacion ('B/C').
     * @param string $formato Texto del formato de salida.
     * @param string $nombre Nombre del material.
     * @param string $plataformaQuiz '', 'moodle', 'google', 'impreso' o 'todas'.
     * @return string[] Claves de formato: 'html', 'docx', 'xlsx', 'pptx', 'pdf', 'gift', 'gs', 'quiz_docx', 'curaduria'.
     */
    public static function detect_formats(string $modo, string $formato, string $nombre = '',
            string $plataformaQuiz = ''): array {
        $m = strtoupper(trim($modo));
        $f = mb_strtolower($formato . ' ' . $nombre, 'UTF-8');
        $out = [];
        if ($m === 'C') {
            return ['curaduria', 'docx'];
        }
        if (self::is_quiz_material($nombre, $formato)) {
            $pq = mb_strtolower(trim($plataformaQuiz), 'UTF-8');
            if ($pq === 'moodle') {
                return ['gift', 'quiz_docx'];
            }
            if ($pq === 'google') {
                return ['gs', 'quiz_docx'];
            }
            if ($pq === 'impreso') {
                return ['quiz_docx', 'pdf'];
            }
            return ['gift', 'gs', 'quiz_docx'];
        }
        if (strpos($m, 'A') !== false || preg_match('/html|moodle|texto|guion|glosario|caso/u', $f)) {
            $out[] = 'html';
        }
        if (preg_match('/excel|xlsx|planilla|hoja de c[aá]lculo/u', $f)) {
            $out[] = 'xlsx';
        }
        if (preg_match('/presentaci[oó]n|pptx|powerpoint|diapositiva/u', $f)) {
            $out[] = 'pptx';
        }
        if (preg_match('/\bpdf\b|afiche|tarjeta|tablero|mapa|infograf|plastific|imprimir|bit[aá]cora/u', $f)) {
            $out[] = 'pdf';
        }
        if (preg_match('/word|docx|manual|dossier|gu[ií]a|ficha|pauta|documento/u', $f) || $m === 'B' || !$out) {
            $out[] = 'docx';
        }
        return array_values(array_unique($out));
    }

    /**
     * Divide un material mixto del plan (modo «B/C», «A/B» o «A/C») en una fila por parte: codigo «M2-B», «M2-C», modo
     * de la parte y su formato. Usa las partes que declara el asistente y, si faltan, las deduce del formato. Un
     * material de un solo modo vuelve tal cual. Funcion pura.
     *
     * @param array $fila Material ya normalizado por parse_plan_json().
     * @param array $partesModelo [['modo' => 'C', 'nombre' => '...', 'formato' => '...'], ...] (opcional).
     * @return array[]
     */
    public static function split_mixed(array $fila, array $partesModelo = []): array {
        $letras = array_values(array_unique(array_filter(array_map('trim', explode('/', (string) $fila['modo'])),
            static fn($x) => in_array($x, ['A', 'B', 'C'], true))));
        if (count($letras) < 2) {
            $fila['modo'] = $letras[0] ?? 'B';
            return [$fila];
        }
        $porModo = [];
        foreach ($partesModelo as $p) {
            $mm = is_array($p) ? strtoupper(trim((string) ($p['modo'] ?? ''))) : '';
            if (in_array($mm, $letras, true) && !isset($porModo[$mm])) {
                $porModo[$mm] = $p;
            }
        }
        $filas = [];
        foreach ($letras as $letra) {
            $p = $porModo[$letra] ?? [];
            $desc = trim((string) ($p['nombre'] ?? ''));
            $formato = trim((string) ($p['formato'] ?? ''));
            $f = $fila;
            $f['codigo'] = mb_substr((string) $fila['codigo'], 0, 17, 'UTF-8') . '-' . $letra;
            $f['nombre'] = mb_substr($fila['nombre'] . ' · parte ' . $letra . ($desc !== '' ? ': ' . $desc : ''), 0, 250, 'UTF-8');
            $f['modo'] = $letra;
            $f['formato'] = mb_substr($formato !== '' ? $formato : self::part_format($letra, (string) $fila['formato']), 0, 180, 'UTF-8');
            $filas[] = $f;
        }
        return $filas;
    }

    /**
     * Formato de salida de una parte cuando el asistente no lo indico: la parte C es una ficha de recomendacion; las
     * partes A y B toman del formato del material lo que no es video ni enlace. Funcion pura.
     *
     * @param string $letra 'A', 'B' o 'C'.
     * @param string $formato Formato del material completo.
     * @return string
     */
    public static function part_format(string $letra, string $formato): string {
        if ($letra === 'C') {
            return 'Ficha de recomendación';
        }
        $resto = implode(' / ', array_filter(array_map('trim', preg_split('/\s*[\/,;]\s*/u', $formato)),
            static fn($t) => $t !== '' && !preg_match('/v[ií]deo|youtube|enlace|curadur|recomendaci[oó]n|simulador/u',
                mb_strtolower($t, 'UTF-8'))));
        if ($resto !== '') {
            return $resto;
        }
        return $letra === 'A' ? 'Texto y HTML para Moodle' : 'Word (.docx)';
    }

    /**
     * Codigo del material al que pertenece una parte («M2-C» → «M2»; «M5» → «M5»). Funcion pura.
     *
     * @param string $codigo
     * @return string
     */
    public static function base_code(string $codigo): string {
        return preg_match('/^(.+)-([ABC])$/u', trim($codigo), $m) ? $m[1] : trim($codigo);
    }

    /**
     * Letra de la parte («M2-C» → «C»; un material de un solo modo → «»). Funcion pura.
     *
     * @param string $codigo
     * @return string
     */
    public static function part_letter(string $codigo): string {
        return preg_match('/-([ABC])$/u', trim($codigo), $m) ? $m[1] : '';
    }

    /**
     * Agrupa las filas del plan por material (las partes de un material mixto juntas) con el estado del material:
     * todas las partes entregadas → Entregado; alguna → Entregado parcialmente; ninguna → Pendiente. Funcion pura.
     *
     * @param array $recursos Filas de local_chatbot_recurso.
     * @return array codigo base => ['filas' => [...], 'estado' => estado del material].
     */
    public static function material_groups(array $recursos): array {
        $grupos = [];
        foreach ($recursos as $i => $r) {
            $codigo = (string) ($r->codigo ?? '');
            $grupos[$codigo !== '' ? self::base_code($codigo) : '#' . $i][] = $r;
        }
        $fuera = [self::MAT_NO_PRODUCIBLE, self::MAT_INSTRUMENTO];
        $out = [];
        foreach ($grupos as $base => $filas) {
            $estados = array_map(static fn($r) => (string) ($r->estado ?? self::MAT_PENDIENTE), $filas);
            $prod = array_values(array_filter($estados, static fn($e) => !in_array($e, $fuera, true)));
            if (count($filas) === 1 || !$prod) {
                $estado = $estados[0];
            } else {
                $entregadas = count(array_filter($prod, static fn($e) => $e === self::MAT_ENTREGADO));
                $estado = $entregadas === count($prod) ? self::MAT_ENTREGADO
                    : ($entregadas > 0 || in_array(self::MAT_ENTREGADO_PARCIAL, $prod, true)
                        ? self::MAT_ENTREGADO_PARCIAL : self::MAT_PENDIENTE);
            }
            $out[(string) $base] = ['filas' => $filas, 'estado' => $estado];
        }
        return $out;
    }

    /**
     * Linea para los prompts de una parte de un material mixto (vacia si el material tiene un solo modo). Funcion pura.
     *
     * @param array $material ['codigo', 'modo', ...].
     * @return string
     */
    public static function part_note(array $material): string {
        $letra = self::part_letter((string) ($material['codigo'] ?? ''));
        if ($letra === '') {
            return '';
        }
        $modos = ['A' => 'Modo A (producción completa con IA)', 'B' => 'Modo B (producción con IA y servicios de la '
            . 'plataforma)', 'C' => 'Modo C (curaduría de recursos existentes)'];
        return '- Este material es la parte ' . $letra . ' del material ' . self::base_code((string) $material['codigo'])
            . ' del diseño, que combina partes en distintos modos: produce solo esta parte, en ' . $modos[$letra]
            . '. Las demás partes se producen por separado.';
    }

    /** @var string Marca del codigo de un material producido que ya no corresponde al plan vigente. */
    const MARCA_ANTERIOR = ' (ant.)';

    /**
     * Si dos nombres de material son el mismo material: comparten la mitad o mas de sus palabras significativas (de
     * cuatro letras o mas, sin tildes). Funcion pura.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    public static function same_material(string $a, string $b): bool {
        $palabras = static function(string $t): array {
            preg_match_all('/[a-z0-9]{4,}/', diseno::plain_lower($t), $m);
            return array_values(array_unique($m[0]));
        };
        [$x, $y] = [$palabras($a), $palabras($b)];
        if (!$x || !$y) {
            return diseno::plain_lower($a) === diseno::plain_lower($b);
        }
        return count(array_intersect($x, $y)) / min(count($x), count($y)) >= 0.5;
    }

    /**
     * Cambios para actualizar el plan con una Etapa 4 nueva (Etapa 9, «muéstralo actualizado»). Visto en el cliente el
     * 21-11-2026: la Etapa 4 rehecha con las reglas de materiales producibles renumero los materiales (M3 paso a ser otro)
     * y el plan conservaba las filas antiguas por codigo. Reglas: un pendiente (version 0) con el mismo codigo se actualiza
     * (y se reinicia si ahora es otro material); uno producido se conserva si es el mismo material y, si es otro o ya no esta
     * en el plan, queda con su codigo marcado «(ant.)» y su version; un pendiente que ya no esta se quita. Funcion pura.
     *
     * @param array $existentes Filas actuales (objetos o arreglos con id, codigo, nombre, version).
     * @param array $nuevos Materiales de parse_plan_json().
     * @return array ['update' => [id => ['m' => material, 'reiniciar' => bool]], 'insert' => [material], 'delete' => [id],
     *               'rename' => [id => codigo]].
     */
    public static function plan_merge(array $existentes, array $nuevos): array {
        $porcodigo = [];
        foreach ($nuevos as $m) {
            $porcodigo[strtoupper(trim((string) $m['codigo']))] = $m;
        }
        $cambios = ['update' => [], 'insert' => [], 'delete' => [], 'rename' => []];
        $usados = [];
        foreach ($existentes as $e) {
            $e = (array) $e;
            $codigo = trim((string) $e['codigo']);
            if (substr($codigo, -strlen(self::MARCA_ANTERIOR)) === self::MARCA_ANTERIOR) {
                continue;
            }
            $clave = strtoupper($codigo);
            $producido = (int) ($e['version'] ?? 0) > 0;
            $nuevo = $porcodigo[$clave] ?? null;
            if ($nuevo === null) {
                if ($producido) {
                    $cambios['rename'][(int) $e['id']] = mb_substr($codigo, 0, 13, 'UTF-8') . self::MARCA_ANTERIOR;
                } else {
                    $cambios['delete'][] = (int) $e['id'];
                }
                continue;
            }
            $mismo = self::same_material((string) $e['nombre'], (string) $nuevo['nombre']);
            if (!$producido) {
                $cambios['update'][(int) $e['id']] = ['m' => $nuevo, 'reiniciar' => !$mismo];
                $usados[$clave] = true;
            } else if ($mismo) {
                $usados[$clave] = true;
            } else {
                $cambios['rename'][(int) $e['id']] = mb_substr($codigo, 0, 13, 'UTF-8') . self::MARCA_ANTERIOR;
            }
        }
        foreach ($porcodigo as $clave => $m) {
            if (!isset($usados[$clave])) {
                $cambios['insert'][] = $m;
            }
        }
        return $cambios;
    }

    /**
     * Etiqueta legible del estado del centro para un diseno (tabla de Seleccion del diseno).
     *
     * @param object|null $plan
     * @param array $recursos Filas de local_chatbot_recurso.
     * @return string
     */
    public static function design_status_label(?object $plan, array $recursos): string {
        if (!$plan || $plan->estado === self::PLAN_SIN_INICIAR) {
            return 'Sin iniciar';
        }
        if ($plan->estado === self::PLAN_CERRADO) {
            return 'Cerrado';
        }
        $producibles = 0;
        $entregados = 0;
        foreach (self::material_groups($recursos) as $base => $grupo) {
            $est = (string) $grupo['estado'];
            // Lo producido de una version anterior del diseno («(ant.)») no es material del plan vigente.
            if (in_array($est, [self::MAT_NO_PRODUCIBLE, self::MAT_INSTRUMENTO], true)
                    || substr((string) $base, -strlen(self::MARCA_ANTERIOR)) === self::MARCA_ANTERIOR) {
                continue;
            }
            $producibles++;
            if (in_array($est, [self::MAT_ENTREGADO, self::MAT_ENTREGADO_PARCIAL], true)) {
                $entregados++;
            }
        }
        return "En producción: {$entregados} de {$producibles}";
    }

    /**
     * Verifica que una URL sea http(s) publica (bloquea esquemas peligrosos y rangos privados/localhost contra SSRF).
     *
     * @param string $url
     * @return bool
     */
    public static function is_safe_public_url(string $url): bool {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        return strpos($host, '.') !== false;
    }

    /**
     * Referencia de un video de YouTube o Vimeo: plataforma, id, si el id tiene un formato posible y la direccion de
     * oEmbed que confirma que el video existe y es publico. Null si el enlace no es de esas plataformas. Funcion pura.
     *
     * Visto al probar en el VPS del cliente el 19-11-2026: el asistente invento enlaces como «watch?v=VocesMayoresINDH»
     * y YouTube responde 200 aunque el video no exista, asi que el codigo HTTP no basta.
     *
     * @param string $url
     * @return array|null ['plataforma' => 'youtube'|'vimeo', 'id' => string, 'valido' => bool, 'oembed' => string].
     */
    public static function video_ref(string $url): ?array {
        $p = parse_url(trim($url));
        if (!is_array($p)) {
            return null;
        }
        $host = (string) preg_replace('/^(www\.|m\.)/', '', strtolower((string) ($p['host'] ?? '')));
        $ruta = (string) ($p['path'] ?? '');
        if (in_array($host, ['youtube.com', 'youtu.be', 'youtube-nocookie.com', 'music.youtube.com'], true)) {
            if ($host === 'youtu.be') {
                $id = trim($ruta, '/');
            } else {
                parse_str((string) ($p['query'] ?? ''), $q);
                $id = (string) ($q['v'] ?? '');
                if ($id === '' && preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $ruta, $m)) {
                    $id = $m[1];
                }
            }
            return ['plataforma' => 'youtube', 'id' => $id, 'valido' => preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1,
                'oembed' => 'https://www.youtube.com/oembed?format=json&url='
                    . rawurlencode('https://www.youtube.com/watch?v=' . $id)];
        }
        if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
            $id = preg_match('#/(?:video/)?(\d{5,})#', $ruta, $m) ? $m[1] : '';
            return ['plataforma' => 'vimeo', 'id' => $id, 'valido' => $id !== '',
                'oembed' => 'https://vimeo.com/api/oembed.json?url=' . rawurlencode('https://vimeo.com/' . $id)];
        }
        return null;
    }

    /**
     * Verifica por HTTP que un enlace exista y sea accesible (Modo C: «Solo se recomiendan recursos con enlace verificado»).
     * Los videos de YouTube y Vimeo se verifican con oEmbed (existen y son publicos) y devuelven su titulo y canal reales.
     * Resuelve redirecciones y rechaza direcciones privadas o codigos >= 400.
     *
     * @param string $url
     * @param int $timeout Segundos.
     * @return array ['ok' => bool, 'url' => string, 'code' => int].
     */
    public static function verify_url(string $url, int $timeout = 8): array {
        $url = trim($url);
        if (!self::is_safe_public_url($url) || !function_exists('curl_init')) {
            return ['ok' => false, 'url' => $url, 'code' => 0];
        }
        // Comprobacion DNS para evitar SSRF via dominio que resuelve a IP privada.
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ip = gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['ok' => false, 'url' => $url, 'code' => 0];
        }
        $video = self::video_ref($url);
        if ($video !== null) {
            if (!$video['valido']) {
                return ['ok' => false, 'url' => $url, 'code' => 0];
            }
            $ch = curl_init($video['oembed']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; SENCE-CentroRecursos/1.0)');
            $cuerpo = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $datos = json_decode($cuerpo, true);
            $titulo = is_array($datos) ? trim((string) ($datos['title'] ?? '')) : '';
            return ['ok' => $code === 200 && $titulo !== '', 'url' => $url, 'code' => $code, 'titulo' => $titulo,
                'autor' => is_array($datos) ? trim((string) ($datos['author_name'] ?? '')) : ''];
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; SENCE-CentroRecursos/1.0)');
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        // Algunos servidores (como YouTube o sitios institucionales) rechazan HEAD con 403/405: reintentar con GET corto.
        if (in_array($code, [403, 405], true) || $code === 0) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_RANGE, '0-2048');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; SENCE-CentroRecursos/1.0)');
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
        }
        $finalOk = $final !== '' && self::is_safe_public_url($final) ? $final : $url;
        return ['ok' => $code >= 200 && $code < 400 && self::is_safe_public_url($finalOk), 'url' => $finalOk, 'code' => $code];
    }

    /**
     * Filtra la ficha de curaduria (Modo C) dejando unicamente opciones cuyo enlace fue verificado. Funcion pura.
     *
     * @param array $opciones Lista de opciones de parse_curaduria_json().
     * @param callable $verificador fn(string $url): array ['ok' => bool, 'url' => string, 'code' => int].
     * @return array ['verificadas' => array, 'descartadas' => int].
     */
    public static function filter_verified_links(array $opciones, callable $verificador): array {
        $verificadas = [];
        $descartadas = 0;
        foreach ($opciones as $op) {
            $enlace = trim((string) ($op['enlace'] ?? ''));
            if ($enlace === '' || !self::is_safe_public_url($enlace)) {
                $descartadas++;
                continue;
            }
            $res = $verificador($enlace);
            if (!empty($res['ok'])) {
                $op['enlace'] = (string) ($res['url'] ?? $enlace);
                // En un video, titulo y canal son los que informa la plataforma, no los que escribio el asistente.
                if (trim((string) ($res['titulo'] ?? '')) !== '') {
                    $op['titulo'] = (string) $res['titulo'];
                }
                if (trim((string) ($res['autor'] ?? '')) !== '') {
                    $op['fuente'] = (string) $res['autor'];
                }
                $verificadas[] = $op;
            } else {
                $descartadas++;
            }
            if (count($verificadas) >= 5) {
                break;
            }
        }
        return ['verificadas' => $verificadas, 'descartadas' => $descartadas];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Parsers de salidas estructuradas del modelo (Etapa 9, Modo C y Modo B).
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Decodifica el JSON del Plan de produccion (Etapa 9). Funcion pura.
     *
     * @param string|null $texto Respuesta del modelo.
     * @return array|null ['materiales' => [...], 'excluidos_fisicos' => string[]].
     */
    public static function parse_plan_json(?string $texto): ?array {
        $json = exante::decode_json($texto);
        if (!$json || !isset($json['materiales']) || !is_array($json['materiales'])) {
            return null;
        }
        $lista = [];
        $idx = 0;
        foreach ($json['materiales'] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $nombre = trim((string) ($m['nombre'] ?? ''));
            if ($nombre === '') {
                continue;
            }
            $idx++;
            $codigo = trim((string) ($m['codigo'] ?? ('M' . $idx)));
            $modoRaw = strtoupper(trim((string) ($m['modo'] ?? 'B')));
            $modo = in_array($modoRaw, ['A', 'B', 'C', 'A/B', 'B/C', 'A/C'], true) ? $modoRaw : 'B';
            $estadoRaw = mb_strtolower(trim((string) ($m['estado'] ?? 'pendiente')), 'UTF-8');
            if (strpos($estadoRaw, 'instrumento') !== false) {
                $estado = self::MAT_INSTRUMENTO;
            } else if (strpos($estadoRaw, 'no producible') !== false || $estadoRaw === 'no_producible') {
                $estado = self::MAT_NO_PRODUCIBLE;
            } else {
                $estado = self::MAT_PENDIENTE;
            }
            $especRaw = mb_strtolower(trim((string) ($m['especificacion'] ?? 'completa')), 'UTF-8');
            $espec = strpos($especRaw, 'incomplet') !== false ? 'incompleta' : 'completa';
            $fila = [
                'orden' => $idx,
                'codigo' => mb_substr($codigo, 0, 20, 'UTF-8'),
                'nombre' => mb_substr($nombre, 0, 250, 'UTF-8'),
                'fase' => mb_substr(trim((string) ($m['fase'] ?? '')), 0, 180, 'UTF-8'),
                'modo' => $modo,
                'formato' => mb_substr(trim((string) ($m['formato'] ?? 'Word (.docx)')), 0, 180, 'UTF-8'),
                'especificacion' => $espec,
                'espec_detalle' => trim((string) ($m['faltantes'] ?? ($m['espec_detalle'] ?? ''))),
                'estado' => $estado,
            ];
            // Material mixto: una fila por parte, cada una en su modo (Etapa 9: «indica el modo de cada parte»).
            foreach ($estado === self::MAT_PENDIENTE ? self::split_mixed($fila, (array) ($m['partes'] ?? [])) : [$fila] as $f) {
                $lista[] = $f;
            }
        }
        if (!$lista) {
            return null;
        }
        $fisicos = [];
        foreach ((array) ($json['excluidos_fisicos'] ?? []) as $f) {
            $t = trim((string) $f);
            if ($t !== '') {
                $fisicos[] = $t;
            }
        }
        return ['materiales' => $lista, 'excluidos_fisicos' => $fisicos];
    }

    /**
     * Decodifica la ficha de curaduria del Modo C (hasta 5 opciones). Funcion pura.
     *
     * @param string|null $texto
     * @return array|null ['criterios_busqueda' => string, 'opciones' => [...]].
     */
    public static function parse_curaduria_json(?string $texto): ?array {
        $json = exante::decode_json($texto);
        if (!$json || !isset($json['opciones']) || !is_array($json['opciones'])) {
            return null;
        }
        $ops = [];
        foreach (array_slice($json['opciones'], 0, 8) as $op) {
            if (!is_array($op) || trim((string) ($op['titulo'] ?? '')) === '') {
                continue;
            }
            $ops[] = [
                'titulo' => trim((string) $op['titulo']),
                'fuente' => trim((string) ($op['fuente'] ?? '')),
                'duracion' => trim((string) ($op['duracion'] ?? 'No informada')),
                'idioma' => trim((string) ($op['idioma'] ?? 'Español')),
                'enlace' => trim((string) ($op['enlace'] ?? '')),
                'momento' => trim((string) ($op['momento'] ?? '')),
                'justificacion' => trim((string) ($op['justificacion'] ?? '')),
                'advertencias' => trim((string) ($op['advertencias'] ?? 'Ninguna.')),
            ];
        }
        return [
            'criterios_busqueda' => trim((string) ($json['criterios_busqueda'] ?? '')),
            'opciones' => $ops,
        ];
    }

    /**
     * Arma HTML limpio de la ficha de recomendacion (Modo C) a partir de las opciones verificadas.
     *
     * @param array $opciones
     * @param string $fechaVerif Fecha de verificacion (ej. 19-11-2026).
     * @param string $codigo Codigo del material.
     * @param array $seleccionados Indices (0-based) elegidos por la persona facilitadora.
     * @return string HTML.
     */
    public static function curaduria_html(array $opciones, string $fechaVerif, string $codigo,
            array $seleccionados = []): string {
        $e = static fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        if (!$opciones) {
            return '<p>No se encontraron recursos abiertos con enlace verificado que cumplan todos los criterios de '
                . 'búsqueda del diseño. Puedes ajustar los criterios o intentarlo nuevamente.</p>';
        }
        $html = '<p><strong>Ficha de recomendación (Modo C).</strong> Enlaces verificados el ' . $e($fechaVerif) . '.</p>'
            . '<table class="table table-sm"><thead><tr><th>N.º</th><th>Título · fuente</th><th>Duración / Idioma</th>'
            . '<th>Momento de uso</th><th>Justificación</th><th>Advertencias</th><th>Enlace verificado</th></tr></thead><tbody>';
        foreach (array_values($opciones) as $i => $op) {
            $marca = in_array($i, $seleccionados, true) ? ' <strong>(Seleccionado)</strong>' : '';
            $url = $e((string) ($op['enlace'] ?? ''));
            $html .= '<tr><td>' . ($i + 1) . $marca . '</td>'
                . '<td><strong>' . $e((string) $op['titulo']) . '</strong><br><small>' . $e((string) $op['fuente']) . '</small></td>'
                . '<td>' . $e((string) $op['duracion']) . '<br><small>' . $e((string) $op['idioma']) . '</small></td>'
                . '<td>' . $e((string) $op['momento']) . '</td>'
                . '<td>' . $e((string) $op['justificacion']) . '</td>'
                . '<td>' . $e((string) $op['advertencias']) . '</td>'
                . '<td><a href="' . $url . '" target="_blank" rel="noopener">Abrir recurso</a></td></tr>';
        }
        $html .= '</tbody></table>'
            . '<p><em>Revisa cada recurso completo antes de usarlo. Comparte el enlace; no descargues ni redistribuyas el recurso.</em></p>'
            . '<p><em>' . $e($codigo) . ' · ' . self::PIE_MATERIAL . '</em></p>';
        return $html;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Constructores de prompts (Etapas 9, 10.2, 10.3 y 10.4). Funciones puras.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Encabezados HTML (h1 a h6) como lineas Markdown («<h2>Parte 1</h2>» → «<p>## Parte 1</p>»), para que el material
     * actual llegue al ajuste con sus titulos y el asistente pueda devolver solo las secciones. Las negritas y cursivas
     * tambien pasan a Markdown: html_to_text() de Moodle pone las negritas en MAYUSCULAS y el asistente las copiaba asi
     * (visto al probar en el VPS del cliente el 19-11-2026). Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function headings_as_markdown(string $html): string {
        $html = (string) preg_replace_callback('/<h([1-6])[^>]*>(.*?)<\/h\1>/isu', static function(array $m): string {
            return '<p>' . str_repeat('#', (int) $m[1]) . ' ' . trim(strip_tags($m[2])) . '</p>';
        }, $html);
        $html = (string) preg_replace('/<(strong|b)\b[^>]*>(.*?)<\/\1>/isu', '**$2**', $html);
        return (string) preg_replace('/<(em|i)\b[^>]*>(.*?)<\/\1>/isu', '*$2*', $html);
    }

    /**
     * Secciones de un HTML por sus encabezados: [['nivel' => n, 'clave' => titulo normalizado, 'html' => encabezado y
     * cuerpo hasta el siguiente encabezado del mismo nivel o superior]]; lo anterior al primer encabezado va con nivel 0.
     * Funcion pura.
     *
     * @param string $html
     * @return array[]
     */
    public static function html_sections(string $html): array {
        $partes = preg_split('/(<h[1-6][^>]*>.*?<\/h[1-6]>)/isu', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $secciones = [['nivel' => 0, 'clave' => '', 'html' => '']];
        foreach ($partes as $p) {
            if (preg_match('/^<h([1-6])[^>]*>(.*?)<\/h\1>$/isu', $p, $m)) {
                $secciones[] = ['nivel' => (int) $m[1], 'clave' => self::heading_key($m[2]), 'html' => $p];
            } else {
                $secciones[count($secciones) - 1]['html'] .= $p;
            }
        }
        return $secciones;
    }

    /**
     * Titulo normalizado para comparar encabezados (sin etiquetas, numeracion inicial, tildes ni signos). Funcion pura.
     *
     * @param string $titulo
     * @return string
     */
    public static function heading_key(string $titulo): string {
        $t = diseno::plain_lower(html_entity_decode(strip_tags($titulo), ENT_QUOTES, 'UTF-8'));
        $t = preg_replace('/^[\d.\s·\-–—]+/u', '', $t);
        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $t));
    }

    /**
     * Aplica un ajuste por seccion (10.4): reemplaza en el material actual cada seccion que trae el ajuste, buscada por
     * su titulo, hasta el siguiente encabezado del mismo nivel o superior, y deja el pie obligatorio una sola vez al
     * final. Devuelve null si el ajuste no trae encabezados o alguno no existe en el material (no se adivina donde va).
     * Funcion pura.
     *
     * @param string $actual HTML del material actual.
     * @param string $parcial HTML de las secciones ajustadas.
     * @param string $pie Linea de cierre «codigo · pie».
     * @return string|null
     */
    public static function merge_sections(string $actual, string $parcial, string $pie): ?string {
        $sinpie = static fn(string $h): string => (string) preg_replace(
            '/<p>(?:(?!<\/p>).)*' . preg_quote(self::PIE_MATERIAL, '/') . '(?:(?!<\/p>).)*<\/p>/isu', '', $h);
        // Secciones del ajuste agrupadas: cada encabezado de primer nivel del ajuste con sus subsecciones como cuerpo.
        $nuevas = [];
        foreach (self::html_sections($sinpie($parcial)) as $s) {
            if ($s['nivel'] === 0) {
                continue;
            }
            $ultima = count($nuevas) - 1;
            if ($ultima >= 0 && $s['nivel'] > $nuevas[$ultima]['nivel']) {
                $nuevas[$ultima]['cuerpo'] .= $s['html'];
            } else {
                $nuevas[] = ['nivel' => $s['nivel'], 'clave' => $s['clave'], 'html' => $s['html'], 'cuerpo' => ''];
            }
        }
        if (!$nuevas) {
            return null;
        }
        $secciones = self::html_sections($sinpie($actual));
        foreach ($nuevas as $nueva) {
            $i = null;
            foreach ($secciones as $k => $s) {
                if ($s['nivel'] > 0 && $s['clave'] === $nueva['clave'] && $s['clave'] !== '') {
                    $i = $k;
                    break;
                }
            }
            if ($i === null) {
                return null;
            }
            // La seccion abarca sus subsecciones: hasta el siguiente encabezado del mismo nivel o superior.
            $fin = $i + 1;
            while ($fin < count($secciones) && $secciones[$fin]['nivel'] > $secciones[$i]['nivel']) {
                $fin++;
            }
            array_splice($secciones, $i, $fin - $i, [['nivel' => $nueva['nivel'], 'clave' => $nueva['clave'],
                'html' => $nueva['html'] . $nueva['cuerpo']]]);
        }
        $html = rtrim(implode('', array_map(static fn($s) => $s['html'], $secciones)));
        // La linea de fuentes utilizadas se conserva aunque el ajuste reemplace la ultima seccion sin repetirla.
        $fuentes = '/<p>(?:(?!<\/p>).)*Fuentes utilizadas(?:(?!<\/p>).)*<\/p>/isu';
        if (!preg_match($fuentes, $html) && preg_match($fuentes, $actual, $m)) {
            $html .= $m[0];
        }
        return $html . '<p><em>' . htmlspecialchars($pie, ENT_QUOTES, 'UTF-8') . '</em></p>';
    }

    /**
     * Prompt de la Etapa 9: Plan de produccion.
     *
     * @param string $disenoTexto Diseno consolidado (texto).
     * @param string $fichaTexto Ficha de diagnostico (texto).
     * @param bool $conbusqueda Si el Modo C esta habilitado.
     * @return string
     */
    public static function build_plan_prompt(string $disenoTexto, string $fichaTexto, bool $conbusqueda = true): string {
        return implode("\n", [
            'Eres el ' . exante::ROL . ' operando en el CENTRO DE PRODUCCIÓN DE RECURSOS DE APRENDIZAJE (Etapa 9: Plan de '
                . 'producción). No diseñas, no rediseñas y no evalúas el diseño: solo levantas el plan de producción de los '
                . 'materiales instruccionales ya especificados en la Etapa 4 del diseño validado.',
            '',
            implode("\n", self::PRINCIPIOS),
            '',
            self::catalog_text($conbusqueda),
            '',
            'TAREA — ETAPA 9: PLAN DE PRODUCCIÓN:',
            '- Recupera la tabla de síntesis de materiales (4.2) y la definición de cada material (4.1) de la Etapa 4 del '
                . 'diseño consolidado, en el orden de la secuencia instruccional.',
            '- Si el diseño incluye la lista de producción con tipo y modo (A, B o C) de cada material, úsala tal como está.',
            '- Si el diseño es anterior y no incluye modos de producción, asigna a cada material el modo que corresponda según '
                . 'su formato y el catálogo de capacidades, sin cambiar el material.',
            '- Los recursos físicos (equipos, herramientas, insumos, EPP y espacios de práctica) NO entran al plan: lístalos '
                . 'solo en «excluidos_fisicos».',
            '- El centro NO produce instrumentos de evaluación (Etapa 6): si un material de la Etapa 4 corresponde a uno de '
                . 'esos instrumentos, regístralo con estado «Instrumento de evaluación del diseño».',
            '- Si un material no puede producirse en ninguno de los tres modos habilitados, regístralo con estado '
                . '«No producible por el centro», sin proponer alternativas.',
            '- Si un material combina partes que se producen en distintos modos (por ejemplo, una selección de videos y '
                . 'un expediente documental), indica el modo de cada parte: su modo es la combinación («B/C») y en «partes» '
                . 'va una entrada por parte con su modo, qué es (en pocas palabras) y su formato de salida.',
            '- Para cada material verifica si la especificación incluye destinatarios, propósito y actividad, contenidos y '
                . 'secuencia, extensión, estructura, formato de salida, modo y (en Modo C) especificación de búsqueda. Si falta '
                . 'algo, marca especificacion «incompleta» y anota brevemente qué falta en «faltantes» (sin proponer cambios al diseño).',
            '',
            guardrails::injection_clause(),
            '',
            'FORMATO DE RESPUESTA: únicamente un objeto JSON válido, sin texto antes ni después y sin bloques de código:',
            '{"materiales": [{"codigo": "M1", "nombre": "...", "fase": "J1 · inicio / actividad...", "modo": "A|B|C|A/B|B/C", '
                . '"formato": "Word (.docx) / PDF A4 / Excel (.xlsx) / Presentación (.pptx) / Cuestionario GIFT / Ficha de recomendación...", '
                . '"especificacion": "completa|incompleta", "faltantes": "", '
                . '"estado": "pendiente|no_producible|instrumento", "partes": [{"modo": "B", "nombre": "...", '
                . '"formato": "..."}, {"modo": "C", "nombre": "...", "formato": "Ficha de recomendación"}]}], '
                . '"excluidos_fisicos": ["..."]}',
            '(«partes» solo en los materiales mixtos; en los demás, déjalo vacío o no lo incluyas).',
            '',
            'FICHA DE DIAGNÓSTICO VALIDADA:',
            guardrails::isolate_user_content($fichaTexto),
            '',
            'DISEÑO CONSOLIDADO VALIDADO:',
            guardrails::isolate_user_content($disenoTexto),
        ]);
    }

    /**
     * Prompt de la Etapa 10.2: Propuesta de estructura del material antes de producir el contenido completo.
     *
     * @param array $material ['codigo', 'nombre', 'fase', 'modo', 'formato', 'especificacion', 'espec_detalle'].
     * @param array $datosNoDiseno ['organismo', 'curso', 'facilitador', 'impresion'].
     * @param string $disenoTexto
     * @param string $fichaTexto
     * @param string[] $refsNombres Nombres y secciones de materiales tecnicos cargados.
     * @param string[] $yaProducidos Codigos y nombres de materiales ya producidos del mismo diseno (reutilizacion).
     * @param string $ajusteEstructura Ajuste solicitado a la estructura (vacio la primera vez).
     * @return string
     */
    public static function build_structure_prompt(array $material, array $datosNoDiseno, string $disenoTexto,
            string $fichaTexto, array $refsNombres = [], array $yaProducidos = [], string $ajusteEstructura = ''): string {
        $l = [
            'Eres el ' . exante::ROL . ' operando en el CENTRO DE PRODUCCIÓN DE RECURSOS (Etapa 10.2: Estructura del material).',
            '',
            implode("\n", self::PRINCIPIOS),
            '',
            'TAREA — ETAPA 10.2: PROPONER LA ESTRUCTURA DEL MATERIAL (NO produzcas todavía el material completo):',
            '- Material elegido: ' . $material['codigo'] . ' · ' . $material['nombre'],
            '- Fase y actividad: ' . ($material['fase'] ?: 'según diseño'),
            '- Modo: ' . $material['modo'] . ' | Formato de salida: ' . $material['formato'],
            '- Especificación en el diseño: ' . $material['especificacion']
                . ($material['espec_detalle'] !== '' ? ' (' . $material['espec_detalle'] . ')' : ''),
            self::part_note($material),
            '- Datos para cabecera (no de diseño): Organismo: ' . ($datosNoDiseno['organismo'] ?? '(sin indicar)')
                . '; Curso: ' . ($datosNoDiseno['curso'] ?? '(sin indicar)')
                . '; Persona facilitadora: ' . ($datosNoDiseno['facilitador'] ?? '(sin indicar)')
                . '; Formato de impresión: ' . ($datosNoDiseno['impresion'] ?? 'Carta') . '.',
            '',
            'Presenta en Markdown claro y breve:',
            '1. Nombre, código, modo y formato de salida.',
            '2. Partes o secciones del material, con su contenido en una línea cada una.',
            '3. Extensión estimada (acorde al tiempo de uso previsto en el diseño).',
            '4. Contenidos técnicos, normativos o de seguridad que llevarán la marca [REVISAR: motivo] (o indicar «Ninguno previsto»).',
            '5. Materiales de referencia propios que se usarán y para qué contenidos (si se cargaron; si alguno no se relaciona, indícalo).',
            $yaProducidos ? '6. Reutilización: materiales ya producidos en este diseño a los que remitirás sin repetir: '
                . implode('; ', $yaProducidos) . '.' : '',
            'Cierra con la pregunta exacta: «¿Apruebas esta estructura para producir el material completo?»',
            '',
            $refsNombres ? 'MATERIALES TÉCNICOS DE REFERENCIA CARGADOS PARA ESTE RECURSO: ' . implode(' | ', $refsNombres) : '',
            $ajusteEstructura !== '' ? "AJUSTE SOLICITADO A LA ESTRUCTURA (si implica cambiar el diseño validado, aplica el principio 1):\n"
                . guardrails::isolate_user_content($ajusteEstructura) : '',
            '',
            guardrails::injection_clause(),
            '',
            'FICHA DE DIAGNÓSTICO VALIDADA:',
            guardrails::isolate_user_content($fichaTexto),
            '',
            'DISEÑO CONSOLIDADO VALIDADO:',
            guardrails::isolate_user_content($disenoTexto),
        ];
        return implode("\n", array_filter($l, static fn($x) => $x !== null));
    }

    /**
     * Prompt de la Etapa 10.3: Produccion del contenido completo segun el modo (A, B, Cuestionarios o C).
     *
     * @param array $material
     * @param string $estructura Estructura aprobada por la persona facilitadora.
     * @param array $datosNoDiseno
     * @param string $disenoTexto
     * @param string $fichaTexto
     * @param string $plataformaQuiz 'moodle', 'google', 'impreso' o ''.
     * @param string[] $yaProducidos
     * @return string
     */
    public static function build_content_prompt(array $material, string $estructura, array $datosNoDiseno,
            string $disenoTexto, string $fichaTexto, string $plataformaQuiz = '', array $yaProducidos = [],
            bool $conWeb = false): string {
        $modo = strtoupper(trim((string) ($material['modo'] ?? 'A')));
        $esQuiz = self::is_quiz_material((string) $material['nombre'], (string) $material['formato']);
        $formatos = self::detect_formats($modo, (string) $material['formato'], (string) $material['nombre'], $plataformaQuiz);

        $l = [
            'Eres el ' . exante::ROL . ' operando en el CENTRO DE PRODUCCIÓN DE RECURSOS (Etapa 10.3: Producción del contenido).',
            '',
            implode("\n", self::PRINCIPIOS),
            '',
            implode("\n", self::CONTROL_CALIDAD),
            '',
            'MATERIAL A PRODUCIR:',
            '- Código y nombre: ' . $material['codigo'] . ' · ' . $material['nombre'],
            '- Fase y actividad: ' . ($material['fase'] ?: 'según diseño'),
            '- Modo: ' . $modo . ' | Formato de salida: ' . $material['formato'],
            self::part_note($material),
            '- Datos de cabecera: Organismo: ' . ($datosNoDiseno['organismo'] ?? '')
                . ' | Curso: ' . ($datosNoDiseno['curso'] ?? '')
                . ' | Persona facilitadora: ' . ($datosNoDiseno['facilitador'] ?? '')
                . ' | Impresión: ' . ($datosNoDiseno['impresion'] ?? 'Carta'),
            $yaProducidos ? '- Materiales ya producidos en este diseño (remite a ellos en lugar de repetir): '
                . implode('; ', $yaProducidos) : '',
            '',
            'ESTRUCTURA APROBADA POR LA PERSONA FACILITADORA (respétala íntegramente):',
            guardrails::isolate_user_content($estructura),
            '',
        ];

        if ($modo === 'C') {
            $l[] = 'INSTRUCCIONES MODO C (CURADURÍA DE RECURSOS EXISTENTES):';
            $l[] = '- Busca en internet recursos abiertos y gratuitos que cumplan exactamente la especificación del diseño '
                . '(tipo de recurso, hasta 5 opciones, criterio/actividad, idioma, duración máxima y criterios de selección).';
            $l[] = '- Verifica que cada enlace esté disponible y sea de acceso libre (la plataforma vuelve a comprobar cada '
                . 'enlace y registra la fecha de verificación).';
            $l[] = '- Excluye recursos con procedimientos incorrectos o inseguros, información desactualizada o contenido '
                . 'publicitario predominante. No completes 5 opciones si menos cumplen los criterios.';
            $l[] = '- En las advertencias indica, por ejemplo, tramos que no corresponden o diferencias con la normativa nacional.';
            $l[] = '- Prioriza recursos de instituciones chilenas o que expliquen la normativa y la práctica en Chile; si un '
                . 'recurso es extranjero, indica en las advertencias en qué difiere de lo que rige en Chile.';
            $l[] = '- No descargues ni reproduzcas los recursos: solo se enlazan. La persona facilitadora selecciona los '
                . 'recursos que usará.';
            $l[] = '- Usa solo enlaces exactos que aparezcan en los resultados de la búsqueda. Nunca construyas, completes '
                . 'ni adivines un enlace o el identificador de un video: la plataforma comprueba cada video en YouTube o Vimeo '
                . 'y descarta los que no existen.';
            $l[] = '- En el momento de uso, la justificación y las advertencias usa «experiencia de aprendizaje», nunca «sesión».';
            $l[] = '- Devuelve ÚNICAMENTE un objeto JSON válido, sin texto antes ni después y sin bloques de código:';
            $l[] = '{"criterios_busqueda": "...", "opciones": [{"titulo": "...", "fuente": "...", "duracion": "7 min", '
                . '"idioma": "Español", "enlace": "https://...", "momento": "...", "justificacion": "...", "advertencias": "..."}]}';
        } else if ($esQuiz) {
            $l[] = 'INSTRUCCIONES PARA CUESTIONARIO (UNA SOLA FUENTE PARA MOODLE GIFT, GOOGLE FORMS E IMPRESO):';
            $l[] = '- Genera el cuestionario completo respetando los criterios del diseño y la estructura aprobada.';
            $l[] = '- Tipos válidos por pregunta: "multiple" (opción múltiple con 4 alternativas), "vf" (verdadero/falso) o "corta".';
            $l[] = '- En "correcta" indica el índice 0..3 (para "multiple"), "verdadero"/"falso" (para "vf") o la respuesta (para "corta").';
            $l[] = '- Incluye retroalimentación formativa breve en cada pregunta y marcas [REVISAR: motivo] si algún dato técnico no está en las fuentes.';
            $l[] = '- Devuelve ÚNICAMENTE un objeto JSON válido, sin texto antes ni después y sin bloques de código:';
            $l[] = '{"titulo": "...", "descripcion": "...", "preguntas": [{"enunciado": "...", "tipo": "multiple|vf|corta", '
                . '"opciones": ["...", "...", "...", "..."], "correcta": 0, "puntaje": 1, "retroalimentacion": "..."}], '
                . '"fuentes": ["..."]}';
            if ($conWeb) {
                foreach (self::web_prompt_rules(true) as $regla) {
                    $l[] = $regla;
                }
            }
        } else if (in_array('xlsx', $formatos, true)) {
            $l[] = 'INSTRUCCIONES MODO B — PLANILLA EXCEL (.xlsx):';
            $l[] = '- Genera la planilla editable completa en JSON estructurado (la plataforma la convertirá a .xlsx real '
                . 'y a vista previa en tabla). Puedes incluir fórmulas Excel simples empezando con "=".';
            $l[] = '- Devuelve ÚNICAMENTE un objeto JSON válido, sin texto antes ni después y sin bloques de código:';
            $l[] = '{"titulo": "...", "introduccion": "...", "hojas": [{"nombre": "Hoja 1", "encabezados": ["Col 1", "Col 2"], '
                . '"filas": [["...", "..."]]}], "fuentes": ["..."]}';
            if ($conWeb) {
                foreach (self::web_prompt_rules(true) as $regla) {
                    $l[] = $regla;
                }
            }
        } else if (in_array('pptx', $formatos, true) && !in_array('docx', $formatos, true) && !in_array('pdf', $formatos, true)) {
            $l[] = 'INSTRUCCIONES MODO B — PRESENTACIÓN (.pptx):';
            $l[] = '- Genera la presentación completa diapositiva por diapositiva en JSON estructurado (la plataforma armará '
                . 'el archivo .pptx editable con portada institucional y notas para la persona facilitadora).';
            $l[] = '- Cada diapositiva debe ser concisa (máx. 5–6 puntos breves en pantalla; el detalle oral va en "notas").';
            $l[] = '- Devuelve ÚNICAMENTE un objeto JSON válido, sin texto antes ni después y sin bloques de código:';
            $l[] = '{"titulo": "...", "subtitulo": "...", "diapositivas": [{"titulo": "...", "puntos": ["..."], "notas": "..."}], '
                . '"fuentes": ["..."]}';
            if ($conWeb) {
                foreach (self::web_prompt_rules(true) as $regla) {
                    $l[] = $regla;
                }
            }
        } else {
            $l[] = 'INSTRUCCIONES DE PRODUCCIÓN (Markdown estructurado para pantalla, HTML Moodle, Word y/o PDF):';
            $l[] = '- Entrega el material completo, redactado en español de Chile y listo para usar.';
            $l[] = '- Redacción formal, clara y estable: frases completas y directas, cada término técnico explicado la primera '
                . 'vez que aparece, sin jerga, exageraciones ni adjetivos de relleno. Títulos en tipo oración (no en MAYÚSCULAS '
                . 'sostenidas). Los datos de identificación (organismo, curso, persona facilitadora, código) van una sola vez, '
                . 'al inicio y en forma breve.';
            $l[] = '- Usa títulos ## y ###, listas y tablas Markdown. Para comparar dos o más elementos, usa una tabla Markdown. '
                . 'Nunca dibujes cajas, líneas o flechas con caracteres (+---+, |, │, ▼, v, ->).';
            $l[] = '- Para notas didácticas destacadas, usa citas Markdown (> **Nota de profundización:** ..., > **Importante:** ..., '
                . '> **Ejemplo:** ... o > **Recuerda:** ...); la plataforma las dibuja como recuadros didácticos con ícono.';
            $l[] = '- Pictogramas: escribe [Pictograma: descripción breve] solo donde una imagen ayude a comprender (como '
                . 'máximo uno por sección), nunca como relleno; la plataforma lo muestra como «Ilustración sugerida».';
            $l[] = '- Para diagramas de pasos o ciclos, usa bloques ```diagrama (nunca arte ASCII): una línea por caja, '
                . '«Título / detalle», sin líneas de flechas ni conectores entre ellas (│, ▼, ↓); la plataforma las dibuja '
                . 'como tarjetas con flechas.';
            $l[] = diseno::REGLA_FORMULAS;
            if ($conWeb) {
                foreach (self::web_prompt_rules() as $regla) {
                    $l[] = $regla;
                }
            } else {
                $l[] = '- Incluye [REVISAR: motivo] en el lugar exacto cuando un dato técnico/normativo no provenga de las fuentes autorizadas.';
            }
            $l[] = '- Si usaste materiales de referencia cargados, agrega al final el título «Fuentes utilizadas:» seguido de '
                . 'una lista con viñetas en líneas separadas (una fuente por línea).';
            $l[] = '- Cierra siempre con la línea exacta: «' . $material['codigo'] . ' · ' . self::PIE_MATERIAL . '»';
            $l[] = '- No agregues saludos ni preguntas al final: la plataforma ya muestra los controles de revisión y aprobación.';
        }

        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FICHA DE DIAGNÓSTICO VALIDADA:';
        $l[] = guardrails::isolate_user_content($fichaTexto);
        $l[] = '';
        $l[] = 'DISEÑO CONSOLIDADO VALIDADO:';
        $l[] = guardrails::isolate_user_content($disenoTexto);
        return implode("\n", $l);
    }

    /**
     * Prompt de la Etapa 10.4: Ajuste focalizado por seccion del material (respetando Principio 1).
     *
     * @param array $material
     * @param string $contenidoActual Texto o JSON actual del material.
     * @param string $ajustePedido Solicitud de ajuste de la persona facilitadora.
     * @param bool $esJson Si el formato de salida debe seguir siendo JSON estructurado.
     * @return string
     */
    public static function build_adjust_prompt(array $material, string $contenidoActual, string $ajustePedido,
            bool $esJson = false): string {
        return implode("\n", [
            'Eres el ' . exante::ROL . ' operando en el CENTRO DE PRODUCCIÓN DE RECURSOS (Etapa 10.4: Revisión y ajustes).',
            '',
            implode("\n", self::PRINCIPIOS),
            '',
            implode("\n", self::CONTROL_CALIDAD),
            '',
            'REGLA DE AJUSTE:',
            '- Primero verifica si el pedido implica modificar el diseño validado (agregar o quitar contenidos, criterios, '
                . 'actividades, tiempos, fases u organización del grupo). Si lo implica, NO cambies el material y responde '
                . ($esJson ? 'un JSON {"rechazado": true, "mensaje": "' . self::RESPUESTA_NO_MODIFICA . '"}'
                    : 'exactamente: "' . self::RESPUESTA_NO_MODIFICA . '"'),
            '- Si el ajuste corresponde a redacción, tono, ejemplos dentro de los contenidos especificados, nivel de lectura '
                . 'o formato, modifica ÚNICAMENTE las secciones indicadas conservando intacto el resto del material.',
            '- Conserva tal cual las marcas [[WEB:n|enlace]] y [[/WEB:n]] que rodean el texto obtenido de internet (la persona '
                . 'facilitadora aún no lo revisa) y no escribas la sección «' . self::WEB_REFERENCIAS . '»: la arma la plataforma.',
            $esJson ? '- Entrega el JSON completo actualizado con exactamente la misma estructura.'
                : '- Entrega solo las secciones modificadas, en Markdown, cada una precedida por su título exacto, con el '
                    . 'mismo nivel de encabezado (## o ###) con que aparece en el material actual: la plataforma las reemplaza '
                    . 'en el material. No incluyas las secciones sin cambios ni la línea de cierre. Si la persona facilitadora '
                    . 'pide expresamente el material completo, entrégalo completo y termina con la línea de cierre «'
                    . $material['codigo'] . ' · ' . self::PIE_MATERIAL . '».',
            '',
            guardrails::injection_clause(),
            '',
            'MATERIAL ACTUAL (' . $material['codigo'] . ' · ' . $material['nombre'] . '):',
            guardrails::isolate_user_content($contenidoActual),
            '',
            'AJUSTE SOLICITADO POR LA PERSONA FACILITADORA:',
            guardrails::isolate_user_content($ajustePedido),
        ]);
    }

    /**
     * Construye el HTML del Repertorio de recursos del diseno (Etapa 12: cierre del centro). Funcion pura.
     *
     * @param array $meta ['experiencia', 'plan', 'modulo', 'organismo', 'curso', 'facilitador', 'puntaje'].
     * @param array $recursos Filas de local_chatbot_recurso.
     * @param array $refs Filas de local_chatbot_recurso_ref.
     * @return string HTML.
     */
    public static function repertory_html(array $meta, array $recursos, array $refs): string {
        $e = static fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $estados = [
            self::MAT_PENDIENTE => 'Pendiente',
            self::MAT_ESTRUCTURA => 'Pendiente (estructura)',
            self::MAT_BORRADOR => 'Pendiente (borrador)',
            self::MAT_ENTREGADO => 'Entregado',
            self::MAT_ENTREGADO_PARCIAL => 'Entregado parcialmente',
            self::MAT_NO_PRODUCIBLE => 'No producible por el centro',
            self::MAT_INSTRUMENTO => 'Instrumento de evaluación del diseño',
        ];
        $html = '<h2>Repertorio de recursos del diseño</h2>'
            . '<p><strong>Experiencia de aprendizaje:</strong> ' . $e((string) ($meta['experiencia'] ?? ''))
            . '<br><strong>Plan formativo / Módulo:</strong> ' . $e((string) ($meta['plan'] ?? ''))
            . (!empty($meta['modulo']) ? ' · ' . $e((string) $meta['modulo']) : '')
            . (!empty($meta['organismo']) ? '<br><strong>Organismo ejecutor:</strong> ' . $e((string) $meta['organismo']) : '')
            . '</p>';

        $html .= '<h3>1. Estado de los materiales del plan de producción</h3>'
            . '<table class="table table-sm"><thead><tr><th>Código</th><th>Material</th><th>Modo</th>'
            . '<th>Formatos entregados</th><th>Versión</th><th>Estado</th><th>Marcas [REVISAR]</th></tr></thead><tbody>';
        $totalRevisar = 0;
        $modoC = [];
        $entregada = static fn($r) => in_array((string) ($r->estado ?? ''), [self::MAT_ENTREGADO, self::MAT_ENTREGADO_PARCIAL], true);
        foreach (self::material_groups($recursos) as $base => $grupo) {
            $filas = $grupo['filas'];
            $est = (string) $grupo['estado'];
            $mixto = count($filas) > 1;
            $rev = 0;
            $fmts = [];
            $vers = [];
            foreach ($filas as $r) {
                $letra = $mixto ? self::part_letter((string) $r->codigo) . ': ' : '';
                if ($entregada($r)) {
                    $rev += (int) ($r->revisar_count ?? 0);
                    $fmts[] = $letra . (string) ($r->formato ?? '');
                }
                $vers[] = $letra . (((int) ($r->version ?? 0)) > 0 ? 'v' . (int) $r->version : '—');
            }
            $totalRevisar += $rev;
            $nombre = (string) $filas[0]->nombre;
            $modo = (string) $filas[0]->modo;
            if ($mixto) {
                $nombre = (string) preg_replace('/ · parte [ABC](: .*)?$/u', '', $nombre);
                $modo = implode('/', array_map(static fn($r) => (string) $r->modo, $filas));
            }
            $algo = $fmts !== [];
            $html .= '<tr><td>' . $e((string) $base) . '</td><td>' . $e($nombre) . '</td>'
                . '<td>' . $e($modo) . '</td><td>' . $e($algo ? implode('; ', $fmts) : '—') . '</td>'
                . '<td>' . $e($mixto ? implode(' · ', $vers) : $vers[0]) . '</td>'
                . '<td>' . $e($estados[$est] ?? $est) . '</td><td>' . ($algo ? $rev : '—') . '</td></tr>';
        }
        foreach ($recursos as $r) {
            if (strtoupper((string) $r->modo) === 'C' && !empty($r->estructurado)) {
                $cur = json_decode((string) $r->estructurado, true);
                $sel = json_decode((string) ($r->seleccionados ?? '[]'), true) ?: [];
                $fechaV = !empty($r->fecha_verificacion) ? (string) $r->fecha_verificacion : '';
                foreach ($sel as $si) {
                    if (isset($cur['opciones'][$si])) {
                        $modoC[] = ['codigo' => (string) $r->codigo, 'op' => $cur['opciones'][$si], 'fecha' => $fechaV];
                    }
                }
            }
        }
        $html .= '</tbody></table>';

        $html .= '<h3>2. Recursos seleccionados en Modo C (curaduría)</h3>';
        if ($modoC) {
            $html .= '<ul>';
            foreach ($modoC as $item) {
                $op = $item['op'];
                $html .= '<li><strong>' . $e($item['codigo']) . ' — ' . $e((string) ($op['titulo'] ?? '')) . '</strong> ('
                    . $e((string) ($op['fuente'] ?? '')) . ', ' . $e((string) ($op['duracion'] ?? '')) . '): '
                    . '<a href="' . $e((string) ($op['enlace'] ?? '')) . '" target="_blank" rel="noopener">'
                    . $e((string) ($op['enlace'] ?? '')) . '</a>'
                    . ($item['fecha'] !== '' ? ' · verificado el ' . $e($item['fecha']) : '') . '</li>';
            }
            $html .= '</ul>';
        } else {
            $html .= '<p>No hay recursos de curaduría seleccionados en este diseño.</p>';
        }

        $html .= '<h3>3. Materiales técnicos de referencia cargados</h3>';
        if ($refs) {
            $html .= '<ul>';
            foreach ($refs as $rf) {
                $html .= '<li>' . $e((string) $rf->filename)
                    . (!empty($rf->secciones) ? ' (' . $e((string) $rf->secciones) . ')' : '') . '</li>';
            }
            $html .= '</ul>';
        } else {
            $html .= '<p>No se cargaron materiales técnicos de referencia adicionales.</p>';
        }

        if ($totalRevisar > 0) {
            $html .= '<p><strong>Recordatorio importante:</strong> existen ' . $totalRevisar
                . ' marca(s) <code>[REVISAR]</code> en los materiales entregados. Verifica y elimina cada marca antes de '
                . 'usar los recursos con las personas participantes, en especial si se refieren a seguridad.</p>';
        } else {
            $html .= '<p><strong>Recordatorio:</strong> revisa siempre los materiales antes de usarlos con las personas participantes.</p>';
        }
        $html .= '<p><em>Repertorio de uso exclusivo de la persona facilitadora para la implementación. '
            . 'No se comparte con el organismo ejecutor ni lo utiliza el Módulo 3.</em></p>';
        return $html;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Base de datos, File API y ejecucion asincrona.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Lista todos los disenos validados disponibles (>= 25 puntos, consolidacion vigente) de la persona en el curso.
     *
     * @param int $userid
     * @param int $courseid
     * @return array[] Cada elemento trae ficha, experiencia, plan, modulo, aprendizaje, fecha, puntaje, plan_cr, estado_cr.
     */
    public static function list_eligible_designs(int $userid, int $courseid): array {
        global $DB;
        $fichas = $DB->get_records('local_chatbot_exante', ['userid' => $userid, 'courseid' => $courseid], 'timemodified DESC');
        $lista = [];
        foreach ($fichas as $ficha) {
            $ficha->data = exante::clean((array) json_decode((string) $ficha->data, true));
            $estado = ruta_m2::state_for($ficha);
            if (!self::eligible_design($estado)) {
                continue;
            }
            $plan = self::get_plan((int) $ficha->id);
            $recursos = $plan ? self::get_resources((int) $ficha->id) : [];
            $lista[] = [
                'fichaid' => (int) $ficha->id,
                'ficha' => $ficha,
                'experiencia' => $estado['experiencia'] ?: exante::design_label($ficha->data),
                'plan' => (string) ($ficha->data['plan'] ?? ''),
                'modulo' => (string) ($ficha->data['modulo'] ?? ''),
                'aprendizaje' => (string) ($ficha->data['aprendizaje'] ?? ''),
                'fecha' => (int) ($estado['evaluacion']['fecha'] ?? $ficha->timemodified),
                'puntaje' => (int) ($estado['evaluacion']['total'] ?? 0),
                'banda' => (string) ($estado['evaluacion']['banda']['texto'] ?? ''),
                'plan_cr' => $plan,
                'estado_cr' => self::design_status_label($plan, $recursos),
            ];
        }
        return $lista;
    }

    /**
     * @param int $fichaid
     * @return \stdClass|null
     */
    public static function get_plan(int $fichaid): ?\stdClass {
        global $DB;
        $plan = $DB->get_record('local_chatbot_recurso_plan', ['fichaid' => $fichaid]);
        if (!$plan) {
            return null;
        }
        if ($plan->generacion === exante::PROCESANDO && $plan->timemodified < time() - self::PROCESO_MAX) {
            $plan->generacion = exante::ERROR;
            $plan->error = 'tiempo';
            $DB->update_record('local_chatbot_recurso_plan', (object) [
                'id' => $plan->id, 'generacion' => exante::ERROR, 'error' => 'tiempo', 'timemodified' => time(),
            ]);
        }
        $plan->datos_arr = (array) json_decode((string) ($plan->datos ?? '{}'), true);
        return $plan;
    }

    /**
     * Crea o recupera la fila del plan de produccion de un diseno.
     *
     * @param int $fichaid
     * @param int $userid
     * @param int $courseid
     * @param array $fichaData Datos de la ficha para precargar organismo/facilitador.
     * @return \stdClass
     */
    public static function ensure_plan(int $fichaid, int $userid, int $courseid, array $fichaData = []): \stdClass {
        global $DB;
        $plan = self::get_plan($fichaid);
        if ($plan) {
            return $plan;
        }
        $now = time();
        $datos = [
            'organismo' => (string) ($fichaData['organismo'] ?? ''),
            'curso' => (string) ($fichaData['plan'] ?? ''),
            'facilitador' => (string) ($fichaData['facilitador'] ?? ''),
            'impresion' => 'Carta',
        ];
        $rec = (object) [
            'fichaid' => $fichaid,
            'userid' => $userid,
            'courseid' => $courseid,
            'huella' => '',
            'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE),
            'estado' => self::PLAN_SIN_INICIAR,
            'generacion' => '',
            'error' => null,
            'modelo' => '',
            'promptversion' => self::PROMPT_VERSION,
            'timeclosed' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $rec->id = $DB->insert_record('local_chatbot_recurso_plan', $rec);
        $rec->datos_arr = $datos;
        return $rec;
    }

    /**
     * Guarda los datos que no son de diseno (organismo, curso, facilitador/a, formato de impresion).
     */
    public static function save_plan_meta(int $fichaid, int $userid, array $datos): void {
        global $DB;
        $plan = $DB->get_record('local_chatbot_recurso_plan', ['fichaid' => $fichaid, 'userid' => $userid]);
        if (!$plan) {
            return;
        }
        $limpio = [
            'organismo' => trim(mb_substr((string) ($datos['organismo'] ?? ''), 0, 200, 'UTF-8')),
            'curso' => trim(mb_substr((string) ($datos['curso'] ?? ''), 0, 200, 'UTF-8')),
            'facilitador' => trim(mb_substr((string) ($datos['facilitador'] ?? ''), 0, 200, 'UTF-8')),
            'impresion' => trim(mb_substr((string) ($datos['impresion'] ?? 'Carta'), 0, 60, 'UTF-8')) ?: 'Carta',
        ];
        $DB->update_record('local_chatbot_recurso_plan', (object) [
            'id' => $plan->id,
            'datos' => json_encode($limpio, JSON_UNESCAPED_UNICODE),
            'timemodified' => time(),
        ]);
    }

    /**
     * Deja pendiente la generacion del Plan de produccion (Etapa 9).
     */
    public static function request_plan(int $fichaid, int $userid, int $courseid, array $fichaData = []): int {
        global $DB;
        $plan = self::ensure_plan($fichaid, $userid, $courseid, $fichaData);
        if (in_array($plan->generacion, [exante::PENDIENTE, exante::PROCESANDO], true)) {
            return (int) $plan->id;
        }
        $DB->update_record('local_chatbot_recurso_plan', (object) [
            'id' => $plan->id, 'generacion' => exante::PENDIENTE, 'error' => null, 'timemodified' => time(),
        ]);
        return (int) $plan->id;
    }

    /**
     * Ejecuta la generacion del Plan de produccion (Etapa 9) con candado exclusivo.
     */
    public static function run_plan(int $planid, int $userid): string {
        global $DB;
        $candado = \core\lock\lock_config::get_lock_factory('local_chatbot_centro')->get_lock('plan' . $planid, 0);
        if (!$candado) {
            return exante::PROCESANDO;
        }
        try {
            $plan = $DB->get_record('local_chatbot_recurso_plan', ['id' => $planid, 'userid' => $userid]);
            if (!$plan || $plan->generacion !== exante::PENDIENTE) {
                return $plan ? (string) $plan->generacion : exante::ERROR;
            }
            $DB->update_record('local_chatbot_recurso_plan', (object) [
                'id' => $planid, 'generacion' => exante::PROCESANDO, 'timemodified' => time(),
            ]);

            if (!ai\provider_factory::is_configured()) {
                $DB->update_record('local_chatbot_recurso_plan', (object) [
                    'id' => $planid, 'generacion' => exante::ERROR, 'error' => 'sinclave', 'timemodified' => time(),
                ]);
                return exante::ERROR;
            }

            $ficha = $DB->get_record('local_chatbot_exante', ['id' => $plan->fichaid, 'userid' => $userid]);
            $filas = $ficha ? diseno::get_by_ficha((int) $ficha->id) : [];
            $cons = $filas[diseno::CONSOLIDACION] ?? null;
            if (!$ficha || !$cons || !$cons->contenido) {
                $DB->update_record('local_chatbot_recurso_plan', (object) [
                    'id' => $planid, 'generacion' => exante::ERROR, 'error' => 'sindiseno', 'timemodified' => time(),
                ]);
                return exante::ERROR;
            }

            $fichaData = exante::clean((array) json_decode((string) $ficha->data, true));
            $fichaTexto = pseudonymizer::scrub(exante::to_text($fichaData));
            $disenoTexto = html_to_text((string) $cons->contenido, 0, false);
            $buscar = exante::web_search_available();
            $prompt = self::build_plan_prompt($disenoTexto, $fichaTexto, $buscar);
            [$adjuntos] = exante::attachment_parts((int) $ficha->id);
            $contenido = [['role' => 'user', 'parts' => array_merge([['text' => $prompt]], $adjuntos)]];

            $modelo = ai\provider_factory::get_configured_model();
            $parsed = null;
            for ($i = 0; $i < 2 && $parsed === null; $i++) {
                $res = exante::generate_steady($contenido, 180, $modelo, false);
                if (isset($res['error'])) {
                    $DB->update_record('local_chatbot_recurso_plan', (object) [
                        'id' => $planid, 'generacion' => exante::ERROR, 'error' => 'servicio', 'timemodified' => time(),
                    ]);
                    return exante::ERROR;
                }
                $parsed = self::parse_plan_json(gemini_client::extract_text($res));
            }
            if ($parsed === null) {
                $DB->update_record('local_chatbot_recurso_plan', (object) [
                    'id' => $planid, 'generacion' => exante::ERROR, 'error' => 'ilegible', 'timemodified' => time(),
                ]);
                return exante::ERROR;
            }

            // Un material mixto de un plan anterior (una sola fila «B/C») sin producir se reemplaza por sus partes.
            foreach (self::get_resources((int) $ficha->id) as $r) {
                if (strpos((string) $r->modo, '/') !== false && (int) $r->version === 0) {
                    $DB->delete_records('local_chatbot_recurso', ['id' => $r->id]);
                }
            }
            // Plan actualizado con la Etapa 4 vigente (plan_merge()): pendientes actualizados o quitados; lo producido se
            // conserva y, si su codigo ahora es otro material o salio del plan, queda marcado «(ant.)».
            $cambios = self::plan_merge(self::get_resources((int) $ficha->id), $parsed['materiales']);
            $now = time();
            foreach ($cambios['delete'] as $idborrar) {
                $DB->delete_records('local_chatbot_recurso', ['id' => $idborrar, 'fichaid' => (int) $ficha->id]);
            }
            foreach ($cambios['rename'] as $idant => $codigoant) {
                $DB->update_record('local_chatbot_recurso', (object) ['id' => $idant, 'codigo' => $codigoant,
                    'orden' => 900, 'timemodified' => $now]);
            }
            foreach ($cambios['update'] as $idact => $u) {
                $m = $u['m'];
                $fila = ['id' => $idact, 'orden' => $m['orden'], 'nombre' => $m['nombre'], 'fase' => $m['fase'],
                    'modo' => $m['modo'], 'formato' => $m['formato'], 'especificacion' => $m['especificacion'],
                    'espec_detalle' => $m['espec_detalle'], 'estado' => $m['estado'], 'timemodified' => $now];
                if ($u['reiniciar']) {
                    // Ahora es otro material: lo preparado para el anterior no sirve.
                    $fila += ['estructura' => null, 'contenido' => null, 'estructurado' => null, 'html_moodle' => null,
                        'seleccionados' => null, 'refs_usadas' => null, 'revisar_count' => 0, 'generacion' => null,
                        'fase_gen' => null, 'ajustes' => null, 'error' => null];
                }
                $DB->update_record('local_chatbot_recurso', (object) $fila);
            }
            foreach ($cambios['insert'] as $m) {
                $DB->insert_record('local_chatbot_recurso', (object) [
                    'planid' => $planid,
                    'fichaid' => (int) $ficha->id,
                    'userid' => $userid,
                    'courseid' => (int) $plan->courseid,
                    'orden' => $m['orden'],
                    'codigo' => $m['codigo'],
                    'nombre' => $m['nombre'],
                    'fase' => $m['fase'],
                    'modo' => $m['modo'],
                    'formato' => $m['formato'],
                    'especificacion' => $m['especificacion'],
                    'espec_detalle' => $m['espec_detalle'],
                    'estado' => $m['estado'],
                    'version' => 0,
                    'revisar_count' => 0,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }

            $datos = (array) json_decode((string) ($plan->datos ?? '{}'), true);
            $datos['excluidos_fisicos'] = $parsed['excluidos_fisicos'];
            $DB->update_record('local_chatbot_recurso_plan', (object) [
                'id' => $planid,
                'huella' => (string) ($cons->huella ?? ''),
                'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE),
                'estado' => self::PLAN_EN_PRODUCCION,
                'generacion' => exante::LISTO,
                'modelo' => (string) $modelo,
                'promptversion' => self::PROMPT_VERSION,
                'error' => null,
                'timemodified' => $now,
            ]);
            return exante::LISTO;
        } finally {
            $candado->release();
        }
    }

    /**
     * @param int $fichaid
     * @return \stdClass[]
     */
    public static function get_resources(int $fichaid): array {
        global $DB;
        $filas = $DB->get_records('local_chatbot_recurso', ['fichaid' => $fichaid], 'orden ASC, id ASC');
        foreach ($filas as $r) {
            if ($r->generacion === exante::PROCESANDO && $r->timemodified < time() - self::PROCESO_MAX) {
                $r->generacion = exante::ERROR;
                $r->error = 'tiempo';
                $DB->update_record('local_chatbot_recurso', (object) [
                    'id' => $r->id, 'generacion' => exante::ERROR, 'error' => 'tiempo', 'timemodified' => time(),
                ]);
            }
        }
        return array_values($filas);
    }

    /**
     * @param int $id
     * @param int $userid
     * @return \stdClass|null
     */
    public static function get_resource(int $id, int $userid): ?\stdClass {
        global $DB;
        $r = $DB->get_record('local_chatbot_recurso', ['id' => $id, 'userid' => $userid]);
        if ($r && $r->generacion === exante::PROCESANDO && $r->timemodified < time() - self::PROCESO_MAX) {
            $r->generacion = exante::ERROR;
            $r->error = 'tiempo';
            $DB->update_record('local_chatbot_recurso', (object) [
                'id' => $r->id, 'generacion' => exante::ERROR, 'error' => 'tiempo', 'timemodified' => time(),
            ]);
        }
        return $r ?: null;
    }

    /**
     * Solicita la generacion asincrona de estructura (10.2), contenido (10.3) o ajuste (10.4) de un material.
     */
    public static function request_resource(int $id, int $userid, string $faseGen, string $ajustes = '',
            array $refIds = [], string $plataformaQuiz = ''): bool {
        global $DB;
        $r = self::get_resource($id, $userid);
        if (!$r || in_array($r->generacion, [exante::PENDIENTE, exante::PROCESANDO], true)) {
            return false;
        }
        $campos = [
            'id' => $r->id,
            'generacion' => exante::PENDIENTE,
            'fase_gen' => $faseGen,
            'ajustes' => mb_substr(trim($ajustes), 0, 4000, 'UTF-8'),
            'error' => null,
            'timemodified' => time(),
        ];
        if ($refIds || $faseGen === self::GEN_ESTRUCTURA) {
            $campos['refs_usadas'] = json_encode(array_values(array_slice(array_map('intval', $refIds), 0, self::REF_MAX_ACTIVOS)));
        }
        if ($plataformaQuiz !== '') {
            $campos['plataforma_quiz'] = $plataformaQuiz;
        }
        $DB->update_record('local_chatbot_recurso', (object) $campos);
        return true;
    }

    /**
     * Ejecuta la generacion pendiente de un material (estructura 10.2, contenido 10.3 o ajuste 10.4).
     */
    public static function run_resource(int $id, int $userid): string {
        global $DB;
        $candado = \core\lock\lock_config::get_lock_factory('local_chatbot_centro')->get_lock('rec' . $id, 0);
        if (!$candado) {
            return exante::PROCESANDO;
        }
        try {
            $r = $DB->get_record('local_chatbot_recurso', ['id' => $id, 'userid' => $userid]);
            if (!$r || $r->generacion !== exante::PENDIENTE) {
                return $r ? (string) $r->generacion : exante::ERROR;
            }
            $DB->update_record('local_chatbot_recurso', (object) [
                'id' => $id, 'generacion' => exante::PROCESANDO, 'timemodified' => time(),
            ]);

            if (!ai\provider_factory::is_configured()) {
                $DB->update_record('local_chatbot_recurso', (object) [
                    'id' => $id, 'generacion' => exante::ERROR, 'error' => 'sinclave', 'timemodified' => time(),
                ]);
                return exante::ERROR;
            }

            $ficha = $DB->get_record('local_chatbot_exante', ['id' => $r->fichaid, 'userid' => $userid]);
            $plan = self::get_plan((int) $r->fichaid);
            $filas = $ficha ? diseno::get_by_ficha((int) $ficha->id) : [];
            $cons = $filas[diseno::CONSOLIDACION] ?? null;
            if (!$ficha || !$plan || !$cons || !$cons->contenido) {
                $DB->update_record('local_chatbot_recurso', (object) [
                    'id' => $id, 'generacion' => exante::ERROR, 'error' => 'sindiseno', 'timemodified' => time(),
                ]);
                return exante::ERROR;
            }

            $fichaData = exante::clean((array) json_decode((string) $ficha->data, true));
            $fichaTexto = pseudonymizer::scrub(exante::to_text($fichaData));
            $disenoTexto = html_to_text((string) $cons->contenido, 0, false);
            [$adjuntos] = exante::attachment_parts((int) $ficha->id);

            $refIds = (array) json_decode((string) ($r->refs_usadas ?? '[]'), true);
            [$refParts, $refNombres] = self::reference_parts((int) $ficha->id, $refIds);

            $yaProducidos = [];
            foreach (self::get_resources((int) $ficha->id) as $otro) {
                if ((int) $otro->id !== (int) $r->id && in_array($otro->estado, [self::MAT_ENTREGADO, self::MAT_ENTREGADO_PARCIAL], true)) {
                    $yaProducidos[] = $otro->codigo . ': ' . $otro->nombre;
                }
            }
            $matArr = [
                'codigo' => (string) $r->codigo,
                'nombre' => (string) $r->nombre,
                'fase' => (string) $r->fase,
                'modo' => (string) $r->modo,
                'formato' => (string) $r->formato,
                'especificacion' => (string) $r->especificacion,
                'espec_detalle' => (string) $r->espec_detalle,
            ];
            $modelo = ai\provider_factory::get_configured_model();
            $faseGen = (string) ($r->fase_gen ?: self::GEN_ESTRUCTURA);
            $modo = strtoupper(trim((string) $r->modo));
            $esQuiz = self::is_quiz_material((string) $r->nombre, (string) $r->formato);
            $formatos = self::detect_formats($modo, (string) $r->formato, (string) $r->nombre, (string) ($r->plataforma_quiz ?? ''));
            $esJsonModoB = $esQuiz || in_array('xlsx', $formatos, true)
                || (in_array('pptx', $formatos, true) && !in_array('docx', $formatos, true) && !in_array('pdf', $formatos, true));
            // Modo C: la ficha de recomendacion se ajusta sobre su JSON (opciones), que se vuelve a verificar.
            $esModoC = $modo === 'C';

            if ($faseGen === self::GEN_ESTRUCTURA) {
                $prompt = self::build_structure_prompt($matArr, $plan->datos_arr, $disenoTexto, $fichaTexto,
                    $refNombres, $yaProducidos, (string) ($r->ajustes ?? ''));
                $parts = array_merge([['text' => $prompt]], $adjuntos, $refParts);
                $res = exante::generate_steady([['role' => 'user', 'parts' => $parts]], 180, $modelo, false);
                if (isset($res['error'])) {
                    return self::fail_resource($id, 'servicio');
                }
                $md = diseno::clean_markdown(gemini_client::extract_text($res));
                if ($md === '') {
                    return self::fail_resource($id, 'ilegible');
                }
                $DB->update_record('local_chatbot_recurso', (object) [
                    'id' => $id,
                    'estructura' => diseno::product_html($md),
                    'estado' => self::MAT_ESTRUCTURA,
                    'generacion' => exante::LISTO,
                    'modelo' => (string) $modelo,
                    'promptversion' => self::PROMPT_VERSION,
                    'error' => null,
                    'timemodified' => time(),
                ]);
                return exante::LISTO;
            }

            if ($faseGen === self::GEN_AJUSTE) {
                $enJson = ($esJsonModoB || $esModoC) && !empty($r->estructurado);
                $actual = $enJson
                    ? (string) $r->estructurado
                    : html_to_text(self::headings_as_markdown((string) ($r->contenido ?? '')), 0, false);
                $prompt = self::build_adjust_prompt($matArr, $actual, (string) ($r->ajustes ?? ''), $enJson);
                $parts = array_merge([['text' => $prompt]], $refParts);
                $res = exante::generate_steady([['role' => 'user', 'parts' => $parts]], 240, $modelo, false);
                if (isset($res['error'])) {
                    return self::fail_resource($id, 'servicio');
                }
                $raw = (string) gemini_client::extract_text($res);
                if (trim($raw) === '') {
                    return self::fail_resource($id, 'ilegible');
                }
                if (stripos($raw, 'Ese ajuste implica modificar el diseño validado') !== false) {
                    $DB->update_record('local_chatbot_recurso', (object) [
                        'id' => $id,
                        'generacion' => exante::LISTO,
                        'error' => 'no_modifica',
                        'timemodified' => time(),
                    ]);
                    return exante::LISTO;
                }
                if ($enJson) {
                    return self::store_produced_material($r, $raw, $esJsonModoB && !$esModoC, $esQuiz, $formatos,
                        (string) $modelo, $esModoC);
                }
                // Ajuste por seccion (10.4): solo las secciones indicadas; si el asistente entrego el material completo
                // (la persona lo pidio), se toma completo.
                $md = diseno::clean_markdown($raw);
                $pie = $r->codigo . ' · ' . self::PIE_MATERIAL;
                [$mdWeb, $bloquesWeb] = self::prepare_web_markdown($md, null, false);
                $fusion = strpos($md, self::PIE_MATERIAL) === false
                    ? self::merge_sections((string) $r->contenido,
                        self::web_restore(diseno::product_html($mdWeb), $bloquesWeb), $pie) : null;
                if ($fusion === null && strpos($md, self::PIE_MATERIAL) === false) {
                    return self::fail_resource($id, 'seccion');
                }
                return self::store_produced_material($r, $raw, false, $esQuiz, $formatos, (string) $modelo, false,
                    (string) $fusion);
            }

            // GEN_CONTENIDO (Etapa 10.3).
            $estructuraTxt = html_to_text((string) ($r->estructura ?? ''), 0, false);
            // Datos que faltan: en los materiales en Markdown (Modos A y B) el asistente los busca en la web y los marca
            // con su fuente para que la persona los edite o acepte (pedido de Interfases del 25-11-2026).
            $webDatos = $modo !== 'C' && ux::on('webcentro') && exante::web_search_available();
            $prompt = self::build_content_prompt($matArr, $estructuraTxt, $plan->datos_arr, $disenoTexto, $fichaTexto,
                (string) ($r->plataforma_quiz ?? ''), $yaProducidos, $webDatos);
            $parts = array_merge([['text' => $prompt]], $adjuntos, $refParts);
            $usarWeb = (($modo === 'C') && exante::web_search_available()) || $webDatos;
            $res = exante::generate_steady([['role' => 'user', 'parts' => $parts]], 240, $modelo, $usarWeb);
            if (isset($res['error'])) {
                return self::fail_resource($id, 'servicio');
            }
            $raw = (string) gemini_client::extract_text($res);
            // Con busqueda web la respuesta a veces no viene como JSON limpio: se repite una vez sin busqueda.
            if ($webDatos && $esJsonModoB && !is_array(exante::decode_json($raw))) {
                $prompt = self::build_content_prompt($matArr, $estructuraTxt, $plan->datos_arr, $disenoTexto, $fichaTexto,
                    (string) ($r->plataforma_quiz ?? ''), $yaProducidos, false);
                $res = exante::generate_steady([['role' => 'user', 'parts' => array_merge([['text' => $prompt]], $adjuntos,
                    $refParts)]], 240, $modelo, false);
                $raw = isset($res['error']) ? '' : (string) gemini_client::extract_text($res);
            }
            if (trim($raw) === '') {
                return self::fail_resource($id, 'ilegible');
            }
            // Datos que el material dejo con [REVISAR]: se buscan en internet en una pasada dirigida (con busqueda
            // obligatoria) y cada uno encontrado queda como fragmento web con su fuente (visto en el cliente el 25-11:
            // el asistente marcaba el dato en vez de buscarlo).
            if ($webDatos && self::count_review_marks($raw) > 0) {
                $raw = self::fill_marks_from_web($raw, $matArr, $modelo, $esJsonModoB);
            }
            // Modo C: si ningun enlace existe, se busca una vez mas diciendo cuales fallaron.
            $cur = $modo === 'C' ? self::parse_curaduria_json($raw) : null;
            if ($cur !== null && $cur['opciones']
                    && !self::filter_verified_links($cur['opciones'], [self::class, 'verify_url'])['verificadas']) {
                $fallidos = array_map(static fn($o) => (string) ($o['enlace'] ?? ''), $cur['opciones']);
                $parts[0] = ['text' => $prompt . "\n\nREINTENTO: ninguno de estos enlaces existe o es público: "
                    . implode(', ', $fallidos) . '. Vuelve a buscar y usa solo enlaces exactos que aparezcan en los '
                    . 'resultados de la búsqueda; si no encuentras recursos que cumplan los criterios, devuelve menos opciones.'];
                $res2 = exante::generate_steady([['role' => 'user', 'parts' => $parts]], 240, $modelo, $usarWeb);
                $raw2 = isset($res2['error']) ? '' : (string) gemini_client::extract_text($res2);
                if (trim($raw2) !== '' && self::parse_curaduria_json($raw2) !== null) {
                    $raw = $raw2;
                }
            }
            return self::store_produced_material($r, $raw, $esJsonModoB, $esQuiz, $formatos, (string) $modelo, $modo === 'C');
        } finally {
            $candado->release();
        }
    }

    /**
     * Guarda el resultado producido (Modo A, B, Cuestionario o Modo C con verificacion real de enlaces).
     */
    private static function store_produced_material(\stdClass $r, string $raw, bool $esJsonModoB, bool $esQuiz,
            array $formatos, string $modelo, bool $esModoC, string $htmlListo = ''): string {
        global $DB;
        $estructurado = null;
        $fechaVerif = '';
        if ($esModoC) {
            $parsed = self::parse_curaduria_json($raw);
            if ($parsed === null) {
                return self::fail_resource((int) $r->id, 'ilegible');
            }
            $filtrado = self::filter_verified_links($parsed['opciones'], [self::class, 'verify_url']);
            $parsed['opciones'] = $filtrado['verificadas'];
            $parsed['descartadas'] = $filtrado['descartadas'];
            $fechaVerif = userdate(time(), '%d-%m-%Y');
            $estructurado = json_encode($parsed, JSON_UNESCAPED_UNICODE);
            $html = self::curaduria_html($parsed['opciones'], $fechaVerif, (string) $r->codigo);
        } else if ($esJsonModoB) {
            $json = exante::decode_json($raw);
            if (!is_array($json)) {
                return self::fail_resource((int) $r->id, 'ilegible');
            }
            // Fragmentos obtenidos de la web dentro de los textos del JSON (v1.32.0).
            [$json] = self::web_json_prepare($json, [self::class, 'verify_url']);
            $estructurado = json_encode($json, JSON_UNESCAPED_UNICODE);
            $html = self::json_preview_html($json, $r, $esQuiz, $formatos);
        } else if ($htmlListo !== '') {
            $html = $htmlListo;
        } else {
            $md = diseno::clean_markdown($raw);
            if (strpos($md, self::PIE_MATERIAL) === false) {
                $md .= "\n\n*" . $r->codigo . ' · ' . self::PIE_MATERIAL . '*';
            }
            // Fragmentos obtenidos de la web: se comprueba cada enlace y se arma «Referencias consultadas».
            [$md, $bloquesWeb] = self::prepare_web_markdown($md, [self::class, 'verify_url']);
            $html = self::web_restore(diseno::product_html($md), $bloquesWeb);
        }

        $revisar = self::count_review_marks($html . ($estructurado ?? ''));
        $htmlMoodle = recurso_export::to_moodle_html(self::strip_web_marks($html), (string) $r->nombre, (string) $r->codigo);
        $DB->update_record('local_chatbot_recurso', (object) [
            'id' => $r->id,
            'contenido' => $html,
            'estructurado' => $estructurado,
            'html_moodle' => $htmlMoodle,
            'revisar_count' => $revisar,
            'fecha_verificacion' => $fechaVerif,
            'estado' => self::MAT_BORRADOR,
            'generacion' => exante::LISTO,
            'modelo' => $modelo,
            'promptversion' => self::PROMPT_VERSION,
            'error' => null,
            'timemodified' => time(),
        ]);
        return exante::LISTO;
    }

    /**
     * Vista previa HTML de un material estructurado (cuestionario, planilla o presentacion).
     */
    private static function json_preview_html(array $json, \stdClass $r, bool $esQuiz, array $formatos): string {
        $pie = $r->codigo . ' · ' . self::PIE_MATERIAL;
        if ($esQuiz) {
            return recurso_export::to_printable_quiz_html($json, (string) $r->nombre, $pie);
        }
        if (in_array('xlsx', $formatos, true)) {
            return self::sheets_preview_html($json, $pie);
        }
        return self::slides_preview_html($json, $pie);
    }

    /**
     * Vista previa HTML de una planilla Excel estructurada.
     */
    private static function sheets_preview_html(array $json, string $pie): string {
        $e = static fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $html = '';
        if (!empty($json['introduccion'])) {
            $html .= '<p>' . $e((string) $json['introduccion']) . '</p>';
        }
        foreach ((array) ($json['hojas'] ?? []) as $h) {
            $html .= '<h3>' . $e((string) ($h['nombre'] ?? 'Hoja')) . '</h3><table class="table table-sm">';
            if (!empty($h['encabezados'])) {
                $html .= '<thead><tr>';
                foreach ((array) $h['encabezados'] as $c) {
                    $html .= '<th>' . $e((string) $c) . '</th>';
                }
                $html .= '</tr></thead>';
            }
            $html .= '<tbody>';
            foreach ((array) ($h['filas'] ?? []) as $f) {
                $html .= '<tr>';
                foreach ((array) $f as $v) {
                    $html .= '<td>' . $e(is_scalar($v) ? (string) $v : '') . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }
        if (!empty($json['fuentes'])) {
            $html .= '<p><strong>Fuentes utilizadas:</strong> ' . $e(implode('; ', array_map('strval', (array) $json['fuentes']))) . '</p>';
        }
        return $html . '<p><em>' . $e($pie) . '</em></p>';
    }

    /**
     * Vista previa HTML de una presentacion estructurada.
     */
    private static function slides_preview_html(array $json, string $pie): string {
        $e = static fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $html = '<h2>' . $e((string) ($json['titulo'] ?? '')) . '</h2>';
        if (!empty($json['subtitulo'])) {
            $html .= '<p><em>' . $e((string) $json['subtitulo']) . '</em></p>';
        }
        foreach (array_values((array) ($json['diapositivas'] ?? [])) as $i => $d) {
            $html .= '<h3>Diapositiva ' . ($i + 1) . ': ' . $e((string) ($d['titulo'] ?? '')) . '</h3><ul>';
            foreach ((array) ($d['puntos'] ?? []) as $pt) {
                $html .= '<li>' . $e((string) $pt) . '</li>';
            }
            $html .= '</ul>';
            if (!empty($d['notas'])) {
                $html .= '<p><small><strong>Notas para la persona facilitadora:</strong> ' . $e((string) $d['notas']) . '</small></p>';
            }
        }
        if (!empty($json['fuentes'])) {
            $html .= '<p><strong>Fuentes utilizadas:</strong> ' . $e(implode('; ', array_map('strval', (array) $json['fuentes']))) . '</p>';
        }
        return $html . '<p><em>' . $e($pie) . '</em></p>';
    }

    private static function fail_resource(int $id, string $motivo): string {
        global $DB;
        $DB->update_record('local_chatbot_recurso', (object) [
            'id' => $id, 'generacion' => exante::ERROR, 'error' => $motivo, 'timemodified' => time(),
        ]);
        return exante::ERROR;
    }

    /**
     * Estado de un material despues de cambiar su contenido: uno entregado que cambia vuelve a borrador (hay que
     * aprobarlo de nuevo para registrar la version siguiente); sin cambios, conserva su estado. Funcion pura.
     *
     * @param string $estado Estado actual.
     * @param bool $cambio Si el contenido cambio.
     * @return string
     */
    public static function state_after_change(string $estado, bool $cambio): string {
        return $cambio && in_array($estado, [self::MAT_ENTREGADO, self::MAT_ENTREGADO_PARCIAL], true) ? self::MAT_BORRADOR : $estado;
    }

    /**
     * Si el boton de aprobar (Etapa 11) se muestra: no en un material ya entregado y sin cambios desde entonces, para
     * no registrar versiones identicas (visto en el cliente el 19-11-2026: cada clic sumaba una version). Funcion pura.
     *
     * @param string $estado
     * @return bool
     */
    public static function can_approve(string $estado): bool {
        return !in_array($estado, [self::MAT_ENTREGADO, self::MAT_ENTREGADO_PARCIAL], true);
    }

    /**
     * Reglas del prompt para buscar en la web los datos que faltan y marcarlos con su fuente (pedido de Interfases del
     * 25-11-2026). Amplian el principio 3: lo que no esta en el diseno ni en los materiales cargados se busca en fuentes
     * chilenas o de uso en Chile, y solo si no se encuentra se usa [REVISAR]. Funcion pura.
     *
     * @return string[]
     */
    public static function web_prompt_rules(bool $json = false): array {
        return [
            'DATOS QUE FALTAN: BÚSQUEDA EN INTERNET CON REFERENCIA (amplía el principio 3):',
            '- Usa primero el diseño validado, la ficha y los materiales de referencia cargados. Solo si un dato técnico, '
                . 'normativo o de seguridad que el material necesita no está en ellos, búscalo en internet.',
            '- ' . exante::REGLA_FUENTES_CHILE,
            $json
                ? '- Envuelve cada fragmento redactado con información de internet así, dentro del mismo valor de texto del '
                    . 'JSON (enunciado, opción, retroalimentación, celda, punto o nota): [[WEB 1 | Título de la fuente | '
                    . 'https://enlace-exacto]] texto obtenido [[/WEB]]. No uses comillas dobles en el título. Numera los '
                    . 'fragmentos 1, 2, 3… en orden; si dos fragmentos usan la misma fuente, repite su título y su enlace. No '
                    . 'marques los números de «correcta» ni de «puntaje».'
                : '- Envuelve cada fragmento redactado con información de internet así, dentro de un solo párrafo, viñeta o celda: '
                    . '[[WEB 1 | Título de la fuente | https://enlace-exacto]] texto obtenido [[/WEB]]. Numera los fragmentos 1, '
                    . '2, 3… en orden; si dos fragmentos usan la misma fuente, repite su título y su enlace.',
            $json
                ? '- Usa solo enlaces exactos que aparezcan en los resultados de la búsqueda; nunca construyas ni adivines un '
                    . 'enlace. No pongas en «fuentes» las fuentes de internet: la plataforma comprueba cada enlace y las agrega.'
                : '- Usa solo enlaces exactos que aparezcan en los resultados de la búsqueda; nunca construyas ni adivines un enlace. '
                    . 'La plataforma comprueba cada enlace y arma la sección «' . self::WEB_REFERENCIAS . '» al final del material: '
                    . 'no la escribas tú.',
            '- La persona facilitadora revisará cada fragmento marcado (Editar o Aceptar): no marques texto que provenga del '
                . 'diseño o de los materiales cargados.',
            '- Antes de dejar una marca [REVISAR] por un dato técnico o normativo (por ejemplo, el número de una ley, decreto o '
                . 'norma, un valor límite o un procedimiento), búscalo en internet. Si no lo encuentras en una fuente confiable, '
                . 'no lo inventes: deja la marca [REVISAR: motivo] en el lugar exacto; la plataforma intentará buscarlo de nuevo.',
        ];
    }

    /**
     * Marcas [REVISAR] de un texto con lo que las rodea, para buscarlas en internet. Funcion pura.
     *
     * @param string $texto Markdown o JSON del material.
     * @param int $tope Maximo de marcas.
     * @return array n (desde 1) => ['marca' => string, 'contexto' => string]
     */
    public static function review_marks_context(string $texto, int $tope = 12): array {
        $out = [];
        if (!preg_match_all('/\[REVISAR(?:\s*:\s*[^\]]+)?\]/u', $texto, $m, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach (array_slice($m[0], 0, $tope) as $i => [$marca, $pos]) {
            $antes = substr($texto, max(0, $pos - 350), min(350, $pos));
            $despues = substr($texto, $pos + strlen($marca), 200);
            $limpio = static fn(string $t): string => trim(preg_replace('/\s+/u', ' ', strip_tags(
                mb_convert_encoding($t, 'UTF-8', 'UTF-8'))));
            $out[$i + 1] = ['marca' => $marca, 'contexto' => $limpio($antes) . ' ▶' . $marca . '◀ ' . $limpio($despues)];
        }
        return $out;
    }

    /**
     * Prompt de la pasada dirigida que busca en internet los datos marcados con [REVISAR]. Funcion pura.
     *
     * @param array $material ['codigo', 'nombre']
     * @param array $marcas Salida de review_marks_context().
     * @return string
     */
    public static function build_web_fill_prompt(array $material, array $marcas): string {
        $l = [
            'Eres el ' . exante::ROL . ' operando en el CENTRO DE PRODUCCIÓN DE RECURSOS. El material «' . $material['codigo']
                . ' · ' . $material['nombre'] . '» dejó datos marcados con [REVISAR] porque no estaban en el diseño ni en '
                . 'los materiales de referencia. Búscalos en internet ahora, uno por uno.',
            '',
            '- ' . exante::REGLA_FUENTES_CHILE,
            '- Para cada marca redacta en español de Chile el texto breve que la reemplaza (una o dos frases, solo el dato '
                . 'pedido, sin cambiar el sentido de la frase donde va) y entrega la fuente exacta: su título y el enlace que '
                . 'aparece en los resultados de la búsqueda. Nunca construyas ni adivines un enlace.',
            '- Si no encuentras el dato en una fuente confiable, chilena o de uso en Chile, responde «encontrado»: false: la '
                . 'marca queda para que la persona facilitadora la complete.',
            '- No uses comillas dobles, corchetes ni el carácter | dentro de «texto» ni de «titulo».',
            '- Devuelve ÚNICAMENTE un objeto JSON válido, sin texto antes ni después y sin bloques de código:',
            '{"datos": [{"n": 1, "encontrado": true, "texto": "...", "titulo": "...", "enlace": "https://..."}]}',
            '',
            'MARCAS (el dato va donde está ▶…◀):',
        ];
        foreach ($marcas as $n => $m) {
            $l[] = $n . '. ' . guardrails::isolate_user_content($m['contexto']);
        }
        return implode("\n", $l);
    }

    /**
     * Reemplaza cada marca [REVISAR] que la busqueda encontro por un fragmento web con su fuente («[[WEB n | titulo |
     * enlace]] texto [[/WEB]]»); las no encontradas quedan igual. En un JSON el reemplazo va escapado. Funcion pura.
     *
     * @param string $texto
     * @param array $datos Lista de ['n', 'encontrado', 'texto', 'titulo', 'enlace'].
     * @param bool $json
     * @return string
     */
    public static function apply_web_fill(string $texto, array $datos, bool $json = false): string {
        $porN = [];
        foreach ($datos as $d) {
            if (!is_array($d) || empty($d['encontrado'])) {
                continue;
            }
            $limpia = static fn($t): string => trim(preg_replace('/\s+/u', ' ', str_replace(['[', ']', '|', '"'], ['(', ')', '/', ''],
                (string) $t)));
            $enlace = trim((string) ($d['enlace'] ?? ''));
            $txt = $limpia($d['texto'] ?? '');
            if ($txt === '' || !preg_match('/^https?:\/\/[^\s\]|]+$/i', $enlace)) {
                continue;
            }
            $porN[(int) ($d['n'] ?? 0)] = '[[WEB 1 | ' . $limpia($d['titulo'] ?? '') . ' | ' . $enlace . ']] ' . $txt . ' [[/WEB]]';
        }
        if (!$porN) {
            return $texto;
        }
        $i = 0;
        return (string) preg_replace_callback('/\[REVISAR(?:\s*:\s*[^\]]+)?\]/u', static function(array $m) use (&$i, $porN, $json): string {
            $i++;
            if (!isset($porN[$i])) {
                return $m[0];
            }
            return $json ? substr(json_encode($porN[$i], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1) : $porN[$i];
        }, $texto);
    }

    /**
     * Pasada dirigida: busca en internet los datos marcados con [REVISAR] y los deja como fragmentos web. Si la busqueda
     * falla o no se puede leer, el material queda como estaba.
     *
     * @param string $raw Respuesta del asistente (Markdown o JSON).
     * @param array $material
     * @param string|null $modelo
     * @param bool $json
     * @return string
     */
    private static function fill_marks_from_web(string $raw, array $material, ?string $modelo, bool $json): string {
        $marcas = self::review_marks_context($raw);
        if (!$marcas) {
            return $raw;
        }
        $res = exante::generate_steady([['role' => 'user', 'parts' => [['text' => self::build_web_fill_prompt($material,
            $marcas)]]]], 150, $modelo, true);
        if (isset($res['error'])) {
            return $raw;
        }
        $datos = exante::decode_json((string) gemini_client::extract_text($res));
        if (!is_array($datos) || !is_array($datos['datos'] ?? null)) {
            return $raw;
        }
        return self::apply_web_fill($raw, $datos['datos'], $json);
    }

    /**
     * Origen de una fuente web por su dominio: «chile» (.cl), «extranjera» u «sin_enlace». Funcion pura.
     *
     * @param string $url
     * @return string
     */
    public static function source_origin(string $url): string {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));
        if ($host === '') {
            return 'sin_enlace';
        }
        return preg_match('/(^|\.)cl$/', $host) === 1 ? 'chile' : 'extranjera';
    }

    /**
     * Titulos de las referencias web ya escritas en un texto («Título — https://...»), por enlace. Funcion pura.
     *
     * @param string $texto Markdown o texto plano.
     * @return array url => titulo.
     */
    public static function reference_titles(string $texto): array {
        $out = [];
        if (preg_match_all('/^\s*(?:[-*•]\s*)?(.+?)\s+—\s+(https?:\/\/[^\s)<>]+)/mu', $texto, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[$x[2]] = trim($x[1]);
            }
        }
        return $out;
    }

    /**
     * Quita del Markdown la seccion «Referencias consultadas» que arma la plataforma (se vuelve a armar). Funcion pura.
     *
     * @param string $md
     * @return string
     */
    public static function remove_web_references(string $md): string {
        $lineas = explode("\n", $md);
        $out = [];
        $dentro = false;
        foreach ($lineas as $linea) {
            if (preg_match('/^\s*#{1,6}\s*\**\s*' . preg_quote(self::WEB_REFERENCIAS, '/') . '\s*\**\s*$/iu', $linea)) {
                $dentro = true;
                continue;
            }
            if ($dentro && (preg_match('/^\s*#{1,6}\s/u', $linea) || strpos($linea, self::PIE_MATERIAL) !== false)) {
                $dentro = false;
            }
            if (!$dentro) {
                $out[] = $linea;
            }
        }
        return implode("\n", $out);
    }

    /**
     * Prepara el Markdown de un material con fragmentos obtenidos de la web. Lee las marcas que escribe el asistente
     * («[[WEB 1 | Título | enlace]] texto [[/WEB]]») y las de un material ya guardado («[[WEB:n|enlace]] texto
     * [[/WEB:n]]»), las numera en orden, comprueba cada enlace con $verificar y las deja como marcadores de letras
     * (WEBXA1XA / WEBXC1XC) que el Markdown no altera; web_restore() las devuelve en el HTML. Con $conReferencias, arma la
     * seccion «Referencias consultadas» antes de la linea de cierre, indicando si cada fuente es chilena. Sin la mejora
     * encendida o sin marcas, el texto sigue igual (las marcas sueltas se quitan). Funcion pura (el verificador se inyecta).
     *
     * @param string $md
     * @param callable|null $verificar fn(string $url): array ['ok' => bool, 'url' => string]; null = no se comprueba.
     * @param bool $conReferencias
     * @return array [string $markdown, array $bloques n => ['url', 'titulo', 'origen', 'verificado']]
     */
    public static function prepare_web_markdown(string $md, ?callable $verificar, bool $conReferencias = true): array {
        $previas = self::reference_titles($md);
        if ($conReferencias) {
            $md = self::remove_web_references($md);
        }
        $bloques = [];
        $cache = [];
        $re = '/\[\[\s*WEB(?:\s+\d+\s*\|\s*([^|\]]*?)\s*\||\s*:\s*\d+\s*\|)\s*([^\]]*?)\s*\]\](.*?)\[\[\s*\/\s*WEB(?:\s*:\s*\d+)?\s*\]\]/su';
        $md = (string) preg_replace_callback($re, static function(array $m) use (&$bloques, &$cache, $previas, $verificar): string {
            $texto = $m[3];
            if (trim($texto) === '') {
                return $texto;
            }
            $url = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $url = preg_match('/^https?:\/\//i', $url) ? $url : '';
            $verificado = false;
            if ($url !== '' && $verificar !== null) {
                if (!array_key_exists($url, $cache)) {
                    $v = (array) $verificar($url);
                    $cache[$url] = !empty($v['ok']) ? (string) ($v['url'] ?? $url) : null;
                }
                $verificado = $cache[$url] !== null;
                $url = $verificado ? $cache[$url] : $url;
            }
            $titulo = trim($m[1]) !== '' ? trim($m[1]) : ($previas[$url] ?? '');
            $n = count($bloques) + 1;
            $bloques[$n] = ['url' => $url, 'titulo' => $titulo, 'origen' => self::source_origin($url),
                'verificado' => $verificar === null || $verificado];
            return 'WEBXA' . $n . 'XA' . $texto . 'WEBXC' . $n . 'XC';
        }, $md);
        // Marcas sin cerrar o sin texto: se quitan para no dejar corchetes en el material.
        $md = (string) preg_replace('/\[\[\s*\/?\s*WEB[^\]]*\]\]/u', '', $md);
        if ($conReferencias && $bloques) {
            $lineas = array_map(static fn(string $x): string => '- ' . $x, self::web_reference_lines($bloques));
            $seccion = "## " . self::WEB_REFERENCIAS . "\n\n" . implode("\n", $lineas) . "\n";
            $pos = strrpos($md, self::PIE_MATERIAL);
            if ($pos !== false) {
                $inicio = strrpos(substr($md, 0, $pos), "\n");
                $inicio = $inicio === false ? 0 : $inicio + 1;
                $md = rtrim(substr($md, 0, $inicio)) . "\n\n" . $seccion . "\n" . substr($md, $inicio);
            } else {
                $md = rtrim($md) . "\n\n" . $seccion;
            }
        }
        return [$md, $bloques];
    }

    /**
     * Una linea por fuente web, sin repetir: «Título — enlace (fuente chilena)», «(fuente extranjera: confirma que se
     * aplica en Chile)» o «(enlace no verificado)». Funcion pura.
     *
     * @param array $bloques n => ['url', 'titulo', 'origen', 'verificado']
     * @return string[]
     */
    public static function web_reference_lines(array $bloques): array {
        $lineas = [];
        $vistas = [];
        foreach ($bloques as $b) {
            $clave = $b['url'] !== '' ? $b['url'] : $b['titulo'];
            if ($clave === '' || isset($vistas[$clave])) {
                continue;
            }
            $vistas[$clave] = true;
            $titulo = $b['titulo'] !== '' ? $b['titulo'] : (string) parse_url($b['url'], PHP_URL_HOST);
            if ($b['url'] === '' || !$b['verificado']) {
                $lineas[] = $titulo . ($b['url'] !== '' ? ' — ' . $b['url'] : '') . ' (enlace no verificado)';
            } else {
                $lineas[] = $titulo . ' — ' . $b['url'] . ($b['origen'] === 'chile' ? ' (fuente chilena)'
                    : ' (fuente extranjera: confirma que se aplica en Chile)');
            }
        }
        return $lineas;
    }

    /**
     * Marcas web en un material estructurado (cuestionario, planilla o presentacion): convierte las marcas del asistente
     * de cada texto del JSON a «[[WEB:n|enlace]] texto [[/WEB:n]]», comprueba cada enlace y deja en «fuentes» las lineas
     * de referencia (las de una version anterior se reemplazan). Funcion pura (el verificador se inyecta).
     *
     * @param array $json
     * @param callable|null $verificar
     * @return array [array $json, array $bloques]
     */
    public static function web_json_prepare(array $json, ?callable $verificar): array {
        $fuentes = array_values(array_filter(array_map('strval', (array) ($json['fuentes'] ?? [])), static fn(string $f): bool
            => !preg_match('/\((fuente chilena|fuente extranjera|enlace no verificado)/u', $f)));
        $previas = self::reference_titles(implode("\n", array_map('strval', (array) ($json['fuentes'] ?? []))));
        $bloques = [];
        $cache = [];
        $re = '/\[\[\s*WEB(?:\s+\d+\s*\|\s*([^|\]]*?)\s*\||\s*:\s*\d+\s*\|)\s*([^\]]*?)\s*\]\](.*?)\[\[\s*\/\s*WEB(?:\s*:\s*\d+)?\s*\]\]/su';
        $recorrer = static function($valor, string $clave = '') use (&$recorrer, &$bloques, &$cache, $previas, $verificar, $re) {
            if (is_array($valor)) {
                foreach ($valor as $k => $v) {
                    if ($clave === '' && $k === 'fuentes') {
                        continue;
                    }
                    $valor[$k] = $recorrer($v, (string) $k . '.');
                }
                return $valor;
            }
            if (!is_string($valor) || strpos($valor, 'WEB') === false) {
                return $valor;
            }
            $valor = (string) preg_replace_callback($re, static function(array $m) use (&$bloques, &$cache, $previas, $verificar): string {
                if (trim($m[3]) === '') {
                    return $m[3];
                }
                $url = trim($m[2]);
                $url = preg_match('/^https?:\/\//i', $url) ? $url : '';
                $verificado = false;
                if ($url !== '' && $verificar !== null) {
                    if (!array_key_exists($url, $cache)) {
                        $v = (array) $verificar($url);
                        $cache[$url] = !empty($v['ok']) ? (string) ($v['url'] ?? $url) : null;
                    }
                    $verificado = $cache[$url] !== null;
                    $url = $verificado ? $cache[$url] : $url;
                }
                $n = count($bloques) + 1;
                $bloques[$n] = ['url' => $url, 'titulo' => trim($m[1]) !== '' ? trim($m[1]) : ($previas[$url] ?? ''),
                    'origen' => self::source_origin($url), 'verificado' => $verificar === null || $verificado];
                return '[[WEB:' . $n . '|' . $url . ']]' . trim($m[3]) . '[[/WEB:' . $n . ']]';
            }, $valor);
            return (string) preg_replace('/\[\[\s*\/?\s*WEB(?![:]\d+\|[^\]]*\]\])(?![:]\d+\]\])[^\]]*\]\]/u', '', $valor);
        };
        $json = $recorrer($json);
        if ($bloques) {
            $json['fuentes'] = array_merge($fuentes, self::web_reference_lines($bloques));
        }
        return [$json, $bloques];
    }

    /**
     * Fragmentos web pendientes de un material estructurado, con el mismo formato que web_blocks(). Funcion pura.
     *
     * @param array $json
     * @return array
     */
    public static function web_blocks_json(array $json): array {
        $titulos = self::reference_titles(implode("\n", array_map('strval', (array) ($json['fuentes'] ?? []))));
        $out = [];
        array_walk_recursive($json, static function($v) use (&$out, $titulos) {
            if (!is_string($v) || !preg_match_all('/\[\[WEB:(\d+)\|([^\]]*)\]\](.*?)\[\[\/WEB:\1\]\]/su', $v, $m, PREG_SET_ORDER)) {
                return;
            }
            foreach ($m as $x) {
                $out[(int) $x[1]] = ['texto' => trim($x[3]), 'url' => $x[2], 'titulo' => $titulos[$x[2]] ?? '',
                    'origen' => self::source_origin($x[2])];
            }
        });
        ksort($out);
        return $out;
    }

    /**
     * resolve_web_block() para un material estructurado: busca el fragmento en los textos del JSON. Funcion pura.
     *
     * @param array $json
     * @param int $n
     * @param string|null $reemplazo Null = aceptar.
     * @return array|null
     */
    public static function resolve_web_block_json(array $json, int $n, ?string $reemplazo = null): ?array {
        $hecho = false;
        $re = '/\[\[WEB:' . $n . '\|[^\]]*\]\](.*?)\[\[\/WEB:' . $n . '\]\]/su';
        array_walk_recursive($json, static function(&$v) use (&$hecho, $re, $reemplazo) {
            if ($hecho || !is_string($v) || !preg_match($re, $v, $m, PREG_OFFSET_CAPTURE)) {
                return;
            }
            $nuevo = $reemplazo === null ? $m[1][0] : trim($reemplazo);
            $v = substr($v, 0, $m[0][1]) . $nuevo . substr($v, $m[0][1] + strlen($m[0][0]));
            $hecho = true;
        });
        return $hecho ? $json : null;
    }

    /**
     * Modo C: cambia el momento de uso, la justificacion o las advertencias de una opcion de la ficha de recomendacion
     * (el titulo, la fuente y el enlace verificado no se editan). Null si la opcion no existe. Funcion pura.
     *
     * @param array $cur JSON de la curaduria.
     * @param int $indice Desde 0.
     * @param array $campos ['momento' => ..., 'justificacion' => ..., 'advertencias' => ...]
     * @return array|null
     */
    public static function edit_curated_option(array $cur, int $indice, array $campos): ?array {
        if (!isset($cur['opciones'][$indice]) || !is_array($cur['opciones'][$indice])) {
            return null;
        }
        foreach (['momento', 'justificacion', 'advertencias'] as $c) {
            if (array_key_exists($c, $campos)) {
                $cur['opciones'][$indice][$c] = trim((string) $campos[$c]);
            }
        }
        return $cur;
    }

    /**
     * Devuelve al HTML las marcas web de prepare_web_markdown(): «[[WEB:n|enlace]]» y «[[/WEB:n]]». Funcion pura.
     *
     * @param string $html
     * @param array $bloques
     * @return string
     */
    public static function web_restore(string $html, array $bloques): string {
        $html = (string) preg_replace_callback('/WEBXA(\d+)XA/', static function(array $m) use ($bloques): string {
            $url = (string) ($bloques[(int) $m[1]]['url'] ?? '');
            return '[[WEB:' . (int) $m[1] . '|' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . ']]';
        }, $html);
        return (string) preg_replace('/WEBXC(\d+)XC/', '[[/WEB:$1]]', $html);
    }

    /**
     * Fragmentos obtenidos de la web que la persona aun no revisa, en orden. Funcion pura.
     *
     * @param string $html Contenido guardado del material.
     * @return array n => ['texto' => string, 'url' => string, 'titulo' => string, 'origen' => string]
     */
    public static function web_blocks(string $html): array {
        $plano = html_entity_decode(strip_tags(preg_replace('#</(li|p|h[1-6]|tr|div)>|<br\s*/?>#i', "\n", $html)),
            ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $titulos = self::reference_titles($plano);
        $out = [];
        if (preg_match_all('/\[\[WEB:(\d+)\|([^\]]*)\]\](.*?)\[\[\/WEB:\1\]\]/su', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $url = html_entity_decode($x[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $texto = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($x[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $out[(int) $x[1]] = ['texto' => $texto, 'url' => $url, 'titulo' => $titulos[$url] ?? '',
                    'origen' => self::source_origin($url)];
            }
        }
        return $out;
    }

    /**
     * Revisa un fragmento web: sin reemplazo lo acepta (quita las marcas y deja el texto); con reemplazo cambia SOLO ese
     * fragmento por el texto escrito. El resto del material no se toca. Null si el fragmento no existe. Funcion pura.
     *
     * @param string $html
     * @param int $n
     * @param string|null $reemplazo
     * @return string|null
     */
    public static function resolve_web_block(string $html, int $n, ?string $reemplazo = null): ?string {
        $re = '/\[\[WEB:' . $n . '\|[^\]]*\]\](.*?)\[\[\/WEB:' . $n . '\]\]/su';
        if (!preg_match($re, $html, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $nuevo = $reemplazo === null ? $m[1][0] : htmlspecialchars(trim($reemplazo), ENT_QUOTES, 'UTF-8');
        return substr($html, 0, $m[0][1]) . $nuevo . substr($html, $m[0][1] + strlen($m[0][0]));
    }

    /**
     * Quita las marcas web (para descargas, HTML de Moodle y copia). Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function strip_web_marks(string $html): string {
        // En el JSON guardado la barra del cierre va escapada («[[\/WEB:n]]»).
        return (string) preg_replace('/\[\[WEB:\d+\|[^\]]*\]\]|\[\[\\\\?\/WEB:\d+\]\]/u', '', $html);
    }

    /**
     * Muestra en pantalla donde empieza y termina cada fragmento web pendiente. Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function render_web_marks(string $html): string {
        $estilo = 'background:#e0f2fe;color:#075985;font-weight:600;';
        $html = (string) preg_replace('/\[\[WEB:(\d+)\|[^\]]*\]\]/u',
            '<span class="badge cr-web me-1" style="' . $estilo . '"><i class="fa fa-globe me-1"></i>Web $1</span>', $html);
        return (string) preg_replace('/\[\[\/WEB:(\d+)\]\]/u',
            '<span class="badge cr-web ms-1" style="' . $estilo . '">/Web $1</span>', $html);
    }

    /**
     * Guarda la revision de un fragmento web (Aceptar o Editar) y recalcula el HTML para Moodle; un material entregado
     * vuelve a borrador para registrar la version siguiente.
     *
     * @param int $id
     * @param int $userid
     * @param int $n
     * @param string|null $reemplazo Null = aceptar.
     * @return bool
     */
    public static function save_web_block(int $id, int $userid, int $n, ?string $reemplazo = null): bool {
        global $DB;
        $r = self::get_resource($id, $userid);
        if (!$r) {
            return false;
        }
        $estructurado = null;
        if (!empty($r->estructurado)) {
            $json = self::resolve_web_block_json((array) json_decode((string) $r->estructurado, true), $n, $reemplazo);
            if ($json === null || strtoupper((string) $r->modo) === 'C') {
                return false;
            }
            $estructurado = json_encode($json, JSON_UNESCAPED_UNICODE);
            $nuevo = self::json_preview_html($json, $r, self::is_quiz_material((string) $r->nombre, (string) $r->formato),
                self::detect_formats(strtoupper((string) $r->modo), (string) $r->formato, (string) $r->nombre,
                    (string) ($r->plataforma_quiz ?? '')));
        } else {
            $nuevo = self::resolve_web_block((string) $r->contenido, $n, $reemplazo);
        }
        if ($nuevo === null) {
            return false;
        }
        $DB->update_record('local_chatbot_recurso', (object) [
            'id' => $r->id,
            'contenido' => $nuevo,
            'estructurado' => $estructurado ?? $r->estructurado,
            'html_moodle' => recurso_export::to_moodle_html(self::strip_web_marks($nuevo), (string) $r->nombre, (string) $r->codigo),
            'revisar_count' => self::count_review_marks($nuevo),
            'estado' => self::state_after_change((string) $r->estado, $reemplazo !== null),
            'timemodified' => time(),
        ]);
        return true;
    }

    /**
     * Resuelve la marca [REVISAR] numero $indice (desde 0, en el orden del material): con $reemplazo vacio la quita (el
     * dato se verifico y queda tal cual); si no, la cambia por el dato correcto. Lo demas no se toca. Null si no existe
     * esa marca. Funcion pura.
     *
     * @param string $html Contenido del material.
     * @param int $indice
     * @param string $reemplazo Texto plano.
     * @return string|null
     */
    public static function resolve_mark(string $html, int $indice, string $reemplazo = ''): ?string {
        if (!preg_match_all('/\s*\[REVISAR(?:\s*:\s*[^\]]+)?\]/u', $html, $m, PREG_OFFSET_CAPTURE) || !isset($m[0][$indice])) {
            return null;
        }
        [$marca, $pos] = $m[0][$indice];
        $reemplazo = trim($reemplazo);
        $nuevo = $reemplazo === '' ? '' : ' ' . htmlspecialchars($reemplazo, ENT_QUOTES, 'UTF-8');
        return substr($html, 0, $pos) . $nuevo . substr($html, $pos + strlen($marca));
    }

    /**
     * Aplica resolve_mark() al material guardado (Etapa 10.4): recalcula marcas y HTML para Moodle, y un material
     * entregado vuelve a borrador para registrar la version siguiente.
     *
     * @param int $id
     * @param int $userid
     * @param int $indice
     * @param string $reemplazo
     * @return bool
     */
    public static function save_resolved_mark(int $id, int $userid, int $indice, string $reemplazo = ''): bool {
        global $DB;
        $r = self::get_resource($id, $userid);
        if (!$r || !empty($r->estructurado)) {
            return false;
        }
        $nuevo = self::resolve_mark((string) $r->contenido, $indice, $reemplazo);
        if ($nuevo === null) {
            return false;
        }
        $DB->update_record('local_chatbot_recurso', (object) [
            'id' => $r->id,
            'contenido' => $nuevo,
            'html_moodle' => recurso_export::to_moodle_html(self::strip_web_marks($nuevo), (string) $r->nombre, (string) $r->codigo),
            'revisar_count' => self::count_review_marks($nuevo),
            'estado' => self::state_after_change((string) $r->estado, true),
            'timemodified' => time(),
        ]);
        return true;
    }

    /**
     * Guarda edicion manual del material en pantalla y recalcula marcas [REVISAR] y HTML Moodle.
     */
    public static function save_manual_edit(int $id, int $userid, string $html): void {
        global $DB;
        $r = self::get_resource($id, $userid);
        if (!$r) {
            return;
        }
        $limpio = clean_text($html, FORMAT_HTML);
        $DB->update_record('local_chatbot_recurso', (object) [
            'id' => $r->id,
            'contenido' => $limpio,
            'html_moodle' => recurso_export::to_moodle_html(self::strip_web_marks($limpio), (string) $r->nombre, (string) $r->codigo),
            'revisar_count' => self::count_review_marks($limpio),
            'estado' => self::state_after_change((string) $r->estado, $limpio !== (string) $r->contenido),
            'timemodified' => time(),
        ]);
    }

    /**
     * Registra la seleccion de recursos curados (Modo C) elegidos por la persona facilitadora.
     */
    /**
     * Modo C: guarda la edicion de una opcion de la ficha de recomendacion (momento, justificacion y advertencias).
     *
     * @return bool
     */
    public static function save_curated_option(int $id, int $userid, int $indice, array $campos): bool {
        global $DB;
        $r = self::get_resource($id, $userid);
        if (!$r || empty($r->estructurado) || strtoupper((string) $r->modo) !== 'C') {
            return false;
        }
        $cur = self::edit_curated_option((array) json_decode((string) $r->estructurado, true), $indice, $campos);
        if ($cur === null) {
            return false;
        }
        $sel = array_map('intval', (array) (json_decode((string) ($r->seleccionados ?? '[]'), true) ?: []));
        $html = self::curaduria_html((array) $cur['opciones'], (string) ($r->fecha_verificacion ?? ''), (string) $r->codigo, $sel);
        $DB->update_record('local_chatbot_recurso', (object) [
            'id' => $r->id,
            'estructurado' => json_encode($cur, JSON_UNESCAPED_UNICODE),
            'contenido' => $html,
            'html_moodle' => recurso_export::to_moodle_html($html, (string) $r->nombre, (string) $r->codigo),
            'estado' => self::state_after_change((string) $r->estado, true),
            'timemodified' => time(),
        ]);
        return true;
    }

    public static function select_curated(int $id, int $userid, array $indices): void {
        global $DB;
        $r = self::get_resource($id, $userid);
        if (!$r || empty($r->estructurado)) {
            return;
        }
        $cur = json_decode((string) $r->estructurado, true);
        $sel = array_values(array_unique(array_map('intval', $indices)));
        $html = self::curaduria_html((array) ($cur['opciones'] ?? []), (string) ($r->fecha_verificacion ?? ''),
            (string) $r->codigo, $sel);
        $DB->update_record('local_chatbot_recurso', (object) [
            'id' => $r->id,
            'seleccionados' => json_encode($sel),
            'estado' => self::state_after_change((string) $r->estado, json_encode($sel) !== (string) ($r->seleccionados ?? '')),
            'contenido' => $html,
            'html_moodle' => recurso_export::to_moodle_html($html, (string) $r->nombre, (string) $r->codigo),
            'timemodified' => time(),
        ]);
    }

    /**
     * Etapa 11: aprueba y registra el material entregado en el repositorio (incrementando version v1, v2...).
     */
    public static function approve_material(int $id, int $userid, bool $parcial = false): void {
        global $DB;
        $r = self::get_resource($id, $userid);
        if (!$r || trim((string) $r->contenido) === '') {
            return;
        }
        $now = time();
        $DB->update_record('local_chatbot_recurso', (object) [
            'id' => $r->id,
            'estado' => $parcial ? self::MAT_ENTREGADO_PARCIAL : self::MAT_ENTREGADO,
            'version' => ((int) $r->version) + 1,
            'revisar_count' => self::count_review_marks((string) $r->contenido),
            'timemodified' => $now,
        ]);
        $DB->set_field('local_chatbot_recurso_plan', 'estado', self::PLAN_EN_PRODUCCION, ['id' => $r->planid]);
    }

    /**
     * Etapa 12: cierra el centro para el diseno seleccionado.
     */
    public static function close_center(int $fichaid, int $userid): void {
        global $DB;
        $plan = $DB->get_record('local_chatbot_recurso_plan', ['fichaid' => $fichaid, 'userid' => $userid]);
        if ($plan) {
            $now = time();
            $DB->update_record('local_chatbot_recurso_plan', (object) [
                'id' => $plan->id, 'estado' => self::PLAN_CERRADO, 'timeclosed' => $now, 'timemodified' => $now,
            ]);
        }
    }

    /**
     * Reabre un centro cerrado para producir materiales pendientes o nuevas versiones.
     */
    public static function reopen_center(int $fichaid, int $userid): void {
        global $DB;
        $plan = $DB->get_record('local_chatbot_recurso_plan', ['fichaid' => $fichaid, 'userid' => $userid]);
        if ($plan) {
            $DB->update_record('local_chatbot_recurso_plan', (object) [
                'id' => $plan->id, 'estado' => self::PLAN_EN_PRODUCCION, 'timemodified' => time(),
            ]);
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Materiales tecnicos propios de referencia (Moodle File API).
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Lista los materiales de referencia cargados para un diseno.
     */
    public static function list_references(int $fichaid): array {
        global $DB;
        return array_values($DB->get_records('local_chatbot_recurso_ref', ['fichaid' => $fichaid], 'timecreated ASC'));
    }

    /**
     * Guarda un archivo tecnico de referencia (PDF, DOCX, XLSX o imagen) en el repositorio del diseno.
     */
    public static function add_reference(int $fichaid, int $userid, int $courseid, string $tmpPath, string $nombre,
            string $mime, string $secciones = ''): ?int {
        global $DB;
        if (!is_file($tmpPath) || filesize($tmpPath) > self::REF_MAX_BYTES || self::reference_kind($mime, $nombre) === null) {
            return null;
        }
        $rec = (object) [
            'fichaid' => $fichaid,
            'userid' => $userid,
            'courseid' => $courseid,
            'filename' => clean_param($nombre, PARAM_FILE) ?: 'referencia',
            'mimetype' => $mime,
            'sizebytes' => (int) filesize($tmpPath),
            'secciones' => mb_substr(trim($secciones), 0, 255, 'UTF-8'),
            'timecreated' => time(),
        ];
        $id = (int) $DB->insert_record('local_chatbot_recurso_ref', $rec);
        $fs = get_file_storage();
        $fs->create_file_from_pathname([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_chatbot',
            'filearea' => self::FILEAREA_REF,
            'itemid' => $id,
            'filepath' => '/',
            'filename' => $rec->filename,
        ], $tmpPath);
        return $id;
    }

    /**
     * Elimina un archivo de referencia del repositorio del diseno.
     */
    public static function delete_reference(int $refid, int $userid): void {
        global $DB;
        if (!$DB->record_exists('local_chatbot_recurso_ref', ['id' => $refid, 'userid' => $userid])) {
            return;
        }
        $fs = get_file_storage();
        $fs->delete_area_files(\context_system::instance()->id, 'local_chatbot', self::FILEAREA_REF, $refid);
        $DB->delete_records('local_chatbot_recurso_ref', ['id' => $refid]);
    }

    /**
     * Arma las partes del pedido al modelo para los materiales tecnicos de referencia seleccionados.
     *
     * @param int $fichaid
     * @param int[] $refIds
     * @return array [partes, nombres_con_secciones].
     */
    public static function reference_parts(int $fichaid, array $refIds): array {
        global $DB;
        if (!$refIds) {
            return [[], []];
        }
        $fs = get_file_storage();
        $ctxid = \context_system::instance()->id;
        $partes = [];
        $nombres = [];
        foreach (array_slice(array_unique(array_map('intval', $refIds)), 0, self::REF_MAX_ACTIVOS) as $rid) {
            $ref = $DB->get_record('local_chatbot_recurso_ref', ['id' => $rid, 'fichaid' => $fichaid]);
            if (!$ref) {
                continue;
            }
            $files = $fs->get_area_files($ctxid, 'local_chatbot', self::FILEAREA_REF, $rid, 'id', false);
            if (!$files) {
                continue;
            }
            $archivo = reset($files);
            $tipo = self::reference_kind((string) $ref->mimetype, (string) $ref->filename);
            $secNota = trim((string) ($ref->secciones ?? '')) !== '' ? ' (secciones indicadas: ' . $ref->secciones . ')' : '';
            $nombres[] = $ref->filename . $secNota;
            $aviso = guardrails::attachment_notice() . "\nMaterial técnico de referencia de la persona facilitadora: "
                . $ref->filename . $secNota . '.';
            if ($tipo === 'pdf' || $tipo === 'imagen') {
                $partes[] = ['text' => $aviso];
                $partes[] = ['inlineData' => [
                    'mimeType' => (string) $ref->mimetype,
                    'data' => base64_encode($archivo->get_content()),
                ]];
                continue;
            }
            $tmp = $archivo->copy_content_to_temp();
            $txt = null;
            if ($tmp) {
                $txt = $tipo === 'xlsx' ? recurso_export::xlsx_text($tmp) : exante::docx_text($tmp);
                @unlink($tmp);
            }
            if ($txt !== null && trim($txt) !== '') {
                $partes[] = ['text' => $aviso . "\n" . guardrails::isolate_user_content(
                    mb_substr(pseudonymizer::scrub($txt), 0, 45000, 'UTF-8'))];
            }
        }
        return [$partes, $nombres];
    }

    /**
     * Genera en disco un archivo descargable concreto de un material y devuelve [ruta, nombreArchivo, mimetype].
     *
     * @param \stdClass $r Fila de local_chatbot_recurso.
     * @param string $tipo 'docx'|'xlsx'|'pptx'|'pdf'|'gift'|'gs'|'html'|'quiz_docx'.
     * @param string $dir Directorio temporal de trabajo.
     * @param string $impresion Formato preferido ('Carta' o 'A4').
     * @param string $logo Ruta a logo Interfases.
     * @return array|null [ruta, filename, mimetype].
     */
    public static function export_material_file(\stdClass $r, string $tipo, string $dir, string $impresion = 'Carta',
            string $logo = ''): ?array {
        // Lo guardado antes del arreglo de diagramas: las listas con conectores salen como tarjetas con flechas.
        $r = clone $r;
        $r->contenido = diagrama::list_flows(self::strip_web_marks((string) $r->contenido));
        $r->html_moodle = !empty($r->html_moodle) ? diagrama::list_flows((string) $r->html_moodle) : '';
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '_', modulo2_setup::ascii_filename($r->codigo . '_' . $r->nombre));
        $base = trim(mb_substr((string) $base, 0, 60, 'UTF-8'), '_') ?: 'material';
        $ver = ((int) $r->version) > 0 ? ('_v' . (int) $r->version) : '';
        $json = !empty($r->estructurado) ? json_decode(self::strip_web_marks((string) $r->estructurado), true) : null;
        $pie = $r->codigo . ' · ' . self::PIE_MATERIAL;

        switch ($tipo) {
            case 'html':
                $ruta = $dir . '/' . $base . $ver . '.html';
                $html = recurso_export::to_moodle_html((string) $r->contenido, (string) $r->nombre, (string) $r->codigo);
                file_put_contents($ruta, $html);
                return [$ruta, $base . $ver . '.html', 'text/html; charset=utf-8'];

            case 'gift':
                if (!is_array($json)) {
                    return null;
                }
                $ruta = $dir . '/' . $base . $ver . '_moodle_GIFT.txt';
                file_put_contents($ruta, recurso_export::to_gift($json));
                return [$ruta, $base . $ver . '_moodle_GIFT.txt', 'text/plain; charset=utf-8'];

            case 'gs':
                if (!is_array($json)) {
                    return null;
                }
                $ruta = $dir . '/' . $base . $ver . '_GoogleForms.gs.txt';
                file_put_contents($ruta, recurso_export::to_google_apps_script($json, (string) $r->nombre));
                return [$ruta, $base . $ver . '_GoogleForms.gs.txt', 'text/plain; charset=utf-8'];

            case 'xlsx':
                $ruta = $dir . '/' . $base . $ver . '.xlsx';
                $hojas = (is_array($json) && !empty($json['hojas']))
                    ? $json['hojas']
                    : recurso_export::html_to_sheets((string) $r->contenido, (string) $r->nombre);
                return recurso_export::build_xlsx($ruta, $hojas, (string) $r->nombre)
                    ? [$ruta, $base . $ver . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
                    : null;

            case 'pptx':
                $ruta = $dir . '/' . $base . $ver . '.pptx';
                $slides = (is_array($json) && !empty($json['diapositivas']))
                    ? $json['diapositivas']
                    : recurso_export::html_to_slides((string) $r->contenido, (string) $r->nombre);
                $sub = is_array($json) ? (string) ($json['subtitulo'] ?? '') : '';
                return recurso_export::build_pptx($ruta, (string) $r->nombre, $sub, $slides, $pie)
                    ? [$ruta, $base . $ver . '.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation']
                    : null;

            case 'pdf':
                $ruta = $dir . '/' . $base . $ver . '.pdf';
                return recurso_export::build_pdf($ruta, (string) $r->codigo . ' · ' . $r->nombre, '',
                    (string) $r->contenido, (string) $r->formato, $impresion, $logo)
                    ? [$ruta, $base . $ver . '.pdf', 'application/pdf']
                    : null;

            case 'quiz_docx':
            case 'docx':
            default:
                $ruta = $dir . '/' . $base . $ver . '.docx';
                $html = ($tipo === 'quiz_docx' && is_array($json))
                    ? recurso_export::to_printable_quiz_html($json, (string) $r->nombre, $pie)
                    : (string) $r->contenido;
                return recurso_export::build_docx($ruta, (string) $r->codigo . ' · ' . $r->nombre, '', $html, $logo)
                    ? [$ruta, $base . $ver . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']
                    : null;
        }
    }
}


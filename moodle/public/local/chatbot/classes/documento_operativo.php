<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Modulo 2, salida operativa al cierre de la Etapa 8: Documento operativo de la experiencia de aprendizaje.
 *
 * Especificacion de Interfases (24-11-2026): version sintetica de trabajo del diseno validado (>= 25 puntos en la
 * Etapa 8) que la persona facilitadora usa para conducir y evaluar la experiencia en aula o taller.
 *
 * Arquitectura hibrida (fidelidad + economia):
 *  - Secciones 1 a 5 (8 a 12 paginas sinteticas): extraidas con el asistente a partir de la ficha validada y las
 *    Etapas 2 a 6 (sin Etapa 1, justificaciones, vacios, verificaciones ni referencias), respetando la regla de fuente
 *    unica y marcando diferencias con [REVISAR: diferencia entre etapas].
 *  - Anexos (Instrumentos de evaluacion): extraidos deterministicamente en PHP desde la Etapa 6 aceptada (sin sus
 *    secciones de cierre), conservando integros cada item, puntaje, umbral, casilla y regla de equivalencia.
 *  - Estado de materiales en 4.1 (Disponible en el repertorio / Pendiente): sincronizado en tiempo real desde
 *    local_chatbot_recurso al descargar el Word, sin regenerar con IA.
 *
 * Se guarda en local_chatbot_diseno con etapa = diseno::OPERATIVO (9), huella de la consolidacion vigente y numero de
 * version en el campo rondas, sin cambios de esquema en BD.
 *
 * Todo lo que no consulta BD es funcion pura y lo ejercita cli/verificar_documento_operativo.php.
 */
class documento_operativo {

    /** @var int Numero de etapa interna en local_chatbot_diseno (fuera de diseno::todas() 1..8). */
    const ETAPA = 9;

    /** @var string Version del prompt del documento operativo (RU-PRIV-006). */
    const PROMPT_VERSION = 'M2-OP-v2';

    /** @var int Segundos maximos antes de dar por caida una generacion en curso. */
    const MAX_SECONDS = 360;

    /** @var string Nombre base del archivo Word descargable. */
    const ARCHIVO = 'Documento_operativo_experiencia';

    /**
     * Si el diseno evaluado habilita el documento operativo: solo con 25 puntos o mas (aprobado). Funcion pura.
     *
     * @param array|null $evaluacion Resultado de diseno::current_evaluation().
     * @return bool
     */
    public static function can_generate(?array $evaluacion): bool {
        return is_array($evaluacion)
            && !empty($evaluacion['banda']['aprobado'])
            && (int) ($evaluacion['total'] ?? 0) >= diseno::APROBADO_DESDE;
    }

    /**
     * Si existe un documento operativo listo y vigente para la huella consolidada actual. Funcion pura.
     *
     * @param \stdClass|null $fila Fila etapa = self::ETAPA de local_chatbot_diseno.
     * @param string $huella Huella de la consolidacion confirmada (etapa 7).
     * @return bool
     */
    public static function is_current(?\stdClass $fila, string $huella): bool {
        return $fila !== null
            && $huella !== ''
            && (string) ($fila->generacion ?? '') === exante::LISTO
            && (string) ($fila->huella ?? '') === $huella
            && trim(strip_tags((string) ($fila->contenido ?? ''))) !== '';
    }

    /**
     * Numero de version del documento operativo (1, 2, 3...). Funcion pura.
     *
     * @param \stdClass|null $fila
     * @return int
     */
    public static function version_number(?\stdClass $fila): int {
        return max(1, (int) ($fila->rondas ?? 1));
    }

    /**
     * Elimina de una etapa aceptada sus secciones de cierre no operativas (registro de ajustes, vacios de informacion,
     * verificacion de calidad, referencias consultadas, fuentes web y anexo de consistencia). Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function strip_stage_closures(string $html): string {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        // Quita el bloque de fuentes web agregadas por la plataforma al final en negrita + lista.
        $html = (string) preg_replace(
            '#<p>\s*<strong>\s*Fuentes web consultadas[^<]*</strong>\s*</p>\s*<ul>.*?</ul>\s*#isu',
            '',
            $html
        );
        $patron = '/^(?:registro de ajustes|registro de vacios|vacios? (?:de informacion|y soluciones)|'
            . 'verificacion de (?:la )?calidad|referencias consultadas|fuentes web consultadas|'
            . '(?:anexo\s*:\s*)?verificacion de consistencia)/u';
        if (!preg_match_all('#<h([1-6])\b[^>]*>(.*?)</h\1>#isu', $html, $titulos, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return trim($html);
        }
        $cortes = [];
        $n = count($titulos);
        for ($i = 0; $i < $n; $i++) {
            $nivel = (int) $titulos[$i][1][0];
            $texto = diseno::plain_lower(trim(preg_replace('/\s+/u', ' ',
                html_entity_decode(strip_tags($titulos[$i][2][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
            $texto = (string) preg_replace('/^\d+(?:\.\d+)*\.?\s*/u', '', $texto);
            if (!preg_match($patron, $texto)) {
                continue;
            }
            $inicio = (int) $titulos[$i][0][1];
            $fin = strlen($html);
            for ($j = $i + 1; $j < $n; $j++) {
                if ((int) $titulos[$j][1][0] <= $nivel) {
                    $fin = (int) $titulos[$j][0][1];
                    break;
                }
            }
            $cortes[] = [$inicio, $fin];
        }
        if (!$cortes) {
            return trim($html);
        }
        // Recorta de atras hacia adelante evitando solapamientos.
        usort($cortes, static fn($a, $b) => $b[0] <=> $a[0]);
        $ultimoInicio = strlen($html) + 1;
        foreach ($cortes as [$ini, $fin]) {
            if ($fin > $ultimoInicio) {
                $fin = $ultimoInicio;
            }
            if ($ini < $fin) {
                $html = substr($html, 0, $ini) . substr($html, $fin);
                $ultimoInicio = $ini;
            }
        }
        return trim($html);
    }

    /**
     * Extrae de forma deterministica los instrumentos completos de la Etapa 6 aceptada para el bloque de ANEXOS del
     * documento operativo, sin modificar items, puntajes, umbrales, casillas ni escalas, y quitando las verificaciones
     * de calidad, vacios y referencias. Funcion pura.
     *
     * @param string $etapa6Html Producto aceptado de la Etapa 6.
     * @return string HTML del bloque ANEXOS ('' si la Etapa 6 viene vacia).
     */
    public static function extract_stage6_instruments(string $etapa6Html): string {
        $limpio = self::strip_stage_closures(diseno::normalize_product($etapa6Html));
        if (trim(strip_tags($limpio)) === '') {
            return '';
        }
        // Quita el titulo principal «Etapa 6: Instrumentos evaluativos» si viene al inicio.
        $limpio = (string) preg_replace(
            '/^\s*<h[1-3]\b[^>]*>\s*(?:<[^>]+>\s*)*(?:etapa|parte)\s+6\b.*?<\/h[1-3]>\s*/isu',
            '',
            $limpio,
            1
        );
        $limpio = trim($limpio);
        if (trim(strip_tags($limpio)) === '') {
            return '';
        }
        $limpio = self::annex_per_instrument($limpio);
        // Si los instrumentos venian como <h1>, bajamos un nivel para que <h1> quede reservado al banner ANEXOS.
        if (preg_match('/<h1\b/i', $limpio)) {
            $limpio = (string) preg_replace_callback('#<(/?)h([1-5])\b#i', static function(array $m): string {
                return '<' . $m[1] . 'h' . min(6, (int) $m[2] + 1);
            }, $limpio);
        }
        return '<h1>ANEXOS · Instrumentos de evaluación</h1>' . "\n" . $limpio;
    }

    /**
     * Deja un anexo por instrumento, como pide la propuesta generica del cliente («Anexo 1 · [IN1]…», en el orden del
     * registro): quita lo que la Etapa 6 trae antes del primer instrumento (presentacion, fundamentos y matriz de
     * alineacion, que ya estan en el consolidado) y antepone «Anexo N · » a cada titulo de instrumento. Si no reconoce
     * ningun titulo de instrumento deja el texto como esta. Funcion pura.
     *
     * @param string $html Etapa 6 sin titulo de etapa ni cierres.
     * @return string
     */
    public static function annex_per_instrument(string $html): string {
        $rePref = '(?:\d+(?:\.\d+)*[.)]?\s+)?';
        $reTitulo = '#(<h([1-4])\b[^>]*>)(\s*(?:<[^>]+>\s*)*)' . $rePref . '(instrumento\s+(?:n\.?\s*[º°o]\s*)?\d+[a-z]?\b)#iu';
        if (!preg_match($reTitulo, $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $nivel = $m[2][0];
        $html = substr($html, $m[0][1]);
        $n = 0;
        return (string) preg_replace_callback(
            '#(<h' . $nivel . '\b[^>]*>)(\s*(?:<[^>]+>\s*)*)' . $rePref . '(instrumento\s+(?:n\.?\s*[º°o]\s*)?\d+[a-z]?\b)#iu',
            static function(array $x) use (&$n): string {
                $n++;
                return $x[1] . $x[2] . 'Anexo ' . $n . ' · ' . $x[3];
            },
            $html
        );
    }

    /**
     * Mapa de disponibilidad de cada material en el repertorio del Centro de produccion de recursos. Funcion pura.
     *
     * @param array $recursos Filas de local_chatbot_recurso (centro_recursos::get_resources()).
     * @return array codigo en mayusculas (ej. 'M1') => 'Disponible en el repertorio'|'Parcialmente disponible en el repertorio'|'Pendiente'.
     */
    public static function repertoire_status_map(array $recursos): array {
        if (!$recursos) {
            return [];
        }
        $mapa = [];
        foreach (centro_recursos::material_groups($recursos) as $codigo => $grupo) {
            if (strpos((string) $codigo, '(ant.)') !== false) {
                continue;
            }
            $cod = strtoupper(trim((string) $codigo));
            if ($cod === '') {
                continue;
            }
            $estado = (string) ($grupo['estado'] ?? '');
            if ($estado === centro_recursos::MAT_ENTREGADO) {
                $mapa[$cod] = 'Disponible en el repertorio';
            } else if ($estado === centro_recursos::MAT_ENTREGADO_PARCIAL) {
                $mapa[$cod] = 'Parcialmente disponible en el repertorio';
            } else {
                $mapa[$cod] = 'Pendiente';
            }
        }
        return $mapa;
    }

    /**
     * Actualiza al vuelo la columna «Estado» de la tabla 4.1 (Materiales instruccionales) con el estado vigente del
     * repertorio del Centro de produccion, y asegura las casillas ☐ en «Listo» (4.1) y «Verificado» (4.2). Funcion pura.
     *
     * @param string $html HTML del documento operativo.
     * @param array $estados Resultado de repertoire_status_map() (vacio si no se uso el centro).
     * @return string
     */
    public static function sync_repertoire_status(string $html, array $estados): string {
        if (trim($html) === '' || stripos($html, '<table') === false) {
            return $html;
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return $html;
        }
        $cambio = false;
        foreach ($body->getElementsByTagName('table') as $tabla) {
            $cab = [];
            $filasTr = [];
            foreach ($tabla->getElementsByTagName('tr') as $tr) {
                $padre = $tr->parentNode;
                if ($padre !== $tabla && ($padre === null || $padre->parentNode !== $tabla)) {
                    continue;
                }
                $celdas = [];
                foreach ($tr->childNodes as $c) {
                    if ($c instanceof \DOMElement && ($c->nodeName === 'td' || $c->nodeName === 'th')) {
                        $celdas[] = $c;
                    }
                }
                if (!$celdas) {
                    continue;
                }
                if (!$cab) {
                    foreach ($celdas as $c) {
                        $cab[] = diseno::plain_lower(trim(preg_replace('/\s+/u', ' ', $c->textContent)));
                    }
                    continue;
                }
                $filasTr[] = $celdas;
            }
            $colCod = null;
            $colMat = null;
            $colEst = null;
            $colCheck = null;
            foreach ($cab as $i => $h) {
                if ($colCod === null && preg_match('/^codigo\b/', $h)) {
                    $colCod = $i;
                }
                if ($colMat === null && preg_match('/^material\b/', $h)) {
                    $colMat = $i;
                }
                if ($colEst === null && preg_match('/^estado\b/', $h)) {
                    $colEst = $i;
                }
                if ($colCheck === null && preg_match('/^(?:listo|verificado)\b/', $h)) {
                    $colCheck = $i;
                }
            }
            // Tabla 4.1: Codigo + Material + Estado.
            if ($colCod !== null && $colMat !== null && $colEst !== null) {
                foreach ($filasTr as $celdas) {
                    if (!isset($celdas[$colCod], $celdas[$colEst])) {
                        continue;
                    }
                    $cod = strtoupper(trim(preg_replace('/\s+/u', '', $celdas[$colCod]->textContent)));
                    if ($cod === '') {
                        continue;
                    }
                    $nuevo = $estados[$cod] ?? 'Pendiente';
                    if (trim($celdas[$colEst]->textContent) !== $nuevo) {
                        $celdas[$colEst]->nodeValue = '';
                        $celdas[$colEst]->appendChild($dom->createTextNode($nuevo));
                        $cambio = true;
                    }
                    if ($colCheck !== null && isset($celdas[$colCheck]) && strpos($celdas[$colCheck]->textContent, '☐') === false) {
                        $celdas[$colCheck]->nodeValue = '';
                        $celdas[$colCheck]->appendChild($dom->createTextNode('☐'));
                        $cambio = true;
                    }
                }
            } else if ($colCheck !== null && preg_grep('/^recurso\b/', $cab)) {
                // Tabla 4.2: Recursos del entorno formativo -> casilla ☐ en «Verificado».
                foreach ($filasTr as $celdas) {
                    if (isset($celdas[$colCheck]) && strpos($celdas[$colCheck]->textContent, '☐') === false) {
                        $celdas[$colCheck]->nodeValue = '';
                        $celdas[$colCheck]->appendChild($dom->createTextNode('☐'));
                        $cambio = true;
                    }
                }
            }
        }
        if (!$cambio) {
            return $html;
        }
        $out = '';
        foreach ($body->childNodes as $hijo) {
            $out .= $dom->saveHTML($hijo);
        }
        return trim($out);
    }

    /**
     * Limpia el HTML generado para las secciones 1 a 5: quita notas editoriales «Origen: ...» si el modelo las repitio,
     * normaliza formulas/casillas y quita cualquier anexo redundante si el modelo empezo a transcribir la Etapa 6.
     * Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function clean_operative_html(string $html): string {
        $html = diseno::normalize_product($html);
        // Las notas «Origen: ...» de la especificacion no forman parte del documento operativo.
        $html = (string) preg_replace('#<p\b[^>]*>\s*(?:<(?:em|i|strong|b)\b[^>]*>\s*)*Origen\s*:.*?</p>\s*#isu', '', $html);
        // Quita una posible seccion de ANEXOS generada por el modelo: los anexos reales se agregan desde la Etapa 6.
        if (preg_match_all('#<h([1-3])\b[^>]*>(.*?)</h\1>#isu', $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $h) {
                $t = diseno::plain_lower(trim(strip_tags($h[2][0])));
                if (preg_match('/^anexos?\b(?:\s*[·:\-–—]|\s+instrumentos)/u', $t)) {
                    $html = rtrim(substr($html, 0, (int) $h[0][1]));
                    break;
                }
            }
        }
        return trim($html);
    }

    /**
     * Ensambla el documento operativo completo: Secciones 1 a 5 limpias + estado del repertorio + Anexos integros
     * extraidos de la Etapa 6. Funcion pura.
     *
     * @param string $secciones1a5Html HTML de las secciones 1 a 5 devuelto por el modelo.
     * @param string $etapa6Html HTML aceptado de la Etapa 6.
     * @param array $estadosRepertorio Resultado de repertoire_status_map().
     * @return string
     */
    public static function assemble_html(string $secciones1a5Html, string $etapa6Html, array $estadosRepertorio = []): string {
        $cuerpo = self::sync_repertoire_status(self::clean_operative_html($secciones1a5Html), $estadosRepertorio);
        $anexos = self::extract_stage6_instruments($etapa6Html);
        return $anexos !== '' ? ($cuerpo . "\n" . $anexos) : $cuerpo;
    }

    /**
     * Cuenta cuantas marcas [REVISAR] o [REVISAR: ...] trae el documento operativo. Funcion pura.
     *
     * @param string $html
     * @return int
     */
    public static function review_marks_count(string $html): int {
        $plano = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_match_all('/\[REVISAR(?:\s*:\s*[^\]]+)?\]/u', $plano) ?: 0;
    }

    /**
     * Extrae todos los criterios de evaluacion no vacios de la ficha, en orden textual. Funcion pura.
     *
     * @param array $ficha Datos limpios de la ficha (exante::clean()).
     * @return string[]
     */
    public static function ficha_criteria(array $ficha): array {
        $criterios = [];
        for ($i = 1; $i <= exante::CRITERIOS_MAX; $i++) {
            $t = trim((string) ($ficha['criterio' . $i] ?? ''));
            if ($t !== '') {
                $criterios[] = $t;
            }
        }
        return $criterios;
    }

    /**
     * Prompt para extraer las Secciones 1 a 5 del Documento Operativo de la Experiencia de Aprendizaje.
     * Los Anexos (instrumentos completos de la Etapa 6) se adjuntan en PHP para garantizar 100 % de fidelidad.
     * Funcion pura.
     *
     * @param array $ficha Datos limpios de la ficha validada (exante::clean()).
     * @param string[] $etapas Productos aceptados de las Etapas 2 a 6 (HTML o texto).
     * @param array $evaluacion Informe vigente de la Etapa 8 (total, banda, fecha).
     * @param string $fecha Fecha legible de la validacion.
     * @param int $version Numero de version (1, 2...).
     * @param array $estadosRepertorio Codigo => estado en el repertorio del centro de produccion.
     * @return string
     */
    public static function build_prompt(array $ficha, array $etapas, array $evaluacion, string $fecha, int $version,
            array $estadosRepertorio = []): string {
        $l = [];
        $l[] = 'Eres el ' . exante::ROL . '. Tu tarea es extraer el DOCUMENTO OPERATIVO DE LA EXPERIENCIA DE APRENDIZAJE '
            . '(Secciones 1 a 5), que es la versión sintética de trabajo que la persona facilitadora lleva al aula o al taller '
            . 'para conducir y evaluar la experiencia.';
        $l[] = '';
        $l[] = 'REGLAS ESTRICTAS DE EXTRACCIÓN:';
        $l[] = '- Es una extracción fiel del diseño validado: NO agregues contenidos, actividades ni materiales nuevos, y '
            . 'NO cambies tiempos, criterios, códigos (M1, M2…, IN1/INS-01…), puntajes, umbrales ni ponderaciones.';
        $l[] = '- Transcribe TEXTUALMENTE, palabra por palabra, el Aprendizaje esperado y cada Criterio de evaluación desde '
            . 'la ficha validada (no los resumas ni los parafrasees).';
        $l[] = '- Condensa la redacción operativa en frases breves y accionables, sin justificaciones ni fundamentaciones.';
        $l[] = '- OMITE por completo: el análisis diagnóstico (Etapa 1), los fundamentos y justificaciones, los registros de '
            . 'vacíos de información, las verificaciones de calidad, las referencias consultadas, el anexo de consistencia, '
            . 'la rúbrica de evaluación técnica y cualquier nota «Origen: …».';
        $l[] = '- Si al extraer detectas una diferencia entre etapas del diseño (por ejemplo, un tiempo o un código distinto), '
            . 'NO la resuelvas inventando un valor: usa el valor de la etapa que lo define (regla de fuente única: fases y '
            . 'organización en Etapa 2/3, materiales en Etapa 4, registro de instrumentos en Etapa 5, desarrollo en Etapa 6) y '
            . 'agrega exactamente la marca [REVISAR: diferencia entre etapas — detalle breve] en el lugar exacto.';
        $l[] = '- Deja EN BLANCO los campos de implementación que completa la persona facilitadora: «Fechas y horario» en la '
            . 'Sección 1, las columnas «Fecha», «Horario», «Fases y actividades» y «Observaciones (espacio, apoyo, visita)» de '
            . 'la tabla 3.2 (Jornada 1, Jornada 2 y Jornada 3), y usa casilla «☐» en las columnas «Listo» (4.1) y «Verificado» (4.2).';
        $l[] = '- NO incluyas los Anexos de instrumentos al final: la plataforma adjunta automáticamente a continuación los '
            . 'instrumentos completos de la Etapa 6 sin recortes. Termina exactamente al cerrar la sección 5.3.';
        $l[] = '- Extensión orientativa de las secciones 1 a 5: entre 8 y 12 páginas, manteniendo todas las actividades y tareas.';
        $l[] = diseno::REGLA_FORMULAS;
        $l[] = exante::NO_PEDAGOGIA;
        $l[] = '- Usa terminología de formación por competencias, lenguaje inclusivo con perspectiva de género y español de Chile.';
        $l[] = '';
        $l[] = 'ESTRUCTURA OBLIGATORIA EN MARKDOWN (usa exactamente estos títulos y columnas de tabla; no agregues portada ni '
            . 'encabezado antes de «# SECCIÓN 1», porque la plataforma genera la cabecera institucional):';
        $l[] = '';
        $l[] = '# SECCIÓN 1 · Datos generales';
        $l[] = 'Tabla Markdown con columnas: `| Campo | Información |` y exactamente estas 10 filas:';
        $l[] = '1. `Organismo ejecutor`';
        $l[] = '2. `Plan formativo` (nombre, código y versión, según ficha/diseño)';
        $l[] = '3. `Módulo` (n.º y nombre, código y horas, según ficha/diseño)';
        $l[] = '4. `Experiencia de aprendizaje` (nombre oficial de la Etapa 2)';
        $l[] = '5. `Duración` («X minutos (Y horas cronológicas), de un total de Z minutos disponibles para el aprendizaje esperado»)';
        $l[] = '6. `Modalidad y espacios` (modalidad · sala, taller, laboratorio, patio u otros espacios y visitas)';
        $l[] = '7. `Participantes y organización` (n.º participantes · duplas, tríos o equipos · subgrupos o estaciones)';
        $l[] = '8. `Características del grupo` (síntesis en 2 o 3 líneas con los aspectos que afectan la conducción)';
        $l[] = '9. `Conducción` (n.º de personas facilitadoras y personas de apoyo, y su función)';
        $l[] = '10. `Fechas y horario` (celda vacía)';
        $l[] = '';
        $l[] = '# SECCIÓN 2 · Aprendizaje esperado y criterios de evaluación';
        $l[] = '1. Tabla de una columna `| Aprendizaje esperado |` con la transcripción textual exacta del aprendizaje esperado.';
        $l[] = '2. Tabla `| Criterio de evaluación | Se trabaja en | Se evalúa con |`: una fila por cada criterio transcrito '
            . 'textualmente, indicando fase y actividades de la Etapa 3 y código y nombre del instrumento de la Etapa 5.';
        $l[] = '';
        $l[] = '# SECCIÓN 3 · Secuencia instruccional';
        $l[] = '## 3.1 Vista general';
        $l[] = 'Tabla `| Fase | Minutos | Actividades (minutos) | Criterios | Organización del grupo | Evidencias parciales |` '
            . 'con una fila por fase (ej. «Fase 1: nombre (Inicio)») y una fila final `Total` cuya suma de minutos coincida '
            . 'con la duración de la experiencia.';
        $l[] = '## 3.2 Distribución por jornada (a completar por la persona facilitadora)';
        $l[] = 'Tabla `| Jornada | Fecha | Horario | Fases y actividades | Observaciones (espacio, apoyo, visita) |` '
            . 'con 3 filas (`Jornada 1`, `Jornada 2`, `Jornada 3`) y todas las demás celdas en blanco.';
        $l[] = '## 3.3 Guion de conducción por actividad';
        $l[] = 'Un bloque por cada actividad, en el orden exacto de la secuencia de la Etapa 3. Para cada fase usa un título '
            . '`### Fase [n.º]: [nombre] · [minutos] minutos · Criterios [n.º]` y para cada actividad un título '
            . '`#### Actividad [n.º]: [nombre] · [minutos] minutos · Lugar: [...] · Organización: [...] · Criterios: [...]`, '
            . 'seguido de la tabla `| Tarea | Min | Persona facilitadora | Personas participantes | Materiales y recursos |` '
            . '(la suma de minutos de las tareas debe coincidir con los minutos de la actividad; cita materiales por código M1, M2… '
            . 'y recursos físicos) y una tabla breve `| Aspecto | Detalle |` con `Evidencia parcial`, `Seguridad` (si corresponde) '
            . 'y `Contingencia` (si corresponde).';
        $l[] = 'Redacción del guion (es la sección más larga; cuida la extensión total de 8 a 12 páginas): en la columna `Tarea` '
            . 'va solo el código de la tarea ([n.º fase].[n.º actividad].[n.º tarea], p. ej. `1.1.1`), no un título; cada celda '
            . 'de `Persona facilitadora` y `Personas participantes` es una acción breve en una sola frase (máximo 15 palabras, '
            . 'verbo en presente, sin explicar el porqué); `Materiales y recursos` lleva solo códigos y nombres cortos de '
            . 'recursos; cada fila de `Aspecto | Detalle` es una frase breve (máximo 20 palabras). No omitas tareas ni cambies '
            . 'minutos para acortar: acorta la redacción.';
        $l[] = '';
        $l[] = '# SECCIÓN 4 · Materiales y recursos';
        $l[] = '## 4.1 Materiales instruccionales';
        $l[] = 'Tabla `| Código | Material | Fase y actividad | Formato de salida | Estado | Listo |` con los materiales de la '
            . 'lista de producción de la Etapa 4, usando el estado del repertorio informado abajo (o «Pendiente» si no se usó) '
            . 'y «☐» en la columna `Listo`.';
        $l[] = '## 4.2 Recursos del entorno formativo (organismo ejecutor)';
        $l[] = 'Tabla `| Recurso | Cantidad | Fase y actividad | Verificado |` con los recursos físicos de la Etapa 4 y «☐» '
            . 'en la columna `Verificado`.';
        $l[] = '';
        $l[] = '# SECCIÓN 5 · Plan de evaluación sintético';
        $l[] = '## 5.1 Instrumentos';
        $l[] = 'Tabla `| Código | Instrumento | Tipo | Criterios | Momento | Puntaje | Umbral | Ponderación |` extraída del '
            . 'registro de instrumentos de la Etapa 5.';
        $l[] = '## 5.2 Regla de aprobación';
        $l[] = 'Tabla `| Condiciones para aprobar el aprendizaje esperado |` con la regla de juicio evaluativo del diseño '
            . '(porcentaje mínimo, cálculo ponderado, umbrales por instrumento y condiciones críticas).';
        $l[] = '## 5.3 Equivalencia con los niveles de la ficha de registro';
        $l[] = 'Tabla `| Nivel | [Código Inst. sumativo 1] | [Código Inst. sumativo 2] | ... | Condición |` (solo instrumentos '
            . 'sumativos) con exactamente cuatro filas en este orden: `Avanzado`, `Adecuado`, `Básico`, `Insuficiente`, con los '
            . 'rangos de puntaje extraídos de la Etapa 6. Cierra con esta línea textual:';
        $l[] = '*Uso en el Módulo 3: con esta tabla la persona facilitadora asigna el nivel de cada participante en la sección 4 '
            . 'de la ficha de registro de la implementación.*';
        $l[] = '';

        // Datos exactos de la ficha y validacion.
        $l[] = 'DATOS EXACTOS DE LA FICHA VALIDADA (REFERENCIA TEXTUAL):';
        $l[] = '- Organismo ejecutor: ' . trim((string) ($ficha['organismo'] ?? ''));
        $l[] = '- Plan formativo: ' . trim((string) ($ficha['plan'] ?? ''));
        $l[] = '- Módulo: ' . trim((string) ($ficha['modulo'] ?? ''));
        $l[] = '- Nivel de cualificación: ' . trim((string) ($ficha['nivel'] ?? ''));
        $l[] = '- Participantes: ' . trim((string) ($ficha['participantes'] ?? ''));
        $l[] = '- Tiempo total disponible (2.2): ' . trim((string) ($ficha['tiempo_total'] ?? '')) . ' minutos';
        $l[] = '- Tiempo asignado a la experiencia (2.3): ' . trim((string) ($ficha['tiempo_experiencia'] ?? '')) . ' minutos';
        $l[] = '- Aprendizaje esperado (transcribir textualmente): ' . trim((string) ($ficha['aprendizaje'] ?? ''));
        $criterios = self::ficha_criteria($ficha);
        if ($criterios) {
            $l[] = '- Criterios de evaluación (transcribir textualmente):';
            foreach ($criterios as $idx => $crit) {
                $l[] = '  ' . ($idx + 1) . '. ' . $crit;
            }
        }
        $l[] = '- Validación técnica (Etapa 8): ' . (int) ($evaluacion['total'] ?? 0) . ' / 36 · '
            . (string) ($evaluacion['banda']['texto'] ?? '') . ($fecha !== '' ? ' · ' . $fecha : '')
            . ' · versión ' . $version;
        $l[] = '';
        if ($estadosRepertorio) {
            $l[] = 'ESTADO ACTUAL EN EL REPERTORIO DEL CENTRO DE PRODUCCIÓN (para columna «Estado» de la tabla 4.1):';
            foreach ($estadosRepertorio as $cod => $est) {
                $l[] = '- ' . $cod . ': ' . $est;
            }
        } else {
            $l[] = 'ESTADO DEL REPERTORIO DEL CENTRO DE PRODUCCIÓN: aún no se ha usado el centro; indica «Pendiente» en todos los materiales de la tabla 4.1.';
        }
        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FORMATO DE RESPUESTA: únicamente el Markdown de las Secciones 1 a 5 (empezando con «# SECCIÓN 1 · Datos '
            . 'generales» y terminando con la nota de uso en el Módulo 3 de la sección 5.3), sin texto antes ni después.';

        // Etapas 2 a 6 aceptadas, ya limpias de secciones de cierre no operativas.
        $etapasMeta = diseno::etapas();
        foreach ([2, 3, 4, 5, 6] as $n) {
            if (empty($etapas[$n])) {
                continue;
            }
            $limpia = self::strip_stage_closures((string) $etapas[$n]);
            $texto = trim(preg_replace('/\n{3,}/', "\n\n", html_entity_decode(strip_tags(
                preg_replace('#</?(?:p|div|li|tr|br|h[1-6])\b[^>]*>#i', "\n",
                    preg_replace('#</?(?:td|th)\b[^>]*>#i', ' | ', $limpia))
            ), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' (' . ($etapasMeta[$n]['titulo'] ?? '') . ')'
                . ($n === 6 ? ' — SOLO PARA EXTRAER REGLAS DE EQUIVALENCIA DE LA SECCIÓN 5.3; NO TRANSCRIBAS LOS INSTRUMENTOS:' : ':');
            $l[] = guardrails::isolate_user_content(\core_text::substr($texto, 0, diseno::PREVIA_MAX));
        }
        return implode("\n", $l);
    }

    /**
     * Metadatos de cabecera y encabezado del Word operativo para documento_final::build(). Funcion pura.
     *
     * @param array $ficha Datos limpios de la ficha.
     * @param string $titulo Nombre de la experiencia de aprendizaje.
     * @param array $evaluacion Evaluacion vigente de la Etapa 8.
     * @param string $fecha Fecha legible.
     * @param int $version Numero de version.
     * @return array
     */
    public static function word_meta(array $ficha, string $titulo, array $evaluacion, string $fecha, int $version): array {
        $total = (int) ($evaluacion['total'] ?? 0);
        $tramo = (string) ($evaluacion['banda']['texto'] ?? '');
        $subtitulo = 'Documento de trabajo para la conducción en aula o taller · Diseño validado: '
            . $total . ' / 36' . ($tramo !== '' ? ' · ' . $tramo : '')
            . ($fecha !== '' ? ' · ' . $fecha : '') . ' · versión ' . max(1, $version);
        $items = [];
        $org = trim((string) ($ficha['organismo'] ?? ''));
        if ($org !== '') {
            $items[] = ['etiqueta' => 'Organismo ejecutor', 'valor' => $org];
        }
        $min = (int) preg_replace('/\D+/', '', (string) ($ficha['tiempo_experiencia'] ?? ''));
        if ($min > 0) {
            $horas = rtrim(rtrim(number_format($min / 60, 1, ',', ''), '0'), ',');
            $items[] = ['etiqueta' => 'Duración', 'valor' => $min . ' min (' . $horas . ' h)'];
        }
        $part = trim((string) ($ficha['participantes'] ?? ''));
        if ($part !== '') {
            $items[] = ['etiqueta' => 'Participantes', 'valor' => $part];
        }
        $items[] = ['etiqueta' => 'Diseño validado', 'valor' => $total . ' / 36 · v' . max(1, $version)];
        return [
            'estilo' => 'operativo',
            'portada' => false,
            'etiqueta' => 'DOCUMENTO OPERATIVO · EXPERIENCIA DE APRENDIZAJE',
            'titulo' => $titulo,
            'subtitulo' => $subtitulo,
            'meta_items' => $items,
            'encabezado' => 'Documento operativo · ' . $titulo . ' · v' . max(1, $version),
        ];
    }

    /**
     * Construye el archivo Word (.docx) editable del Documento Operativo. Funcion pura.
     *
     * @param string $ruta Ruta de salida .docx.
     * @param array $meta Resultado de word_meta().
     * @param string $html HTML del documento operativo (ya sincronizado con el repertorio).
     * @param string $logo Ruta al logo institucional PNG.
     * @return bool
     */
    public static function build_docx(string $ruta, array $meta, string $html, string $logo = ''): bool {
        if (documento_final::build($ruta, $meta, $html, $logo)) {
            return true;
        }
        return diseno::html_to_docx($ruta, (string) ($meta['titulo'] ?? 'Documento operativo'),
            (string) ($meta['subtitulo'] ?? ''), $html);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Integracion con BD y servicio de IA (Moodle).
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Genera el documento operativo para la fila etapa = self::ETAPA de local_chatbot_diseno.
     *
     * @param \stdClass $fila Fila etapa = self::ETAPA.
     * @param array $ficha Datos limpios de la ficha.
     * @param int $fichaid ID de la ficha.
     * @return array [estado de generacion, campos a guardar].
     */
    public static function generate_row(\stdClass $fila, array $ficha, int $fichaid): array {
        $filas = diseno::get_by_ficha($fichaid);
        $c = $filas[diseno::CONSOLIDACION] ?? null;
        $eval = diseno::current_evaluation($filas);
        if (!$c || $c->estado !== diseno::ACEPTADO || !self::can_generate($eval)) {
            return [exante::ERROR, ['error' => 'noaprobado']];
        }
        $etapas = [];
        foreach ([2, 3, 4, 5, 6] as $n) {
            if (empty($filas[$n]) || $filas[$n]->estado !== diseno::ACEPTADO || trim((string) $filas[$n]->contenido) === '') {
                return [exante::ERROR, ['error' => 'sinetapas']];
            }
            $etapas[$n] = (string) $filas[$n]->contenido;
        }
        $version = max(1, (int) ($fila->rondas ?? 0) + 1);
        $fecha = userdate((int) ($eval['fecha'] ?? time()), get_string('strftimedate', 'langconfig'));
        $estados = self::repertoire_status_map(centro_recursos::get_resources($fichaid));
        $prompt = pseudonymizer::scrub(self::build_prompt($ficha, $etapas, $eval, $fecha, $version, $estados));
        $contenido = [['role' => 'user', 'parts' => [['text' => $prompt]]]];
        $modelo = ai\provider_factory::get_configured_model();
        $resultado = exante::generate_steady($contenido, 300, $modelo, false);
        if (isset($resultado['error'])) {
            return [exante::ERROR, ['error' => 'servicio']];
        }
        $markdown = diseno::clean_markdown(gemini_client::extract_text($resultado));
        if ($markdown === '') {
            return [exante::ERROR, ['error' => 'ilegible']];
        }
        $html = self::assemble_html(diseno::product_html($markdown), $etapas[6], $estados);
        return [exante::LISTO, [
            'contenido' => $html,
            'estado' => diseno::ACEPTADO,
            'huella' => (string) $c->huella,
            'rondas' => $version,
            'modelo' => (string) $modelo,
            'promptversion' => self::PROMPT_VERSION,
            'timeaccepted' => time(),
            'error' => null,
        ]];
    }

    /**
     * Encola automaticamente la generacion del documento operativo al cerrar la Etapa 8 con >= 25 puntos.
     * Queda en estado PENDIENTE y la pagina de la Etapa 8 la ejecuta de inmediato en segundo plano sin demorar
     * la entrega del informe de evaluacion ni arriesgar timeouts en la Etapa 8.
     *
     * @param \stdClass $evalFila Fila de la Etapa 8.
     * @param int $fichaid ID de la ficha.
     * @param array $evaluacion Informe recien generado de la Etapa 8.
     * @return void
     */
    public static function queue_after_eval(\stdClass $evalFila, int $fichaid, array $evaluacion): void {
        global $DB;
        if (!self::can_generate($evaluacion)) {
            return;
        }
        try {
            $filaOp = $DB->get_record('local_chatbot_diseno', ['fichaid' => $fichaid, 'etapa' => self::ETAPA]);
            $ahora = time();
            if (!$filaOp) {
                $DB->insert_record('local_chatbot_diseno', (object) [
                    'fichaid' => $fichaid,
                    'userid' => (int) $evalFila->userid,
                    'courseid' => (int) $evalFila->courseid,
                    'etapa' => self::ETAPA,
                    'contenido' => '',
                    'estado' => diseno::BORRADOR,
                    'generacion' => exante::PENDIENTE,
                    'validacionid' => (int) $evalFila->validacionid,
                    'rondas' => 0,
                    'timecreated' => $ahora,
                    'timemodified' => $ahora,
                ]);
                return;
            }
            if (in_array((string) $filaOp->generacion, [exante::PENDIENTE, exante::PROCESANDO], true)) {
                return;
            }
            // Conserva rondas para que la siguiente generacion incremente la version (1, 2, 3...).
            $DB->update_record('local_chatbot_diseno', (object) [
                'id' => $filaOp->id,
                'generacion' => exante::PENDIENTE,
                'validacionid' => (int) $evalFila->validacionid,
                'error' => null,
                'timemodified' => $ahora,
            ]);
        } catch (\Throwable $e) {
            debugging('local_chatbot documento_operativo queue_after_eval error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}

<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Modulo 3, Fase 4, salida al cierre de la Etapa 15: Documento operativo de reflexion sobre la practica de facilitacion.
 *
 * Especificacion de Interfases («Documento Operativo de Reflexion sobre la Practica - Evaluacion Ex Post - Propuesta
 * Generica»): cuaderno de trabajo de la persona facilitadora para reflexionar sobre su practica al cierre del ciclo. Se
 * genera al confirmar la consolidacion de la evaluacion ex post (Etapa 15) y se entrega en Word editable (6 a 8 paginas).
 *
 * Arquitectura hibrida, como los documentos operativos de la continuidad y del Modulo 2:
 *  - Seccion 1 («El ciclo en una pagina»): la arma el codigo con la ficha ex post validada y los calculos de la plataforma
 *    (expost::results()): logros, niveles, movimientos de nivel, cobertura, reaccion y transferencia. Asi los numeros son
 *    siempre los de la ficha (regla de la propuesta).
 *  - Secciones 2 a 8: las extrae el asistente del informe consolidado (Etapas 11 a 14), sin agregar juicios ni cambiar logros,
 *    niveles o valoraciones; si el informe difiere de la ficha, usa la ficha y marca [REVISAR: diferencia con la ficha ex post].
 *  - Lo que no es juicio lo fija el codigo despues: las columnas en blanco para la persona facilitadora («Mi lectura»,
 *    «Mi comentario», «¿Como la aplicare…?», «¿La mantendre?», «Considerar» con ☐), los textos fijos de las secciones 6 a 8,
 *    un espacio de respuesta bajo cada pregunta y la Seccion 9 en blanco.
 *
 * Se guarda en local_chatbot_calidad con etapa = self::ETAPA (fuera de las etapas 1 a 16), con la huella de la consolidacion
 * vigente, el numero de version en rondas y la huella de sus fuentes en informe: sin cambios en la base.
 *
 * Todo lo que no consulta la base es funcion pura y lo ejercita cli/verificar_documento_reflexion.php.
 */
class documento_reflexion {

    /** @var int Etapa interna en local_chatbot_calidad (1 a 8, Fase 4 9 a 15, documento de la continuidad 16). */
    const ETAPA = calidad::REFLEXION;

    /** @var string Version del prompt (RU-PRIV-006). */
    const PROMPT_VERSION = 'M3-RX-v1';

    /** @var int Segundos antes de dar por caida una generacion en curso. */
    const MAX_SECONDS = 600;

    /** @var int Segundos de espera de la respuesta del asistente. */
    const ESPERA = 420;

    /** @var string Nombre base del archivo Word. */
    const ARCHIVO = 'Documento_reflexion_sobre_la_practica';

    /** @var int Tope de caracteres de cada etapa que viaja al modelo. */
    const ETAPA_MAX = 30000;

    /** @var string Rotulos del Word (textuales de la propuesta). */
    const ETIQUETA = 'DOCUMENTO OPERATIVO · REFLEXIÓN SOBRE LA PRÁCTICA';
    const ENCABEZADO = 'Documento operativo · Reflexión sobre la práctica';
    const CUADERNO = 'Cuaderno de trabajo de la persona facilitadora';

    /** @var string Marca de la propuesta cuando el informe difiere de la ficha ex post. */
    const MARCA_DIFERENCIA = '[REVISAR: diferencia con la ficha ex post]';

    /** @var string[] Titulos de las nueve secciones (textuales). */
    const SECCIONES = [1 => 'El ciclo en una página', 2 => 'Trayectoria del ciclo', 3 => 'Decisiones clave',
        4 => 'Mi reflexión y la evidencia', 5 => 'Lecciones aprendidas', 6 => 'Prácticas que funcionaron',
        7 => 'Preguntas para la reflexión', 8 => 'Hacia una nueva ejecución',
        9 => 'Mi plan de desarrollo como persona facilitadora'];

    /** @var string Textos fijos de la propuesta. */
    const TEXTO_S6 = 'Una ficha por práctica sistematizada, en el orden del informe.';
    const TEXTO_S7 = 'Responda con calma y con ejemplos concretos de su práctica. No hay respuestas correctas: el propósito es '
        . 'profundizar en su propia forma de facilitar aprendizajes.';
    const TEXTO_S8 = 'Estas recomendaciones son insumos para el diagnóstico de una nueva ejecución del curso, con otro grupo y otro '
        . 'contexto. No reemplazan ese diagnóstico ni constituyen un nuevo diseño.';
    const TEXTO_S9 = 'Defina hasta tres aspectos de su práctica que quiere fortalecer a partir de este ciclo.';

    /** @var string[] Columnas de la Seccion 9 y numero de filas en blanco. */
    const COLUMNAS_S9 = ['Qué quiero fortalecer', 'Cómo lo haré', 'Plazo', 'Apoyo que necesito'];
    const FILAS_S9 = 3;

    /**
     * @var string[] Columnas que completa la persona facilitadora (clave normalizada => contenido de la celda): quedan en blanco
     *      o con la casilla ☐, diga lo que diga el modelo.
     */
    const COLUMNAS_BLANCAS = ['mi lectura' => '', 'mi comentario' => '', 'como la aplicare en mi practica' => '',
        'considerar' => '☐'];

    /** @var int Peso minimo de una columna para escribir a mano (data-peso del encabezado, documento_final::pesos()). */
    const PESO_BLANCA = 90;

    // ------------------------------------------------------------------------------------------------------------
    // Vigencia y versiones.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Si hay un documento listo y vigente para la consolidacion ex post confirmada de hoy. Funcion pura.
     *
     * @param \stdClass|null $fila Fila etapa = self::ETAPA.
     * @param string $huella Huella de la consolidacion confirmada (fila de la Etapa 15).
     * @return bool
     */
    public static function is_current(?\stdClass $fila, string $huella): bool {
        return documento_continuidad::is_current($fila, $huella);
    }

    /**
     * Numero de version (1, 2, 3...). Funcion pura.
     *
     * @param \stdClass|null $fila
     * @return int
     */
    public static function version_number(?\stdClass $fila): int {
        return documento_continuidad::version_number($fila);
    }

    /**
     * Huella de lo que el documento extrae: la validacion de la ficha ex post, las Etapas 11 a 14 aceptadas, el tipo de ajuste
     * y la version del prompt. Si no cambia, reconfirmar la consolidacion no genera una version nueva. Funcion pura.
     *
     * @param string $validacion Huella de la validacion de la ficha ex post.
     * @param \stdClass[] $filas Etapas de la evaluacion.
     * @param string|null $ajuste
     * @return string
     */
    public static function source_fingerprint(string $validacion, array $filas, ?string $ajuste): string {
        return sha1(json_encode([expost::stages_fingerprint($filas, $validacion), (string) $ajuste, self::PROMPT_VERSION]));
    }

    /**
     * Si el documento guardado corresponde a esas fuentes. Funcion pura.
     *
     * @param \stdClass|null $fila
     * @param string $fuente
     * @return bool
     */
    public static function same_source(?\stdClass $fila, string $fuente): bool {
        return documento_continuidad::same_source($fila, $fuente);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Seccion 1: el ciclo en una pagina (codigo).
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Texto breve de un criterio para la tabla de logros: hasta 14 palabras. Funcion pura.
     *
     * @param string $texto
     * @return string
     */
    public static function short_criterion(string $texto): string {
        $t = trim(preg_replace('/\s+/u', ' ', $texto));
        $p = preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
        return count($p) > 14 ? implode(' ', array_slice($p, 0, 14)) . '…' : $t;
    }

    /**
     * Variacion en puntos porcentuales («+ 21 puntos», «0 puntos»). Funcion pura.
     *
     * @param float|null $v
     * @return string
     */
    public static function points(?float $v): string {
        if ($v === null) {
            return '—';
        }
        $n = str_replace('.', ',', rtrim(rtrim(number_format(abs($v), 1, '.', ''), '0'), '.'));
        return ($v > 0 ? '+ ' : ($v < 0 ? '− ' : '')) . $n . ' puntos';
    }

    /**
     * Datos de la Seccion 1 con la ficha ex post y los calculos de la plataforma. Funcion pura.
     *
     * @param array $data Ficha ex post validada (expost::clean()).
     * @param array $pre Precarga de la validacion (expost::preload()).
     * @param array $r expost::results().
     * @param string|null $ajuste Tipo de ajuste decidido en la Etapa 3.
     * @return array campos (rotulo => valor), criterios (filas [criterio, inicial, final, variacion]), promedio (fila),
     *         indicadores (rotulo => valor).
     */
    public static function cycle_data(array $data, array $pre, array $r, ?string $ajuste): array {
        $s1 = (array) ($pre['s1'] ?? []);
        $limpio = static fn($v): string => trim(rtrim(trim((string) $v), '.'));
        $junta = static fn(array $x): string => implode(' · ', array_values(array_filter(array_map($limpio, $x),
            static fn($v) => $v !== '')));
        $pct = static fn(?float $v): string => $v === null ? '—' : implementacion::pct($v);
        $presentes = static fn($n): string => trim((string) $n) === '' ? '' : (int) $n . ' participantes presentes';
        $continuidad = ($s1['exp_continuidad'] ?? '') !== '' ? $s1['exp_continuidad'] : ($data['exp_cont'] ?? '');
        $campos = [
            'Plan formativo y módulo' => $junta([$s1['plan'] ?? '', $s1['modulo'] ?? '']),
            'Experiencia inicial' => $junta([$s1['exp_inicial'] ?? '', $s1['fechas_ini'] ?? '',
                $presentes($s1['presentes_ini'] ?? '')]),
            'Experiencia de continuidad' => $junta([$continuidad, $data['fechas_cont'] ?? '',
                $presentes($data['presentes_cont'] ?? '')]),
            'Tipo de ajuste decidido' => $ajuste !== null ? calidad::adjustment_name($ajuste) : '',
            'Participantes evaluados al cierre' => trim((string) ($data['evaluados'] ?? '')),
        ];
        $criterios = [];
        $bajo = [];
        $situaciones = 0;
        foreach ($r['filas'] as $f) {
            $nombre = $f['k'] . '. ' . self::short_criterion((string) $f['criterio']);
            if ($f['tipo'] === 'pendiente_persiste') {
                $criterios[] = [$nombre, '—', 'Pendiente', '—'];
                continue;
            }
            $criterios[] = [$nombre, $f['inicial'] === null ? '— (pendiente)' : $pct($f['inicial']), $pct($f['final']),
                self::points($f['variacion'])];
            $n = (int) (($f['niveles_f']['ins'] ?? 0) + ($f['niveles_f']['bas'] ?? 0));
            if ($n > 0) {
                $situaciones += $n;
                $bajo[] = 'criterio ' . $f['k'];
            }
        }
        $promedio = ['Logro promedio', $pct($r['promedio_inicial']), $pct($r['promedio_final']),
            ($r['promedio_inicial'] !== null && $r['promedio_final'] !== null)
                ? self::points(round($r['promedio_final'] - $r['promedio_inicial'], 1)) : '—'];
        $persisten = $situaciones === 0 ? 'Sin situaciones bajo el nivel Adecuado'
            : $situaciones . ' situación(es) bajo el nivel Adecuado, en ' . implode(', ', $bajo);
        if ($r['persisten']) {
            $persisten .= '; pendientes que persisten: ' . implode(', ', array_map(static fn($x) => 'criterio ' . $x['k'],
                $r['persisten']));
        }
        $reaccion = '';
        if (trim((string) ($data['v42'] ?? '')) !== '') {
            $reaccion = 'Satisfacción general: ' . (expost::scale(true)[$data['v42']] ?? $data['v42']);
        }
        $respaldo = self::first_sentence((string) ($data['datos4'] ?? ''));
        $reaccion = trim($reaccion . ($respaldo !== '' ? ($reaccion !== '' ? ' · ' : '') . $respaldo : ''));
        $op = (string) ($data['op51'] ?? '');
        if ($op === expost::NO) {
            $transferencia = 'Sin oportunidad de aplicación' . (trim((string) ($data['motivo5'] ?? '')) !== ''
                ? ': ' . self::first_sentence((string) $data['motivo5']) : '');
        } else if (trim((string) ($data['n_seg5'] ?? '')) !== '') {
            $transferencia = (int) ($data['n_apl5'] ?? 0) . ' de ' . (int) $data['n_seg5']
                . ' personas con seguimiento aplicaron lo aprendido';
        } else {
            $transferencia = '';
        }
        $nv = $r['nivelacion'];
        $indicadores = [
            'Cobertura evaluativa' => $pct($r['cobertura_inicial']) . ' al inicio · ' . $pct($r['cobertura_final']) . ' al cierre',
            'Nivelación' => $nv['mov'] . ' de ' . $nv['tot'] . ' situaciones alcanzaron el nivel Adecuado',
            'Profundización' => $r['profundizacion'] . ' personas pasaron de Adecuado a Avanzado',
            'Brechas que persisten' => $persisten,
            'Reacción de las personas participantes' => $reaccion,
            'Transferencia al trabajo' => $transferencia,
        ];
        return ['campos' => $campos, 'criterios' => $criterios, 'promedio' => $promedio, 'indicadores' => $indicadores];
    }

    /**
     * Primera oracion de un texto (para los respaldos breves). Funcion pura.
     *
     * @param string $texto
     * @return string
     */
    public static function first_sentence(string $texto): string {
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags($texto)));
        if ($t === '') {
            return '';
        }
        $p = preg_split('/(?<=[.!?])\s+/u', $t, 2);
        $o = trim((string) $p[0]);
        return \core_text::strlen($o) > 220 ? \core_text::substr($o, 0, 217) . '…' : $o;
    }

    /**
     * HTML de la Seccion 1. Funcion pura.
     *
     * @param array $d cycle_data().
     * @return string
     */
    public static function section1_html(array $d): string {
        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $vacio = static fn(string $v): string => $v === '' ? '[REVISAR: dato no registrado en la ficha]' : $v;
        $h = '<h1>SECCIÓN 1 · ' . $e(self::SECCIONES[1]) . '</h1>';
        $h .= '<table data-orientacion="v"><thead><tr><th>Campo</th><th>Información</th></tr></thead><tbody>';
        foreach ($d['campos'] as $k => $v) {
            $h .= '<tr><td>' . $e($k) . '</td><td>' . $e($vacio((string) $v)) . '</td></tr>';
        }
        $h .= '</tbody></table>';
        $h .= '<table data-orientacion="v"><thead><tr><th>Criterio de evaluación</th><th>Logro inicial</th><th>Logro final</th>'
            . '<th>Variación</th></tr></thead><tbody>';
        foreach (array_merge($d['criterios'], [$d['promedio']]) as $fila) {
            $h .= '<tr>' . implode('', array_map(static fn($c) => '<td>' . $e((string) $c) . '</td>', $fila)) . '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '<table data-orientacion="v"><thead><tr><th>Indicador</th><th>Resultado</th></tr></thead><tbody>';
        foreach ($d['indicadores'] as $k => $v) {
            $h .= '<tr><td>' . $e($k) . '</td><td>' . $e($vacio((string) $v)) . '</td></tr>';
        }
        return $h . '</tbody></table>';
    }

    /**
     * HTML de la Seccion 9 (en blanco para la persona facilitadora). Funcion pura.
     *
     * @return string
     */
    public static function section9_html(): string {
        $h = '<h1>SECCIÓN 9 · ' . htmlspecialchars(self::SECCIONES[9], ENT_QUOTES, 'UTF-8') . '</h1><p>' . self::TEXTO_S9 . '</p>'
            . '<table data-orientacion="v"><thead><tr>';
        foreach (self::COLUMNAS_S9 as $c) {
            $h .= '<th>' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        for ($i = 0; $i < self::FILAS_S9; $i++) {
            $h .= '<tr>' . str_repeat('<td>&nbsp;</td>', count(self::COLUMNAS_S9)) . '</tr>';
        }
        return $h . '</tbody></table>';
    }

    // ------------------------------------------------------------------------------------------------------------
    // Secciones 2 a 8 (asistente) y lo que fija el codigo.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Clave normalizada de un texto (sin tildes, signos ni mayusculas). Funcion pura.
     *
     * @param string $t
     * @return string
     */
    private static function key(string $t): string {
        return trim(preg_replace('/[^a-z0-9]+/u', ' ', diseno::plain_lower(html_entity_decode(strip_tags($t), ENT_QUOTES,
            'UTF-8'))));
    }

    /**
     * Separa el HTML por las secciones «SECCIÓN N · …» (h1 o h2). Funcion pura.
     *
     * @param string $html
     * @return array numero => HTML de la seccion (con su titulo).
     */
    public static function split_sections(string $html): array {
        $out = [];
        $partes = preg_split('/(<h[12][^>]*>\s*(?:<[^>]+>\s*)*SECCI[ÓO]N\s+\d+.*?<\/h[12]>)/isu', $html, -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $actual = null;
        foreach ($partes as $p) {
            if (preg_match('/^<h[12][^>]*>\s*(?:<[^>]+>\s*)*SECCI[ÓO]N\s+(\d+)/isu', $p, $m)) {
                $actual = (int) $m[1];
                $out[$actual] = ($out[$actual] ?? '') . $p;
            } else if ($actual !== null) {
                $out[$actual] .= $p;
            }
        }
        return $out;
    }

    /**
     * Deja en blanco las columnas que completa la persona facilitadora y la fila «¿La mantendré?» de las fichas de practicas.
     * Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function blank_facilitator_cells(string $html): string {
        return (string) preg_replace_callback('/<table\b[^>]*>.*?<\/table>/isu', static function(array $t): string {
            $tabla = $t[0];
            if (!preg_match_all('/<tr\b[^>]*>.*?<\/tr>/isu', $tabla, $filas)) {
                return $tabla;
            }
            $celdas = static function(string $fila): array {
                preg_match_all('/<(t[hd])\b[^>]*>(.*?)<\/\1>/isu', $fila, $m, PREG_SET_ORDER);
                return $m;
            };
            $cab = $celdas($filas[0][0]);
            $blancas = [];
            foreach ($cab as $i => $c) {
                $k = self::key($c[2]);
                if (array_key_exists($k, self::COLUMNAS_BLANCAS)) {
                    $blancas[$i] = self::COLUMNAS_BLANCAS[$k];
                }
            }
            foreach ($filas[0] as $nf => $fila) {
                $cs = $celdas($fila);
                $nueva = $fila;
                if ($nf === 0 && $blancas) {
                    // Encabezado: las columnas para escribir a mano piden un ancho minimo en el Word (documento_final).
                    $i = -1;
                    $nueva = (string) preg_replace_callback('/<(t[hd])(\s[^>]*)?>/iu',
                        static function(array $c) use (&$i, $blancas): string {
                            $i++;
                            $attr = (string) ($c[2] ?? '');
                            return isset($blancas[$i]) && $blancas[$i] === '' && strpos($attr, 'data-peso') === false
                                ? '<' . $c[1] . $attr . ' data-peso="' . self::PESO_BLANCA . '">' : $c[0];
                        }, $fila);
                }
                if ($nf > 0 && $blancas) {
                    $i = -1;
                    $nueva = (string) preg_replace_callback('/<(t[hd])\b([^>]*)>(.*?)<\/\1>/isu',
                        static function(array $c) use (&$i, $blancas): string {
                            $i++;
                            return isset($blancas[$i]) ? '<' . $c[1] . $c[2] . '>' . ($blancas[$i] === '' ? '&nbsp;'
                                : $blancas[$i]) . '</' . $c[1] . '>' : $c[0];
                        }, $fila);
                }
                // Ficha de practica: la fila «¿La mantendré?» queda para la persona facilitadora.
                if (count($cs) === 2 && self::key($cs[0][2]) === 'la mantendre') {
                    $nueva = '<tr><td>' . $cs[0][2] . '</td><td>&nbsp;</td></tr>';
                }
                if ($nueva !== $fila) {
                    $tabla = str_replace($fila, $nueva, $tabla);
                }
            }
            return $tabla;
        }, $html);
    }

    /**
     * Agrega, si faltan, los textos fijos de la propuesta despues del titulo de la seccion. Funcion pura.
     *
     * @param string $seccion HTML de la seccion (con su titulo).
     * @param string $texto
     * @return string
     */
    private static function ensure_intro(string $seccion, string $texto): string {
        if (strpos(self::key($seccion), self::key($texto)) !== false) {
            return $seccion;
        }
        return (string) preg_replace('/(<\/h[12]>)/iu', '$1<p>' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</p>',
            $seccion, 1);
    }

    /**
     * Seccion 7: un espacio de respuesta (recuadro en blanco) bajo cada pregunta. Funcion pura.
     *
     * @param string $seccion
     * @return string
     */
    public static function answer_spaces(string $seccion): string {
        $recuadro = '<table data-orientacion="v" class="chatbot-respuesta"><tbody><tr><td>&nbsp;<br>&nbsp;<br>&nbsp;<br>&nbsp;'
            . '</td></tr></tbody></table>';
        return (string) preg_replace('/(<p\b[^>]*>\s*(?:<(?:strong|b)\b[^>]*>\s*)?Pregunta\s+\d+\s*:.*?<\/p>)(?!\s*<table[^>]*'
            . 'chatbot-respuesta)/isu', '$1' . $recuadro, $seccion);
    }

    /**
     * Ensambla el documento: Seccion 1 por codigo, Secciones 2 a 8 del asistente con lo fijo, y Seccion 9 en blanco. Una
     * seccion que el asistente no entrego queda con [REVISAR]. Funcion pura.
     *
     * @param string $secciones HTML de las secciones 2 a 8 devuelto por el modelo.
     * @param array $ciclo cycle_data().
     * @return string
     */
    public static function assemble_html(string $secciones, array $ciclo): string {
        $partes = self::split_sections(documento_operativo::clean_operative_html($secciones));
        $h = self::section1_html($ciclo);
        for ($n = 2; $n <= 8; $n++) {
            $s = $partes[$n] ?? ('<h1>SECCIÓN ' . $n . ' · ' . self::SECCIONES[$n] . '</h1><p>[REVISAR: sección no extraída del '
                . 'informe consolidado]</p>');
            $s = (string) preg_replace('/^<h2\b([^>]*)>(.*?)<\/h2>/isu', '<h1$1>$2</h1>', $s, 1);
            if ($n === 6) {
                $s = self::ensure_intro($s, self::TEXTO_S6);
            } else if ($n === 7) {
                $s = self::answer_spaces(self::ensure_intro($s, self::TEXTO_S7));
            } else if ($n === 8) {
                $s = self::ensure_intro($s, self::TEXTO_S8);
            }
            $h .= self::blank_facilitator_cells($s);
        }
        $h .= self::section9_html();
        // Toda tabla en pagina vertical: el cuaderno se imprime y se escribe a mano.
        return (string) preg_replace('/<table(?![^>]*data-orientacion)/iu', '<table data-orientacion="v"', $h);
    }

    /**
     * Prompt de extraccion de las Secciones 2 a 8. Funcion pura.
     *
     * @param array $c etapas (numero => texto de las Etapas 11 a 14), ficha (texto de la ficha ex post), datos (results_text()),
     *        ajuste (nombre del tipo de ajuste).
     * @return string
     */
    public static function build_prompt(array $c): string {
        $l = [];
        $l[] = 'Eres el ' . implementacion::ROL . '. Tu tarea es extraer el DOCUMENTO OPERATIVO DE REFLEXIÓN SOBRE LA PRÁCTICA DE '
            . 'FACILITACIÓN: un cuaderno de trabajo para que la persona facilitadora reflexione sobre su práctica al cierre del '
            . 'ciclo. Muestra en pocas páginas lo que ocurrió, qué se decidió y con qué resultado, y deja espacios para su propia '
            . 'lectura, sus respuestas y su plan de desarrollo.';
        $l[] = '';
        $l[] = 'REGLAS DE GENERACIÓN:';
        $l[] = '- Es una extracción del informe consolidado (Etapas 11 a 14): no agregues juicios, hallazgos ni recomendaciones, '
            . 'y no cambies logros, niveles ni valoraciones. Las preguntas de reflexión son las de la Etapa 12.';
        $l[] = '- Usa frases breves y el lenguaje respetuoso del análisis metacognitivo, centrado en las decisiones y no en la persona.';
        $l[] = '- Los datos numéricos (logros, niveles y movimientos de nivel) son los de la ficha ex post validada que vienen en '
            . '«DATOS CALCULADOS POR LA PLATAFORMA». Si el informe presenta estimaciones distintas o datos incoherentes (por '
            . 'ejemplo, fechas o nombres de la experiencia), usa la ficha y agrega la marca ' . self::MARCA_DIFERENCIA
            . ' en el lugar exacto.';
        $l[] = '- Deja en blanco los espacios que completa la persona facilitadora: su lectura de cada decisión, cómo aplicará cada '
            . 'lección, sus comentarios y sus respuestas. No las inventes.';
        $l[] = '- No escribas la Sección 1 ni la Sección 9 (las arma la plataforma), ni notas «Origen», ni portada, ni índice.';
        $l[] = '- Extensión total del documento: 6 a 8 páginas; sintetiza. Escribe en español de Chile, con lenguaje inclusivo y '
            . 'sin los conceptos «pedagógico» o «pedagogía»; usa «experiencia de aprendizaje», nunca «sesión». No repitas nombres '
            . 'de personas participantes.';
        $l[] = '';
        $l[] = 'FORMATO DE RESPUESTA: Markdown, solo con estas secciones, en este orden y con estos títulos y tablas exactos:';
        $l[] = '# SECCIÓN 2 · Trayectoria del ciclo';
        $l[] = 'Tabla `| Momento | Lo que ocurrió | Efecto en los aprendizajes |` con las filas Diseño, Implementación, Decisión '
            . 'del tipo de ajuste, Experiencia de continuidad y Resultados (síntesis de 12.1 Trayectoria del ciclo formativo).';
        $l[] = '# SECCIÓN 3 · Decisiones clave';
        $l[] = 'Tabla `| Decisión y momento | Evidencia y resultado | Calificación | Fundamentación | Mi lectura |`: una fila por '
            . 'decisión de 12.2 (habitualmente cuatro: diseño, cambio de implementación, tipo de ajuste y organización de la '
            . 'continuidad). «Decisión y momento»: «Momento · qué se decidió». «Evidencia y resultado»: «evidencia utilizada · '
            . 'resultado obtenido». «Calificación»: Acertada, Parcialmente acertada o No acertada, como en el informe. «Mi '
            . 'lectura» vacía.';
        $l[] = '# SECCIÓN 4 · Mi reflexión y la evidencia';
        $l[] = 'Tabla `| Relación | Aspecto | Lo que muestra la evidencia | Mi comentario |` (12.3): en «Relación», «Coincide con '
            . 'mi reflexión», «La evidencia lo refuerza» o «No lo consideré»; el aspecto viene de la sección 7 de la ficha (o es '
            . 'el que la reflexión no incluyó). «Mi comentario» vacío.';
        $l[] = '# SECCIÓN 5 · Lecciones aprendidas';
        $l[] = 'Tabla `| Ámbito | Lección | ¿Cómo la aplicaré en mi práctica? |` con los ámbitos Diseño, Implementación, Evaluación '
            . 'y Ajustes (12.4); si hay más de una lección por ámbito, una fila por lección. La última columna vacía.';
        $l[] = '# SECCIÓN 6 · Prácticas que funcionaron';
        $l[] = self::TEXTO_S6 . ' Por cada práctica de 13.1, una tabla de dos columnas `| Práctica N | Nombre de la práctica |` '
            . 'con las filas «Qué resolvió», «Evidencia», «Condiciones de éxito», «Cómo replicarla», «Precauciones» y «¿La '
            . 'mantendré?» (vacía). Después, la tabla `| Práctica descartada | Motivo del descarte |` (13.2), o una fila «Sin '
            . 'prácticas descartadas».';
        $l[] = '# SECCIÓN 7 · Preguntas para la reflexión';
        $l[] = self::TEXTO_S7 . ' Luego, cada pregunta de 12.5 (dos o tres) en su propio párrafo: «**Pregunta 1:** texto de la '
            . 'pregunta». Sin respuestas.';
        $l[] = '# SECCIÓN 8 · Hacia una nueva ejecución';
        $l[] = self::TEXTO_S8;
        $l[] = '## 8.1 Recomendaciones por ámbito — tabla `| Ámbito | Recomendación | Evidencia del ciclo | Prioridad | Considerar |` '
            . 'con los ámbitos Diagnóstico, Diseño, Implementación, Evaluación y Seguimiento de la transferencia (14.1 a 14.5; '
            . 'una o más filas por ámbito, priorizadas; prioridad Alta, Media o Baja). «Considerar» con ☐.';
        $l[] = '## 8.2 Mis recomendaciones y su respaldo — tabla `| Mi recomendación (pregunta 7.4 de la ficha) | Respaldo de la '
            . 'evidencia | Dato que lo fundamenta |` (14.6): respaldo Respaldada, Parcialmente respaldada o No respaldada.';
        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        if (!empty($c['ajuste'])) {
            $l[] = 'TIPO DE AJUSTE DECIDIDO EN LA ETAPA 3: ' . $c['ajuste'] . '.';
            $l[] = '';
        }
        $l[] = (string) ($c['datos'] ?? '');
        $l[] = '';
        $l[] = 'FICHA DE EVALUACIÓN EX POST VALIDADA:';
        $l[] = guardrails::isolate_user_content((string) ($c['ficha'] ?? ''));
        foreach ((array) ($c['etapas'] ?? []) as $n => $texto) {
            $l[] = '';
            $l[] = 'INFORME CONSOLIDADO · ETAPA ' . $n . ' (' . (expost::etapas()[$n]['titulo'] ?? '') . '):';
            $l[] = guardrails::isolate_user_content(\core_text::substr((string) $texto, 0, self::ETAPA_MAX));
        }
        return implode("\n", $l);
    }

    /**
     * Datos del Word: cabecera de la propuesta («Cuaderno de trabajo… · Ciclo formativo: … · Informe ex post: …»). Funcion pura.
     *
     * @param array $pre Precarga de la ficha ex post.
     * @param array $data Ficha ex post.
     * @param string $fecha Fecha del informe ex post confirmado.
     * @param int $version
     * @return array
     */
    public static function word_meta(array $pre, array $data, string $fecha, int $version): array {
        $s1 = (array) ($pre['s1'] ?? []);
        $fechas = implode(' y ', array_values(array_filter([trim(rtrim(trim((string) ($s1['fechas_ini'] ?? '')), '.')),
            trim(rtrim(trim((string) ($data['fechas_cont'] ?? '')), '.'))])));
        $version = max(1, $version);
        $titulo = trim(rtrim(trim((string) ($s1['exp_inicial'] ?? '')), '.'));
        $items = [];
        if (($s1['facilitador'] ?? '') !== '') {
            $items[] = ['etiqueta' => 'Persona facilitadora', 'valor' => (string) $s1['facilitador']];
        }
        if ($fechas !== '') {
            $items[] = ['etiqueta' => 'Ciclo formativo', 'valor' => $fechas];
        }
        $items[] = ['etiqueta' => 'Versión', 'valor' => 'v' . $version . ($fecha !== '' ? ' · ' . $fecha : '')];
        return [
            'estilo' => 'operativo',
            'portada' => false,
            'etiqueta' => self::ETIQUETA,
            'titulo' => $titulo !== '' ? $titulo : 'Experiencia de aprendizaje',
            'subtitulo' => self::CUADERNO . ($fechas !== '' ? ' · Ciclo formativo: ' . $fechas : '')
                . ($fecha !== '' ? ' · Informe ex post: ' . $fecha : '') . ' · versión ' . $version,
            'meta_items' => $items,
            'encabezado' => self::ENCABEZADO,
        ];
    }

    /**
     * Arma el Word (.docx) editable. Funcion pura.
     *
     * @param string $ruta
     * @param array $meta
     * @param string $html
     * @param string $logo
     * @return bool
     */
    public static function build_docx(string $ruta, array $meta, string $html, string $logo = ''): bool {
        return documento_continuidad::build_docx($ruta, $meta, $html, $logo);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Base de datos y servicio de IA.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Pide la generacion (la pagina de la Etapa 15 la ejecuta en segundo plano). La siguiente generacion sube la version.
     *
     * @param int $implid
     * @param int $userid
     * @param int $courseid
     * @param int $validacionid Validacion vigente de la ficha ex post.
     * @return int id de la fila.
     */
    public static function queue(int $implid, int $userid, int $courseid, int $validacionid): int {
        global $DB;
        $ahora = time();
        $fila = $DB->get_record('local_chatbot_calidad', ['implid' => $implid, 'etapa' => self::ETAPA]);
        if (!$fila) {
            return (int) $DB->insert_record('local_chatbot_calidad', (object) ['implid' => $implid, 'userid' => $userid,
                'courseid' => $courseid, 'etapa' => self::ETAPA, 'contenido' => null, 'estado' => calidad::BORRADOR,
                'generacion' => implementacion::PENDIENTE, 'validacionid' => $validacionid, 'rondas' => 0,
                'timecreated' => $ahora, 'timemodified' => $ahora]);
        }
        if (in_array((string) $fila->generacion, [implementacion::PENDIENTE, implementacion::PROCESANDO], true)) {
            return (int) $fila->id;
        }
        $DB->update_record('local_chatbot_calidad', (object) ['id' => $fila->id, 'generacion' => implementacion::PENDIENTE,
            'validacionid' => $validacionid, 'error' => null, 'timemodified' => $ahora]);
        return (int) $fila->id;
    }

    /**
     * Al confirmar la consolidacion de la Etapa 15: si las fuentes no cambiaron, el documento vigente sigue valiendo (misma
     * version, nueva huella); si cambiaron o no hay documento, se pide una version nueva.
     *
     * @param \stdClass $impl Ficha de implementacion.
     * @param \stdClass $val Validacion vigente de la ficha ex post.
     * @return void
     */
    public static function after_confirm(\stdClass $impl, \stdClass $val): void {
        global $DB;
        try {
            $filas = calidad::get_by_impl((int) $impl->id);
            $c15 = $filas[expost::CONSOLIDACION] ?? null;
            if (!$c15 || $c15->estado !== calidad::ACEPTADO) {
                return;
            }
            $fuente = self::source_fingerprint((string) $val->huella, $filas, calidad::decided_adjustment($filas));
            $doc = $filas[self::ETAPA] ?? null;
            if (self::same_source($doc, $fuente)) {
                $DB->update_record('local_chatbot_calidad', (object) ['id' => $doc->id, 'huella' => (string) $c15->huella,
                    'timemodified' => time()]);
                return;
            }
            if (ai\provider_factory::is_configured()) {
                self::queue((int) $impl->id, (int) $impl->userid, (int) $impl->courseid, (int) $val->id);
            }
        } catch (\Throwable $e) {
            debugging('local_chatbot documento_reflexion after_confirm: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Genera el documento (lo llama calidad::generate() para la fila etapa = self::ETAPA).
     *
     * @param \stdClass $fila
     * @return array [estado de la generacion, campos a guardar].
     */
    public static function generate_row(\stdClass $fila): array {
        global $DB;
        if (!ai\provider_factory::is_configured()) {
            return [implementacion::ERROR, ['error' => 'sinclave']];
        }
        $val = $DB->get_record('local_chatbot_expost_val', ['id' => $fila->validacionid, 'userid' => $fila->userid]);
        if (!$val) {
            return [implementacion::ERROR, ['error' => 'sinficha']];
        }
        $filas = calidad::get_by_impl((int) $fila->implid);
        $c15 = $filas[expost::CONSOLIDACION] ?? null;
        if (!$c15 || $c15->estado !== calidad::ACEPTADO
                || (string) $c15->huella !== expost::stages_fingerprint($filas, (string) $val->huella)) {
            return [implementacion::ERROR, ['error' => 'sinconsolidacion15']];
        }
        $etapas = [];
        foreach (expost::ETAPAS as $n) {
            if (empty($filas[$n]) || $filas[$n]->estado !== calidad::ACEPTADO || trim((string) $filas[$n]->contenido) === '') {
                return [implementacion::ERROR, ['error' => 'sinetapas']];
            }
            $etapas[$n] = html_to_text((string) $filas[$n]->contenido, 0, false);
        }
        $snap = (array) json_decode((string) $val->snapshot, true);
        $data = expost::clean((array) ($snap['data'] ?? []));
        $pre = (array) ($snap['pre'] ?? []);
        $etq = (array) ($snap['etq'] ?? []);
        if (!$pre) {
            return [implementacion::ERROR, ['error' => 'sinficha']];
        }
        $r = expost::results($data, $pre);
        $ajuste = calidad::decided_adjustment($filas);
        $prompt = pseudonymizer::scrub(self::build_prompt(['etapas' => $etapas, 'ficha' => expost::to_text($data, $pre, $etq),
            'datos' => expost::results_text($r), 'ajuste' => $ajuste !== null ? calidad::adjustment_name($ajuste) : '']));
        $modelo = ai\provider_factory::get_configured_model();
        $resultado = exante::generate_steady([['role' => 'user', 'parts' => [['text' => $prompt]]]], self::ESPERA, $modelo, false);
        if (isset($resultado['error'])) {
            return [implementacion::ERROR, ['error' => 'servicio']];
        }
        $markdown = diseno::clean_markdown(gemini_client::extract_text($resultado));
        if ($markdown === '') {
            return [implementacion::ERROR, ['error' => 'ilegible']];
        }
        $html = self::assemble_html(diseno::product_html($markdown), self::cycle_data($data, $pre, $r, $ajuste));
        return [implementacion::LISTO, [
            'contenido' => $html,
            'estado' => calidad::ACEPTADO,
            'huella' => (string) $c15->huella,
            'rondas' => max(1, (int) ($fila->rondas ?? 0) + 1),
            'modelo' => (string) $modelo,
            'promptversion' => self::PROMPT_VERSION,
            'informe' => json_encode(['fuente' => self::source_fingerprint((string) $val->huella, $filas, $ajuste)]),
            'timeaccepted' => time(),
            'error' => null,
        ]];
    }
}

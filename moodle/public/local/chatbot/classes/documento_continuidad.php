<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Modulo 3, salida operativa al cierre de la Etapa 8: Documento operativo de la experiencia de aprendizaje de continuidad.
 *
 * Especificacion de Interfases («Documento Operativo de la Experiencia de Continuidad - Propuesta Generica», 26-11-2026):
 * version de trabajo de la experiencia de continuidad que la persona facilitadora usa para conducirla en el aula o el
 * taller. Se genera siempre al confirmar la consolidacion (Etapa 8), cualquiera sea el tipo de ajuste, y se entrega en Word
 * editable. Si la continuidad se ajusta despues, se genera una nueva version.
 *
 * Arquitectura hibrida, como el documento operativo del Modulo 2 (classes/documento_operativo.php):
 *  - Secciones 1 a 7 (6 a 10 paginas): las extrae el asistente del informe consolidado, principalmente de la Etapa 6, con
 *    los datos exactos que entrega la plataforma (tiempo restante, tipo de ajuste, logro y criticidad por criterio).
 *  - Lo que no depende del juicio lo fija el codigo despues de la extraccion: las filas de datos generales que salen de la
 *    ficha, la transcripcion textual del aprendizaje esperado y los criterios, las tablas en blanco para la persona
 *    facilitadora, las filas fijas de la regla de aprobacion y la nota de uso en la Fase 4, y las marcas [REVISAR] de
 *    tiempo (la secuencia supera el tiempo restante o difiere entre etapas).
 *  - Anexos: un anexo por instrumento, extraido sin cambios de la Etapa 6 (instrumentos ajustados) y, para los que se
 *    aplican en forma recuperativa, de la Etapa 6 del diseño del Modulo 2.
 *  - Estado de los materiales (6.1): se sincroniza al descargar con el repertorio del Centro de produccion del diseño.
 *
 * Se guarda en local_chatbot_calidad con etapa = self::ETAPA (fuera de las etapas 1 a 8 y 9 a 15), con la huella de la
 * consolidacion vigente, el numero de version en rondas y la huella de sus fuentes en informe: sin cambios en la base.
 *
 * Todo lo que no consulta la base es funcion pura y lo ejercita cli/verificar_documento_continuidad.php.
 */
class documento_continuidad {

    /** @var int Etapa interna en local_chatbot_calidad (las del modulo son 1 a 8 y las de la Fase 4, 9 a 15). */
    const ETAPA = 16;

    /** @var string Version del prompt (RU-PRIV-006). */
    const PROMPT_VERSION = 'M3-OP-v1';

    /** @var int Segundos antes de dar por caida una generacion en curso. */
    const MAX_SECONDS = 600;

    /** @var int Segundos de espera de la respuesta del asistente. */
    const ESPERA = 420;

    /** @var string Nombre base del archivo Word. */
    const ARCHIVO = 'Documento_operativo_continuidad';

    /** @var int Tope de caracteres de la Etapa 6 que viajan al modelo (trae los instrumentos completos). */
    const ETAPA6_MAX = 60000;

    /** @var int Tope de caracteres de cada una de las demas fuentes. */
    const PREVIA_MAX = 20000;

    /** @var string Rotulo de la cabecera y encabezado de pagina del Word (textuales de la propuesta). */
    const ETIQUETA = 'DOCUMENTO OPERATIVO · EXPERIENCIA DE CONTINUIDAD';
    const ENCABEZADO = 'Documento operativo · Experiencia de continuidad';

    /** @var string Filas fijas de la tabla 7.2 y nota de la 7.3 (textuales de la propuesta). */
    const FILA_RECUPERATIVOS = 'Los instrumentos recuperativos se califican con la regla del diseño validado del Módulo 2, sin cambios.';
    const FILA_NO_REGRESION = 'En la ficha de evaluación ex post se registra el nivel más alto demostrado por cada persona en el ciclo: '
        . 'un criterio ya logrado en la experiencia inicial se mantiene como logrado (principio de no regresión).';
    const NOTA_FASE4 = 'Uso en la Fase 4: con esta tabla y la del diseño validado (para los instrumentos recuperativos), la '
        . 'persona facilitadora asigna el nivel final de cada participante en la sección 2 de la ficha de evaluación ex post.';

    /** @var string Nota de los anexos de instrumentos recuperativos (propuesta: «transcrito sin cambios»). */
    const NOTA_RECUPERATIVO = 'Instrumento del diseño validado del Módulo 2, transcrito sin cambios.';

    /** @var int Filas en blanco de la tabla 4.2 y jornadas de la 5.2 (como en la propuesta). */
    const FILAS_ASIGNACION = 4;
    const JORNADAS = 2;

    /** @var string[] Filas de la tabla «Datos generales», en el orden de la propuesta. */
    const FILAS_DATOS = ['Organismo ejecutor', 'Plan formativo y módulo', 'Experiencia inicial', 'Experiencia de continuidad',
        'Tipo de ajuste decidido', 'Duración', 'Participantes', 'Modalidad y espacios', 'Conducción', 'Fechas y horario'];

    // ------------------------------------------------------------------------------------------------------------
    // Vigencia y versiones.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Si hay un documento listo y vigente para la consolidacion confirmada de hoy. Funcion pura.
     *
     * @param \stdClass|null $fila Fila etapa = self::ETAPA.
     * @param string $huella Huella de la consolidacion confirmada (fila de la Etapa 8).
     * @return bool
     */
    public static function is_current(?\stdClass $fila, string $huella): bool {
        return $fila !== null
            && $huella !== ''
            && (string) ($fila->generacion ?? '') === implementacion::LISTO
            && (string) ($fila->huella ?? '') === $huella
            && trim(strip_tags((string) ($fila->contenido ?? ''))) !== '';
    }

    /**
     * Numero de version del documento (1, 2, 3...). Funcion pura.
     *
     * @param \stdClass|null $fila
     * @return int
     */
    public static function version_number(?\stdClass $fila): int {
        return max(1, (int) ($fila->rondas ?? 1));
    }

    /**
     * Huella de lo que el documento extrae: la validacion de la ficha (tiempo restante), las Etapas 2, 4 y 6 aceptadas, el
     * tipo de ajuste, el diseño del Modulo 2 y la version del prompt. Si no cambia, una nueva consolidacion (por ejemplo, de
     * la sintesis) no genera una nueva version: «si la continuidad se ajusta despues, se genera una nueva version». Funcion
     * pura.
     *
     * @param string $validacion Huella de la validacion de la ficha.
     * @param \stdClass[] $filas Etapas de la evaluacion (etapa => fila).
     * @param string|null $ajuste Tipo de ajuste vigente.
     * @param string $diseno Huella del diseño del Modulo 2.
     * @return string
     */
    public static function source_fingerprint(string $validacion, array $filas, ?string $ajuste, string $diseno): string {
        $partes = [];
        foreach ([2, 4, 6] as $n) {
            $f = $filas[$n] ?? null;
            $partes[$n] = ($f && $f->estado === calidad::ACEPTADO) ? (string) $f->contenido : '';
        }
        return sha1(json_encode([$validacion, $partes, (string) $ajuste, $diseno, self::PROMPT_VERSION], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Si el documento guardado ya corresponde a esas fuentes (listo, con contenido y la misma huella). Funcion pura.
     *
     * @param \stdClass|null $fila
     * @param string $fuente Resultado de source_fingerprint().
     * @return bool
     */
    public static function same_source(?\stdClass $fila, string $fuente): bool {
        if (!$fila || (string) ($fila->generacion ?? '') !== implementacion::LISTO
                || trim(strip_tags((string) ($fila->contenido ?? ''))) === '') {
            return false;
        }
        $info = json_decode((string) ($fila->informe ?? ''), true);
        return is_array($info) && ($info['fuente'] ?? '') === $fuente;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Ayudas de HTML.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param string $html
     * @return array [\DOMDocument, \DOMElement|null]
     */
    private static function dom(string $html): array {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        return [$dom, $dom->getElementsByTagName('body')->item(0)];
    }

    /**
     * @param \DOMDocument $dom
     * @param \DOMElement $body
     * @return string
     */
    private static function html_of(\DOMDocument $dom, \DOMElement $body): string {
        $out = '';
        foreach ($body->childNodes as $hijo) {
            $out .= $dom->saveHTML($hijo);
        }
        return trim($out);
    }

    /**
     * Texto de un nodo en una linea.
     *
     * @param \DOMNode $n
     * @return string
     */
    private static function text(\DOMNode $n): string {
        return trim(preg_replace('/\s+/u', ' ', $n->textContent));
    }

    /**
     * Texto para comparar: minusculas, sin tildes, sin asteriscos ni dos puntos finales.
     *
     * @param string $t
     * @return string
     */
    private static function key(string $t): string {
        return trim(diseno::plain_lower(trim(preg_replace('/\s+/u', ' ', $t))), " \t*:.");
    }

    /**
     * Filas propias de una tabla (no las de tablas anidadas): la primera es el encabezado.
     *
     * @param \DOMElement $tabla
     * @return \DOMElement[][] Celdas de cada fila.
     */
    private static function rows(\DOMElement $tabla): array {
        $filas = [];
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
            if ($celdas) {
                $filas[] = $celdas;
            }
        }
        return $filas;
    }

    /**
     * Primera tabla cuyo encabezado cumple la condicion.
     *
     * @param \DOMElement $body
     * @param callable $cumple Recibe los encabezados ya normalizados (key()).
     * @return array|null [tabla, filas (rows()), encabezados]
     */
    private static function find_table(\DOMElement $body, callable $cumple): ?array {
        foreach ($body->getElementsByTagName('table') as $tabla) {
            $filas = self::rows($tabla);
            if (!$filas) {
                continue;
            }
            $cab = array_map(static function(\DOMElement $c): string {
                return self::key(self::text($c));
            }, $filas[0]);
            if ($cumple($cab)) {
                return [$tabla, $filas, $cab];
            }
        }
        return null;
    }

    /**
     * Posicion del primer encabezado que empieza con el prefijo.
     *
     * @param string[] $cab
     * @param string $prefijo
     * @return int|null
     */
    private static function col(array $cab, string $prefijo): ?int {
        foreach ($cab as $i => $h) {
            if (strpos($h, $prefijo) === 0) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Reemplaza el contenido de una celda por un texto.
     *
     * @param \DOMDocument $dom
     * @param \DOMElement $celda
     * @param string $texto
     * @return void
     */
    private static function set_text(\DOMDocument $dom, \DOMElement $celda, string $texto): void {
        while ($celda->firstChild) {
            $celda->removeChild($celda->firstChild);
        }
        if ($texto !== '') {
            $celda->appendChild($dom->createTextNode($texto));
        }
    }

    /**
     * Agrega una fila al cuerpo de la tabla (antes de $antes, si se indica).
     *
     * @param \DOMDocument $dom
     * @param \DOMElement $tabla
     * @param string[] $textos
     * @param \DOMElement|null $antes Fila (tr) delante de la cual se inserta.
     * @return \DOMElement La fila creada.
     */
    private static function add_row(\DOMDocument $dom, \DOMElement $tabla, array $textos, ?\DOMElement $antes = null): \DOMElement {
        $tr = $dom->createElement('tr');
        foreach ($textos as $t) {
            $td = $dom->createElement('td');
            if ((string) $t !== '') {
                $td->appendChild($dom->createTextNode((string) $t));
            }
            $tr->appendChild($td);
        }
        if ($antes !== null && $antes->parentNode) {
            $antes->parentNode->insertBefore($tr, $antes);
            return $tr;
        }
        $tbody = null;
        foreach ($tabla->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->nodeName === 'tbody') {
                $tbody = $c;
            }
        }
        ($tbody ?? $tabla)->appendChild($tr);
        return $tr;
    }

    /**
     * Rotulo con que empieza un criterio o un aprendizaje esperado («C1: », «1. », «Criterio 2: », «AE 1. »), o ''.
     * Funcion pura.
     *
     * @param string $texto
     * @return string
     */
    public static function leading_label(string $texto): string {
        if (preg_match('/^\s*((?:(?:criterio|ae|c)\s*(?:n\.?\s*[º°o]\s*)?)?\d+[a-z]?\s*[.:)\-–—]+\s*)/iu', $texto, $m)) {
            return $m[1];
        }
        return '';
    }

    // ------------------------------------------------------------------------------------------------------------
    // Lo que fija el codigo despues de la extraccion.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Datos generales que salen de la ficha y del diseño, no del juicio del asistente. Funcion pura.
     *
     * @param array $ficha Ficha de registro de la implementacion (implementacion::clean()).
     * @param array $m2 Ficha de diagnostico del diseño del Modulo 2 (exante::clean()): trae el organismo ejecutor.
     * @param string|null $ajuste Tipo de ajuste vigente.
     * @return array Rotulo de la fila => valor ('' deja la celda en blanco; null no toca lo que extrajo el asistente).
     */
    public static function general_data(array $ficha, array $m2, ?string $ajuste): array {
        $unir = static function(array $partes): ?string {
            $partes = array_values(array_filter(array_map(static function($v): string {
                return trim(rtrim(trim((string) $v), '.'));
            }, $partes), 'strlen'));
            return $partes ? implode(' · ', $partes) : null;
        };
        $org = trim((string) ($m2['organismo'] ?? ''));
        return ['Organismo ejecutor' => $org !== '' ? $org : null,
            'Plan formativo y módulo' => $unir([$ficha['plan'] ?? '', $ficha['modulo'] ?? '']),
            'Experiencia inicial' => $unir([$ficha['experiencia'] ?? '', $ficha['fechas'] ?? '']),
            'Tipo de ajuste decidido' => $ajuste !== null ? calidad::adjustment_name($ajuste) : null,
            'Fechas y horario' => ''];
    }

    /**
     * Tabla «Datos generales» (Seccion 1): las filas que salen de la ficha llevan el valor exacto y «Fechas y horario»
     * queda en blanco; si el asistente omitio una de esas filas, se agrega en su lugar. Funcion pura.
     *
     * @param string $html
     * @param array $datos Resultado de general_data().
     * @return string
     */
    public static function fill_general_data(string $html, array $datos): string {
        if (stripos($html, '<table') === false || !$datos) {
            return $html;
        }
        [$dom, $body] = self::dom($html);
        $t = $body ? self::find_table($body, static function(array $cab): bool {
            return isset($cab[0], $cab[1]) && strpos($cab[0], 'campo') === 0 && strpos($cab[1], 'informacion') === 0;
        }) : null;
        if (!$t) {
            return $html;
        }
        [$tabla, $filas] = $t;
        $vistos = [];
        $filaFechas = null;
        foreach (array_slice($filas, 1) as $celdas) {
            if (count($celdas) < 2) {
                continue;
            }
            $rotulo = self::key(self::text($celdas[0]));
            foreach ($datos as $nombre => $valor) {
                $k = self::key($nombre);
                $coincide = $rotulo === $k || strpos($rotulo, $k) === 0 || (strlen($rotulo) >= 8 && strpos($k, $rotulo) === 0);
                if (!$coincide) {
                    continue;
                }
                $vistos[$nombre] = true;
                if ($nombre === 'Fechas y horario') {
                    $filaFechas = $celdas[0]->parentNode;
                }
                if ($valor !== null && self::text($celdas[1]) !== $valor) {
                    self::set_text($dom, $celdas[1], (string) $valor);
                }
                break;
            }
        }
        foreach ($datos as $nombre => $valor) {
            if (isset($vistos[$nombre]) || $valor === null) {
                continue;
            }
            $tr = self::add_row($dom, $tabla, [$nombre, (string) $valor], $nombre === 'Fechas y horario' ? null : $filaFechas);
            if ($nombre === 'Fechas y horario') {
                $filaFechas = $tr;
            }
        }
        return self::html_of($dom, $body);
    }

    /**
     * Las tablas que completa la persona facilitadora quedan en blanco: 4.2 (asignacion de participantes, al menos cuatro
     * filas) y 5.2 (distribucion por jornada: solo «Jornada N», al menos dos). Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function blank_facilitator_tables(string $html): string {
        if (stripos($html, '<table') === false) {
            return $html;
        }
        [$dom, $body] = self::dom($html);
        if (!$body) {
            return $html;
        }
        $cambio = false;
        foreach ([['participante', 'trayectoria', self::FILAS_ASIGNACION, false],
                ['jornada', 'fecha', self::JORNADAS, true]] as [$p0, $p1, $minimo, $conRotulo]) {
            $t = self::find_table($body, static function(array $cab) use ($p0, $p1): bool {
                return isset($cab[0], $cab[1]) && strpos($cab[0], $p0) === 0 && strpos($cab[1], $p1) === 0;
            });
            if (!$t) {
                continue;
            }
            [$tabla, $filas] = $t;
            $columnas = count($filas[0]);
            $cuerpo = array_slice($filas, 1);
            foreach ($cuerpo as $i => $celdas) {
                foreach ($celdas as $k => $celda) {
                    $nuevo = ($conRotulo && $k === 0) ? 'Jornada ' . ($i + 1) : '';
                    if (self::text($celda) !== $nuevo) {
                        self::set_text($dom, $celda, $nuevo);
                        $cambio = true;
                    }
                }
            }
            for ($i = count($cuerpo); $i < $minimo; $i++) {
                $textos = array_fill(0, $columnas, '');
                if ($conRotulo) {
                    $textos[0] = 'Jornada ' . ($i + 1);
                }
                self::add_row($dom, $tabla, $textos);
                $cambio = true;
            }
        }
        return $cambio ? self::html_of($dom, $body) : $html;
    }

    /**
     * Seccion 3: el aprendizaje esperado y cada criterio de evaluacion, textuales del plan formativo («se transcriben
     * textualmente»). Cada criterio se reconoce por parecido con los del plan y conserva el rotulo que le puso la Etapa 6
     * («C1: »); sin rotulo, lleva su numero en el plan. Funcion pura.
     *
     * @param string $html
     * @param string $aprendizaje Aprendizaje esperado del plan formativo.
     * @param string[] $criterios Criterios del plan formativo, en orden.
     * @return string
     */
    public static function enforce_textual_criteria(string $html, string $aprendizaje, array $criterios): string {
        if (stripos($html, '<table') === false) {
            return $html;
        }
        [$dom, $body] = self::dom($html);
        if (!$body) {
            return $html;
        }
        $cambio = false;
        $aprendizaje = trim($aprendizaje);
        $ae = $aprendizaje !== '' ? self::find_table($body, static function(array $cab): bool {
            return count($cab) === 1 && strpos($cab[0], 'aprendizaje esperado') === 0;
        }) : null;
        if ($ae) {
            [$tabla, $filas] = $ae;
            if (count($filas) < 2) {
                self::add_row($dom, $tabla, [$aprendizaje]);
                $cambio = true;
            } else {
                $actual = self::text($filas[1][0]);
                $rotulo = self::leading_label($actual);
                $nuevo = ($rotulo !== '' && self::leading_label($aprendizaje) === '' ? $rotulo : '') . $aprendizaje;
                if ($actual !== $nuevo) {
                    self::set_text($dom, $filas[1][0], $nuevo);
                    $cambio = true;
                }
            }
        }
        $criterios = array_values(array_filter(array_map('trim', $criterios), 'strlen'));
        $tc = $criterios ? self::find_table($body, static function(array $cab): bool {
            return isset($cab[0]) && strpos($cab[0], 'criterio de evaluacion') === 0 && self::col($cab, 'se trabaja') !== null;
        }) : null;
        if ($tc) {
            foreach (array_slice($tc[1], 1) as $celdas) {
                $actual = self::text($celdas[0]);
                $rotulo = self::leading_label($actual);
                $resto = trim(\core_text::substr($actual, \core_text::strlen($rotulo)));
                $mejor = 0.0;
                $pos = null;
                foreach ($criterios as $k => $c) {
                    $p = implementacion::similarity($resto, $c);
                    if ($p > $mejor) {
                        $mejor = $p;
                        $pos = $k;
                    }
                }
                if ($pos === null || $mejor < 0.6) {
                    continue;
                }
                $nuevo = ($rotulo !== '' ? $rotulo : ($pos + 1) . '. ') . $criterios[$pos];
                if ($actual !== $nuevo) {
                    self::set_text($dom, $celdas[0], $nuevo);
                    $cambio = true;
                }
            }
        }
        return $cambio ? self::html_of($dom, $body) : $html;
    }

    /**
     * Textos fijos de la propuesta: las dos filas de la tabla 7.2 (instrumentos recuperativos y principio de no regresion)
     * y la nota de uso en la Fase 4 bajo la tabla 7.3. Si el asistente los reescribio, quedan textuales; si los omitio, se
     * agregan. Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function ensure_fixed_texts(string $html): string {
        if (trim($html) === '') {
            return $html;
        }
        [$dom, $body] = self::dom($html);
        if (!$body) {
            return $html;
        }
        $t = self::find_table($body, static function(array $cab): bool {
            return count($cab) === 1 && strpos($cab[0], 'condiciones') === 0;
        });
        if ($t) {
            [$tabla, $filas] = $t;
            $cuerpo = array_slice($filas, 1);
            // Cada fila fija se reconoce por su frase clave; si viene mezclada con otra condicion (mucho mas larga), se deja
            // y se agrega la fila textual.
            foreach ([self::FILA_RECUPERATIVOS => '/instrumentos recuperativos|recuperativ\w* se califican/u',
                    self::FILA_NO_REGRESION => '/no regresion|nivel mas alto/u'] as $fija => $clave) {
                $hecha = false;
                foreach ($cuerpo as $celdas) {
                    $actual = self::text($celdas[0]);
                    if ($actual === $fija) {
                        $hecha = true;
                        break;
                    }
                    if (preg_match($clave, self::key($actual))
                            && \core_text::strlen($actual) <= 1.6 * \core_text::strlen($fija)) {
                        self::set_text($dom, $celdas[0], $fija);
                        $hecha = true;
                        break;
                    }
                }
                if (!$hecha) {
                    self::add_row($dom, $tabla, [$fija]);
                }
            }
        }
        // Nota de uso en la Fase 4.
        $nota = null;
        foreach ($body->getElementsByTagName('p') as $p) {
            if (strpos(self::key(self::text($p)), 'uso en la fase 4') === 0) {
                $nota = $p;
                break;
            }
        }
        $em = $dom->createElement('em');
        $em->appendChild($dom->createTextNode(self::NOTA_FASE4));
        if ($nota) {
            if (self::text($nota) !== self::NOTA_FASE4) {
                while ($nota->firstChild) {
                    $nota->removeChild($nota->firstChild);
                }
                $nota->appendChild($em);
            }
        } else {
            $p = $dom->createElement('p');
            $p->appendChild($em);
            $eq = self::find_table($body, static function(array $cab): bool {
                return isset($cab[0]) && strpos($cab[0], 'nivel') === 0 && strpos((string) end($cab), 'condicion') === 0;
            });
            $tras = $eq ? $eq[0] : null;
            if ($tras && $tras->parentNode) {
                $tras->parentNode->insertBefore($p, $tras->nextSibling);
            } else {
                $body->appendChild($p);
            }
        }
        return self::html_of($dom, $body);
    }

    /**
     * Revision de tiempos de la vista general (5.1): la fila «Total» (se agrega si falta) lleva el tiempo restante, y se
     * marca con [REVISAR] cuando la secuencia supera el tiempo restante de la Etapa 0, cuando no coincide con la secuencia de
     * la Etapa 6 o cuando la Etapa 4 y la Etapa 6 difieren («no la resuelve: usa el valor de la Etapa 6 y agrega la marca»).
     * Funcion pura.
     *
     * @param string $html
     * @param int|null $restante Tiempo restante (total disponible − utilizado).
     * @param int|null $min4 Total de la «Distribución del tiempo restante» de la Etapa 4.
     * @param int|null $min6 Total de la secuencia de la Etapa 6.
     * @return string
     */
    public static function time_marks(string $html, ?int $restante, ?int $min4, ?int $min6): string {
        if (stripos($html, '<table') === false) {
            return $html;
        }
        [$dom, $body] = self::dom($html);
        $t = $body ? self::find_table($body, static function(array $cab): bool {
            return isset($cab[0]) && strpos($cab[0], 'fase') === 0 && self::col($cab, 'minut') !== null;
        }) : null;
        if (!$t) {
            return $html;
        }
        [$tabla, $filas, $cab] = $t;
        $cm = self::col($cab, 'minut');
        $columnas = count($cab);
        $total = null;
        $filaTotal = null;
        $suma = 0;
        foreach (array_slice($filas, 1) as $celdas) {
            $min = isset($celdas[$cm]) && preg_match('/\d+/', self::text($celdas[$cm]), $m) ? (int) $m[0] : 0;
            if (preg_match('/^\W*total\b/u', self::key(self::text($celdas[0])))) {
                $filaTotal = $celdas;
                $total = $min;
                continue;
            }
            $suma += $min;
        }
        if ($filaTotal === null) {
            $textos = array_fill(0, $columnas, '');
            $textos[0] = 'Total';
            $textos[$cm] = (string) $suma;
            $filaTotal = [];
            foreach (self::add_row($dom, $tabla, $textos)->childNodes as $td) {
                if ($td instanceof \DOMElement) {
                    $filaTotal[] = $td;
                }
            }
            $total = $suma;
        }
        $textoFila = self::key(implode(' ', array_map(static function(\DOMElement $c): string {
            return self::text($c);
        }, $filaTotal)));
        // El tiempo restante es un dato exacto de la plataforma: si el asistente escribio otro, se corrige (fila Total y fila
        // «Duración» de los datos generales).
        if ($restante !== null) {
            $celdas = $filaTotal;
            $g = self::find_table($body, static function(array $cab): bool {
                return isset($cab[0], $cab[1]) && strpos($cab[0], 'campo') === 0 && strpos($cab[1], 'informacion') === 0;
            });
            foreach ($g ? array_slice($g[1], 1) : [] as $fila) {
                if (isset($fila[1]) && strpos(self::key(self::text($fila[0])), 'duracion') === 0) {
                    $celdas[] = $fila[1];
                }
            }
            foreach ($celdas as $celda) {
                $t = self::text($celda);
                $nuevo = (string) preg_replace('/(tiempo restante(?:\s+de|\s*:)?\s*)\d+/iu', '${1}' . max(0, $restante), $t);
                if ($nuevo !== $t) {
                    self::set_text($dom, $celda, $nuevo);
                }
            }
        }
        // «Tiempo restante: N» en la fila Total, como en la propuesta.
        if ($restante !== null && strpos($textoFila, 'tiempo restante') === false) {
            $destino = $filaTotal[$cm === 2 ? 1 : 2] ?? $filaTotal[count($filaTotal) - 1];
            $previo = self::text($destino);
            self::set_text($dom, $destino, trim($previo . ' Tiempo restante: ' . max(0, $restante) . ' minutos'));
        }
        $marcas = [];
        if ($restante !== null && $total !== null && $total > max(0, $restante)
                && strpos($textoFila, 'supera el tiempo restante') === false) {
            $marcas[] = '[REVISAR: la secuencia suma ' . $total . ' minutos y supera el tiempo restante de ' . max(0, $restante)
                . ' minutos]';
        }
        if ($min6 !== null && $total !== null && $total !== $min6 && strpos($textoFila, 'diferencia con la etapa 6') === false) {
            $marcas[] = '[REVISAR: diferencia con la Etapa 6 — su secuencia suma ' . $min6 . ' minutos]';
        }
        if ($min4 !== null && $min6 !== null && $min4 !== $min6 && strpos($textoFila, 'diferencia entre etapas') === false) {
            $marcas[] = '[REVISAR: diferencia entre etapas — la Etapa 4 distribuye ' . $min4 . ' minutos y la secuencia de la '
                . 'Etapa 6 suma ' . $min6 . ']';
        }
        if ($marcas && isset($filaTotal[$cm])) {
            self::set_text($dom, $filaTotal[$cm], trim(self::text($filaTotal[$cm]) . ' ' . implode(' ', $marcas)));
        }
        return self::html_of($dom, $body);
    }

    /**
     * Minutos totales de la vista general del documento (fila «Total» de la tabla 5.1). Funcion pura.
     *
     * @param string $html
     * @return int|null
     */
    public static function sequence_total(string $html): ?int {
        if (stripos($html, '<table') === false) {
            return null;
        }
        [, $body] = self::dom($html);
        $t = $body ? self::find_table($body, static function(array $cab): bool {
            return isset($cab[0]) && strpos($cab[0], 'fase') === 0 && self::col($cab, 'minut') !== null;
        }) : null;
        if (!$t) {
            return null;
        }
        $cm = self::col($t[2], 'minut');
        foreach (array_slice($t[1], 1) as $celdas) {
            if (preg_match('/^\W*total\b/u', self::key(self::text($celdas[0]))) && isset($celdas[$cm])
                    && preg_match('/\d+/', self::text($celdas[$cm]), $m)) {
                return (int) $m[0];
            }
        }
        return null;
    }

    /**
     * Valor de una fila de la tabla «Datos generales» (por ejemplo, «Experiencia de continuidad»), sin corchetes. Funcion
     * pura.
     *
     * @param string $html
     * @param string $rotulo
     * @return string
     */
    public static function general_value(string $html, string $rotulo): string {
        if (stripos($html, '<table') === false) {
            return '';
        }
        [, $body] = self::dom($html);
        $t = $body ? self::find_table($body, static function(array $cab): bool {
            return isset($cab[0], $cab[1]) && strpos($cab[0], 'campo') === 0 && strpos($cab[1], 'informacion') === 0;
        }) : null;
        if (!$t) {
            return '';
        }
        $k = self::key($rotulo);
        foreach (array_slice($t[1], 1) as $celdas) {
            if (isset($celdas[1]) && strpos(self::key(self::text($celdas[0])), $k) === 0) {
                return trim(self::text($celdas[1]), " \t[]");
            }
        }
        return '';
    }

    /**
     * Orientacion de las tablas como en la plantilla del cliente: la Seccion 5 (secuencia, jornadas y guion) en paginas
     * horizontales y las demas secciones en verticales, aunque traigan tablas de siete u ocho columnas (6.1 y 7.1). Sin esto,
     * cada tabla ancha abria su propia pagina horizontal entre paginas verticales casi vacias. Los anexos no se tocan: sus
     * tablas siguen la regla general de documento_final. Funcion pura.
     *
     * @param string $html Secciones 1 a 7.
     * @return string
     */
    public static function orientation_hints(string $html): string {
        if (stripos($html, '<table') === false) {
            return $html;
        }
        [$dom, $body] = self::dom($html);
        if (!$body) {
            return $html;
        }
        $seccion = 0;
        foreach (iterator_to_array($body->childNodes) as $nodo) {
            if (!$nodo instanceof \DOMElement) {
                continue;
            }
            if ($nodo->nodeName === 'h1') {
                $seccion = preg_match('/^secci[oó]n\s+(\d+)/iu', self::text($nodo), $m) ? (int) $m[1] : 0;
                continue;
            }
            $tablas = $nodo->nodeName === 'table' ? [$nodo] : iterator_to_array($nodo->getElementsByTagName('table'));
            foreach ($tablas as $tabla) {
                if ($seccion > 0) {
                    $tabla->setAttribute('data-orientacion', $seccion === 5 ? 'h' : 'v');
                }
            }
        }
        return self::html_of($dom, $body);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Anexos: un anexo por instrumento.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Titulos de un HTML con su nivel, posicion y texto (sin la numeracion inicial «9.1.»).
     *
     * @param string $html
     * @return array[] nivel, inicio, fin (fin de la etiqueta de cierre) y texto.
     */
    private static function headings(string $html): array {
        $salida = [];
        if (preg_match_all('#<h([1-6])\b[^>]*>(.*?)</h\1>#isu', $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $h) {
                $texto = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($h[2][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $salida[] = ['nivel' => (int) $h[1][0], 'inicio' => (int) $h[0][1], 'fin' => (int) $h[0][1] + strlen($h[0][0]),
                    'texto' => trim(preg_replace('/^\d+(?:\.\d+)*[.)]?\s+/u', '', $texto))];
            }
        }
        return $salida;
    }

    /**
     * Si un titulo es el de un instrumento: «Instrumento N…» o un codigo («IN3», «INS-03-R»); con $tipos, tambien el
     * nombre de un tipo de instrumento con al menos dos palabras («Rúbrica analítica…», «Lista de cotejo…»; un subtitulo
     * suelto como «Escala» no cuenta). Funcion pura.
     *
     * @param string $texto Titulo sin numeracion inicial.
     * @param bool $tipos
     * @return bool
     */
    public static function is_instrument_title(string $texto, bool $tipos = false): bool {
        $t = diseno::plain_lower(trim(preg_replace('/^anexo\s+\d+\s*[·:.\-–—]\s*/iu', '', trim($texto))));
        if (preg_match('/^instrumento\s+(?:n\.?\s*[º°o]?\s*)?\d/u', $t) || preg_match('/^(?:ins?|inst)\s*[-.]?\s*\d/u', $t)) {
            return true;
        }
        return $tipos && count(preg_split('/\s+/u', $t)) >= 2 && preg_match('/^(?:rubrica|lista de cotejo|escala|pauta|prueba|'
            . 'cuestionario|guia de observacion|registro anecdotico|ficha de observacion)\b/u', $t) === 1;
    }

    /**
     * Numero de un instrumento en un codigo o titulo («Instrumento 5», «IN5», «INS-05», «Inst. 4a» → «5», «5», «5», «4a»).
     * Funcion pura.
     *
     * @param string $texto
     * @return string|null
     */
    public static function instrument_number(string $texto): ?string {
        $t = diseno::plain_lower($texto);
        if (preg_match('/\binstrumento\s+(?:n\.?\s*[º°o]?\s*)?(\d+)([a-z]?)\b/u', $t, $m)
                || preg_match('/\binst\.?\s*(\d+)([a-z]?)\b/u', $t, $m)
                || preg_match('/\bin(?:s|st)?\s*[-.]?\s*(\d+)([a-z]?)\b/u', $t, $m)) {
            $n = ltrim($m[1], '0');
            return ($n !== '' ? $n : '0') . $m[2];
        }
        return null;
    }

    /**
     * La seccion de instrumentos de una etapa («Instrumentos de evaluación ajustados…», «Instrumentos evaluativos…»): su
     * contenido sin el titulo, hasta el siguiente titulo de su nivel, y sin las secciones de cierre. Si hay varias, la
     * ultima del nivel mas alto. Funcion pura.
     *
     * @param string $html Producto de la etapa (normalizado).
     * @return string '' si la etapa no la trae.
     */
    public static function instruments_section(string $html): string {
        $html = documento_operativo::strip_stage_closures($html);
        $titulos = self::headings($html);
        $cand = null;
        foreach ($titulos as $i => $h) {
            $t = diseno::plain_lower($h['texto']);
            $esSeccion = preg_match('/^instrumentos\b|^instrumentos? (?:de evaluacion|evaluativos?)\b/u', $t)
                && !preg_match('/^instrumento\s+(?:n\.?\s*[º°o]?\s*)?\d/u', $t);
            if ($esSeccion && ($cand === null || $h['nivel'] <= $titulos[$cand]['nivel'])) {
                $cand = $i;
            }
        }
        if ($cand === null) {
            return '';
        }
        $sec = $titulos[$cand];
        $fin = strlen($html);
        for ($j = $cand + 1, $n = count($titulos); $j < $n; $j++) {
            if ($titulos[$j]['nivel'] <= $sec['nivel']) {
                $fin = $titulos[$j]['inicio'];
                break;
            }
        }
        return trim(substr($html, $sec['fin'], $fin - $sec['fin']));
    }

    /**
     * Separa una seccion de instrumentos en un bloque por instrumento: cada titulo de instrumento del nivel del primero
     * abre un bloque que llega hasta el siguiente; lo anterior al primero (presentacion, matriz) no entra. Funcion pura.
     *
     * @param string $html Seccion de instrumentos (instruments_section()) o etapa de instrumentos.
     * @return array[] titulo (sin numeracion), numero (instrument_number()), nivel y contenido (HTML sin el titulo).
     */
    public static function split_instruments(string $html): array {
        $titulos = self::headings($html);
        foreach ([false, true] as $tipos) {
            $nivel = null;
            $abre = [];
            foreach ($titulos as $h) {
                if (!self::is_instrument_title($h['texto'], $tipos)) {
                    continue;
                }
                if ($nivel === null) {
                    $nivel = $h['nivel'];
                }
                if ($h['nivel'] === $nivel) {
                    $abre[] = $h;
                }
            }
            if (!$abre) {
                continue;
            }
            // Reconocidos solo por el tipo, con texto de fondo antes del primero: puede ser parte de un instrumento sin
            // titulo propio, asi que no se separa (la seccion va completa en un anexo).
            if ($tipos && \core_text::strlen(trim(strip_tags(substr($html, 0, $abre[0]['inicio'])))) > 300) {
                return [];
            }
            $bloques = [];
            foreach ($abre as $k => $h) {
                $fin = isset($abre[$k + 1]) ? $abre[$k + 1]['inicio'] : strlen($html);
                $titulo = trim(preg_replace('/^anexo\s+\d+\s*[·:.\-–—]\s*/iu', '', $h['texto']));
                $bloques[] = ['titulo' => $titulo, 'numero' => self::instrument_number($titulo), 'nivel' => $nivel,
                    'contenido' => trim(substr($html, $h['fin'], $fin - $h['fin']))];
            }
            return $bloques;
        }
        return [];
    }

    /**
     * Mueve los titulos de un HTML para que el nivel $desde quede en $hasta, sin subir ninguno por sobre $minimo. Funcion
     * pura.
     *
     * @param string $html
     * @param int $desde
     * @param int $hasta
     * @param int $minimo
     * @return string
     */
    public static function shift_headings(string $html, int $desde, int $hasta, int $minimo = 3): string {
        $delta = $desde - $hasta;
        if ($delta === 0 && $minimo <= 1) {
            return $html;
        }
        return (string) preg_replace_callback('#<(/?)h([1-6])\b#i', static function(array $m) use ($delta, $minimo): string {
            return '<' . $m[1] . 'h' . max($minimo, min(6, (int) $m[2] - $delta));
        }, $html);
    }

    /**
     * Codigos de los instrumentos recuperativos del plan de evaluacion sintetico (tabla 7.1: «Tipo» recuperativo). Funcion
     * pura.
     *
     * @param string $html Documento (secciones 1 a 7).
     * @return array[] codigo e instrumento de cada fila recuperativa.
     */
    public static function recovery_codes(string $html): array {
        if (stripos($html, '<table') === false) {
            return [];
        }
        [, $body] = self::dom($html);
        $t = $body ? self::find_table($body, static function(array $cab): bool {
            return self::col($cab, 'codigo') !== null && self::col($cab, 'tipo') !== null && self::col($cab, 'instrumento') !== null;
        }) : null;
        if (!$t) {
            return [];
        }
        [, $filas, $cab] = $t;
        $cc = self::col($cab, 'codigo');
        $ci = self::col($cab, 'instrumento');
        $ct = self::col($cab, 'tipo');
        $salida = [];
        foreach (array_slice($filas, 1) as $celdas) {
            if (!isset($celdas[$ct]) || strpos(self::key(self::text($celdas[$ct])), 'recuperativ') === false) {
                continue;
            }
            $salida[] = ['codigo' => isset($celdas[$cc]) ? self::text($celdas[$cc]) : '',
                'instrumento' => isset($celdas[$ci]) ? self::text($celdas[$ci]) : ''];
        }
        return $salida;
    }

    /**
     * Instrumentos del diseño del Modulo 2 (su Etapa 6), uno por bloque. Funcion pura.
     *
     * @param string $m2etapa6 Etapa 6 aceptada del diseño (HTML).
     * @return array[] Como split_instruments().
     */
    public static function design_instruments(string $m2etapa6): array {
        $html = diseno::normalize_product($m2etapa6);
        if (trim(strip_tags($html)) === '') {
            return [];
        }
        $seccion = self::instruments_section($html);
        return self::split_instruments($seccion !== '' ? $seccion : documento_operativo::strip_stage_closures($html));
    }

    /**
     * Bloque ANEXOS: un anexo por instrumento de la continuidad (Etapa 6, sin cambios) y, a continuacion, uno por
     * instrumento recuperativo (Etapa 6 del diseño del Modulo 2, sin cambios). Funcion pura.
     *
     * @param string $etapa6 Etapa 6 aceptada de la continuidad (HTML).
     * @param array[] $recuperativos recovery_codes() del documento.
     * @param array[] $diseno design_instruments() del diseño del Modulo 2.
     * @return string HTML desde «ANEXOS · Instrumentos de evaluación».
     */
    public static function annexes_html(string $etapa6, array $recuperativos, array $diseno): string {
        $e = static function(string $t): string {
            return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        };
        $html = '<h1>ANEXOS · Instrumentos de evaluación</h1>';
        $n = 0;
        $seccion = self::instruments_section(diseno::normalize_product($etapa6));
        $bloques = $seccion !== '' ? self::split_instruments($seccion) : [];
        foreach ($bloques as $b) {
            $n++;
            $html .= "\n<h2>" . $e('Anexo ' . $n . ' · ' . $b['titulo']) . '</h2>'
                . self::shift_headings($b['contenido'], $b['nivel'] + 1, 3);
        }
        if (!$bloques && trim(strip_tags($seccion)) !== '') {
            // La Etapa 6 trae los instrumentos sin un titulo por instrumento: van completos en un solo anexo.
            $n++;
            $minimo = 6;
            foreach (self::headings($seccion) as $h) {
                $minimo = min($minimo, $h['nivel']);
            }
            $html .= "\n<h2>" . $e('Anexo ' . $n . ' · Instrumentos de evaluación de la continuidad') . '</h2>'
                . self::shift_headings($seccion, $minimo, 3);
        }
        if ($n === 0) {
            $html .= "\n<p>[REVISAR: la Etapa 6 no trae los instrumentos de evaluación de la continuidad]</p>";
        }
        $usados = [];
        foreach ($recuperativos as $r) {
            $num = self::instrument_number((string) $r['codigo']) ?? self::instrument_number((string) $r['instrumento']);
            $clave = $num ?? diseno::plain_lower((string) $r['codigo']);
            if (isset($usados[$clave])) {
                continue;
            }
            $usados[$clave] = true;
            $bloque = null;
            foreach ($diseno as $b) {
                if ($num !== null && $b['numero'] === $num) {
                    $bloque = $b;
                    break;
                }
            }
            $n++;
            $codigo = trim((string) $r['codigo']) !== '' ? trim((string) $r['codigo']) : trim((string) $r['instrumento']);
            if ($bloque) {
                $html .= "\n<h2>" . $e('Anexo ' . $n . ' · ' . $bloque['titulo'] . ' · recuperativo') . '</h2>'
                    . '<p><em>' . $e(self::NOTA_RECUPERATIVO) . '</em></p>'
                    . self::shift_headings($bloque['contenido'], $bloque['nivel'] + 1, 3);
            } else {
                $html .= "\n<h2>" . $e('Anexo ' . $n . ' · ' . $codigo . ' · recuperativo') . '</h2>'
                    . '<p>[REVISAR: instrumento recuperativo no encontrado en la Etapa 6 del diseño del Módulo 2]</p>';
            }
        }
        return $html;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Ensamblado, prompt y Word.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Ensambla el documento: secciones 1 a 7 del asistente, con lo que fija el codigo, mas los anexos. Funcion pura.
     *
     * @param string $secciones HTML de las secciones 1 a 7 que devolvio el asistente.
     * @param array $c etapa6 (HTML de la continuidad), m2etapa6 (HTML de la Etapa 6 del diseño), datos (general_data()),
     *        aprendizaje (string), criterios (string[] del plan), restante, min4, min6 (int|null) y estados (codigo de
     *        material => estado en el repertorio del Centro de produccion).
     * @return string
     */
    public static function assemble_html(string $secciones, array $c): string {
        $h = documento_operativo::clean_operative_html($secciones);
        $h = self::fill_general_data($h, (array) ($c['datos'] ?? []));
        $h = self::blank_facilitator_tables($h);
        $h = self::enforce_textual_criteria($h, (string) ($c['aprendizaje'] ?? ''), (array) ($c['criterios'] ?? []));
        $h = self::ensure_fixed_texts($h);
        $h = self::time_marks($h, $c['restante'] ?? null, $c['min4'] ?? null, $c['min6'] ?? null);
        $h = documento_operativo::sync_repertoire_status($h, (array) ($c['estados'] ?? []));
        $h = self::orientation_hints($h);
        $anexos = self::annexes_html((string) ($c['etapa6'] ?? ''), self::recovery_codes($h),
            self::design_instruments((string) ($c['m2etapa6'] ?? '')));
        return $h . "\n" . $anexos;
    }

    /**
     * Una etapa como texto para el modelo: sin las secciones de cierre, con la marca de nivel de cada titulo y las celdas
     * separadas por «|». Funcion pura.
     *
     * @param string $html
     * @param int $tope Caracteres.
     * @return string
     */
    public static function stage_text(string $html, int $tope): string {
        $limpia = documento_operativo::strip_stage_closures(diseno::normalize_product($html));
        $limpia = (string) preg_replace_callback('#<h([1-6])\b[^>]*>#i', static function(array $m): string {
            return "\n" . str_repeat('#', (int) $m[1]) . ' ';
        }, $limpia);
        $texto = trim(preg_replace('/\n{3,}/', "\n\n", html_entity_decode(strip_tags(
            preg_replace('#</?(?:p|div|li|tr|br|h[1-6]|table|ul|ol)\b[^>]*>#i', "\n",
                preg_replace('#</?(?:td|th)\b[^>]*>#i', ' | ', $limpia))
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return \core_text::substr($texto, 0, $tope);
    }

    /**
     * Datos calculados por la plataforma para la seccion 2.1, en texto. Funcion pura.
     *
     * @param array $analisis calidad::analysis().
     * @param string[] $criterios Criterios del plan formativo, en orden.
     * @return string
     */
    public static function starting_point_text(array $analisis, array $criterios): string {
        $l = [];
        foreach ((array) ($analisis['criterios'] ?? []) as $c) {
            $l[] = '- Criterio ' . $c['n'] . ' «' . $c['criterio'] . '»: logro inicial ' . implementacion::pct((float) $c['logro'])
                . '; criticidad ' . ($c['criticidad'] === null ? 'sin brecha (logro desde 90 %)' : $c['criticidad'])
                . '; requieren nivelación (Insuficiente + Básico): ' . (int) $c['nivelacion']
                . '; pueden profundizar (Adecuado + Avanzado): ' . (int) $c['profundizacion'] . '.';
        }
        $pend = [];
        foreach ((array) ($analisis['sin_ficha'] ?? []) as $pos) {
            $pend[] = 'criterio ' . (int) $pos . (isset($criterios[(int) $pos - 1]) ? ' «' . $criterios[(int) $pos - 1] . '»' : '');
        }
        $l[] = '- Pendientes (criterios del plan formativo no trabajados en la experiencia inicial): '
            . ($pend ? implode('; ', $pend) . '.' : 'ninguno (revisa en la Etapa 2 si hay evaluaciones no aplicadas).');
        return implode("\n", $l);
    }

    /**
     * Prompt para extraer las secciones 1 a 7. Los anexos los agrega la plataforma. Funcion pura.
     *
     * @param array $c datos (general_data()), ficha (implementacion::clean()), aprendizaje, criterios (string[] del plan),
     *        analisis (calidad::analysis()), ajuste (AJUSTE_*), fecha (del informe consolidado), version (int), min4, min6
     *        (int|null), estados (codigo => estado en el repertorio), instrumentos_m2 (titulos de los instrumentos del diseño),
     *        etapas (2, 4 y 6 de la continuidad, HTML) y m2 (4 y 5 del diseño, HTML).
     * @return string
     */
    public static function build_prompt(array $c): string {
        $t = (array) (($c['analisis'] ?? [])['tiempos'] ?? []);
        $restante = $t['restante'] ?? null;
        $ficha = (array) ($c['ficha'] ?? []);
        $datos = (array) ($c['datos'] ?? []);
        $l = [];
        $l[] = 'Eres el ' . implementacion::ROL . '. Tu tarea es extraer el DOCUMENTO OPERATIVO DE LA EXPERIENCIA DE APRENDIZAJE '
            . 'DE CONTINUIDAD (secciones 1 a 7): la versión de trabajo que la persona facilitadora usa para conducir la '
            . 'experiencia de continuidad en el aula o el taller. Reúne solo lo necesario para implementarla y evaluarla: datos '
            . 'generales, punto de partida (brechas y pendientes), aprendizaje esperado y criterios, organización de las '
            . 'trayectorias, secuencia instruccional, materiales y plan de evaluación sintético.';
        $l[] = '';
        $l[] = 'REGLAS ESTRICTAS DE EXTRACCIÓN:';
        $l[] = '- Es una extracción del informe consolidado, principalmente de la Etapa 6: NO agregues contenidos, actividades ni '
            . 'materiales, y NO cambies tiempos, criterios, trayectorias, códigos, puntajes, umbrales ni ponderaciones.';
        $l[] = '- Transcribe TEXTUALMENTE, palabra por palabra, el aprendizaje esperado y cada criterio de evaluación de la '
            . 'sección 3, tal como figuran en el plan formativo (abajo).';
        $l[] = '- Condensa la redacción en frases breves y accionables. OMITE la validación de la ficha, el análisis, la tabla de '
            . 'decisión, la mejora del diseño, los registros de vacíos, las verificaciones, las referencias y cualquier nota '
            . '«Origen: …».';
        $l[] = '- La suma de los tiempos de la secuencia no puede superar el tiempo restante registrado en la Etapa 0'
            . ($restante !== null ? ' (' . max(0, (int) $restante) . ' minutos)' : '') . '. Si al extraer detectas una diferencia '
            . 'entre etapas (por ejemplo, entre la distribución del tiempo restante de la Etapa 4 y la secuencia de la Etapa 6), '
            . 'NO la resuelvas: usa el valor de la Etapa 6 y agrega la marca [REVISAR: diferencia entre etapas — detalle breve] en '
            . 'el lugar exacto.';
        $l[] = '- Deja EN BLANCO lo que completa la persona facilitadora: «Fechas y horario» (sección 1), las filas de la tabla '
            . '4.2, las columnas «Fecha», «Horario», «Fases o bloques» y «Observaciones» de la tabla 5.2, y usa la casilla «☐» en '
            . '«Listo» (6.1) y «Verificado» (6.2).';
        $l[] = '- Si un dato que pide una tabla no está en el informe, no lo inventes: escribe la marca [REVISAR: motivo breve] '
            . 'en esa celda.';
        $l[] = '- NO incluyas los anexos con los instrumentos: la plataforma agrega a continuación los instrumentos completos de '
            . 'la Etapa 6 y, si corresponde, los del diseño del Módulo 2 que se aplican en forma recuperativa. Termina con la nota '
            . 'de uso de la sección 7.3.';
        $l[] = '- Extensión orientativa de las secciones 1 a 7: entre 6 y 10 páginas, según el número de bloques y trayectorias.';
        $l[] = diseno::REGLA_FORMULAS;
        $l[] = implementacion::NO_PEDAGOGIA;
        $l[] = '- Usa terminología de formación por competencias, «experiencia de aprendizaje» (nunca «sesión»), lenguaje '
            . 'inclusivo con perspectiva de género y español de Chile. No escribas nombres de personas participantes.';
        $l[] = '';
        $l[] = 'ESTRUCTURA OBLIGATORIA EN MARKDOWN (usa exactamente estos títulos y columnas de tabla; no agregues portada ni '
            . 'encabezado antes de «# SECCIÓN 1», porque la plataforma genera la cabecera institucional):';
        $l[] = '';
        $l[] = '# SECCIÓN 1 · Datos generales';
        $l[] = 'Tabla `| Campo | Información |` con exactamente estas 10 filas, en este orden:';
        $l[] = '1. `Organismo ejecutor`';
        $l[] = '2. `Plan formativo y módulo` («[nombre y código del plan] · [n.º, nombre y código del módulo]»)';
        $l[] = '3. `Experiencia inicial` («[nombre de la experiencia implementada] · [fechas de implementación]»)';
        $l[] = '4. `Experiencia de continuidad` (nombre de la experiencia de continuidad, de la Etapa 6)';
        $l[] = '5. `Tipo de ajuste decidido`';
        $l[] = '6. `Duración` («X minutos, de un tiempo restante de Y minutos (total disponible − tiempo utilizado)», con X = '
            . 'suma de la secuencia de la Etapa 6)';
        $l[] = '7. `Participantes` («N.º total · nivelación: n · profundización: n · evaluación recuperativa: n», esta última solo '
            . 'si corresponde)';
        $l[] = '8. `Modalidad y espacios` (aula, taller, patio, laboratorio u otros espacios)';
        $l[] = '9. `Conducción` (n.º de personas facilitadoras y personas de apoyo, y su función)';
        $l[] = '10. `Fechas y horario` (celda vacía)';
        $l[] = '';
        $l[] = '# SECCIÓN 2 · Punto de partida';
        $l[] = '## 2.1 Brechas y pendientes que atiende la continuidad';
        $l[] = 'Tabla `| Criterio de evaluación | Logro inicial | Criticidad | Nivelación | Profundiz. | Acción de la continuidad |` '
            . 'con una fila por cada criterio del diseño, también los que no presentan brecha (en ellos, criticidad «Sin brecha»): '
            . 'n.º y texto breve del criterio; logro inicial, criticidad y personas que requieren nivelación o pueden profundizar, '
            . 'tal como vienen en los DATOS CALCULADOS (no los recalcules); en la última columna, la acción de la Etapa 4 y el '
            . 'bloque de la Etapa 6 en que se trabaja. Agrega una fila final `Pendientes` con «—» en logro, nivelación y '
            . 'profundización, su criticidad (Alta o Media) y, en la última columna, los criterios no trabajados o las '
            . 'evaluaciones no aplicadas y cómo se abordan (si no hay, «Sin pendientes»).';
        $l[] = '';
        $l[] = '# SECCIÓN 3 · Aprendizaje esperado y criterios de evaluación';
        $l[] = '1. Tabla de una columna `| Aprendizaje esperado |` con la transcripción textual exacta.';
        $l[] = '2. Tabla `| Criterio de evaluación | Se trabaja en | Trayectoria | Se evalúa con |`: una fila por cada criterio de '
            . 'la continuidad («n.º y texto del criterio», textual), la fase o bloque en que se trabaja, la trayectoria '
            . '(Nivelación, Profundización o Ambas) y el código o nombre del instrumento con que se evalúa.';
        $l[] = '';
        $l[] = '# SECCIÓN 4 · Organización de las trayectorias';
        $l[] = '## 4.1 Trayectorias';
        $l[] = 'Tabla `| Trayectoria | Destinatarios | Foco | N.º |` con las filas `Nivelación` (personas en nivel Insuficiente o '
            . 'Básico en los criterios con brecha; foco: alcanzar al menos el nivel Adecuado), `Profundización` (personas en nivel '
            . 'Adecuado o Avanzado; foco: mayor complejidad, transferencia o tutoría de pares) y, solo si corresponde, `Evaluación '
            . 'recuperativa` (personas ausentes o con evaluaciones no aplicadas; foco: los instrumentos que rinden).';
        $l[] = '## 4.2 Asignación de participantes (a completar por la persona facilitadora)';
        $l[] = 'Tabla `| Participante | Trayectoria | Criterios por nivelar o rol | Dupla o tutor/a |` con 4 filas vacías.';
        $l[] = '';
        $l[] = '# SECCIÓN 5 · Secuencia instruccional';
        $l[] = '## 5.1 Vista general';
        $l[] = 'Tabla `| Fase o bloque | Minutos | Nivelación | Profundización | Recuperativa | Criterios y evidencias |` con una fila '
            . 'por fase o bloque de la secuencia de la Etapa 6, en su orden («Inicio: nombre», «Desarrollo: bloque A …», «Cierre: '
            . 'nombre»), la actividad de cada trayectoria («—» en «Recuperativa» si no corresponde) y los criterios y evidencias, y '
            . 'una fila final `Total` con la suma de los minutos y, en la columna «Nivelación», «Tiempo restante: Y minutos».';
        $l[] = '## 5.2 Distribución por jornada (a completar por la persona facilitadora)';
        $l[] = 'Tabla `| Jornada | Fecha | Horario | Fases o bloques | Observaciones (espacio, apoyo, recuperativos) |` con 2 filas '
            . '(`Jornada 1` y `Jornada 2`) y las demás celdas vacías.';
        $l[] = '## 5.3 Guion de conducción por bloque';
        $l[] = 'Un bloque por fase o bloque de la secuencia, en su orden. Para cada fase usa el título `### [Fase]: [nombre] · '
            . '[minutos] minutos · Criterios [n.º]` y para cada bloque `#### Bloque [letra o n.º]: [nombre] · [minutos] minutos · '
            . 'Lugar: [...] · Acción que atiende: [n.º y criticidad]`, seguido de la tabla `| Tarea | Min | Persona facilitadora | '
            . 'Nivelación | Profundización | Materiales y recursos |` (las tareas mantienen los minutos del informe y su suma '
            . 'coincide con el tiempo del bloque; cuando las trayectorias trabajan en paralelo, cada columna describe lo que hace '
            . 'cada una en ese tramo; materiales por su código y recursos físicos) y una tabla breve `| Aspecto | Detalle |` con '
            . '`Evaluación recuperativa` (instrumento, tiempo y lugar, solo si en ese bloque rinden personas ausentes), `Evidencia '
            . 'parcial` (producto o desempeño que se registra, por trayectoria) y `Seguridad` (solo si corresponde).';
        $l[] = 'Redacción del guion (es la sección más larga; cuida la extensión total): en la columna `Tarea` va solo el código '
            . 'de la tarea ([n.º del bloque].[n.º de la tarea], p. ej. `1.1`); cada celda de acción es una frase breve (máximo 15 '
            . 'palabras, verbo en presente, sin explicar el porqué); `Materiales y recursos` lleva solo códigos y nombres cortos. '
            . 'No omitas tareas ni cambies minutos para acortar: acorta la redacción.';
        $l[] = '';
        $l[] = '# SECCIÓN 6 · Materiales y recursos';
        $l[] = '## 6.1 Materiales instruccionales';
        $l[] = 'Tabla `| Código | Material | Categoría | Origen y versión | Bloque y trayectoria | Estado | Listo |`, una fila por '
            . 'material de apoyo de la Etapa 6. Categorías: Reutilizado (material del diseño del Módulo 2 sin cambios: conserva su '
            . 'código del diseño, p. ej. M1), Ajustado (material del diseño con cambios: código del diseño seguido de «-C», p. ej. '
            . 'M3-C) y Nuevo (sin equivalente en el diseño: MC1, MC2… en el orden en que aparece). Si la Etapa 6 trae códigos y '
            . 'categorías, úsalos tal cual; si no, asígnalos comparando con los materiales del diseño (Etapa 4 del Módulo 2, abajo) '
            . 'solo cuando la correspondencia sea evidente, y si no lo es agrega [REVISAR: categoría no indicada en la Etapa 6]. '
            . '«Origen y versión»: código y versión del material del diseño (p. ej. «M7 v1») o «—» si es nuevo. «Estado»: el del '
            . 'repertorio informado abajo para los reutilizados y «Pendiente» para ajustados y nuevos (la plataforma lo actualiza '
            . 'al descargar). «Listo»: «☐». Los instrumentos de evaluación no son materiales: no van en esta tabla.';
        $l[] = '## 6.2 Recursos del entorno formativo (organismo ejecutor)';
        $l[] = 'Tabla `| Recurso | Cantidad | Bloque | Verificado |` con los recursos físicos (espacios, equipos, herramientas, '
            . 'insumos o EPP) de la Etapa 6 y «☐» en `Verificado`.';
        $l[] = '';
        $l[] = '# SECCIÓN 7 · Plan de evaluación sintético';
        $l[] = '## 7.1 Instrumentos';
        $l[] = 'Tabla `| Código | Instrumento | Tipo | Criterios | Aplica a | Momento | Puntaje | Umbral |` con los instrumentos del '
            . 'plan de evaluación de la Etapa 6, en el orden de su registro. «Tipo»: Sumativo ajustado, Formativo, Recuperativo u '
            . 'otro que indique la Etapa 6. Un instrumento del diseño del Módulo 2 que se aplica sin cambios a personas ausentes es '
            . '«Recuperativo»: en su «Código» escribe el código o el número que tiene en el diseño (p. ej. «Instrumento 5» o «IN5», '
            . 'según la lista de instrumentos del diseño de abajo), porque la plataforma lo adjunta desde el diseño.';
        $l[] = '## 7.2 Regla de aprobación y registro del logro final';
        $l[] = 'Tabla de una columna `| Condiciones |` con 3 filas: (1) la regla de calificación de la continuidad (cálculo '
            . 'ponderado, umbrales por instrumento y condiciones críticas, de la Etapa 6); (2) textual: «'
            . self::FILA_RECUPERATIVOS . '»; (3) textual: «' . self::FILA_NO_REGRESION . '».';
        $l[] = '## 7.3 Equivalencia con los niveles de la ficha ex post';
        $l[] = 'Tabla `| Nivel | [código del instrumento ajustado 1] | [código 2] | … | Condición |` (solo los instrumentos '
            . 'ajustados de la continuidad que asignan nivel) con exactamente cuatro filas en este orden: `Avanzado`, `Adecuado`, '
            . '`Básico` e `Insuficiente`, con los rangos de puntaje y la condición (porcentaje o condición crítica) de la regla de '
            . 'equivalencia de cada instrumento en la Etapa 6. Si la Etapa 6 no define la equivalencia de un instrumento, escribe '
            . '[REVISAR: equivalencia no definida] en sus celdas. Cierra con esta línea textual:';
        $l[] = '*' . self::NOTA_FASE4 . '*';
        $l[] = '';
        $l[] = 'DATOS EXACTOS DEL INFORME CONSOLIDADO (REFERENCIA TEXTUAL):';
        foreach (['Organismo ejecutor', 'Plan formativo y módulo', 'Experiencia inicial', 'Tipo de ajuste decidido'] as $k) {
            if (($datos[$k] ?? null) !== null) {
                $l[] = '- ' . $k . ': ' . $datos[$k];
            }
        }
        $l[] = '- Participantes de la experiencia inicial: inscritos ' . (trim((string) ($ficha['inscritos'] ?? '')) ?: '—')
            . '; presentes ' . (trim((string) ($ficha['presentes'] ?? '')) ?: '—') . '.';
        $l[] = '- Tiempos (Etapa 0): total disponible ' . ($t['total'] ?? '—') . ' min; efectivamente utilizado '
            . ($t['utilizado'] ?? '—') . ' min; TIEMPO RESTANTE (total − utilizado) = '
            . ($restante !== null ? (int) $restante : '—') . ' min.';
        if (($c['min6'] ?? null) !== null) {
            $l[] = '- Suma de la secuencia de la Etapa 6 (leída por la plataforma): ' . (int) $c['min6'] . ' min.';
        }
        if (($c['min4'] ?? null) !== null) {
            $l[] = '- Total de la distribución del tiempo restante de la Etapa 4: ' . (int) $c['min4'] . ' min.';
        }
        $l[] = '- Informe consolidado: ' . (string) ($c['fecha'] ?? '') . ' · versión ' . max(1, (int) ($c['version'] ?? 1))
            . ' del documento operativo.';
        $l[] = '- Aprendizaje esperado (transcribir textualmente): ' . trim((string) ($c['aprendizaje'] ?? ''));
        $criterios = array_values((array) ($c['criterios'] ?? []));
        if ($criterios) {
            $l[] = '- Criterios de evaluación del plan formativo (transcribir textualmente; el número es su posición en el plan):';
            foreach ($criterios as $i => $crit) {
                $l[] = '  ' . ($i + 1) . '. ' . $crit;
            }
        }
        $l[] = '';
        $l[] = 'DATOS CALCULADOS POR LA PLATAFORMA PARA LA SECCIÓN 2.1 (cálculos exactos de la ficha; úsalos tal cual):';
        $l[] = self::starting_point_text((array) ($c['analisis'] ?? []), $criterios);
        $l[] = '';
        $estados = (array) ($c['estados'] ?? []);
        if ($estados) {
            $l[] = 'ESTADO DE LOS MATERIALES DEL DISEÑO EN EL REPERTORIO DEL CENTRO DE PRODUCCIÓN (Módulo 2; para la columna '
                . '«Estado» de los materiales reutilizados en la tabla 6.1, con estos mismos códigos):';
            foreach ($estados as $cod => $est) {
                $l[] = '- ' . $cod . ': ' . $est;
            }
        } else {
            $l[] = 'ESTADO DE LOS MATERIALES EN EL REPERTORIO: el diseño no usó el Centro de producción; indica «Pendiente» en '
                . 'todos los materiales de la tabla 6.1.';
        }
        $l[] = '';
        $inst = (array) ($c['instrumentos_m2'] ?? []);
        $l[] = 'INSTRUMENTOS DEL DISEÑO DEL MÓDULO 2 (para identificar en la tabla 7.1 los que se aplican en forma recuperativa):';
        $l[] = $inst ? '- ' . implode("\n- ", $inst) : '- (la Etapa 6 del diseño no trae títulos de instrumentos reconocibles)';
        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FORMATO DE RESPUESTA: únicamente el Markdown de las secciones 1 a 7 (empezando con «# SECCIÓN 1 · Datos '
            . 'generales» y terminando con la nota de uso en la Fase 4 de la sección 7.3), sin texto antes ni después.';
        $etapas = (array) ($c['etapas'] ?? []);
        foreach ([6 => 'Diseño de la experiencia de aprendizaje de continuidad (FUENTE PRINCIPAL)',
                2 => 'Identificación de brechas y pendientes', 4 => 'Acciones remediales o plan de rediseño'] as $n => $titulo) {
            if (trim(strip_tags((string) ($etapas[$n] ?? ''))) === '') {
                continue;
            }
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' DEL INFORME CONSOLIDADO (' . $titulo . '):';
            $l[] = guardrails::isolate_user_content(self::stage_text((string) $etapas[$n],
                $n === 6 ? self::ETAPA6_MAX : self::PREVIA_MAX));
        }
        $m2 = (array) ($c['m2'] ?? []);
        foreach ([4 => 'Materiales instruccionales: definición y lista de producción, para la tabla 6.1',
                5 => 'Plan de evaluación: registro de instrumentos, para los instrumentos recuperativos'] as $n => $titulo) {
            if (trim(strip_tags((string) ($m2[$n] ?? ''))) === '') {
                continue;
            }
            $l[] = '';
            $l[] = 'DISEÑO VALIDADO DEL MÓDULO 2, ETAPA ' . $n . ' (' . $titulo . '; solo de referencia):';
            $l[] = guardrails::isolate_user_content(self::stage_text((string) $m2[$n], self::PREVIA_MAX));
        }
        return implode("\n", $l);
    }

    /**
     * Cabecera y encabezado del Word para documento_final::build() (estilo operativo). Funcion pura.
     *
     * @param array $datos general_data().
     * @param string $titulo Nombre de la experiencia de continuidad.
     * @param string|null $ajuste Tipo de ajuste vigente.
     * @param string $fecha Fecha del informe consolidado.
     * @param int $version
     * @param int|null $restante Tiempo restante.
     * @param int|null $minutos Duracion de la continuidad (fila «Total» de 5.1).
     * @return array
     */
    public static function word_meta(array $datos, string $titulo, ?string $ajuste, string $fecha, int $version,
            ?int $restante, ?int $minutos): array {
        $version = max(1, $version);
        $nombreAjuste = $ajuste !== null ? calidad::adjustment_name($ajuste) : '';
        $subtitulo = 'Documento de trabajo para la conducción en aula o taller'
            . ($nombreAjuste !== '' ? ' · Tipo de ajuste: ' . \core_text::strtolower($nombreAjuste) : '')
            . ($fecha !== '' ? ' · Informe consolidado: ' . $fecha : '') . ' · versión ' . $version;
        $items = [];
        $org = trim((string) ($datos['Organismo ejecutor'] ?? ''));
        if ($org !== '') {
            $items[] = ['etiqueta' => 'Organismo ejecutor', 'valor' => $org];
        }
        if ($minutos !== null) {
            $items[] = ['etiqueta' => 'Duración', 'valor' => $minutos . ($restante !== null ? ' de ' . max(0, $restante) : '') . ' min'];
        } else if ($restante !== null) {
            $items[] = ['etiqueta' => 'Tiempo restante', 'valor' => max(0, $restante) . ' min'];
        }
        if ($nombreAjuste !== '') {
            $items[] = ['etiqueta' => 'Tipo de ajuste', 'valor' => $nombreAjuste];
        }
        $items[] = ['etiqueta' => 'Versión', 'valor' => 'v' . $version . ($fecha !== '' ? ' · ' . $fecha : '')];
        return [
            'estilo' => 'operativo',
            'portada' => false,
            'etiqueta' => self::ETIQUETA,
            'titulo' => $titulo !== '' ? $titulo : 'Experiencia de aprendizaje de continuidad',
            'subtitulo' => $subtitulo,
            'meta_items' => $items,
            'encabezado' => self::ENCABEZADO,
        ];
    }

    /**
     * Arma el Word (.docx) editable. Funcion pura.
     *
     * @param string $ruta
     * @param array $meta word_meta().
     * @param string $html Documento (ya sincronizado con el repertorio).
     * @param string $logo
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
    // Base de datos y servicio de IA.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Pide la generacion (queda pendiente y la pagina de la Etapa 8 la ejecuta). Conserva rondas: la generacion siguiente
     * sube la version.
     *
     * @param int $implid Ficha de registro de la implementacion.
     * @param int $userid
     * @param int $courseid
     * @param int $validacionid Validacion vigente de la Fase 1.
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
     * Al confirmar la consolidacion: si las fuentes del documento no cambiaron, el documento vigente sigue valiendo (misma
     * version, nueva huella); si cambiaron o no hay documento, se pide una version nueva.
     *
     * @param int $userid
     * @param int $courseid
     * @param \stdClass $fase1 Validacion vigente de la ficha (implementacion::fase1_ok()).
     * @return void
     */
    public static function after_confirm(int $userid, int $courseid, \stdClass $fase1): void {
        global $DB;
        try {
            $ficha = implementacion::get_ficha($userid, $courseid);
            if (!$ficha) {
                return;
            }
            $filas = calidad::get_by_impl((int) $ficha->id);
            $c8 = $filas[calidad::CONSOLIDACION] ?? null;
            if (!$c8 || $c8->estado !== calidad::ACEPTADO) {
                return;
            }
            $diseno = (int) $ficha->disenoid > 0 ? implementacion::get_design((int) $ficha->disenoid, $userid) : null;
            $fuente = self::source_fingerprint((string) $fase1->huella, $filas, calidad::decided_adjustment($filas),
                (string) ($diseno['huella'] ?? ''));
            $op = $filas[self::ETAPA] ?? null;
            if (self::same_source($op, $fuente)) {
                $DB->update_record('local_chatbot_calidad', (object) ['id' => $op->id, 'huella' => (string) $c8->huella,
                    'timemodified' => time()]);
                return;
            }
            if (ai\provider_factory::is_configured()) {
                self::queue((int) $ficha->id, $userid, $courseid, (int) $fase1->id);
            }
        } catch (\Throwable $e) {
            debugging('local_chatbot documento_continuidad after_confirm: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Genera el documento (lo llama calidad::generate() para la fila etapa = self::ETAPA).
     *
     * @param \stdClass $fila Fila del documento.
     * @param \stdClass $val Validacion de la ficha en que se basa.
     * @param array $data Ficha validada (implementacion::clean()).
     * @param array $diseno implementacion::get_design() del diseño elegido.
     * @param \stdClass[] $filas Etapas de la evaluacion.
     * @return array [estado de la generacion, campos a guardar].
     */
    public static function generate_row(\stdClass $fila, \stdClass $val, array $data, array $diseno, array $filas): array {
        $c8 = $filas[calidad::CONSOLIDACION] ?? null;
        if (!$c8 || $c8->estado !== calidad::ACEPTADO
                || (string) $c8->huella !== calidad::stages_fingerprint($filas, (string) $val->huella)) {
            return [implementacion::ERROR, ['error' => 'sinconsolidacion']];
        }
        $etapas = [];
        foreach ([2, 4, 6] as $n) {
            if (empty($filas[$n]) || $filas[$n]->estado !== calidad::ACEPTADO || trim((string) $filas[$n]->contenido) === '') {
                return [implementacion::ERROR, ['error' => 'sinetapas']];
            }
            $etapas[$n] = (string) $filas[$n]->contenido;
        }
        $ajuste = calidad::decided_adjustment($filas);
        if ($ajuste === null) {
            return [implementacion::ERROR, ['error' => 'sinajuste']];
        }
        $m2 = [];
        foreach ([4, 5, 6] as $n) {
            $f = $diseno['filas'][$n] ?? null;
            $m2[$n] = ($f && $f->estado === diseno::ACEPTADO) ? (string) $f->contenido : '';
        }
        $ref = (array) ($diseno['referencia'] ?? []);
        $criterios = array_values((array) ($ref['criterios'] ?? []));
        $aprendizaje = trim((string) ($ref['aprendizaje'] ?? '')) !== '' ? trim((string) $ref['aprendizaje'])
            : trim((string) ($data['aprendizaje'] ?? ''));
        $analisis = calidad::analysis($data, $ref);
        $restante = $analisis['tiempos']['restante'];
        $estados = array_merge(documento_operativo::repertoire_status_map(centro_recursos::get_resources((int) $val->disenoid)),
            produccion_m3::status_map(centro_recursos::get_resources(produccion_m3::plan_key((int) $fila->implid))));
        $datos = self::general_data($data, (array) ($diseno['ficha']->data ?? []), $ajuste);
        $version = max(1, (int) ($fila->rondas ?? 0) + 1);
        $fecha = userdate((int) $c8->timeaccepted, get_string('strftimedate', 'langconfig'));
        $tabla4 = calidad::time_table($etapas[4]);
        $min4 = $tabla4 ? ($tabla4['total_declarado'] ?? $tabla4['suma']) : null;
        $min6 = calidad::sequence_minutes($etapas[6]);
        $instrumentos = self::design_instruments($m2[6]);
        $prompt = pseudonymizer::scrub(self::build_prompt(['datos' => $datos, 'ficha' => $data, 'aprendizaje' => $aprendizaje,
            'criterios' => $criterios, 'analisis' => $analisis, 'ajuste' => $ajuste, 'fecha' => $fecha, 'version' => $version,
            'min4' => $min4, 'min6' => $min6, 'estados' => $estados,
            'instrumentos_m2' => array_column($instrumentos, 'titulo'), 'etapas' => $etapas, 'm2' => $m2]));
        $contenido = [['role' => 'user', 'parts' => [['text' => $prompt]]]];
        $modelo = ai\provider_factory::get_configured_model();
        $resultado = exante::generate_steady($contenido, self::ESPERA, $modelo, false);
        if (isset($resultado['error'])) {
            return [implementacion::ERROR, ['error' => 'servicio']];
        }
        $markdown = diseno::clean_markdown(gemini_client::extract_text($resultado));
        if ($markdown === '') {
            return [implementacion::ERROR, ['error' => 'ilegible']];
        }
        $html = self::assemble_html(diseno::product_html($markdown), ['etapa6' => $etapas[6], 'm2etapa6' => $m2[6],
            'datos' => $datos, 'aprendizaje' => $aprendizaje, 'criterios' => $criterios, 'restante' => $restante,
            'min4' => $min4, 'min6' => $min6, 'estados' => $estados]);
        $fuente = self::source_fingerprint((string) $val->huella, $filas, $ajuste, (string) ($diseno['huella'] ?? ''));
        return [implementacion::LISTO, [
            'contenido' => $html,
            'estado' => calidad::ACEPTADO,
            'huella' => (string) $c8->huella,
            'rondas' => $version,
            'modelo' => (string) $modelo,
            'promptversion' => self::PROMPT_VERSION,
            'informe' => json_encode(['fuente' => $fuente], JSON_UNESCAPED_UNICODE),
            'timeaccepted' => time(),
            'error' => null,
        ]];
    }
}

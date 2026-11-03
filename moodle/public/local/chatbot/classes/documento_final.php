<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Documento final validado del Modulo 2 en Word, con el diseño del ejemplo «Diseño Final Validado - Ejemplo - Interfases»
 * (pedido del cliente del 02-11-2026): portada con el logo, tabla de contenidos, encabezado con el logo y pie «Página X de Y»,
 * cada etapa en una pagina nueva con su titulo («ETAPA 1  Análisis diagnóstico»), titulos numerados en azul, vinetas
 * azules, bandas «SESIÓN N», tablas con encabezado azul oscuro, primera columna sombreada y filas alternadas, y las tablas
 * anchas (siete columnas o mas, o seis con mucho texto) en paginas horizontales.
 *
 * Arma el .docx a mano (sin librerias) a partir del HTML del producto: el recorrido del HTML y el armado de cada
 * parrafo son funciones de esta clase y no consultan la base; quien la llama pasa el logo y los datos de la portada.
 * El Word de cada etapa suelta sigue en diseno::html_to_docx().
 */
class documento_final {

    /** @var string Colores del diseño (hex sin «#»). */
    const NAVY = '0B2447';
    const AZUL = '2E5A88';
    const TITULO2 = '17365D';
    const TEXTO = '1E2533';
    const BANDA = 'EAEFF6';
    const ZEBRA = 'F7F9FC';
    const BORDE = 'C3CEDD';
    const GRIS = '5B6577';

    /** @var int Ancho util de la pagina vertical y de la horizontal, en twips (margenes de 1300). */
    const ANCHO_V = 9640;
    const ANCHO_H = 13240;

    /** @var int Una tabla con tantas columnas y mucho texto, o con mas columnas, va en una pagina horizontal (is_wide()). */
    const COLUMNAS_ANCHAS = 6;

    /** @var string[] Siglas que se respetan al pasar a minuscula un titulo escrito en mayusculas. */
    const SIGLAS = ['MCTP', 'EPP', 'CE', 'CEs', 'AE', 'PM', 'OTEC', 'UCL', 'SENCE', 'TIC', 'TICs', 'PF', 'ELEAM', 'ABP', 'EDECOMP',
        'DDHH', 'RUT', 'SpA', 'ChileValora', 'MINSAL', 'OIT', 'ONU', 'OMS', 'NCh', 'ISO'];

    /** @var array Secciones ya cerradas: [orientacion, xml]. */
    private $secciones = [];
    private $xml = '';
    private $orient = 'v';
    private $toc = [];
    private $bmid = 0;
    /** @var int Etapa en curso (0 fuera de una etapa), para numerar sus secciones como en el ejemplo («1.1»). */
    private $etapa = 0;
    /** @var string Numero de la seccion en curso dentro de la etapa, tal como venia («3»). */
    private $seccion = '';
    /** @var int Ultimo numero de seccion de la etapa en curso, para numerar las secciones que vienen sin numero. */
    private $ultima = 0;
    /** @var int Niveles que se restan a los titulos (el titulo de la etapa venia como h2 o h3). */
    private $desfase = 0;

    /**
     * Escribe el documento final.
     *
     * @param string $ruta Archivo .docx de salida.
     * @param array $meta etiqueta (sobre el titulo de la portada), titulo, subtitulo, lineas (lista de [rotulo, texto] al
     *        pie de la portada), encabezado (texto del encabezado de cada pagina), portada (false: sin portada ni indice,
     *        para el Word de una etapa).
     * @param string $html Cuerpo: el diseño consolidado y el anexo, en HTML.
     * @param string $logo Ruta del logo (PNG); vacia o ilegible, el documento sale sin logo.
     * @return bool
     */
    public static function build(string $ruta, array $meta, string $html, string $logo = ''): bool {
        if (!class_exists('\ZipArchive')) {
            return false;
        }
        return (new self())->escribir($ruta, $meta, $html, $logo);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Piezas puras (se prueban sin armar el documento).
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Titulo de nivel de etapa: «Etapa 3: Secuencia instruccional», «ETAPA 3 Secuencia...», «PARTE 3: SECUENCIA
     * INSTRUCCIONAL» (el consolidado llama «partes» a las etapas, en el mismo orden) o «Anexo: Evaluación...». El titulo
     * escrito en mayusculas pasa a minuscula normal.
     *
     * @param string $texto
     * @return array|null ['etiqueta' => 'ETAPA 3', 'titulo' => 'Secuencia instruccional', 'indice' => 'Etapa 3: Secuencia
     *         instruccional', 'numero' => 3 (0 en el anexo)] o null si no es una etapa ni un anexo.
     */
    public static function parse_stage_title(string $texto): ?array {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto));
        if (preg_match('/^(?:etapa|parte)\s+(\d+)(?!\d|\.\d)\s*[:.\-–—]?\s*(.*)$/iu', $texto, $m) && trim($m[2]) !== '') {
            $titulo = self::sentence_case(trim($m[2]));
            return ['etiqueta' => 'ETAPA ' . $m[1], 'titulo' => $titulo, 'indice' => 'Etapa ' . $m[1] . ': ' . $titulo,
                'numero' => (int) $m[1]];
        }
        if (preg_match('/^anexo\s*[:.\-–—]?\s*(.*)$/iu', $texto, $m) && trim($m[1]) !== '') {
            $titulo = self::sentence_case(trim($m[1]));
            return ['etiqueta' => 'ANEXO', 'titulo' => $titulo, 'indice' => 'Anexo: ' . $titulo, 'numero' => 0];
        }
        return null;
    }

    /**
     * Un titulo escrito todo en mayusculas («SUB-FASE 2.1: FUNDAMENTACIÓN TÉCNICA (120 MINUTOS)») pasa a minuscula normal
     * con la primera letra en mayuscula, y tambien la primera despues de dos puntos («Sub-fase 2.1: Fundamentación técnica
     * (120 minutos)»). Se respetan las siglas conocidas, los numeros romanos y las palabras que ya venian con minusculas
     * («CEs»). Un titulo que no esta en mayusculas se deja como viene.
     *
     * @param string $texto
     * @return string
     */
    public static function sentence_case(string $texto): string {
        $letras = preg_replace('/[^\p{L}]/u', '', $texto);
        $n = \core_text::strlen($letras);
        if ($n < 6 || preg_match_all('/\p{Lu}/u', $letras) / $n < 0.8) {
            return $texto;
        }
        $siglas = [];
        foreach (self::SIGLAS as $s) {
            $siglas[\core_text::strtoupper($s)] = $s;
        }
        $primera = true;
        return preg_replace_callback('/:|[\p{L}\d]+/u', static function(array $m) use (&$primera, $siglas): string {
            $w = $m[0];
            if ($w === ':') {
                $primera = true;
                return $w;
            }
            if (preg_match('/\d/', $w)) {
                return $w;
            }
            $mayus = \core_text::strtoupper($w);
            if (isset($siglas[$mayus]) || preg_match('/\p{Ll}/u', $w) || preg_match('/^[IVX]{1,5}$/', $w)) {
                $primera = false;
                return $siglas[$mayus] ?? $w;
            }
            $bajo = \core_text::strtolower($w);
            if ($primera) {
                $primera = false;
                return \core_text::strtoupper(\core_text::substr($bajo, 0, 1)) . \core_text::substr($bajo, 1);
            }
            return $bajo;
        }, $texto);
    }

    /**
     * Numero de un titulo tal como se muestra: un numero solo lleva punto («3.»); uno compuesto, no («3.1»).
     *
     * @param string $num
     * @return string
     */
    public static function number_label(string $num): string {
        return preg_match('/^\d+$/', $num) ? $num . '.' : $num;
    }

    /**
     * Separa el rotulo de un «Rótulo: valor» (rotulo de hasta 70 caracteres, o de hasta 140 si no le sigue nada, que no
     * termina en punto ni lleva casilla); sin rotulo, devuelve ['', texto]. Una linea para escribir muy larga se acorta a
     * 40 guiones bajos.
     *
     * @param string $t
     * @return array [rotulo, valor]
     */
    public static function label_split(string $t): array {
        $t = trim($t);
        // Los dos puntos entre digitos son de una hora («09:00»), no separan un rotulo.
        $car = '(?:[^:☐]|(?<=\d):(?=\d))';
        $sep = '(?:(?<!\d):|:(?!\d))';
        if ((preg_match('/^(' . $car . '{2,70}?)' . $sep . '\s*(.*)$/u', $t, $m)
                || preg_match('/^(' . $car . '{2,140})' . $sep . '\s*()$/u', $t, $m))
                && !preg_match('/[.;]\s*$|https?$/iu', $m[1])) {
            return [trim($m[1]), trim(preg_replace('/_{41,}/', str_repeat('_', 40), $m[2]))];
        }
        return ['', $t];
    }

    /**
     * Lineas de un bloque preformateado que no es un dibujo (el encabezado de un instrumento, una pauta de correccion, una
     * hoja de calculo), para mostrarlo como ficha. Tipos: titulo (linea en mayusculas), campo (uno o mas «Rótulo: valor»
     * separados por espacios), vineta, casilla («[  ]», que se muestra ☐), linea (espacio para escribir: solo guiones
     * bajos), espacio (linea en blanco) y texto. Las lineas de separacion (=====, -----) se omiten.
     *
     * @param string $texto
     * @return array Lista de ['tipo' => string, 'partes' => [[rotulo, valor], ...]].
     */
    public static function form_lines(string $texto): array {
        $salida = [];
        $agregar = static function(string $tipo, array $partes) use (&$salida): void {
            $salida[] = ['tipo' => $tipo, 'partes' => $partes];
        };
        foreach (preg_split('/\R/u', $texto) as $l) {
            // Un icono que se perdio al guardar («[?]»: la base no guarda emojis) es una casilla de opcion; en un item de
            // cotejo, que ya trae sus casillas, sobra.
            $l = preg_match_all('/\[\s{0,2}\]/u', $l) >= 2 ? preg_replace('/\[\?+\]\s*/u', '', $l)
                : preg_replace('/\[\?+\]/u', '[ ]', $l);
            $l = trim(preg_replace('/\[\s*\]/u', '☐', $l));
            if ($l === '') {
                if ($salida && $salida[count($salida) - 1]['tipo'] !== 'espacio') {
                    $agregar('espacio', []);
                }
                continue;
            }
            // Iconos entre corchetes («[🚿] Aseo...»): el icono sin corchetes.
            $l = preg_replace('/\[([^\]\p{L}\p{N}\s☐]{1,6})\]/u', '$1', $l);
            if (preg_match('/^_{10,}$/', $l)) {
                $agregar('linea', []);
                continue;
            }
            if (preg_match('/^[=\-~*#_.·]{4,}$/u', $l)) {
                continue;
            }
            // Titulo entre corchetes: «[SECCIÓN 1: RUTINAS DE HIGIENE] (Marque con ✓...)».
            if (preg_match('/^\[([^\]]{4,})\]\s*(.*)$/u', $l, $m) && diagrama::is_upper($m[1])) {
                $agregar('titulo', [['', trim($m[1] . ' ' . $m[2])]]);
                continue;
            }
            // Item de lista de cotejo: «Aseo bucal ........ ☐ Realizado  ☐ No requerido».
            // Solo con la linea de puntos: «Rol: ☐ Pares  ☐ Facilitadora» es un campo con sus casillas.
            if (substr_count($l, '☐') >= 2 && preg_match('/^([^☐]*[\p{L}\p{N}][^☐]*?)\s*[.·…_]{3,}\s*☐(.*)$/u', $l, $m)) {
                $opciones = array_values(array_filter(array_map('trim', explode('☐', '☐' . $m[2])), 'strlen'));
                $agregar('cotejo', array_merge([['', trim($m[1])]], array_map(static function(string $o): array {
                    return ['', $o];
                }, $opciones)));
                continue;
            }
            if (preg_match('/^[-•*▪]\s+(.*)$/u', $l, $m)) {
                // Dos viñetas en una misma linea («- Puntaje: ___   - Logro: ___ %») van por separado.
                foreach (preg_split('/\s{2,}[-•]\s+/u', $m[1]) as $v) {
                    $agregar('vineta', [self::label_split($v)]);
                }
                continue;
            }
            if (strpos($l, '☐') === 0) {
                $agregar('casilla', [self::label_split(trim(\core_text::substr($l, 1)))]);
                continue;
            }
            if (diagrama::is_upper($l) && (strpos($l, ':') === false || preg_match('/:\s*$/u', $l))) {
                $agregar('titulo', [['', trim(rtrim($l, ': '))]]);
                continue;
            }
            $partes = array_map([self::class, 'label_split'], preg_split('/\s{2,}/u', $l));
            if (!array_filter(array_column($partes, 0), 'strlen')) {
                $agregar('texto', [['', $l]]);
            } else if (count($partes) === 1 && preg_match('/^_{40,}$/', $partes[0][1])) {
                // Un rotulo seguido solo de una larga linea para escribir: el rotulo y, debajo, la linea.
                $agregar('campo', [[$partes[0][0], '']]);
                $agregar('linea', []);
            } else {
                $agregar('campo', $partes);
            }
        }
        while ($salida && $salida[count($salida) - 1]['tipo'] === 'espacio') {
            array_pop($salida);
        }
        return $salida;
    }

    /**
     * Separa la numeracion de un titulo: «1.2 Resultado...» => ['1.2', 'Resultado...'].
     *
     * @param string $texto
     * @return array [numero ('' si no tiene), resto].
     */
    public static function split_number(string $texto): array {
        $texto = trim($texto);
        if (preg_match('/^(\d+(?:\.\d+)*)\.?\s+(.+)$/u', $texto, $m)) {
            return [$m[1], $m[2]];
        }
        return ['', $texto];
    }

    /**
     * Banda de sesion: «SESIÓN 2: Demostración...» => ['SESIÓN 2', 'Demostración...'].
     *
     * @param string $texto
     * @return array|null
     */
    public static function parse_session(string $texto): ?array {
        if (preg_match('/^(SESI[ÓO]N\s+\d+)\s*[:.\-–—]?\s*(.*)$/iu', trim($texto), $m)) {
            return [\core_text::strtoupper($m[1]), trim($m[2])];
        }
        return null;
    }

    /**
     * Si un parrafo es una formula de calculo («Porcentaje de logro (%) = (...) × 100»), para mostrarla en un recuadro.
     *
     * @param string $texto
     * @return bool
     */
    public static function is_formula(string $texto): bool {
        $texto = trim($texto);
        return \core_text::strlen($texto) <= 220 && strpos($texto, '=') !== false && preg_match('/[×÷]/u', $texto) === 1;
    }

    /**
     * HTML comun de un bloque preformateado, para que la pagina, el Word de la etapa y el documento final muestren lo
     * mismo: tabla dibujada → tabla; arbol → listas; otro dibujo → lista; ficha → titulos en negrita, campos con el rotulo
     * en negrita, viñetas, casillas ☐, lineas para escribir y los items de cotejo en una tabla con una columna por opcion.
     *
     * @param string $texto Texto del bloque.
     * @return string HTML ('' si el bloque no tiene texto).
     */
    public static function pre_html(string $texto): string {
        $e = static function(string $t): string {
            return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        };
        $tabla = diagrama::art_table_html($texto);
        if ($tabla !== null) {
            return $tabla;
        }
        $arbol = diagrama::tree_items($texto);
        if ($arbol !== null) {
            $html = '';
            $abierta = 0;
            foreach ($arbol as [$nivel, $t]) {
                $nivel = min(2, $nivel);
                if ($nivel === 0) {
                    $html .= str_repeat('</li></ul>', $abierta) . '<p><strong>' . $e($t) . '</strong></p>';
                    $abierta = 0;
                    continue;
                }
                if ($nivel > $abierta) {
                    $html .= str_repeat('<ul><li>', $nivel - $abierta) . $e($t);
                } else {
                    $html .= str_repeat('</li></ul>', $abierta - $nivel) . '</li><li>' . $e($t);
                }
                $abierta = $nivel;
            }
            return $html . str_repeat('</li></ul>', $abierta);
        }
        if (diagrama::has_art($texto)) {
            return '<ul>' . implode('', array_map(static function(string $l) use ($e): string {
                return '<li>' . $e($l) . '</li>';
            }, diagrama::art_lines($texto))) . '</ul>';
        }
        $partes = static function(array $ps) use ($e): string {
            // Lo que sigue a un rotulo sin valor («Estado anímico:  😊 Tranquilo/a  😐 Serio/a») es su valor.
            $x = '';
            $vacio = false;
            foreach ($ps as [$r, $v]) {
                $x .= ($x === '' ? '' : ($vacio ? ' ' : ' &nbsp;&nbsp; '))
                    . ($r !== '' ? '<strong>' . $e($r) . ':</strong>' . ($v !== '' ? ' ' . $e($v) : '') : $e($v));
                $vacio = $r !== '' && $v === '';
            }
            return $x;
        };
        $lineas = self::form_lines($texto);
        $html = '';
        $n = count($lineas);
        for ($i = 0; $i < $n; $i++) {
            $l = $lineas[$i];
            if ($l['tipo'] === 'cotejo') {
                $cols = count($l['partes']);
                $html .= '<table><thead><tr><th>Ítem</th>';
                foreach (array_slice($l['partes'], 1) as [, $o]) {
                    $html .= '<th>' . $e($o) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                for (; $i < $n && $lineas[$i]['tipo'] === 'cotejo' && count($lineas[$i]['partes']) === $cols; $i++) {
                    $html .= '<tr><td>' . $e($lineas[$i]['partes'][0][1]) . '</td>' . str_repeat('<td>☐</td>', $cols - 1) . '</tr>';
                }
                $i--;
                $html .= '</tbody></table>';
            } else if ($l['tipo'] === 'vineta') {
                $html .= '<ul>';
                for (; $i < $n && $lineas[$i]['tipo'] === 'vineta'; $i++) {
                    $html .= '<li>' . $partes($lineas[$i]['partes']) . '</li>';
                }
                $i--;
                $html .= '</ul>';
            } else if ($l['tipo'] === 'titulo') {
                $html .= '<p><strong>' . $e($l['partes'][0][1]) . '</strong></p>';
            } else if ($l['tipo'] === 'casilla') {
                $html .= '<p>☐ ' . $partes($l['partes']) . '</p>';
            } else if ($l['tipo'] === 'linea') {
                $html .= '<p>' . str_repeat('_', 60) . '</p>';
            } else if ($l['tipo'] !== 'espacio') {
                $html .= '<p>' . $partes($l['partes']) . '</p>';
            }
        }
        return $html;
    }

    /**
     * Anchos de columna en twips, proporcionales a los pesos, sin bajar del minimo de cada columna (el de su palabra mas
     * larga, para que no se corten palabras) y sumando exactamente el total.
     *
     * @param float[] $pesos
     * @param int $total
     * @param int|int[] $minimos Un minimo para todas o uno por columna.
     * @return int[]
     */
    public static function column_widths(array $pesos, int $total, $minimos = 700): array {
        $n = count($pesos);
        if ($n === 0) {
            return [];
        }
        $pesos = array_values($pesos);
        $min = [];
        for ($i = 0; $i < $n; $i++) {
            $min[$i] = is_array($minimos) ? (int) ($minimos[$i] ?? 700) : (int) $minimos;
        }
        // Si los minimos no caben, se reducen en proporcion: la tabla nunca pasa del ancho util.
        if (array_sum($min) > $total) {
            $f = $total / array_sum($min);
            $min = array_map(static function($m) use ($f) {
                return (int) floor($m * $f);
            }, $min);
        }
        // Las columnas que quedarian bajo su minimo se fijan en el, y el resto se reparte lo que queda por peso.
        $fijas = [];
        $anchos = array_fill(0, $n, 0);
        for ($vuelta = 0; $vuelta <= $n; $vuelta++) {
            $libre = $total - array_sum(array_intersect_key($min, $fijas));
            $suma = 0;
            foreach ($pesos as $i => $p) {
                $suma += isset($fijas[$i]) ? 0 : ($p ?: 1);
            }
            $cambio = false;
            foreach ($pesos as $i => $p) {
                if (isset($fijas[$i])) {
                    $anchos[$i] = $min[$i];
                    continue;
                }
                $anchos[$i] = (int) floor($libre * ($p ?: 1) / max(1, $suma));
                if ($anchos[$i] < $min[$i]) {
                    $fijas[$i] = true;
                    $cambio = true;
                }
            }
            if (!$cambio) {
                break;
            }
        }
        // Lo que sobra o falta (redondeo) va a la columna mas ancha, para que la tabla mida exactamente el ancho util.
        $dif = $total - array_sum($anchos);
        $mayor = array_search(max($anchos), $anchos, true);
        $anchos[$mayor] += $dif;
        return $anchos;
    }

    /**
     * Si una tabla va en una pagina horizontal: siete columnas o mas, o seis con mucho texto (en una pagina vertical
     * quedarian columnas muy angostas). Una de seis columnas cortas (una lista de cotejo) cabe en vertical.
     *
     * @param int $cols
     * @param float $promedio Caracteres por fila, en promedio.
     * @return bool
     */
    public static function is_wide(int $cols, float $promedio): bool {
        return $cols > self::COLUMNAS_ANCHAS || ($cols === self::COLUMNAS_ANCHAS && $promedio >= 300);
    }

    /**
     * Alto aproximado de una celda en twips (letra de 9 puntos: unos 90 twips por caracter y 230 por linea), para decidir si
     * una fila o una tabla cabe en una pagina sin partirse.
     *
     * @param int $caracteres
     * @param int $ancho Ancho de la celda en twips.
     * @param int $bloques Parrafos y viñetas de la celda (cada uno empieza linea).
     * @return int
     */
    public static function cell_height(int $caracteres, int $ancho, int $bloques = 1): int {
        $porlinea = max(4, (int) floor(max(300, $ancho - 220) / 90));
        return max(1, (int) ceil($caracteres / $porlinea) + max(0, $bloques - 1)) * 230 + 160;
    }

    /**
     * Texto sin caracteres que XML no admite, escapado.
     *
     * @param string $t
     * @return string
     */
    public static function esc(string $t): string {
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $t);
        return htmlspecialchars((string) $t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Armado del documento.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param string $ruta
     * @param array $meta
     * @param string $html
     * @param string $logo
     * @return bool
     */
    private function escribir(string $ruta, array $meta, string $html, string $logo): bool {
        $png = ($logo !== '' && is_readable($logo)) ? (string) file_get_contents($logo) : '';
        $dim = $png !== '' ? @getimagesizefromstring($png) : false;
        $ratio = ($dim && $dim[1] > 0) ? $dim[0] / $dim[1] : 2.8;

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body) {
            $this->bloques($body, 0, []);
        }
        // Lo que quedo despues de la ultima tabla ancha, si no tiene contenido, no abre otra pagina.
        if (!$this->secciones || !$this->sin_contenido($this->xml)) {
            $this->cerrar_seccion();
        }

        // Sin portada (el Word de una etapa): el documento empieza en el titulo, sin indice ni salto de pagina previo.
        $conportada = ($meta['portada'] ?? true) !== false;
        if ($conportada) {
            $this->secciones[0][1] = $this->portada($meta, $png !== '') . $this->indice() . $this->secciones[0][1];
        } else {
            // Si el documento empieza con una tabla ancha, la primera seccion vertical quedo vacia: no abre pagina.
            if (count($this->secciones) > 1 && $this->sin_contenido($this->secciones[0][1])) {
                array_shift($this->secciones);
            }
            $this->secciones[0][1] = preg_replace('/<w:pageBreakBefore\/>/', '', $this->secciones[0][1], 1);
        }

        $cuerpo = '';
        $ultima = count($this->secciones) - 1;
        foreach ($this->secciones as $i => [$orient, $xml]) {
            $sect = $this->sect_pr($orient, $i === 0 && $conportada);
            $cuerpo .= $xml . ($i < $ultima ? '<w:p><w:pPr>' . $sect . '</w:pPr></w:p>' : '');
            if ($i === $ultima) {
                $cuerpo .= $sect;
            }
        }

        $zip = new \ZipArchive();
        if ($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"';
        $cab = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $texto = (string) ($meta['encabezado'] ?? 'Diseño de experiencia de aprendizaje · Diseño final validado');

        $zip->addFromString('[Content_Types].xml', $cab . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
            . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
            . '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
            . '<Override PartName="/word/header2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
            . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', $cab . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>');
        $zip->addFromString('docProps/core.xml', $cab . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::esc((string) ($meta['titulo'] ?? '')) . '</dc:title>'
            . '<dc:creator>Asistente de Diseño Instruccional</dc:creator></cp:coreProperties>');
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
            . '<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
            . '<Relationship Id="rId6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header2.xml"/>'
            . '<Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
            . ($png !== '' ? '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.png"/>' : '');
        $zip->addFromString('word/_rels/document.xml.rels', $cab . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
        $zip->addFromString('word/styles.xml', $this->estilos());
        $zip->addFromString('word/numbering.xml', $this->numeracion());
        $zip->addFromString('word/settings.xml', $cab . '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:zoom w:percent="100"/><w:defaultTabStop w:val="720"/><w:characterSpacingControl w:val="doNotCompress"/>'
            // Los numeros de pagina del indice se calculan al abrir: Word pregunta una vez si actualizar los campos.
            . '<w:updateFields w:val="true"/><w:compat><w:compatSetting w:name="compatibilityMode" '
            . 'w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>');
        $zip->addFromString('word/header1.xml', $this->encabezado($texto, self::ANCHO_V, $png !== '', $ratio, 2, $ns, $cab));
        $zip->addFromString('word/header2.xml', $this->encabezado($texto, self::ANCHO_H, $png !== '', $ratio, 3, $ns, $cab));
        if ($png !== '') {
            $zip->addFromString('word/_rels/header1.xml.rels', $cab . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.png"/></Relationships>');
            $zip->addFromString('word/_rels/header2.xml.rels', $cab . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.png"/></Relationships>');
            $zip->addFromString('word/media/logo.png', $png);
        }
        $zip->addFromString('word/footer1.xml', $cab . '<w:ftr ' . $ns . '>' . $this->pie() . '</w:ftr>');
        $zip->addFromString('word/document.xml', $cab . '<w:document ' . $ns . '><w:body>' . $cuerpo . '</w:body></w:document>');
        return $zip->close();
    }

    /**
     * Propiedades de una seccion: vertical u horizontal, con su encabezado y pie. La primera no lleva encabezado en la
     * primera pagina (la portada).
     *
     * @param string $orient v o h.
     * @param bool $primera
     * @return string
     */
    private function sect_pr(string $orient, bool $primera): string {
        $h = $orient === 'h';
        return '<w:sectPr><w:headerReference w:type="default" r:id="' . ($h ? 'rId6' : 'rId5') . '"/>'
            . '<w:footerReference w:type="default" r:id="rId7"/>'
            . '<w:pgSz w:w="' . ($h ? 15840 : 12240) . '" w:h="' . ($h ? 12240 : 15840) . '"' . ($h ? ' w:orient="landscape"' : '') . '/>'
            . '<w:pgMar w:top="1300" w:right="1300" w:bottom="1200" w:left="1300" w:header="560" w:footer="560" w:gutter="0"/>'
            . '<w:cols w:space="720"/>' . ($primera ? '<w:titlePg/>' : '') . '<w:docGrid w:linePitch="360"/></w:sectPr>';
    }

    /**
     * Cierra la seccion en curso y abre la siguiente.
     *
     * @param string|null $siguiente Orientacion de la siguiente; null al terminar el documento.
     * @return void
     */
    private function cerrar_seccion(?string $siguiente = null): void {
        // Una seccion que quedo vacia (su unico contenido, el titulo de la tabla, se fue con ella) no se cierra: se sigue en
        // la anterior si tiene la orientacion que viene; asi dos tablas anchas seguidas comparten paginas horizontales.
        $n = count($this->secciones);
        // Tambien un texto corto entre dos tablas anchas (el titulo de la fase siguiente, su alcance, un subtotal) se queda
        // en la pagina horizontal: si no, cada fase deja una pagina vertical casi vacia.
        if ($siguiente === 'h' && $this->orient === 'v' && $n > 0 && $this->secciones[$n - 1][0] === 'h' && $this->breve($this->xml)) {
            $this->xml = array_pop($this->secciones)[1] . $this->xml;
            $this->orient = 'h';
            return;
        }
        if ($siguiente !== null && $n > 0 && $this->secciones[$n - 1][0] === $siguiente && $this->sin_contenido($this->xml)) {
            $this->xml = array_pop($this->secciones)[1];
            $this->orient = $siguiente;
            return;
        }
        $this->secciones[] = [$this->orient, $this->xml];
        $this->xml = '';
        if ($siguiente !== null) {
            $this->orient = $siguiente;
        }
    }

    /**
     * @param string $xml
     * @return bool Si es un trozo corto de texto, sin tablas, imagenes ni titulos de etapa (cabe junto a una tabla ancha).
     */
    private function breve(string $xml): bool {
        return strpos($xml, '<w:tbl>') === false && strpos($xml, '<w:drawing') === false
            && strpos($xml, 'w:val="Heading1"') === false
            && \core_text::strlen(trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'))) <= 900;
    }

    /**
     * @param string $xml
     * @return bool Si el XML no tiene texto, tablas ni imagenes (solo parrafos vacios).
     */
    private function sin_contenido(string $xml): bool {
        return strpos($xml, '<w:tbl>') === false && strpos($xml, '<w:drawing') === false
            && trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8')) === '';
    }

    // ------------------------------------------------------------------------------------------------------------
    // Partes fijas: estilos, numeracion, encabezado, pie, portada e indice.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @return string
     */
    private function estilos(): string {
        $titulo = static function(string $id, string $nombre, int $nivel, string $rpr, string $ppr = '') {
            return '<w:style w:type="paragraph" w:styleId="' . $id . '"><w:name w:val="' . $nombre . '"/><w:basedOn w:val="Normal"/>'
                . '<w:next w:val="Normal"/><w:qFormat/><w:pPr>' . $ppr . '<w:outlineLvl w:val="' . $nivel . '"/></w:pPr><w:rPr>' . $rpr
                . '</w:rPr></w:style>';
        };
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Calibri" w:eastAsia="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:color w:val="' . self::TEXTO . '"/>'
            . '<w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="es-CL" w:eastAsia="en-US" w:bidi="ar-SA"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault/></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . $titulo('Heading1', 'heading 1', 0, '<w:b/><w:bCs/><w:color w:val="' . self::NAVY . '"/><w:sz w:val="32"/><w:szCs w:val="32"/>')
            . $titulo('Heading2', 'heading 2', 1, '<w:b/><w:bCs/><w:color w:val="' . self::TITULO2 . '"/><w:sz w:val="24"/><w:szCs w:val="24"/>')
            . $titulo('Heading3', 'heading 3', 2, '<w:b/><w:bCs/><w:color w:val="' . self::TITULO2 . '"/><w:sz w:val="22"/><w:szCs w:val="22"/>')
            . $titulo('Heading4', 'heading 4', 3, '<w:b/><w:bCs/><w:i/><w:iCs/><w:color w:val="' . self::AZUL . '"/>')
            // Estilos del indice: los usa Word si la persona actualiza la tabla de contenidos.
            . '<w:style w:type="paragraph" w:styleId="TOC1"><w:name w:val="toc 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/>'
            . '<w:uiPriority w:val="39"/><w:pPr><w:keepNext/><w:tabs><w:tab w:val="right" w:leader="dot" w:pos="' . self::ANCHO_V . '"/></w:tabs>'
            . '<w:spacing w:before="50" w:after="10" w:line="240" w:lineRule="exact"/></w:pPr><w:rPr><w:b/><w:bCs/><w:color w:val="' . self::NAVY . '"/><w:sz w:val="19"/>'
            . '<w:szCs w:val="19"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="TOC2"><w:name w:val="toc 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/>'
            . '<w:uiPriority w:val="39"/><w:pPr><w:tabs><w:tab w:val="right" w:leader="dot" w:pos="' . self::ANCHO_V . '"/></w:tabs>'
            . '<w:spacing w:line="185" w:lineRule="exact"/><w:ind w:left="400"/></w:pPr><w:rPr><w:color w:val="' . self::TITULO2 . '"/><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:qFormat/></w:style>'
            . '</w:styles>';
    }

    /**
     * Vinetas azules: un punto en el primer nivel y un guion en el segundo.
     *
     * @return string
     */
    private function numeracion(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:abstractNum w:abstractNumId="0">'
            . '<w:multiLevelType w:val="hybridMultilevel"/>'
            . '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/><w:lvlJc w:val="left"/>'
            . '<w:pPr><w:ind w:left="400" w:hanging="260"/></w:pPr><w:rPr><w:b/><w:bCs/><w:color w:val="' . self::AZUL . '"/></w:rPr></w:lvl>'
            . '<w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="–"/><w:lvlJc w:val="left"/>'
            . '<w:pPr><w:ind w:left="800" w:hanging="260"/></w:pPr><w:rPr><w:color w:val="' . self::AZUL . '"/></w:rPr></w:lvl>'
            . '</w:abstractNum><w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num></w:numbering>';
    }

    /**
     * @param string $texto
     * @param int $ancho Ancho util de la pagina, para la tabulacion derecha.
     * @param bool $conlogo
     * @param float $ratio Ancho / alto del logo.
     * @param int $id Identificador del dibujo (unico en el documento).
     * @param string $ns
     * @param string $cab
     * @return string
     */
    private function encabezado(string $texto, int $ancho, bool $conlogo, float $ratio, int $id, string $ns, string $cab): string {
        $alto = 409575;
        $logo = $conlogo ? '<w:r><w:rPr><w:noProof/></w:rPr>' . $this->dibujo('rId1', $id, (int) round($alto * $ratio), $alto, 'Logo') . '</w:r>' : '';
        return $cab . '<w:hdr ' . $ns . '><w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="6" w:color="C9D5E5"/></w:pBdr>'
            . '<w:tabs><w:tab w:val="right" w:pos="' . $ancho . '"/></w:tabs></w:pPr>' . $logo
            . '<w:r><w:rPr><w:color w:val="' . self::GRIS . '"/><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr><w:tab/><w:t>'
            . self::esc($texto) . '</w:t></w:r></w:p></w:hdr>';
    }

    /**
     * @return string
     */
    private function pie(): string {
        $gris = '<w:rPr><w:color w:val="' . self::GRIS . '"/><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr>';
        $azul = '<w:rPr><w:b/><w:bCs/><w:color w:val="' . self::NAVY . '"/><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr>';
        $campo = static function(string $instr, string $rpr, string $valor) {
            return '<w:r>' . $rpr . '<w:fldChar w:fldCharType="begin"/></w:r><w:r>' . $rpr . '<w:instrText xml:space="preserve"> ' . $instr
                . ' </w:instrText></w:r><w:r>' . $rpr . '<w:fldChar w:fldCharType="separate"/></w:r><w:r>' . $rpr . '<w:t>' . $valor
                . '</w:t></w:r><w:r>' . $rpr . '<w:fldChar w:fldCharType="end"/></w:r>';
        };
        return '<w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r>' . $gris . '<w:t xml:space="preserve">Página </w:t></w:r>'
            . $campo('PAGE', $azul, '1') . '<w:r>' . $gris . '<w:t xml:space="preserve"> de </w:t></w:r>' . $campo('NUMPAGES', $azul, '1') . '</w:p>';
    }

    /**
     * Dibujo en linea de una imagen de la parte (relacion $rid).
     *
     * @param string $rid
     * @param int $id
     * @param int $cx Ancho en EMU.
     * @param int $cy Alto en EMU.
     * @param string $nombre
     * @return string
     */
    private function dibujo(string $rid, int $id, int $cx, int $cy, string $nombre): string {
        return '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:effectExtent l="0" t="0" r="0" b="0"/><wp:docPr id="' . $id . '" name="' . self::esc($nombre) . '"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/>'
            . '</wp:cNvGraphicFramePr><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="0" name=""/>'
            . '<pic:cNvPicPr><a:picLocks noChangeAspect="1" noChangeArrowheads="1"/></pic:cNvPicPr></pic:nvPicPr><pic:blipFill>'
            . '<a:blip r:embed="' . $rid . '"/><a:srcRect/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr bwMode="auto">'
            . '<a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
            . '</pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>';
    }

    /**
     * Portada: logo, etiqueta, titulo, subtitulo y los datos del diseño al pie; termina con salto de pagina.
     *
     * @param array $meta
     * @param bool $conlogo
     * @return string
     */
    private function portada(array $meta, bool $conlogo): string {
        $x = '';
        if ($conlogo) {
            $x .= '<w:p><w:pPr><w:spacing w:before="300"/></w:pPr><w:r><w:rPr><w:noProof/></w:rPr>'
                . $this->dibujo('rId4', 1, 2857500, 1019175, 'Logo') . '</w:r></w:p>';
        }
        $barra = '<w:left w:val="single" w:sz="48" w:space="14" w:color="' . self::AZUL . '"/>';
        $x .= $this->p('', ['spacing' => 'w:before="1700"'])
            . $this->p($this->run(\core_text::strtoupper((string) ($meta['etiqueta'] ?? 'Diseño final validado')),
                ['b' => 1, 'color' => self::AZUL, 'spacing' => 40, 'sz' => 26]),
                ['pBdr' => $barra, 'spacing' => 'w:after="160"', 'ind' => 'w:left="300"'])
            . $this->p($this->run((string) ($meta['titulo'] ?? ''), ['b' => 1, 'color' => self::NAVY, 'sz' => 46]),
                ['pBdr' => $barra, 'spacing' => 'w:line="300" w:lineRule="atLeast"', 'ind' => 'w:left="300"'])
            . $this->p($this->run((string) ($meta['subtitulo'] ?? ''), ['color' => self::TITULO2, 'sz' => 28]),
                ['spacing' => 'w:before="360"', 'ind' => 'w:left="300"']);
        $primera = true;
        foreach ((array) ($meta['lineas'] ?? []) as [$rotulo, $valor]) {
            $x .= $this->p($this->run($rotulo . ': ', ['b' => 1, 'color' => self::NAVY, 'sz' => 20]) . $this->run($valor, ['sz' => 20]),
                $primera ? ['pBdr' => '<w:top w:val="single" w:sz="8" w:space="10" w:color="' . self::TITULO2 . '"/>',
                    'spacing' => 'w:before="2400"'] : ['spacing' => 'w:after="40"']);
            $primera = false;
        }
        return $x . '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    /** @var array[] Niveles de compactacion del indice: letra (medios puntos), espacio y altura de linea de cada nivel de entrada. */
    const TOC_NIVELES = [
        // Normal: lineas «auto», unos 409 y 212 twips por entrada de nivel 1 y 2.
        ['sz1' => 21, 'sz2' => 18, 'antes1' => 110, 'despues1' => 30, 'linea1' => 'w:line="252" w:lineRule="auto"',
            'linea2' => 'w:line="232" w:lineRule="auto"', 'alto1' => 409, 'alto2' => 212],
        ['sz1' => 20, 'sz2' => 17, 'antes1' => 70, 'despues1' => 20, 'linea1' => 'w:line="260" w:lineRule="exact"',
            'linea2' => 'w:line="200" w:lineRule="exact"', 'alto1' => 350, 'alto2' => 200],
        ['sz1' => 19, 'sz2' => 16, 'antes1' => 50, 'despues1' => 10, 'linea1' => 'w:line="240" w:lineRule="exact"',
            'linea2' => 'w:line="185" w:lineRule="exact"', 'alto1' => 300, 'alto2' => 185],
        ['sz1' => 18, 'sz2' => 15, 'antes1' => 30, 'despues1' => 0, 'linea1' => 'w:line="230" w:lineRule="exact"',
            'linea2' => 'w:line="170" w:lineRule="exact"', 'alto1' => 260, 'alto2' => 170],
    ];

    /** @var string Comienzo del campo TOC que envuelve las entradas del indice (la primera lo lleva antes de su enlace). */
    const TOC_INICIO = '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> TOC \o "1-2" \h \z \u </w:instrText></w:r>'
        . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>';

    /** @var string Cierre de ese campo (lo lleva la ultima entrada). */
    const TOC_FIN = '<w:r><w:fldChar w:fldCharType="end"/></w:r>';

    /** @var int Alto util de una pagina vertical para el indice, en twips, ya descontados el titulo y un margen de seguridad. */
    const TOC_ALTO = 11800;

    /**
     * Nivel de compactacion del indice: el primero (el mas legible) en que las entradas caben en una pagina. Si ni el
     * ultimo alcanza (un documento enorme), se usa el ultimo y el indice sigue en la pagina siguiente. Funcion pura.
     *
     * @param int $titulos Entradas de nivel 1 (etapas y anexo).
     * @param int $secciones Entradas de nivel 2.
     * @return int Indice en TOC_NIVELES.
     */
    public static function toc_level(int $titulos, int $secciones): int {
        foreach (self::TOC_NIVELES as $i => $t) {
            if ($titulos * $t['alto1'] + $secciones * $t['alto2'] <= self::TOC_ALTO) {
                return $i;
            }
        }
        return count(self::TOC_NIVELES) - 1;
    }

    /**
     * Tabla de contenidos: titulos de etapa y de seccion, con enlace y numero de pagina (campo PAGEREF que Word calcula
     * al abrir el documento).
     *
     * @return string
     */
    private function indice(): string {
        $x = $this->p($this->run('Tabla de contenidos', ['b' => 1, 'color' => self::NAVY, 'sz' => 36]),
            ['pBdr' => '<w:bottom w:val="single" w:sz="12" w:space="6" w:color="' . self::AZUL . '"/>', 'spacing' => 'w:after="240"']);
        // El tamaño y el espacio de las lineas se ajustan al numero de entradas para que el indice quepa en una pagina.
        $n1 = 0;
        foreach ($this->toc as [$nivel]) {
            $n1 += $nivel === 1 ? 1 : 0;
        }
        $nivelindice = self::toc_level($n1, count($this->toc) - $n1);
        $t = self::TOC_NIVELES[$nivelindice];
        $ultimaentrada = count($this->toc) - 1;
        foreach ($this->toc as $i => [$nivel, $texto, $marca]) {
            $f = $nivel === 1 ? ['b' => 1, 'color' => self::NAVY, 'sz' => $t['sz1']] : ['color' => self::TITULO2, 'sz' => $t['sz2']];
            $rpr = $this->rpr($f);
            $pagina = '<w:r>' . $rpr . '<w:fldChar w:fldCharType="begin"/></w:r><w:r>' . $rpr . '<w:instrText xml:space="preserve"> PAGEREF '
                . $marca . ' \h </w:instrText></w:r><w:r>' . $rpr . '<w:fldChar w:fldCharType="separate"/></w:r><w:r>' . $rpr
                . '<w:t></w:t></w:r><w:r>' . $rpr . '<w:fldChar w:fldCharType="end"/></w:r>';
            $x .= $this->p(($i === 0 ? self::TOC_INICIO : '') . '<w:hyperlink w:anchor="' . $marca . '" w:history="1">' . $this->run($texto, $f)
                . '<w:r>' . $rpr . '<w:tab/></w:r>' . $pagina . '</w:hyperlink>' . ($i === $ultimaentrada ? self::TOC_FIN : ''), $nivel === 1
                ? ['keepNext' => 1, 'tabs' => '<w:tab w:val="right" w:leader="dot" w:pos="' . self::ANCHO_V . '"/>',
                    'spacing' => 'w:before="' . $t['antes1'] . '" w:after="' . $t['despues1'] . '" ' . $t['linea1']]
                : ['tabs' => '<w:tab w:val="right" w:leader="dot" w:pos="' . self::ANCHO_V . '"/>',
                    'spacing' => $t['linea2'], 'ind' => 'w:left="400"']);
        }
        return $x;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Piezas de parrafo.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Propiedades de run, en el orden del esquema.
     *
     * @param array $f b, i, u, color, spacing, sz.
     * @return string
     */
    private function rpr(array $f): string {
        $x = (!empty($f['b']) ? '<w:b/><w:bCs/>' : '') . (!empty($f['i']) ? '<w:i/><w:iCs/>' : '')
            . (!empty($f['color']) ? '<w:color w:val="' . $f['color'] . '"/>' : '')
            . (!empty($f['spacing']) ? '<w:spacing w:val="' . (int) $f['spacing'] . '"/>' : '')
            . (!empty($f['sz']) ? '<w:sz w:val="' . (int) $f['sz'] . '"/><w:szCs w:val="' . (int) $f['sz'] . '"/>' : '')
            . (!empty($f['u']) ? '<w:u w:val="single"/>' : '');
        return $x !== '' ? '<w:rPr>' . $x . '</w:rPr>' : '';
    }

    /**
     * @param string $texto
     * @param array $f
     * @return string
     */
    private function run(string $texto, array $f = []): string {
        return '<w:r>' . $this->rpr($f) . '<w:t xml:space="preserve">' . self::esc($texto) . '</w:t></w:r>';
    }

    /**
     * Propiedades de parrafo, en el orden del esquema.
     *
     * @param array $o style, keepNext, keepLines, pageBreakBefore, ilvl, pBdr, shd, tabs, spacing, ind, jc.
     * @return string
     */
    private function ppr(array $o): string {
        return (!empty($o['style']) ? '<w:pStyle w:val="' . $o['style'] . '"/>' : '')
            . (!empty($o['keepNext']) ? '<w:keepNext/>' : '') . (!empty($o['keepLines']) ? '<w:keepLines/>' : '')
            . (!empty($o['pageBreakBefore']) ? '<w:pageBreakBefore/>' : '')
            . (isset($o['ilvl']) ? '<w:numPr><w:ilvl w:val="' . (int) $o['ilvl'] . '"/><w:numId w:val="1"/></w:numPr>' : '')
            . (!empty($o['pBdr']) ? '<w:pBdr>' . $o['pBdr'] . '</w:pBdr>' : '')
            . (!empty($o['shd']) ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['shd'] . '"/>' : '')
            . (!empty($o['tabs']) ? '<w:tabs>' . $o['tabs'] . '</w:tabs>' : '')
            . (!empty($o['spacing']) ? '<w:spacing ' . $o['spacing'] . '/>' : '')
            . (!empty($o['ind']) ? '<w:ind ' . $o['ind'] . '/>' : '')
            . (!empty($o['jc']) ? '<w:jc w:val="' . $o['jc'] . '"/>' : '');
    }

    /**
     * @param string $runs
     * @param array $o
     * @return string
     */
    private function p(string $runs, array $o = []): string {
        $ppr = $this->ppr($o);
        return '<w:p>' . ($ppr !== '' ? '<w:pPr>' . $ppr . '</w:pPr>' : '') . $runs . '</w:p>';
    }

    /**
     * Elementos en linea de un nodo: texto con su formato y saltos de linea.
     *
     * @param \DOMNode $nodo
     * @param array $f Formato heredado.
     * @return array Lista de ['t' => texto, 'f' => formato] o ['br' => true].
     */
    private function inline(\DOMNode $nodo, array $f = []): array {
        $items = [];
        foreach ($nodo->childNodes as $h) {
            if ($h instanceof \DOMText) {
                // Las casillas de un instrumento («[ ] A) ...») se muestran como casillas.
                $t = preg_replace(['/\s+/u', '/\[\s{0,2}\]/u'], [' ', '☐'], $h->nodeValue);
                if ($t !== '') {
                    $items[] = ['t' => $t, 'f' => $f];
                }
                continue;
            }
            if (!$h instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($h->nodeName);
            if ($tag === 'br') {
                $items[] = ['br' => true];
                continue;
            }
            if (in_array($tag, ['ul', 'ol', 'table', 'img', 'script', 'style'], true)) {
                continue;
            }
            $g = $f;
            if (in_array($tag, ['strong', 'b', 'th'], true)) {
                $g['b'] = 1;
                $g['color'] = $g['color'] ?? self::NAVY;
            } else if (in_array($tag, ['em', 'i'], true)) {
                $g['i'] = 1;
            } else if ($tag === 'u') {
                $g['u'] = 1;
            }
            $items = array_merge($items, $this->inline($h, $g));
            if (in_array($tag, ['p', 'div', 'li', 'blockquote'], true)) {
                $items[] = ['t' => ' ', 'f' => $f];
            }
        }
        return $items;
    }

    /**
     * Runs de una lista de elementos en linea, sin espacios al comienzo ni al final del parrafo.
     *
     * @param array $items
     * @param array $base Formato que se agrega a todos (tamaño, color del texto de una celda).
     * @return string
     */
    private function runs(array $items, array $base = []): string {
        $n = count($items);
        $x = '';
        foreach ($items as $i => $it) {
            if (!empty($it['br'])) {
                $x .= '<w:r><w:br/></w:r>';
                continue;
            }
            $t = $it['t'];
            if ($i === 0) {
                $t = ltrim($t);
            }
            if ($i === $n - 1) {
                $t = rtrim($t);
            }
            if ($t !== '') {
                // El formato de la celda (color del encabezado, tamaño) manda sobre el de adentro.
                $x .= $this->run($t, array_merge($it['f'], $base));
            }
        }
        return $x;
    }

    /**
     * @param array $items
     * @return string Texto plano de los elementos.
     */
    private function plain(array $items): string {
        return trim(preg_replace('/\s+/u', ' ', implode('', array_map(static function($i) {
            return $i['t'] ?? ' ';
        }, $items))));
    }

    /**
     * Marcador para el indice y para el campo PAGEREF.
     *
     * @param int $nivel
     * @param string $texto
     * @return string Nombre del marcador.
     */
    private function marcador(int $nivel, string $texto): string {
        $nombre = 'toc_' . (++$this->bmid);
        $this->toc[] = [$nivel, $texto, $nombre];
        return $nombre;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Recorrido del HTML.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Bloques (titulos, parrafos, listas, tablas) de un contenedor, hacia la seccion en curso.
     *
     * @param \DOMNode $padre
     * @param int $nivel Nivel de lista.
     * @param array $fmt Formato base.
     * @return void
     */
    private function bloques(\DOMNode $padre, int $nivel, array $fmt): void {
        $suelto = [];
        $vaciar = function() use (&$suelto, $fmt) {
            if ($this->plain($suelto) !== '' || array_filter($suelto, static function($i) {
                return !empty($i['br']);
            })) {
                $this->parrafo($suelto);
            }
            $suelto = [];
        };
        foreach ($padre->childNodes as $hijo) {
            $tag = $hijo instanceof \DOMElement ? strtolower($hijo->nodeName) : '';
            if ($hijo instanceof \DOMText || in_array($tag, ['strong', 'b', 'em', 'i', 'u', 'span', 'a', 'code', 'br'], true)) {
                $caja = $hijo->ownerDocument->createElement('div');
                $caja->appendChild($hijo->cloneNode(true));
                $suelto = array_merge($suelto, $this->inline($caja, $fmt));
                continue;
            }
            $vaciar();
            if (preg_match('/^h([1-6])$/', $tag, $m)) {
                $this->titulo((int) $m[1], $hijo);
            } else if ($tag === 'p') {
                $this->parrafo($this->inline($hijo, $fmt), $hijo);
            } else if ($tag === 'blockquote') {
                $this->bloques($hijo, $nivel, $fmt);
            } else if ($tag === 'pre') {
                $this->preformateado((string) $hijo->textContent);
            } else if ($tag === 'ul' || $tag === 'ol') {
                $this->lista($hijo, $nivel);
            } else if ($tag === 'table') {
                $this->tabla($hijo);
            } else if ($tag !== '' && $tag !== 'hr' && $tag !== 'img') {
                $this->bloques($hijo, $nivel, $fmt);
            }
        }
        $vaciar();
    }

    /**
     * Parrafo de texto corrido; una formula de calculo va en recuadro y una sesion («SESIÓN 1 ...») en banda.
     *
     * @param array $items
     * @param \DOMElement|null $el Elemento de origen.
     * @return void
     */
    private function parrafo(array $items, ?\DOMElement $el = null): void {
        $texto = $this->plain($items);
        if ($texto === '' && !array_filter($items, static function($i) {
            return !empty($i['br']);
        })) {
            return;
        }
        // Una lista escrita dentro del parrafo («1. ...», «* ...» en lineas seguidas, Markdown que no se convirtio) se arma
        // como lista.
        $lineas = $el !== null ? $this->lineas_lista($el) : null;
        if ($lineas !== null) {
            foreach ($lineas as $l) {
                if ($l['marca'] === '') {
                    $this->xml .= $this->p($this->runs($l['items']), ['spacing' => 'w:after="100" w:line="276" w:lineRule="auto"',
                        'jc' => $this->alineacion($l['items'])]);
                } else {
                    $this->item_lista(preg_match('/^\d/', $l['marca']) ? $l['marca'] : '', $l['nivel'], $l['items']);
                }
            }
            $this->xml .= '<w:p><w:pPr><w:spacing w:after="60" w:line="120" w:lineRule="exact"/></w:pPr></w:p>';
            return;
        }
        $sesion = self::parse_session($texto);
        if ($sesion !== null && $this->es_negrita($items)) {
            $this->banda($sesion);
            return;
        }
        if (self::is_formula($texto)) {
            $this->recuadro($texto);
            return;
        }
        $this->xml .= $this->p($this->runs($items), ['spacing' => 'w:after="140" w:line="276" w:lineRule="auto"',
            'jc' => $this->alineacion($items)]);
    }

    /**
     * Justificado, salvo que el texto tenga una linea para escribir (_____): justificada, separa las palabras.
     *
     * @param array $items
     * @return string
     */
    private function alineacion(array $items): string {
        return preg_match('/_{8,}/', $this->plain($items)) ? 'left' : 'both';
    }

    /**
     * Lineas de un parrafo que trae una lista escrita a mano: el Markdown «1. ...» o «* ...» pegado a la linea anterior no se
     * convierte en lista y queda como lineas dentro del parrafo.
     *
     * @param \DOMElement $el
     * @return array|null Lista de ['marca' => '' | '1.' | '*', 'nivel' => 0 | 1, 'items' => elementos en linea]; null si el
     *         parrafo no tiene lineas asi.
     */
    private function lineas_lista(\DOMElement $el): ?array {
        $marca = '(?:[*\-•]|\d+[.)])';
        if (!preg_match('/\n[ \t]*' . $marca . '[ \t]+\S/u', $el->textContent)) {
            return null;
        }
        $lineas = [['crudo' => '', 'items' => []]];
        foreach ($el->childNodes as $h) {
            if ($h instanceof \DOMText) {
                foreach (preg_split('/\n/u', $h->nodeValue) as $i => $t) {
                    if ($i > 0) {
                        $lineas[] = ['crudo' => '', 'items' => []];
                    }
                    $k = count($lineas) - 1;
                    if (!$lineas[$k]['items']) {
                        $lineas[$k]['crudo'] .= $t;
                    }
                    $lineas[$k]['items'][] = ['t' => $t, 'f' => []];
                }
                continue;
            }
            if ($h instanceof \DOMElement && strtolower($h->nodeName) === 'br') {
                $lineas[] = ['crudo' => '', 'items' => []];
                continue;
            }
            $caja = $el->ownerDocument->createElement('div');
            $caja->appendChild($h->cloneNode(true));
            $k = count($lineas) - 1;
            $lineas[$k]['items'] = array_merge($lineas[$k]['items'], $this->inline($caja));
        }
        $salida = [];
        foreach ($lineas as $l) {
            $items = $l['items'];
            $nivel = 0;
            $m = [];
            if (preg_match('/^([ \t]*)(' . $marca . ')[ \t]+/u', $l['crudo'], $m)) {
                $nivel = strlen($m[1]) >= 2 ? 1 : 0;
                $items[0]['t'] = preg_replace('/^[ \t]*' . $marca . '[ \t]+/u', '', $items[0]['t']);
            }
            foreach ($items as $i => $it) {
                if (isset($it['t'])) {
                    $items[$i]['t'] = preg_replace(['/\s+/u', '/\[\s{0,2}\]/u'], [' ', '☐'], $it['t']);
                }
            }
            if ($this->plain($items) !== '') {
                $salida[] = ['marca' => $m[2] ?? '', 'nivel' => $nivel, 'items' => $items];
            }
        }
        return $salida;
    }

    /**
     * @param array $items
     * @return bool Si casi todo el texto del parrafo esta en negrita.
     */
    private function es_negrita(array $items): bool {
        $total = 0;
        $negrita = 0;
        foreach ($items as $it) {
            $n = \core_text::strlen(trim($it['t'] ?? ''));
            $total += $n;
            $negrita += !empty($it['f']['b']) ? $n : 0;
        }
        return $total > 0 && $negrita / $total >= 0.8;
    }

    /**
     * Banda de sesion: franja azul claro con barra a la izquierda.
     *
     * @param array $sesion [«SESIÓN 1», resto].
     * @return void
     */
    private function banda(array $sesion): void {
        $this->xml .= $this->p($this->run($sesion[0] . '  ', ['b' => 1, 'color' => self::AZUL, 'spacing' => 10, 'sz' => 21])
            . $this->run($sesion[1], ['b' => 1, 'color' => self::NAVY, 'sz' => 23]),
            ['keepNext' => 1, 'keepLines' => 1, 'pBdr' => '<w:left w:val="single" w:sz="36" w:space="8" w:color="' . self::AZUL . '"/>',
                'shd' => self::BANDA, 'spacing' => 'w:before="280" w:after="140"', 'ind' => 'w:left="160"']);
    }

    /**
     * Formula de calculo en un recuadro centrado.
     *
     * @param string $texto
     * @return void
     */
    private function recuadro(string $texto): void {
        $this->xml .= '<w:tbl><w:tblPr><w:tblW w:w="' . $this->ancho() . '" w:type="dxa"/><w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/>'
            . '<w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>'
            . '</w:tblPr><w:tblGrid><w:gridCol w:w="' . $this->ancho() . '"/></w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr><w:tc><w:tcPr>'
            . '<w:tcW w:w="' . $this->ancho() . '" w:type="dxa"/><w:tcBorders><w:left w:val="single" w:sz="36" w:space="0" w:color="' . self::AZUL
            . '"/></w:tcBorders><w:shd w:val="clear" w:color="auto" w:fill="' . self::BANDA . '"/><w:tcMar><w:top w:w="140" w:type="dxa"/>'
            . '<w:left w:w="200" w:type="dxa"/><w:bottom w:w="140" w:type="dxa"/><w:right w:w="200" w:type="dxa"/></w:tcMar>'
            . '<w:vAlign w:val="center"/></w:tcPr>'
            . $this->p($this->run($texto, ['b' => 1, 'color' => self::NAVY, 'sz' => 23]), ['jc' => 'center'])
            . '</w:tc></w:tr></w:tbl>' . $this->espacio();
    }

    /**
     * @return int Ancho util de la seccion en curso.
     */
    private function ancho(): int {
        return $this->orient === 'h' ? self::ANCHO_H : self::ANCHO_V;
    }

    /**
     * Pequeño espacio despues de una tabla.
     *
     * @return string
     */
    private function espacio(): string {
        return '<w:p><w:pPr><w:spacing w:after="120" w:line="140" w:lineRule="exact"/></w:pPr></w:p>';
    }

    /**
     * Titulo: de etapa (pagina nueva, con su etiqueta), de seccion numerado, o de subseccion.
     *
     * @param int $n Nivel del titulo HTML (1 a 6).
     * @param \DOMElement $el
     * @return void
     */
    private function titulo(int $n, \DOMElement $el): void {
        $items = $this->inline($el);
        $texto = $this->plain($items);
        if ($texto === '') {
            return;
        }
        // El titulo general del consolidado («DOCUMENTO CONSOLIDADO DE DISEÑO...») ya esta en la portada.
        if ($n === 1 && preg_match('/^documento\s+consolidado\b/iu', $texto)) {
            return;
        }
        $etapa = $n <= 3 ? self::parse_stage_title($texto) : null;
        // Un producto de etapa trae su titulo como h2 y las secciones como h3: los niveles se corren para numerarlos como
        // en el documento final («2.1», «2.7.1»).
        if ($etapa !== null) {
            $this->desfase = $n - 1;
        } else if ($this->desfase > 0) {
            // Dentro del Word de una etapa, un titulo del nivel de la etapa («## Referencias consultadas») es una seccion
            // mas, no un capitulo nuevo en otra pagina.
            $n = max(2, $n - $this->desfase);
        }
        // Como en el ejemplo, los titulos van en minuscula normal y sin los dos puntos del final.
        $texto = preg_replace('/\s*:\s*$/u', '', self::sentence_case($texto));
        if ($etapa !== null || $n === 1) {
            $this->etapa = $etapa !== null ? (int) $etapa['numero'] : 0;
            $this->seccion = '';
            $this->ultima = 0;
            $marca = $this->marcador(1, $etapa['indice'] ?? $texto);
            $id = $this->bmid;
            $ini = '<w:bookmarkStart w:id="' . $id . '" w:name="' . $marca . '"/>';
            $fin = '<w:bookmarkEnd w:id="' . $id . '"/>';
            $base = ['style' => 'Heading1', 'keepNext' => 1, 'keepLines' => 1, 'pageBreakBefore' => 1,
                'pBdr' => '<w:bottom w:val="single" w:sz="12" w:space="6" w:color="' . self::AZUL . '"/>', 'spacing' => 'w:after="240"'];
            if ($etapa !== null) {
                $this->xml .= $this->p($ini . $this->run($etapa['etiqueta'], ['color' => self::AZUL, 'spacing' => 20, 'sz' => 22])
                    . '<w:r><w:tab/></w:r>' . $this->run($etapa['titulo']) . $fin,
                    $base + ['tabs' => '<w:tab w:val="left" w:pos="1350"/>', 'ind' => 'w:left="1350" w:hanging="1350"']);
            } else {
                $this->xml .= $this->p($ini . $this->run($texto) . $fin, $base);
            }
            return;
        }
        if ($n === 2) {
            [$num, $resto] = self::split_number($texto);
            // Dentro de una etapa, «1. Síntesis diagnóstica» se numera «1.1» como en el ejemplo.
            $this->seccion = '';
            if ($this->etapa > 0 && preg_match('/^\d+$/', $num)) {
                $this->seccion = $num;
                $this->ultima = max($this->ultima, (int) $num);
                $num = $this->etapa . '.' . $num;
            } else if ($this->etapa > 0 && preg_match('/^' . $this->etapa . '\.(\d+)$/', $num, $ya)) {
                // Ya viene con el numero de la etapa («3.4»): cuenta igual, o la seccion sin numero que sigue («Verificación
                // de calidad de la etapa») repetiria el «3.1».
                $this->seccion = $ya[1];
                $this->ultima = max($this->ultima, (int) $ya[1]);
            } else if ($this->etapa > 0 && $num === '') {
                // Una seccion sin numero dentro de la etapa («Verificación de calidad de la etapa») sigue la numeracion,
                // como en el ejemplo («2.11»).
                $this->ultima++;
                $num = $this->etapa . '.' . $this->ultima;
            }
            $num = self::number_label($num);
            $marca = $this->marcador(2, trim($num . ' ' . $resto));
            $id = $this->bmid;
            $this->xml .= $this->p('<w:bookmarkStart w:id="' . $id . '" w:name="' . $marca . '"/>'
                . ($num !== '' ? $this->run($num . '  ', ['color' => self::AZUL]) : '') . $this->run($resto)
                . '<w:bookmarkEnd w:id="' . $id . '"/>',
                ['style' => 'Heading2', 'keepNext' => 1, 'keepLines' => 1, 'spacing' => 'w:before="280" w:after="120"']);
            return;
        }
        $sesion = $n >= 3 ? self::parse_session($texto) : null;
        if ($sesion !== null) {
            $this->banda($sesion);
            return;
        }
        [$num, $resto] = self::split_number($texto);
        // «3.1. Equipamiento» bajo la seccion «3.» de la etapa 2 pasa a «2.3.1».
        if ($num !== '' && $this->etapa > 0 && $this->seccion !== ''
                && preg_match('/^' . preg_quote($this->seccion, '/') . '(?:\.\d+)+$/', $num)) {
            $num = $this->etapa . '.' . $num;
        }
        $num = self::number_label($num);
        $this->xml .= $this->p(($num !== '' ? $this->run($num . '  ', ['color' => self::AZUL]) : '') . $this->run($resto),
            ['style' => $n === 3 ? 'Heading3' : 'Heading4', 'keepNext' => 1, 'keepLines' => 1,
            'spacing' => $n === 3 ? 'w:before="200" w:after="80"' : 'w:before="160" w:after="60"']);
    }

    /**
     * Texto preformateado. Los dibujos de caracteres no se arman bien en Word: una tabla dibujada con cajas vuelve a ser
     * tabla, un arbol de ramas (├── └──) se vuelve lista con sus niveles y otro dibujo, la lista de lo que decia cada caja.
     * Lo demas (encabezados de instrumentos, pautas, hojas de calculo) va en una ficha.
     *
     * @param string $texto
     * @return void
     */
    private function preformateado(string $texto): void {
        $tabla = diagrama::art_table_html($texto);
        if ($tabla !== null) {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $tabla . '</body></html>');
            libxml_clear_errors();
            $t = $dom->getElementsByTagName('table')->item(0);
            if ($t instanceof \DOMElement) {
                $this->tabla($t);
                return;
            }
        }
        $arbol = diagrama::tree_items($texto);
        if ($arbol !== null) {
            $this->arbol($arbol);
            return;
        }
        if (diagrama::has_art($texto)) {
            foreach (diagrama::art_lines($texto) as $l) {
                $this->xml .= $this->p($this->run($l), ['style' => 'ListParagraph', 'keepLines' => 1, 'ilvl' => 0,
                    'spacing' => 'w:after="60" w:line="264" w:lineRule="auto"']);
            }
            return;
        }
        $lineas = self::form_lines($texto);
        if (in_array('cotejo', array_column($lineas, 'tipo'), true)) {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . self::pre_html($texto) . '</body></html>');
            libxml_clear_errors();
            $body = $dom->getElementsByTagName('body')->item(0);
            if ($body) {
                $this->bloques($body, 0, []);
            }
            return;
        }
        $this->ficha($lineas);
    }

    /**
     * Arbol como lista: las lineas sin rama en negrita (titulos de momento) y las ramas como viñetas de uno o dos niveles,
     * con el rotulo («Estación A:») en negrita.
     *
     * @param array $items De diagrama::tree_items().
     * @return void
     */
    private function arbol(array $items): void {
        foreach ($items as [$nivel, $t]) {
            if ($nivel === 0) {
                $this->xml .= $this->p($this->run(self::sentence_case($t), ['b' => 1, 'color' => self::NAVY]),
                    ['keepNext' => 1, 'keepLines' => 1, 'spacing' => 'w:before="140" w:after="60"']);
                continue;
            }
            [$rotulo, $valor] = self::label_split($t);
            $runs = $rotulo !== '' ? $this->run($rotulo . ': ', ['b' => 1, 'color' => self::NAVY]) . $this->run($valor) : $this->run($t);
            $this->xml .= $this->p($runs, ['style' => 'ListParagraph', 'keepLines' => 1, 'ilvl' => min(1, $nivel - 1),
                'spacing' => 'w:after="50" w:line="264" w:lineRule="auto"', 'jc' => 'both']);
        }
        $this->xml .= '<w:p><w:pPr><w:spacing w:after="60" w:line="120" w:lineRule="exact"/></w:pPr></w:p>';
    }

    /**
     * Ficha: recuadro con borde superior azul y fondo claro para el encabezado de un instrumento, una pauta o una hoja de
     * calculo; rotulos en negrita, casillas ☐ y lineas para escribir.
     *
     * @param array $lineas De form_lines().
     * @return void
     */
    private function ficha(array $lineas): void {
        if (!$lineas) {
            return;
        }
        $sz = 19;
        $espacio = 'w:after="50" w:line="264" w:lineRule="auto"';
        $x = '';
        foreach ($lineas as $l) {
            if ($l['tipo'] === 'titulo') {
                $x .= $this->p($this->run($l['partes'][0][1], ['b' => 1, 'color' => self::NAVY, 'spacing' => 6, 'sz' => 20]),
                    ['keepNext' => 1, 'spacing' => 'w:before="40" w:after="80"']);
            } else if ($l['tipo'] === 'linea') {
                $x .= $this->p('', ['pBdr' => '<w:bottom w:val="single" w:sz="4" w:space="1" w:color="' . self::GRIS . '"/>',
                    'spacing' => 'w:before="220" w:after="60"']);
            } else if ($l['tipo'] === 'espacio') {
                $x .= '<w:p><w:pPr><w:spacing w:after="0" w:line="120" w:lineRule="exact"/></w:pPr></w:p>';
            } else if ($l['tipo'] === 'vineta' || $l['tipo'] === 'casilla') {
                $marca = $l['tipo'] === 'vineta' ? $this->run('•', ['b' => 1, 'color' => self::AZUL, 'sz' => $sz])
                    : $this->run('☐', ['color' => self::NAVY, 'sz' => $sz]);
                $x .= $this->p($marca . '<w:r><w:tab/></w:r>' . $this->partes($l['partes'], $sz),
                    ['tabs' => '<w:tab w:val="left" w:pos="300"/>', 'spacing' => $espacio, 'ind' => 'w:left="300" w:hanging="300"']);
            } else {
                $x .= $this->p($this->partes($l['partes'], $sz), ['spacing' => $espacio]);
            }
        }
        $ancho = $this->ancho();
        $fino = 'w:val="single" w:sz="4" w:space="0" w:color="' . self::BORDE . '"';
        $this->xml .= '<w:tbl><w:tblPr><w:tblW w:w="' . $ancho . '" w:type="dxa"/><w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/>'
            . '<w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>'
            . '<w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid><w:gridCol w:w="' . $ancho . '"/></w:tblGrid><w:tr><w:tc><w:tcPr>'
            . '<w:tcW w:w="' . $ancho . '" w:type="dxa"/><w:tcBorders><w:top w:val="single" w:sz="18" w:space="0" w:color="' . self::AZUL . '"/>'
            . '<w:left ' . $fino . '/><w:bottom ' . $fino . '/><w:right ' . $fino . '/></w:tcBorders>'
            . '<w:shd w:val="clear" w:color="auto" w:fill="' . self::ZEBRA . '"/><w:tcMar><w:top w:w="140" w:type="dxa"/>'
            . '<w:left w:w="220" w:type="dxa"/><w:bottom w:w="120" w:type="dxa"/><w:right w:w="220" w:type="dxa"/></w:tcMar></w:tcPr>'
            . $x . '</w:tc></w:tr></w:tbl>' . $this->espacio();
    }

    /**
     * Runs de las partes «Rótulo: valor» de una linea de ficha: el rotulo en negrita y las partes separadas por espacio.
     *
     * @param array $partes
     * @param int $sz
     * @return string
     */
    private function partes(array $partes, int $sz): string {
        $x = '';
        foreach ($partes as $i => [$rotulo, $valor]) {
            if ($i > 0) {
                $x .= $this->run('     ', ['sz' => $sz]);
            }
            if ($rotulo !== '') {
                $x .= $this->run($rotulo . ': ', ['b' => 1, 'color' => self::NAVY, 'sz' => $sz]);
            }
            if ($valor !== '') {
                $x .= $this->run($valor, ['sz' => $sz]);
            }
        }
        return $x;
    }

    /**
     * Lista con vinetas azules, o numerada con el numero en azul.
     *
     * @param \DOMElement $lista
     * @param int $nivel
     * @return void
     */
    private function lista(\DOMElement $lista, int $nivel): void {
        $ordenada = strtolower($lista->nodeName) === 'ol';
        $i = 0;
        foreach ($lista->childNodes as $li) {
            if ($li instanceof \DOMElement && in_array(strtolower($li->nodeName), ['ul', 'ol'], true)) {
                $this->lista($li, $nivel + 1);
                continue;
            }
            if (!$li instanceof \DOMElement || strtolower($li->nodeName) !== 'li') {
                continue;
            }
            $i++;
            $items = $this->inline($li);
            if ($this->plain($items) !== '') {
                $this->item_lista($ordenada ? $i . '.' : '', $nivel, $items);
            }
            foreach ($li->childNodes as $sub) {
                $nombre = $sub instanceof \DOMElement ? strtolower($sub->nodeName) : '';
                if ($nombre === 'ul' || $nombre === 'ol') {
                    $this->lista($sub, $nivel + 1);
                } else if ($nombre === 'table') {
                    $this->tabla($sub);
                } else if ($nombre === 'pre') {
                    $this->preformateado((string) $sub->textContent);
                }
            }
        }
        if ($nivel === 0) {
            $this->xml .= '<w:p><w:pPr><w:spacing w:after="60" w:line="120" w:lineRule="exact"/></w:pPr></w:p>';
        }
    }

    /**
     * Item de lista: numerado (el numero en azul) o con viñeta azul de uno o dos niveles.
     *
     * @param string $numero «1.»; vacio para una viñeta.
     * @param int $nivel
     * @param array $items
     * @return void
     */
    private function item_lista(string $numero, int $nivel, array $items): void {
        $espacio = 'w:after="70" w:line="276" w:lineRule="auto"';
        if ($numero !== '') {
            $izq = 360 * ($nivel + 1);
            $this->xml .= $this->p($this->run($numero, ['b' => 1, 'color' => self::AZUL]) . '<w:r><w:tab/></w:r>' . $this->runs($items),
                ['keepLines' => 1, 'tabs' => '<w:tab w:val="left" w:pos="' . $izq . '"/>', 'spacing' => $espacio,
                    'ind' => 'w:left="' . $izq . '" w:hanging="360"', 'jc' => $this->alineacion($items)]);
            return;
        }
        $this->xml .= $this->p($this->runs($items), ['style' => 'ListParagraph', 'keepLines' => 1, 'ilvl' => min(1, $nivel),
            'spacing' => $espacio, 'jc' => $this->alineacion($items)]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Tablas.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Filas de una tabla: cada celda con su elemento y su extension en columnas.
     *
     * @param \DOMElement $tabla
     * @return array Lista de filas; cada fila, lista de ['td' => elemento, 'span' => int].
     */
    private function filas(\DOMElement $tabla): array {
        $filas = [];
        foreach ($tabla->getElementsByTagName('tr') as $tr) {
            $celdas = [];
            foreach ($tr->childNodes as $td) {
                if ($td instanceof \DOMElement && in_array(strtolower($td->nodeName), ['td', 'th'], true)) {
                    $celdas[] = ['td' => $td, 'span' => max(1, (int) $td->getAttribute('colspan'))];
                }
            }
            if ($celdas) {
                $filas[] = $celdas;
            }
        }
        return $filas;
    }

    /**
     * Tabla con el diseño del ejemplo. Las de seis columnas o mas van en una pagina horizontal.
     *
     * @param \DOMElement $tabla
     * @return void
     */
    private function tabla(\DOMElement $tabla): void {
        $filas = $this->filas($tabla);
        if (!$filas) {
            return;
        }
        $cols = 0;
        foreach ($filas as $f) {
            $cols = max($cols, array_sum(array_column($f, 'span')));
        }
        $diagrama = strpos(' ' . $tabla->getAttribute('class') . ' ', ' m2-diagrama ') !== false;
        $caracteres = 0;
        foreach ($filas as $f) {
            foreach ($f as $celda) {
                $caracteres += \core_text::strlen(trim(preg_replace('/\s+/u', ' ', $celda['td']->textContent)));
            }
        }
        $ancha = !$diagrama && self::is_wide($cols, $caracteres / count($filas));
        if ($ancha) {
            // El titulo de la tabla (por ejemplo «3.3 Matriz...») pasa a la pagina horizontal junto con ella.
            $titulo = $this->sacar_cola();
            $this->cerrar_seccion('h');
            $this->xml .= $titulo;
        } else {
            // Un parrafo corto justo antes (el titulo de un diagrama, «Cálculo del porcentaje...») no se separa de la tabla.
            $this->mantener_con_siguiente();
        }
        $total = $this->ancho();

        // Entre «Vacío» y «Solución» va una columna con la flecha, como en el ejemplo.
        $vacio = false;
        if (!$diagrama && $cols === 2 && preg_match('/^vac[ií]o|^dificultad/iu', trim($filas[0][0]['td']->textContent))) {
            $vacio = true;
            // Con una fila de una sola celda (un titulo a todo el ancho) la columna de la flecha no cuadra: tabla comun.
            foreach ($filas as $f) {
                if (count($f) !== 2) {
                    $vacio = false;
                }
            }
        }
        if ($diagrama) {
            $anchos = [];
            for ($c = 0; $c < $cols; $c++) {
                $anchos[] = $c % 2 === 1 ? 640 : (int) floor(($total - 640 * intdiv($cols, 2)) / max(1, $cols - intdiv($cols, 2)));
            }
            // El redondeo se suma a la ultima caja: las columnas miden exactamente el ancho de la tabla.
            $anchos[$cols - 1] += $total - array_sum($anchos);
        } else if ($vacio) {
            $anchos = [(int) floor(($total - 700) / 2), 700, $total - 700 - (int) floor(($total - 700) / 2)];
        } else {
            [$pesos, $minimos] = $this->pesos($filas, $cols);
            $anchos = self::column_widths($pesos, $total, $minimos);
        }

        // Alto aproximado de cada fila, para decidir que se mantiene junto en una pagina.
        $altos = [];
        foreach ($filas as $r => $celdas) {
            $col = 0;
            $altos[$r] = 0;
            foreach ($celdas as $c => $celda) {
                $td = $celda['td'];
                $bloques = $td->getElementsByTagName('p')->length + $td->getElementsByTagName('li')->length;
                $altos[$r] = max($altos[$r], self::cell_height(\core_text::strlen(trim(preg_replace('/\s+/u', ' ', $td->textContent))),
                    array_sum(array_slice($anchos, $col, $celda['span'])), max(1, $bloques)));
                $col += $celda['span'] + ($vacio && $c === 0 ? 1 : 0);
            }
        }
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . $total . '" w:type="dxa"/><w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/>'
            . '<w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>'
            . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="10" w:type="dxa"/><w:right w:w="10" w:type="dxa"/></w:tblCellMar>'
            . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/></w:tblPr>'
            . '<w:tblGrid>';
        foreach ($anchos as $a) {
            $xml .= '<w:gridCol w:w="' . $a . '"/>';
        }
        $xml .= '</w:tblGrid>';
        $encabezado = $this->tiene_encabezado($tabla, $filas);
        // Una tabla baja (o un diagrama) no se parte entre paginas. En una alta, la fila que ocupa mas de un tercio de pagina
        // puede partirse: si no, salta entera a la pagina siguiente y deja media pagina en blanco. El encabezado se repite.
        $pagina = ($this->orient === 'h' ? 12240 : 15840) - 2500;
        $juntas = $diagrama || array_sum($altos) <= 0.45 * $pagina;
        foreach ($filas as $r => $celdas) {
            $esenc = $encabezado && $r === 0;
            $cuerpo = $r - ($encabezado ? 1 : 0);
            // Fila de grupo («DIMENSIÓN 1: ...» y el resto vacio): una banda a todo el ancho, junto a la fila que sigue.
            $grupo = !$esenc && !$diagrama && !$vacio && $cols >= 3 && count($celdas) === $cols && $this->solo_primera($celdas);
            if ($grupo) {
                $celdas = [['td' => $celdas[0]['td'], 'span' => $cols]];
            }
            $trpr = (!$juntas && !$esenc && $altos[$r] > $pagina / 3 ? '' : '<w:cantSplit/>') . ($esenc ? '<w:tblHeader/>' : '');
            $xml .= '<w:tr>' . ($trpr !== '' ? '<w:trPr>' . $trpr . '</w:trPr>' : '');
            $col = 0;
            foreach ($celdas as $c => $celda) {
                $span = $celda['span'];
                $xml .= $this->celda($celda['td'], $esenc, $cuerpo, $col, $span, $anchos, $diagrama, $vacio, $c === 0 && $cols > 1,
                    ($juntas || $grupo) && $r < count($filas) - 1);
                if ($vacio && $col === 0) {
                    $xml .= $this->celda_flecha($esenc, $cuerpo, $anchos[1]);
                    $col++;
                }
                $col += $span;
            }
            $xml .= '</w:tr>';
        }
        $this->xml .= $xml . '</w:tbl>' . $this->espacio();
        if ($ancha) {
            $this->cerrar_seccion('v');
        }
    }

    /**
     * Marca el ultimo parrafo de la seccion en curso para que quede en la misma pagina que lo que sigue, si es corto.
     *
     * @return void
     */
    private function mantener_con_siguiente(): void {
        $pos = strrpos($this->xml, '<w:p>');
        if ($pos === false) {
            return;
        }
        $ultimo = substr($this->xml, $pos);
        if (substr_count($ultimo, '</w:p>') !== 1 || substr($ultimo, -6) !== '</w:p>' || strpos($ultimo, '<w:keepNext/>') !== false
                || strpos($ultimo, '<w:sectPr') !== false) {
            return;
        }
        $texto = html_entity_decode(strip_tags($ultimo), ENT_QUOTES | ENT_XML1, 'UTF-8');
        if (trim($texto) === '' || \core_text::strlen($texto) > 200) {
            return;
        }
        if (preg_match('/^<w:p><w:pPr><w:pStyle w:val="[^"]*"\/>/', $ultimo, $m)) {
            $nuevo = $m[0] . '<w:keepNext/>' . substr($ultimo, strlen($m[0]));
        } else if (strpos($ultimo, '<w:p><w:pPr>') === 0) {
            $nuevo = '<w:p><w:pPr><w:keepNext/>' . substr($ultimo, strlen('<w:p><w:pPr>'));
        } else {
            $nuevo = '<w:p><w:pPr><w:keepNext/></w:pPr>' . substr($ultimo, strlen('<w:p>'));
        }
        $this->xml = substr($this->xml, 0, $pos) . $nuevo;
    }

    /**
     * Saca del final de la seccion en curso el titulo de la tabla ancha que viene y el texto corto que la presenta, para
     * llevarlos a la pagina horizontal junto con ella. Sin esto, el titulo, su introduccion y a veces el titulo de la etapa
     * quedan solos en una pagina vertical casi vacia.
     *
     * Solo se mueve si, hacia atras, se llega a un titulo de seccion con a lo mas cuatro parrafos cortos (1100 caracteres)
     * de por medio; si el texto es mas largo, se queda donde esta y la tabla va sola.
     *
     * @return string XML de lo que se saco, o '' si no se movio nada.
     */
    private function sacar_cola(): string {
        $xml = $this->xml;
        $cola = [];
        $texto = 0;
        while (($pos = strrpos($xml, '<w:p>')) !== false) {
            $ultimo = substr($xml, $pos);
            if (substr_count($ultimo, '</w:p>') !== 1 || substr($ultimo, -6) !== '</w:p>' || strpos($ultimo, '<w:sectPr') !== false) {
                return '';
            }
            $esttitulo = preg_match('/<w:pStyle w:val="Heading([1-4])"\/>/', $ultimo, $m) === 1;
            if (!$esttitulo) {
                $largo = \core_text::strlen(trim(html_entity_decode(strip_tags($ultimo), ENT_QUOTES | ENT_XML1, 'UTF-8')));
                $texto += $largo;
                // Un parrafo vacio, mas de cuatro o demasiado texto: la tabla no tiene una introduccion corta que llevarse.
                if ($largo === 0 || count($cola) >= 4 || $texto > 1100) {
                    return '';
                }
            }
            $xml = substr($xml, 0, $pos);
            // El salto de seccion ya empieza pagina nueva.
            array_unshift($cola, str_replace('<w:pageBreakBefore/>', '', $ultimo));
            if ($esttitulo) {
                $this->xml = $xml;
                // Si el titulo de seccion venia justo despues del titulo de la etapa, este tambien se va.
                return ($m[1] !== '1' ? $this->sacar_titulo_etapa() : '') . implode('', $cola);
            }
        }
        return '';
    }

    /**
     * Saca del final de la seccion en curso el titulo de seccion (nivel 2 a 4) que quedo suelto, para llevarlo a la
     * pagina siguiente junto con su tabla.
     *
     * @return string XML del titulo, o '' si el ultimo parrafo no es un titulo.
     */
    private function sacar_titulo(): string {
        $pos = strrpos($this->xml, '<w:p>');
        if ($pos === false) {
            return '';
        }
        $ultimo = substr($this->xml, $pos);
        if (substr_count($ultimo, '</w:p>') !== 1 || substr($ultimo, -6) !== '</w:p>'
                || !preg_match('/<w:pStyle w:val="Heading([1-4])"\/>/', $ultimo, $m)) {
            return '';
        }
        $this->xml = substr($this->xml, 0, $pos);
        // El salto de seccion ya empieza pagina nueva.
        $ultimo = str_replace('<w:pageBreakBefore/>', '', $ultimo);
        // Si el de seccion venia justo despues del titulo de la etapa, este tambien se va.
        return ($m[1] !== '1' ? $this->sacar_titulo_etapa() : '') . $ultimo;
    }

    /**
     * Saca del final de la seccion en curso el titulo de etapa (Heading1) si es el ultimo parrafo.
     *
     * @return string
     */
    private function sacar_titulo_etapa(): string {
        $pos = strrpos($this->xml, '<w:p>');
        if ($pos === false) {
            return '';
        }
        $ultimo = substr($this->xml, $pos);
        if (substr_count($ultimo, '</w:p>') !== 1 || substr($ultimo, -6) !== '</w:p>'
                || strpos($ultimo, '<w:pStyle w:val="Heading1"/>') === false) {
            return '';
        }
        $this->xml = substr($this->xml, 0, $pos);
        return str_replace('<w:pageBreakBefore/>', '', $ultimo);
    }

    /**
     * Pesos y anchos minimos de las columnas: el peso sale del largo promedio de su texto y el minimo, de su palabra mas
     * larga (con letra de 9 puntos, unos 105 twips por caracter mas los margenes de la celda).
     *
     * @param array $filas
     * @param int $cols
     * @return array [pesos (float[]), minimos (int[])]
     */
    private function pesos(array $filas, int $cols): array {
        $suma = array_fill(0, $cols, 0);
        $cuenta = array_fill(0, $cols, 0);
        $palabra = array_fill(0, $cols, 0);
        foreach ($filas as $celdas) {
            // Una fila de grupo («DIMENSIÓN 1: ...») va a todo el ancho: no ensancha la primera columna.
            if ($cols >= 3 && count($celdas) === $cols && $this->solo_primera($celdas)) {
                continue;
            }
            $col = 0;
            foreach ($celdas as $celda) {
                if ($celda['span'] === 1 && $col < $cols) {
                    $texto = trim($celda['td']->textContent);
                    $suma[$col] += min(120, \core_text::strlen($texto));
                    $cuenta[$col]++;
                    foreach (preg_split('/\s+/u', $texto) as $w) {
                        $palabra[$col] = max($palabra[$col], min(18, \core_text::strlen($w)));
                    }
                }
                $col += $celda['span'];
            }
        }
        $pesos = [];
        $minimos = [];
        for ($c = 0; $c < $cols; $c++) {
            $pesos[] = max(8, min(60, $cuenta[$c] ? $suma[$c] / $cuenta[$c] : 8));
            $minimos[] = max(700, $palabra[$c] * 105 + 280);
        }
        return [$pesos, $minimos];
    }

    /**
     * @param array $celdas
     * @return bool Si solo la primera celda de la fila tiene texto.
     */
    private function solo_primera(array $celdas): bool {
        if (trim($celdas[0]['td']->textContent) === '') {
            return false;
        }
        foreach (array_slice($celdas, 1) as $celda) {
            if (trim($celda['td']->textContent) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Si la primera fila es de encabezado: todas sus celdas son th, o esta en un thead.
     *
     * @param \DOMElement $tabla
     * @param array $filas
     * @return bool
     */
    private function tiene_encabezado(\DOMElement $tabla, array $filas): bool {
        $primera = $filas[0];
        foreach ($primera as $celda) {
            if (strtolower($celda['td']->nodeName) !== 'th') {
                return $tabla->getElementsByTagName('thead')->length > 0;
            }
        }
        return true;
    }

    /**
     * Celda de flecha entre «Vacío» y «Solución».
     *
     * @param bool $esenc
     * @param int $cuerpo
     * @param int $ancho
     * @return string
     */
    private function celda_flecha(bool $esenc, int $cuerpo, int $ancho): string {
        $bordes = $esenc ? '<w:right w:val="single" w:sz="8" w:space="0" w:color="FFFFFF"/>'
            : '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="' . self::BORDE . '"/>';
        $fondo = $esenc ? self::NAVY : 'FFFFFF';
        return '<w:tc><w:tcPr><w:tcW w:w="' . $ancho . '" w:type="dxa"/><w:tcBorders>' . $bordes . '</w:tcBorders>'
            . '<w:shd w:val="clear" w:color="auto" w:fill="' . $fondo . '"/><w:vAlign w:val="center"/></w:tcPr>'
            . $this->p($esenc ? '' : $this->run('➜', ['b' => 1, 'color' => self::AZUL, 'sz' => 32]), ['jc' => 'center']) . '</w:tc>';
    }

    /**
     * Celda de una tabla.
     *
     * @param \DOMElement $td
     * @param bool $esenc Celda del encabezado.
     * @param int $cuerpo Numero de fila del cuerpo (0 en adelante).
     * @param int $col Columna donde empieza.
     * @param int $span Columnas que ocupa.
     * @param int[] $anchos
     * @param bool $diagrama Tabla de diagrama de cajas y flechas.
     * @param bool $vacio Tabla «Vacío / Solución».
     * @param bool $primera Primera celda de la fila.
     * @param bool $mantener Mantener la fila junto a la siguiente (tablas cortas y diagramas no se parten).
     * @return string
     */
    private function celda(\DOMElement $td, bool $esenc, int $cuerpo, int $col, int $span, array $anchos, bool $diagrama,
            bool $vacio, bool $primera, bool $mantener = false): string {
        $ancho = array_sum(array_slice($anchos, $col, $span));
        $th = strtolower($td->nodeName) === 'th';
        $texto = trim(preg_replace('/\s+/u', ' ', $td->textContent));
        $fmt = ['sz' => 18];
        $o = ['spacing' => 'w:after="40" w:line="252" w:lineRule="auto"'];
        $borde = '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="single" w:sz="4" w:space="0" w:color="' . self::BORDE . '"/>'
            . '<w:right w:val="nil"/>';
        $fondo = '';
        $mar = '<w:top w:w="80" w:type="dxa"/><w:left w:w="110" w:type="dxa"/><w:bottom w:w="80" w:type="dxa"/><w:right w:w="110" w:type="dxa"/>';
        if ($diagrama) {
            $e = diseno::w_celda($td);
            $flecha = preg_match('/^[→←↑↓]$/u', $texto) === 1;
            $borde = $e['borde'] !== '' ? '<w:top w:val="single" w:sz="8" w:space="0" w:color="' . $e['borde'] . '"/><w:left w:val="single" w:sz="8" w:space="0" w:color="'
                . $e['borde'] . '"/><w:bottom w:val="single" w:sz="8" w:space="0" w:color="' . $e['borde'] . '"/><w:right w:val="single" w:sz="8" w:space="0" w:color="'
                . $e['borde'] . '"/>' : '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/>';
            $fondo = $e['fondo'];
            $fmt = $flecha ? ['sz' => 36, 'b' => 1, 'color' => $e['color'] ?: self::AZUL] : ['sz' => 20];
            $o['jc'] = 'center';
            $mar = '<w:top w:w="100" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/>';
        } else if ($esenc) {
            $fmt = ['sz' => 18, 'b' => 1, 'color' => 'FFFFFF'];
            $fondo = self::NAVY;
            $borde = '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="single" w:sz="8" w:space="0" w:color="FFFFFF"/>';
            $o['keepNext'] = 1;
            $mar = '<w:top w:w="70" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="70" w:type="dxa"/><w:right w:w="100" w:type="dxa"/>';
        } else if ($th) {
            // Fila de totales dentro del cuerpo (por ejemplo «Puntaje total»).
            $fmt = ['sz' => 18, 'b' => 1, 'color' => self::NAVY];
            $fondo = self::BANDA;
        } else if ($primera && !$vacio) {
            $fmt = ['sz' => 18, 'b' => 1, 'color' => self::NAVY];
            $fondo = self::BANDA;
        } else if ($vacio && $col === 0) {
            $fondo = self::BANDA;
        } else if ($cuerpo % 2 === 1) {
            $fondo = self::ZEBRA;
        }
        if ($vacio && !$esenc && $col > 0) {
            $borde = '<w:top w:val="nil"/><w:left w:val="single" w:sz="24" w:space="0" w:color="' . self::AZUL . '"/><w:bottom w:val="single" w:sz="4" w:space="0" w:color="'
                . self::BORDE . '"/><w:right w:val="nil"/>';
        }
        if (!$esenc && !$diagrama && preg_match('/^[\d.,]+\s*(\/\s*\d+|pts?|min|%)?$/u', $texto)) {
            $o['jc'] = 'center';
        }
        if ($mantener) {
            $o['keepNext'] = 1;
        }
        $contenido = $this->contenido_celda($td, $fmt, $o);
        return '<w:tc><w:tcPr><w:tcW w:w="' . $ancho . '" w:type="dxa"/>' . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '')
            . '<w:tcBorders>' . $borde . '</w:tcBorders>' . ($fondo !== '' ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fondo . '"/>' : '')
            . '<w:tcMar>' . $mar . '</w:tcMar><w:vAlign w:val="' . ($esenc || $diagrama ? 'center' : 'top') . '"/></w:tcPr>'
            . ($contenido !== '' ? $contenido : '<w:p/>') . '</w:tc>';
    }

    /**
     * Parrafos de una celda: su texto en linea, o sus parrafos y listas.
     *
     * @param \DOMElement $td
     * @param array $fmt
     * @param array $o Propiedades de parrafo.
     * @return string
     */
    private function contenido_celda(\DOMElement $td, array $fmt, array $o): string {
        $xml = '';
        $suelto = [];
        $vaciar = function() use (&$suelto, &$xml, $fmt, $o) {
            if ($this->plain($suelto) !== '') {
                $xml .= $this->p($this->runs($suelto, $fmt), $o + ['spacing' => 'w:after="40"']);
            }
            $suelto = [];
        };
        foreach ($td->childNodes as $h) {
            $tag = $h instanceof \DOMElement ? strtolower($h->nodeName) : '';
            if ($tag === 'ul' || $tag === 'ol') {
                $vaciar();
                $i = 0;
                foreach ($h->childNodes as $li) {
                    if ($li instanceof \DOMElement && strtolower($li->nodeName) === 'li') {
                        $i++;
                        $marca = $tag === 'ol' ? $i . '. ' : '• ';
                        $xml .= $this->p($this->run($marca, $fmt + ['color' => self::AZUL]) . $this->runs($this->inline($li), $fmt),
                            ['spacing' => 'w:after="30" w:line="252" w:lineRule="auto"', 'ind' => 'w:left="220" w:hanging="220"']);
                    }
                }
                continue;
            }
            if ($tag === 'p' || $tag === 'div') {
                $vaciar();
                $suelto = $this->inline($h, []);
                $vaciar();
                continue;
            }
            $caja = $h->ownerDocument->createElement('div');
            $caja->appendChild($h->cloneNode(true));
            $suelto = array_merge($suelto, $this->inline($caja, []));
        }
        $vaciar();
        return $xml;
    }
}

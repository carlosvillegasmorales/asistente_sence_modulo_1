<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Diagramas de cajas y flechas de los productos del Modulo 2 (pedido del cliente del 02-11-2026).
 *
 * El modelo dibujaba diagramas con caracteres (│ ─ ┌ ▼) dentro de un bloque de codigo: en el editor se ven, pero al
 * descargar el Word se desarman. Ahora los entrega como un bloque «diagrama» con una linea por caja, y este codigo lo
 * convierte en una tabla de cajas con color y flechas entre celdas: se edita en el editor, sale como tabla en el Word
 * y no depende de nada del servidor. Todo es funcion pura.
 *
 * Formato del bloque:
 *   ```diagrama
 *   modo: ciclo            (opcional: flujo, por defecto, o ciclo)
 *   titulo: Rotacion       (opcional)
 *   Estacion A / Aseo personal (criterios 1.5 y 1.6)
 *   Estacion B / Vestimenta
 *   ```
 * Cada linea es una caja, «Titulo / detalle» (el detalle es opcional); varias cajas en una linea van separadas por «|».
 * Las cajas se ordenan en filas en zigzag: la flecha va de una caja a la siguiente y baja al cambiar de fila. En
 * «ciclo» las filas son de dos cajas y, si son dos filas, una flecha final vuelve a la primera caja.
 */
class diagrama {

    /** @var int Cajas minimas y maximas de un diagrama. */
    const MIN_CAJAS = 2;
    const MAX_CAJAS = 12;

    /** @var string Color de fondo y de borde de las cajas (azul institucional claro). */
    const FONDO = '#E8F0F7';
    const BORDE = '#20639B';

    /** @var string Color del texto principal y del rotulo de las tarjetas. */
    const TEXTO = '#1F4E79';
    const ROTULO = '#4A6A8A';

    /** @var string Palabras con que empieza el rotulo de una caja («Momento 1», «Estación A», «Paso 3»). */
    const ROTULOS = '(?:paso|momento|fase|etapa|estaci[oó]n|actividad|m[oó]dulo|nivel|parte|tarjeta)';

    /** @var string Caracteres con que se dibujan cajas y lineas. */
    const ARTE = '│─┌┐└┘├┤┬┴┼▼▲►◄┃━┏┓┗┛╔╗╚╝║═';

    /**
     * Lee el cuerpo de un bloque «diagrama».
     *
     * @param string $cuerpo
     * @return array|null ['modo' => flujo|ciclo, 'titulo' => string, 'cajas' => [['titulo' => string, 'detalle' => string]]];
     *                    null si no tiene entre MIN_CAJAS y MAX_CAJAS cajas.
     */
    public static function parse(string $cuerpo): ?array {
        $modo = 'flujo';
        $titulo = '';
        $cajas = [];
        foreach (preg_split('/\R/u', $cuerpo) as $linea) {
            $linea = trim($linea);
            if ($linea === '') {
                continue;
            }
            if (preg_match('/^modo\s*:\s*(\S+)\s*$/iu', $linea, $m)) {
                $modo = \core_text::strtolower($m[1]) === 'ciclo' ? 'ciclo' : 'flujo';
                continue;
            }
            if (preg_match('/^t[ií]tulo\s*:\s*(.+)$/iu', $linea, $m)) {
                $titulo = trim($m[1]);
                continue;
            }
            // Una linea que solo dibuja la union entre cajas (│, ▼, ↓, |) no es una caja: las flechas las pone la tabla.
            // Visto en el centro de produccion el 19-11-2026: 5 cajas y 8 lineas de conectores pasaban de 12 «cajas»
            // y el diagrama salia como lista de viñetas con «│» y «▼».
            if (self::is_connector($linea)) {
                continue;
            }
            foreach (explode('|', $linea) as $trozo) {
                // Marcas de lista o de flecha al comienzo de la caja.
                $trozo = trim(preg_replace('/^(?:[-*•]|-+>|[→⇒►]|\d+[.)])\s*/u', '', trim($trozo)));
                // Corchetes que envuelven la caja: «[Momento 1: Higiene previa de manos]».
                $trozo = trim(preg_replace('/^\[(.+)\]$/u', '$1', $trozo));
                if ($trozo === '' || self::is_connector($trozo)) {
                    continue;
                }
                if (strpos($trozo, ' / ') === false
                        && preg_match('/^(' . self::ROTULOS . '\s+[^\s:]{1,4})\s*[:.\x{2013}\x{2014}-]\s+(.+)$/iu', $trozo, $r)) {
                    $cajas[] = ['titulo' => trim($r[1]), 'detalle' => trim($r[2])];
                    continue;
                }
                $partes = explode(' / ', $trozo, 2);
                $cajas[] = ['titulo' => trim($partes[0]), 'detalle' => trim($partes[1] ?? '')];
            }
        }
        $n = count($cajas);
        return $n >= self::MIN_CAJAS && $n <= self::MAX_CAJAS ? ['modo' => $modo, 'titulo' => $titulo, 'cajas' => $cajas] : null;
    }

    /**
     * Cajas por fila: dos en un ciclo; en un flujo, todas si son hasta cuatro y tres por fila si son mas.
     *
     * @param string $modo
     * @param int $n
     * @return int
     */
    public static function per_row(string $modo, int $n): int {
        return $modo === 'ciclo' ? 2 : ($n <= 4 ? $n : 3);
    }

    /**
     * Cuadricula del diagrama: filas de celdas (caja, flecha, caja, ...) y filas de conexion entre filas de cajas.
     *
     * @param array $d Resultado de parse().
     * @return array Lista de filas; cada fila es una lista de celdas ['t' => 'caja'|'flecha'|'vacia', 'c' => caja|texto].
     */
    public static function grid(array $d): array {
        $cajas = $d['cajas'];
        $n = count($cajas);
        $per = self::per_row($d['modo'], $n);
        $filas = (int) ceil($n / $per);
        $ancho = $per * 2 - 1;
        $vacia = static function() use ($ancho): array {
            return array_fill(0, $ancho, ['t' => 'vacia', 'c' => '']);
        };
        // Lugar (columna de caja) de la caja j de la fila r: las filas impares van de derecha a izquierda.
        $slot = static function(int $r, int $j) use ($per): int {
            return $r % 2 === 0 ? $j : $per - 1 - $j;
        };
        $salida = [];
        for ($r = 0; $r < $filas; $r++) {
            $fila = $vacia();
            $enfila = array_slice($cajas, $r * $per, $per);
            $m = count($enfila);
            foreach ($enfila as $j => $caja) {
                $fila[$slot($r, $j) * 2] = ['t' => 'caja', 'c' => $caja];
                if ($j < $m - 1) {
                    $desde = $slot($r, $j);
                    $hasta = $slot($r, $j + 1);
                    $fila[min($desde, $hasta) * 2 + 1] = ['t' => 'flecha', 'c' => $r % 2 === 0 ? '→' : '←'];
                }
            }
            $salida[] = $fila;
            if ($r < $filas - 1) {
                $conexion = $vacia();
                $conexion[$slot($r, $m - 1) * 2] = ['t' => 'flecha', 'c' => '↓'];
                // En un ciclo de dos filas completas, la ultima caja vuelve a la primera.
                if ($d['modo'] === 'ciclo' && $filas === 2 && $n === $per * 2) {
                    $conexion[$slot(1, $per - 1) * 2] = ['t' => 'flecha', 'c' => '↑'];
                }
                $salida[] = $conexion;
            }
        }
        return $salida;
    }

    /**
     * Tabla HTML del diagrama, con estilos en linea (el editor y el Word los conservan).
     *
     * @param array $d Resultado de parse().
     * @return string
     */
    public static function render_html(array $d): string {
        $e = static function(string $t): string {
            return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        };
        $per = self::per_row($d['modo'], count($d['cajas']));
        $ancho = (int) floor(100 / $per) - 4;
        foreach ($d['cajas'] as $i => $c) {
            $d['cajas'][$i]['n'] = $i + 1;
        }
        $html = ($d['titulo'] !== '' ? '<p><strong>' . $e($d['titulo']) . '</strong></p>' : '')
            . '<table class="m2-diagrama" style="width: 100%; border-collapse: separate; border-spacing: 0;"><tbody>';
        foreach (self::grid($d) as $fila) {
            $html .= '<tr>';
            foreach ($fila as $celda) {
                if ($celda['t'] === 'caja') {
                    // Tarjeta: rotulo pequeño en mayusculas («MOMENTO 1», o «PASO n» en una secuencia sin numeros), texto
                    // principal destacado y el detalle debajo.
                    $caja = $celda['c'];
                    $rotulo = '';
                    $principal = $caja['titulo'];
                    $detalle = $caja['detalle'];
                    if ($detalle !== '' && preg_match('/^' . self::ROTULOS . '\s+\S{1,4}$/iu', $principal)) {
                        [$rotulo, $principal, $detalle] = [$principal, $detalle, ''];
                    } else if ($d['modo'] === 'flujo' && empty($d['sinpaso']) && !preg_match('/\d/u', $principal)) {
                        $rotulo = 'Paso ' . (int) ($caja['n'] ?? 0);
                    }
                    $html .= '<td style="width: ' . $ancho . '%; background-color: ' . self::FONDO . '; border: 1px solid '
                        . self::BORDE . '; border-radius: 10px; padding: 10px 12px; text-align: center; vertical-align: middle;">'
                        . ($rotulo !== '' ? '<span style="font-size: 10px; letter-spacing: 1px; color: ' . self::ROTULO . ';">'
                            . $e(\core_text::strtoupper($rotulo)) . '</span><br>' : '')
                        . '<strong style="color: ' . self::TEXTO . ';">' . $e($principal) . '</strong>'
                        . ($detalle !== '' ? '<br><span style="font-size: 12px;">' . $e($detalle) . '</span>' : '')
                        . '</td>';
                } else if ($celda['t'] === 'flecha') {
                    $html .= '<td style="width: 4%; text-align: center; vertical-align: middle; font-size: 20px; color: '
                        . self::BORDE . ';"><strong>' . $celda['c'] . '</strong></td>';
                } else {
                    $html .= '<td style="width: 4%;">&nbsp;</td>';
                }
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /**
     * Texto en mayusculas sostenidas a tipo oracion («ENVEJECIMIENTO NORMAL (FISIOLÓGICO)» → «Envejecimiento normal
     * (fisiológico)»); un texto con minusculas no se toca. Funcion pura.
     *
     * @param string $t
     * @return string
     */
    public static function sentence(string $t): string {
        $t = trim($t);
        if ($t === '' || \core_text::strtoupper($t) !== $t || !preg_match('/\p{Lu}{4}/u', $t)) {
            return $t;
        }
        $t = \core_text::strtolower($t);
        return (string) preg_replace_callback('/^([^\p{L}]*)(\p{L})/u', static fn($m) => $m[1] . \core_text::strtoupper($m[2]), $t);
    }

    /**
     * Cajas de un dibujo ASCII («+-----+», «| texto |», «v»): grupos de lineas con texto separados por bordes, y en cada
     * grupo una columna por caja lado a lado. Cada caja trae su titulo, su descripcion y sus viñetas («•», «-»). Funcion pura.
     *
     * @param string[] $lineas
     * @return array[] Grupos; cada grupo es una lista de cajas ['titulo' => string, 'detalle' => string, 'items' => string[]].
     */
    public static function ascii_groups(array $lineas): array {
        $lineas = array_values(array_filter(array_map('rtrim', $lineas), static fn($l) => trim($l) !== ''));
        $grupos = [];
        $actual = [];
        $n = count($lineas);
        for ($i = 0; $i < $n; $i++) {
            $l = $lineas[$i];
            // Bordes y conectores («+----+», «|», «v   v», «+ v») no son texto de una caja.
            if (!preg_match('/[\p{L}\d]/u', $l) || preg_match('/^[\s|+\-=vV^↓▼]*$/u', $l)) {
                // Una fila vacia dentro de una caja («|        |     |        |») no la corta.
                $siguiente = $lineas[$i + 1] ?? '';
                if ($actual && preg_match('/^\s*\|[\s|]*\|\s*$/u', $l) && substr_count($l, '|') === substr_count($actual[count($actual) - 1], '|')
                        && preg_match('/[\p{L}\d]/u', $siguiente) && strpos(ltrim($siguiente), '|') === 0) {
                    continue;
                }
                if ($actual) {
                    $grupos[] = $actual;
                    $actual = [];
                }
                continue;
            }
            $actual[] = $l;
        }
        if ($actual) {
            $grupos[] = $actual;
        }
        $salida = [];
        foreach ($grupos as $g) {
            $columnas = [];
            foreach ($g as $l) {
                $celdas = preg_match_all('/\|([^|]*)\|/u', $l, $m) ? $m[1] : [trim($l, " \t|")];
                foreach ($celdas as $c => $texto) {
                    $columnas[$c][] = trim($texto);
                }
            }
            $cajas = [];
            foreach ($columnas as $textos) {
                $titulo = [];
                $items = [];
                foreach ($textos as $t) {
                    if ($t === '') {
                        continue;
                    }
                    if (preg_match('/^[•·*\-–]\s*(.+)$/u', $t, $m)) {
                        $items[] = trim($m[1]);
                    } else if ($items) {
                        $items[count($items) - 1] .= ' ' . $t;
                    } else {
                        $titulo[] = $t;
                    }
                }
                if (!$titulo && !$items) {
                    continue;
                }
                // Titulo en corchetes o en mayusculas y luego una descripcion: la primera linea es el titulo.
                $detalle = '';
                if (count($titulo) > 1 && (preg_match('/^\[.+\]$/u', $titulo[0]) || \core_text::strtoupper($titulo[0]) === $titulo[0])
                        && \core_text::strtoupper($titulo[1]) !== $titulo[1]) {
                    $detalle = implode(' ', array_slice($titulo, 1));
                    $titulo = [$titulo[0]];
                }
                $cajas[] = ['titulo' => self::sentence(trim(implode(' ', $titulo), '[] ')), 'detalle' => $detalle, 'items' => $items];
            }
            if ($cajas) {
                $salida[] = $cajas;
            }
        }
        return $salida;
    }

    /**
     * HTML de un dibujo ASCII: cajas encadenadas de a una → diagrama de pasos; cajas lado a lado → tarjetas en columnas;
     * una caja sola sin contenido → rotulo. null si no se reconocen cajas. Funcion pura.
     *
     * @param string[] $lineas
     * @return string|null
     */
    public static function ascii_html(array $lineas): ?string {
        $grupos = self::ascii_groups($lineas);
        if (!$grupos) {
            return null;
        }
        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        // Todas las cajas de a una, con contenido: es una secuencia.
        $solas = array_filter($grupos, static fn($g) => count($g) === 1);
        if (count($solas) === count($grupos) && count($grupos) >= self::MIN_CAJAS && count($grupos) <= self::MAX_CAJAS) {
            $inicio = 0;
            $titulo = '';
            $primera = $grupos[0][0];
            if (!$primera['items'] && $primera['detalle'] === '' && count($grupos) > self::MIN_CAJAS) {
                $titulo = $primera['titulo'];
                $inicio = 1;
            }
            $cajas = [];
            foreach (array_slice($grupos, $inicio) as $g) {
                $c = $g[0];
                $cajas[] = ['titulo' => $c['titulo'], 'detalle' => trim($c['detalle'] . ($c['items'] ? ' ' . implode('; ', $c['items']) : ''))];
            }
            return self::render_html(['modo' => 'flujo', 'titulo' => $titulo, 'cajas' => $cajas, 'sinpaso' => true]);
        }
        $html = '';
        foreach ($grupos as $g) {
            if (count($g) === 1 && !$g[0]['items'] && $g[0]['detalle'] === '') {
                $html .= '<p style="margin: 12px 0 4px 0; color: ' . self::TEXTO . ';"><strong>' . $e($g[0]['titulo']) . '</strong></p>';
                continue;
            }
            $ancho = (int) floor(100 / count($g));
            $html .= '<table class="m2-tarjetas" style="width: 100%; border-collapse: separate; border-spacing: 8px;"><tbody><tr>';
            foreach ($g as $c) {
                $html .= '<td style="width: ' . $ancho . '%; background-color: #F4F8FC; border: 1px solid ' . self::BORDE
                    . '; border-radius: 10px; padding: 10px 12px; vertical-align: top;">'
                    . '<strong style="color: ' . self::TEXTO . ';">' . $e($c['titulo']) . '</strong>'
                    . ($c['detalle'] !== '' ? '<br>' . $e($c['detalle']) : '')
                    . ($c['items'] ? '<ul>' . implode('', array_map(static fn($i) => '<li>' . $e($i) . '</li>', $c['items'])) . '</ul>' : '')
                    . '</td>';
            }
            $html .= '</tr></tbody></table>';
        }
        return $html;
    }

    /**
     * Listas que son un dibujo ASCII guardado linea por linea («+-----+», «| ENVEJECIMIENTO NORMAL |», «v»), como las que
     * dejo el modelo en el centro de produccion (visto en el M3 del cliente el 19-11-2026, que el PDF mostraba como una
     * tabla rota): se dibujan como tarjetas o pasos. Las demas listas no se tocan. Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function ascii_lists(string $html): string {
        if (stripos($html, '<li') === false || strpos($html, '+-') === false) {
            return $html;
        }
        return (string) preg_replace_callback('#<(ul|ol)\b[^>]*>((?:(?!</?(?:ul|ol)\b).)*)</\1>#isu',
            static function(array $m): string {
                if (!preg_match_all('#<li\b[^>]*>(.*?)</li>#isu', $m[2], $li)) {
                    return $m[0];
                }
                $lineas = array_map(static fn($t) => html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $li[1]);
                $bordes = count(preg_grep('/^\s*\+[-=+]{3,}/u', $lineas));
                $dibujo = count(array_filter($lineas, static fn($l) => preg_match('/^\s*[+|]|^[\s|vV^]+$/u', $l) === 1));
                if (count($lineas) < 4 || $bordes < 2 || $dibujo < 0.6 * count($lineas)) {
                    return $m[0];
                }
                return self::ascii_html($lineas) ?? $m[0];
            }, $html);
    }

    /**
     * Si un texto solo dibuja la union entre cajas: lineas y flechas (│ | ▼ ↓ → ─ -->) sin palabras.
     *
     * @param string $texto
     * @return bool
     */
    public static function is_connector(string $texto): bool {
        $texto = trim($texto);
        return $texto !== '' && preg_match('/^[\s' . preg_quote(self::ARTE, '/')
            . '|\/\\\\\-=_<>+\x{2013}\x{2014}→←↑↓⇒⇐⇑⇓⬇⬆➜➔➝⟶⟵]+$/u', $texto) === 1;
    }

    /**
     * Listas que en realidad son un flujo: viñetas con pasos y viñetas que solo traen conectores («│», «▼»), como las
     * que dejo el modelo antes de este arreglo. Se dibujan como diagrama de tarjetas con flechas; las demas listas no se
     * tocan. Sirve tambien para lo ya guardado, porque se aplica al mostrar y al descargar.
     *
     * @param string $html
     * @return string
     */
    public static function list_flows(string $html): string {
        if (stripos($html, '<li') === false) {
            return $html;
        }
        return (string) preg_replace_callback('#<(ul|ol)\b[^>]*>((?:(?!</?(?:ul|ol)\b).)*)</\1>#isu',
            static function(array $m): string {
                if (!preg_match_all('#<li\b[^>]*>(.*?)</li>#isu', $m[2], $li)) {
                    return $m[0];
                }
                $cajas = [];
                $conectores = 0;
                foreach ($li[1] as $item) {
                    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($item), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    if ($t === '') {
                        continue;
                    }
                    if (self::is_connector($t)) {
                        $conectores++;
                    } else {
                        $cajas[] = str_replace('|', '/', $t);
                    }
                }
                if ($conectores === 0) {
                    return $m[0];
                }
                $d = self::parse(implode("\n", $cajas));
                return $d === null ? $m[0] : self::render_html($d);
            }, $html);
    }

    /**
     * Si un texto tiene dibujo hecho con caracteres (cajas, lineas y flechas): al menos tres de ellos.
     *
     * @param string $texto
     * @return bool
     */
    public static function has_art(string $texto): bool {
        return preg_match_all('/[' . preg_quote(self::ARTE, '/') . ']|^\s*[+|][-=+]{3,}[+|]?\s*$/mu', $texto) >= 3;
    }

    /**
     * Lineas de texto de un dibujo de caracteres, sin los trazos: lo que decia cada caja, en el orden en que aparece.
     *
     * @param string $texto
     * @return string[]
     */
    public static function art_lines(string $texto): array {
        $trazos = '/[' . preg_quote(self::ARTE, '/') . '→←↑↓⇒⇐⇑⇓]|^\s*[+|][-=+]{3,}[+|]?\s*$|-{2,}>?|<-{2,}/mu';
        $lineas = [];
        // Cada trazo separa: dos cajas lado a lado son dos textos, no uno.
        foreach (preg_split('/\R/u', preg_replace($trazos, '  ', $texto)) as $l) {
            foreach (preg_split('/\s{2,}/u', trim($l)) as $segmento) {
                $segmento = trim($segmento);
                if ($segmento !== '' && !in_array($segmento, $lineas, true)) {
                    $lineas[] = $segmento;
                }
            }
        }
        return $lineas;
    }

    /**
     * Filas de una tabla dibujada con caracteres de caja (┌─┬─┐, │, ├─┼─┤, └─┴─┘): cada fila es la lista de sus celdas y
     * cada celda, la lista de sus parrafos. Las columnas de una fila salen de los cruces (┬ ┼) del borde que tiene encima;
     * una caja dibujada dentro de una celda separa parrafos. null si el texto no es una tabla de ese tipo.
     *
     * @param string $texto
     * @return array|null
     */
    public static function art_rows(string $texto): ?array {
        $r = self::art_parse($texto);
        return $r === null ? null : $r[0];
    }

    /**
     * Lee una tabla dibujada (ver art_rows()) y devuelve tambien, por fila, las posiciones de sus bordes de columna.
     *
     * @param string $texto
     * @return array|null [filas, bordes por fila]
     */
    private static function art_parse(string $texto): ?array {
        $lineas = array_values(array_filter(preg_split('/\R/u', $texto), static function(string $l): bool {
            return trim($l) !== '';
        }));
        if (count($lineas) < 3 || !in_array(self::first_char($lineas[0]), ['┌', '╔', '+'], true)
                || !in_array(self::first_char($lineas[count($lineas) - 1]), ['└', '╚', '+'], true)) {
            return null;
        }
        $filas = [];
        $fila = null;
        $limites = [];
        $bordes = [];
        foreach ($lineas as $linea) {
            $c = preg_split('//u', rtrim($linea), -1, PREG_SPLIT_NO_EMPTY);
            $ini = self::first_char($linea);
            // Tambien la tabla en ASCII: bordes «+----+----+» y celdas entre «|».
            if (in_array($ini, ['┌', '├', '└', '╔', '╠', '╚'], true)
                    || ($ini === '+' && preg_match('/^\s*\+[-=+\s]*\+\s*$/', $linea))) {
                // Borde de la tabla: cierra la fila y fija las columnas de la siguiente.
                if ($fila !== null) {
                    $filas[] = $fila;
                    $fila = null;
                }
                $pos = (int) array_search($ini, $c, true);
                $limites = [$pos];
                foreach ($c as $i => $ch) {
                    if ($i > $pos && $i < count($c) - 1 && in_array($ch, ['┬', '┼', '╦', '╬', '+'], true)) {
                        $limites[] = $i;
                    }
                }
                $limites[] = count($c) - 1;
                continue;
            }
            if ($ini !== '│' && $ini !== '║' && $ini !== '|') {
                return null;
            }
            $barras = [];
            foreach ($c as $i => $ch) {
                if ($ch === '│' || $ch === '║' || $ch === '|') {
                    $barras[] = $i;
                }
            }
            // Cortes: el borde izquierdo, la barra mas cercana a cada cruce (el texto puede correrla un caracter) y la ultima.
            $cortes = [$barras[0]];
            for ($k = 1; $k < count($limites) - 1; $k++) {
                $mejor = null;
                foreach ($barras as $b) {
                    if ($b > end($cortes) && abs($b - $limites[$k]) <= 3
                            && ($mejor === null || abs($b - $limites[$k]) < abs($mejor - $limites[$k]))) {
                        $mejor = $b;
                    }
                }
                $cortes[] = $mejor ?? max(end($cortes) + 1, $limites[$k]);
            }
            $ultima = $barras[count($barras) - 1];
            $cortes[] = $ultima > end($cortes) ? $ultima : count($c);
            $trozos = [];
            for ($k = 0; $k < count($cortes) - 1; $k++) {
                $trozo = implode('', array_slice($c, $cortes[$k] + 1, max(0, $cortes[$k + 1] - $cortes[$k] - 1)));
                $trozos[] = trim(preg_replace('/[' . preg_quote(self::ARTE, '/') . ']/u', ' ', $trozo));
            }
            // En una tabla ASCII («| Rótulo | Valor |») cada linea con texto en la primera celda es una fila nueva, aunque
            // no haya borde entre filas; la linea con la primera celda vacia continua la fila anterior.
            if ($ini === '|' && $fila !== null && $trozos[0] !== '' && count($trozos) > 1
                    && array_filter(array_merge(...array_values($fila)), 'strlen')) {
                $filas[] = $fila;
                $fila = null;
            }
            if ($fila === null) {
                $fila = array_fill(0, count($cortes) - 1, []);
                $bordes[count($filas)] = $limites;
            }
            foreach ($trozos as $k => $t) {
                $fila[$k][] = $t;
            }
        }
        if ($fila !== null) {
            $filas[] = $fila;
        }
        $salida = [];
        $posiciones = [];
        foreach ($filas as $r => $fila) {
            $celdas = array_map([self::class, 'cell_paragraphs'], array_values($fila));
            if (array_filter($celdas)) {
                $salida[] = $celdas;
                $posiciones[] = $bordes[$r] ?? [];
            }
        }
        return $salida ? [$salida, $posiciones] : null;
    }

    /**
     * Parrafos de una celda dibujada: junta las lineas de un mismo parrafo y las palabras cortadas con guion
     * («Coevalua-» + «ción»). Empiezan parrafo nuevo una linea vacia o un trazo, una viñeta o un numero, y lo que sigue a
     * una linea en mayusculas (salvo un parentesis: «(60 minutos)» sigue a su titulo).
     *
     * @param string[] $lineas
     * @return string[]
     */
    public static function cell_paragraphs(array $lineas): array {
        $parrafos = [];
        $actual = '';
        $cerrar = false;
        foreach ($lineas as $l) {
            $l = trim(preg_replace('/\s+/u', ' ', $l));
            if ($l === '') {
                if ($actual !== '') {
                    $parrafos[] = $actual;
                    $actual = '';
                }
                $cerrar = false;
                continue;
            }
            $nuevo = preg_match('/^(?:[•▪◦*-]\s|\d+[.)]\s)/u', $l) === 1 || self::is_upper($l);
            if ($actual === '') {
                $actual = $l;
            } else if ($nuevo || ($cerrar && strpos($l, '(') !== 0)) {
                $parrafos[] = $actual;
                $actual = $l;
            } else if (preg_match('/\p{L}-$/u', $actual) && preg_match('/^\p{Ll}/u', $l)) {
                $actual = \core_text::substr($actual, 0, \core_text::strlen($actual) - 1) . $l;
            } else {
                $actual .= ' ' . $l;
            }
            $cerrar = self::is_upper($l);
        }
        if ($actual !== '') {
            $parrafos[] = $actual;
        }
        return $parrafos;
    }

    /**
     * Tabla HTML de una tabla dibujada con caracteres de caja (ver art_rows()). Si la primera fila es una sola celda sobre
     * varias columnas, es el titulo y va como encabezado; una fila con menos celdas extiende la ultima hasta el borde.
     *
     * @param string $texto
     * @return string|null null si el texto no es una tabla (o es una sola caja).
     */
    public static function art_table_html(string $texto): ?string {
        $leida = self::art_parse($texto);
        if ($leida === null) {
            return null;
        }
        [$filas, $bordes] = $leida;
        // Columnas de la cuadricula: todos los bordes interiores de todas las filas (los que distan uno o dos caracteres
        // son el mismo). Cada celda abarca las columnas que hay entre sus dos bordes.
        $interiores = [];
        foreach ($bordes as $b) {
            foreach (array_slice($b, 1, -1) as $x) {
                $interiores[] = $x;
            }
        }
        sort($interiores);
        $grilla = [];
        foreach ($interiores as $x) {
            if (!$grilla || $x - $grilla[count($grilla) - 1] > 2) {
                $grilla[] = $x;
            }
        }
        $cols = count($grilla) + 1;
        if (count($filas) < 2 && $cols < 2) {
            return null;
        }
        $html = '<table>';
        foreach ($filas as $r => $celdas) {
            $n = count($celdas);
            $tag = ($r === 0 && $n === 1 && $cols > 1) ? 'th' : 'td';
            $b = $bordes[$r];
            $html .= '<tr>';
            $usadas = 0;
            foreach ($celdas as $k => $parrafos) {
                if (count($b) === $n + 1) {
                    $span = 1;
                    foreach ($grilla as $x) {
                        if ($x > $b[$k] + 2 && $x < $b[$k + 1] - 2) {
                            $span++;
                        }
                    }
                } else {
                    $span = 1;
                }
                // La ultima celda cierra la fila: la cuadricula queda completa aunque un borde no calce.
                $span = $k === $n - 1 ? max(1, $cols - $usadas) : min($span, max(1, $cols - $usadas - ($n - 1 - $k)));
                $usadas += $span;
                $html .= '<' . $tag . ($span > 1 ? ' colspan="' . $span . '"' : '') . '>' . self::paragraphs_html($parrafos) . '</' . $tag . '>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    /**
     * Elementos de un arbol dibujado con ramas (├── y └──), con su nivel: 0 para las lineas sin rama y 1, 2... segun la
     * sangria de la rama. Se omiten las flechas y los trazos sueltos, y los corchetes que encierran un titulo.
     *
     * @param string $texto
     * @return array|null Lista de [nivel, texto]; null si no es un arbol (menos de dos ramas, o tiene cajas).
     */
    public static function tree_items(string $texto): ?array {
        if (preg_match('/[┌┐╔╗]/u', $texto)) {
            return null;
        }
        $items = [];
        $sangrias = [];
        $ramas = 0;
        foreach (preg_split('/\R/u', $texto) as $l) {
            if (preg_match('/^([\s│┃║]*)[├└╠╚┣┗][─━═-]*\s*(.*)$/u', $l, $m)) {
                $s = \core_text::strlen($m[1]);
                $sangrias[$s] = true;
                $items[] = [$s, $m[2]];
                $ramas++;
                continue;
            }
            $items[] = [-1, $l];
        }
        if ($ramas < 2) {
            return null;
        }
        ksort($sangrias);
        $orden = array_flip(array_keys($sangrias));
        $salida = [];
        foreach ($items as [$s, $t]) {
            $t = trim(preg_replace('/[' . preg_quote(self::ARTE, '/') . '→←↑↓⇒⇐⇑⇓]/u', ' ', $t));
            $t = trim(preg_replace(['/^\[([^\]]+)\]/u', '/\s{2,}/u'], ['$1', ' '], $t));
            if ($t !== '') {
                $salida[] = [$s < 0 ? 0 : 1 + $orden[$s], $t];
            }
        }
        return $salida;
    }

    /**
     * Si una linea esta escrita en mayusculas (sin contar lo que va entre parentesis).
     *
     * @param string $l
     * @return bool
     */
    public static function is_upper(string $l): bool {
        $letras = preg_replace('/[^\p{L}]/u', '', preg_replace('/\([^)]*\)/u', '', $l));
        $n = \core_text::strlen($letras);
        return $n >= 4 && preg_match_all('/\p{Lu}/u', $letras) / $n >= 0.8;
    }

    /**
     * Primer caracter que no es espacio.
     *
     * @param string $l
     * @return string
     */
    private static function first_char(string $l): string {
        return \core_text::substr(ltrim($l), 0, 1);
    }

    /**
     * HTML de los parrafos de una celda: las viñetas seguidas forman una lista y una linea en mayusculas va en negrita.
     *
     * @param string[] $parrafos
     * @return string
     */
    private static function paragraphs_html(array $parrafos): string {
        $esc = static function(string $t): string {
            return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        };
        $html = '';
        $lista = '';
        foreach ($parrafos as $p) {
            if (preg_match('/^[•▪◦*-]\s+(.*)$/u', $p, $m)) {
                $lista .= '<li>' . $esc($m[1]) . '</li>';
                continue;
            }
            if ($lista !== '') {
                $html .= '<ul>' . $lista . '</ul>';
                $lista = '';
            }
            $html .= '<p>' . (self::is_upper($p) ? '<strong>' . $esc($p) . '</strong>' : $esc($p)) . '</p>';
        }
        return $html . ($lista !== '' ? '<ul>' . $lista . '</ul>' : '');
    }

    /**
     * Saca del Markdown los bloques «diagrama» y los dibujos de caracteres para que no se desarmen.
     *
     * Cada bloque «diagrama» valido queda como un marcador (@@DIAGRAMA_n@@) que insert() cambia por la tabla despues de
     * pasar el Markdown a HTML. Un bloque de codigo con dibujo de caracteres se vuelve una lista con lo que decia cada
     * caja; un bloque «diagrama» que no se puede leer, tambien.
     *
     * @param string $markdown
     * @return array [Markdown sin los bloques, lista de tablas HTML por marcador].
     */
    public static function extract(string $markdown): array {
        $tablas = [];
        $texto = preg_replace_callback('/^```[ \t]*([A-Za-z]*)[ \t]*\R(.*?)\R```[ \t]*$/ms',
            static function(array $m) use (&$tablas): string {
                $lenguaje = \core_text::strtolower($m[1]);
                if ($lenguaje === 'diagrama') {
                    $d = self::parse($m[2]);
                    if ($d !== null) {
                        $tablas[] = self::render_html($d);
                        return '@@DIAGRAMA_' . (count($tablas) - 1) . '@@';
                    }
                    $lineas = array_map(static function($l) {
                        return preg_replace('/^(?:modo|t[ií]tulo)\s*:\s*.*$/iu', '', $l);
                    }, preg_split('/\R/u', $m[2]));
                    return self::as_list(array_filter(array_map('trim', $lineas)));
                }
                // Una tabla Markdown dentro de un bloque de codigo se deja como tabla.
                $filas = array_filter(array_map('trim', preg_split('/\R/u', $m[2])), 'strlen');
                if (count($filas) >= 2 && count(array_filter($filas, static function(string $f): bool {
                    return $f[0] === '|';
                })) === count($filas) && preg_grep('/^\|?\s*:?-{3,}/', $filas)) {
                    return "\n" . implode("\n", $filas) . "\n";
                }
                // Un dibujo de caracteres: la tabla dibujada vuelve a ser tabla, el arbol lista con niveles y lo demas lista.
                if (self::has_art($m[2])) {
                    $tablas[] = documento_final::pre_html($m[2]);
                    return '@@DIAGRAMA_' . (count($tablas) - 1) . '@@';
                }
                return $m[0];
            }, $markdown);
        return [$texto, $tablas];
    }

    /**
     * Vuelve a poner las tablas en el HTML que salio del Markdown.
     *
     * @param string $html
     * @param string[] $tablas Lista de extract().
     * @return string
     */
    public static function insert(string $html, array $tablas): string {
        foreach ($tablas as $i => $tabla) {
            $html = preg_replace('#<p>\s*@@DIAGRAMA_' . $i . '@@\s*</p>#', $tabla, $html, 1, $n);
            if (!$n) {
                $html = str_replace('@@DIAGRAMA_' . $i . '@@', $tabla, $html);
            }
        }
        return $html;
    }

    /**
     * Lista Markdown de viñetas.
     *
     * @param string[] $lineas
     * @return string
     */
    private static function as_list(array $lineas): string {
        $lineas = array_values(array_filter($lineas, static fn($l) => !self::is_connector((string) $l)));
        return $lineas ? implode("\n", array_map(static function($l) {
            return '- ' . $l;
        }, $lineas)) : '';
    }
}

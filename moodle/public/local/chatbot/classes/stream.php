<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Respuestas en vivo: lectura del flujo del proveedor y punto hasta donde se puede mostrar.
 *
 * El texto se libera por frases completas, y cada frase pasa por los guardrails de salida antes
 * de mostrarse: las reglas de bloqueo (respuesta del quiz, pistas en el diagnostico, datos
 * sensibles) miran dentro de una frase, asi que una frase que activaria una regla nunca llega a
 * la pantalla. Al terminar, la respuesta completa vuelve a revisarse y reemplaza a la mostrada.
 *
 * Todo lo de esta clase es logica pura.
 */
class stream {

    /**
     * Saca del buffer los eventos SSE completos y devuelve sus datos decodificados.
     *
     * Lo que queda incompleto se deja en el buffer para la siguiente vuelta.
     *
     * @param string $buffer Texto recibido y aun no procesado (se modifica).
     * @return array[] Datos JSON de cada evento completo.
     */
    public static function parse_sse(string &$buffer): array {
        $eventos = [];
        $buffer = str_replace("\r\n", "\n", $buffer);
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $bloque = substr($buffer, 0, $pos);
            $buffer = (string) substr($buffer, $pos + 2);
            $datos = '';
            foreach (explode("\n", $bloque) as $linea) {
                if (strpos($linea, 'data:') === 0) {
                    $datos .= ltrim(substr($linea, 5));
                }
            }
            if ($datos !== '') {
                $json = json_decode($datos, true);
                if (is_array($json)) {
                    $eventos[] = $json;
                }
            }
        }
        return $eventos;
    }

    /**
     * Hasta que byte del texto se puede mostrar: el final de la ultima frase completa.
     *
     * Una frase termina en salto de linea, o en punto, signo de interrogacion o de exclamacion
     * seguido de espacio o salto de linea (asi «3.5» no corta). Los separadores son de un byte,
     * de modo que el corte nunca parte un caracter UTF-8.
     *
     * @param string $text Texto acumulado.
     * @param int $from Lo que ya se mostro.
     * @param bool $final Si el flujo termino: entonces se puede mostrar todo.
     * @return int Nueva posicion (igual a $from si todavia no hay frase completa).
     */
    public static function release_point(string $text, int $from, bool $final): int {
        $largo = strlen($text);
        if ($final) {
            return $largo;
        }
        for ($i = $largo - 1; $i >= $from; $i--) {
            $c = $text[$i];
            if ($c === "\n") {
                return $i + 1;
            }
            if (($c === '.' || $c === '?' || $c === '!') && $i + 1 < $largo
                    && ($text[$i + 1] === ' ' || $text[$i + 1] === "\n")) {
                return $i + 1;
            }
        }
        return $from;
    }
}

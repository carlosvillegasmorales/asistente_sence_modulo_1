<?php
/**
 * Arranque minimo de los verificadores que prueban funciones puras.
 *
 * Los verificadores de cli/ corren sin base de datos ni bootstrap de Moodle,
 * para poder encadenarse a CI en cualquier maquina con PHP. Algunas funciones
 * puras usan get_string() o core_text; aqui se declaran dobles de esas dos
 * piezas, y nada mas.
 *
 * Sobre la fidelidad de los dobles:
 * - get_string() devuelve la clave entre corchetes: a una verificacion le
 *   importa que mensaje se eligio, no como se redacto.
 * - core_text reproduce la implementacion de Moodle con las mismas
 *   extensiones (mbstring, intl, iconv), no una aproximacion. specialtoascii
 *   usa la misma transliteracion Any-Latin; Latin-ASCII que lib/classes/text.php.
 */

define('MOODLE_INTERNAL', true);

if (!function_exists('get_string')) {
    /**
     * @param string $identifier
     * @param string $component
     * @param mixed $a
     * @return string
     */
    function get_string($identifier, $component = '', $a = null) {
        return '[[' . $identifier . ']]';
    }
}

if (!class_exists('core_text')) {
    /**
     * Doble de core_text con la misma implementacion que Moodle.
     */
    class core_text {
        /**
         * @param string $text
         * @return string
         */
        public static function strtolower($text) {
            return mb_strtolower((string) $text, 'UTF-8');
        }

        /**
         * @param string $text
         * @return string
         */
        public static function strtoupper($text) {
            return mb_strtoupper((string) $text, 'UTF-8');
        }

        /**
         * @param string $text
         * @param int $start
         * @param int|null $len
         * @return string
         */
        public static function substr($text, $start, $len = null) {
            return mb_substr((string) $text, $start, $len, 'UTF-8');
        }

        /**
         * @param string $text
         * @return int
         */
        public static function strlen($text) {
            return mb_strlen((string) $text, 'UTF-8');
        }

        /**
         * @param string $text
         * @return string
         */
        public static function specialtoascii($text) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII', (string) $text);
            return (string) iconv('utf-8', 'ASCII//TRANSLIT//IGNORE', $text);
        }
    }
}

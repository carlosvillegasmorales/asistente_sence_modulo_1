<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Traduce identidades reales a identificadores pseudonimizados antes de que
 * cualquier dato salga hacia el proveedor de IA.
 *
 * Las reglas de gobernanza del Modulo 1 son explicitas: "minimizar la
 * informacion personal compartida con el modelo de lenguaje" y "enviar al
 * asistente identificadores pseudonimizados y datos estrictamente necesarios
 * para cada tarea". La version anterior de este plugin hacia lo contrario:
 * mandaba el nombre real de la persona, los nombres de sus cursos y, en el
 * modo facilitador, nombres y correos completos de terceros.
 *
 * El seudonimo se deriva por hash del identificador del sitio mas el userid,
 * asi que es estable entre sesiones (el asistente puede referirse a la misma
 * persona a lo largo de una conversacion) pero no reversible desde afuera y
 * no requiere almacenar una tabla de correspondencia que a su vez habria que
 * proteger.
 */
class pseudonymizer {

    /** @var string Prefijo legible para que el modelo entienda que es una persona facilitadora. */
    const PREFIX = 'FAC-';

    /**
     * @param int $userid
     * @return string Identificador estable y no reversible, p. ej. "FAC-9f3a1c22".
     */
    public static function for_user(int $userid): string {
        global $CFG;
        $salt = isset($CFG->siteidentifier) ? $CFG->siteidentifier : $CFG->wwwroot;
        return self::PREFIX . substr(hash('sha256', $salt . '|user|' . $userid), 0, 8);
    }

    /**
     * Los nombres de curso tambien son datos del sitio que no aportan nada al
     * razonamiento pedagogico del asistente.
     *
     * @param int $courseid
     * @return string
     */
    public static function for_course(int $courseid): string {
        global $CFG;
        if ($courseid <= 0) {
            return 'CUR-000';
        }
        $salt = isset($CFG->siteidentifier) ? $CFG->siteidentifier : $CFG->wwwroot;
        return 'CUR-' . substr(hash('sha256', $salt . '|course|' . $courseid), 0, 6);
    }

    /**
     * Barrido defensivo del texto que se envia al modelo: aunque el prompt se
     * arma con seudonimos, la persona puede escribir su propio nombre o su
     * correo en el mensaje. Esto no puede ser perfecto, pero evita el caso
     * mas frecuente sin mutilar el contenido pedagogico.
     *
     * @param string $text
     * @return string
     */
    public static function scrub(string $text): string {
        // Correos electronicos.
        $text = preg_replace('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', '[correo omitido]', $text);
        // RUT chileno con o sin puntos, con guion y digito verificador.
        $text = preg_replace('/\b\d{1,2}\.?\d{3}\.?\d{3}\s*-\s*[\dkK]\b/', '[rut omitido]', $text);
        // Numeros telefonicos chilenos e internacionales (ERTF v3.0).
        $text = preg_replace('/(?:\+?56\s?)?(?:9\s?\d{4}\s?\d{4}|[2-9]\s?\d{3,4}\s?\d{4})\b/', '[telefono omitido]', $text);
        $text = preg_replace('/(?:\+\d{1,3}[\s-]|\b\d{1,3}[\s-])\(?\d{2,4}\)?[\s-]\d{3,4}[\s-]\d{3,4}\b/', '[telefono omitido]', $text);
        return $text;
    }
}

<?php
/**
 * Post-installation hook for local_chatbot.
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Carga el instrumento base del Modulo 1 apenas se instala el plugin:
 * dimensiones e items del diagnostico de brechas, criterios de la rubrica
 * del proyecto integrador y la version 1 de los prompts de cada modo.
 *
 * Sin esto el plugin quedaria instalado pero sin diagnostico que aplicar,
 * y la persona facilitadora no tendria por donde empezar.
 */
function xmldb_local_chatbot_install() {
    \local_chatbot\seeder::seed_defaults();
}

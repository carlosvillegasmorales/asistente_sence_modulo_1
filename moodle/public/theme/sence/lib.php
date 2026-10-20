<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Theme Sence library functions
 *
 * @package    theme_sence
 * @copyright  2026 ProyectoSence
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Returns the main SCSS content.
 *
 * @param theme_config $theme The theme config object.
 * @return string
 */
function theme_sence_get_main_scss_content($theme) {
    global $CFG;

    $scss = '';
    
    // 1. Obtener todo el SCSS base de Boost (Bootstrap + Moodle base)
    if (function_exists('theme_boost_get_main_scss_content')) {
        $scss .= theme_boost_get_main_scss_content($theme);
    } else {
        // En caso de que se necesite requerir lib de boost
        require_once($CFG->dirroot . '/theme/boost/lib.php');
        $scss .= theme_boost_get_main_scss_content($theme);
    }

    // 2. Añadir nuestro SCSS personalizado al final
    $scss .= file_get_contents($CFG->dirroot . '/theme/sence/scss/sence.scss');

    return $scss;
}

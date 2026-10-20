<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');

mtrace("Corrigiendo estilos visuales para Moodle 5.0 y reubicando el botón...");

$sence_blue = '#0f69b4';
$sence_red = '#e3001b';
$dark_blue = '#004b87';
$white = '#ffffff';

// Moodle 4.0+ / 5.0 usa .primary-navigation en lugar de .navbar
$scss = <<<SCSS
/* ==========================================================================
   IDENTIDAD VISUAL SENCE CHILE (GOB.CL) - MOODLE 5.0 FIX
   ========================================================================== */
@import url('https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap');

body {
    font-family: 'Roboto', sans-serif !important;
}

/* Barra Superior Principal (Moodle 5) */
.primary-navigation {
    background-color: {$sence_blue} !important;
    border-bottom: 4px solid {$sence_red} !important;
}

.primary-navigation .nav-link, 
.primary-navigation .navbar-brand,
.primary-navigation .site-name {
    color: {$white} !important;
}

.primary-navigation .nav-link:hover {
    background-color: {$dark_blue} !important;
    border-radius: 4px;
}

/* Botones Moodle 5 */
.btn-primary {
    background-color: {$sence_blue} !important;
    border-color: {$sence_blue} !important;
    border-radius: 4px !important;
    font-weight: 500;
}
.btn-primary:hover {
    background-color: {$dark_blue} !important;
}

/* Modo de Edición Switch */
.custom-control-input:checked ~ .custom-control-label::before {
    background-color: {$sence_blue} !important;
    border-color: {$sence_blue} !important;
}
SCSS;

set_config('scsspre', $scss, 'theme_boost');
mtrace("- SCSS Moodle 5 guardado.");

// Para el botón, ya que custommenuitems falla en Moodle 5 top bar, 
// lo inyectaremos por JavaScript global a través de 'additionalhtmlfooter'.
// Esto garantiza que aparezca en la barra superior sí o sí.
$js_menu = <<<HTML
<script>
document.addEventListener("DOMContentLoaded", function() {
    var primaryNav = document.querySelector('.primary-navigation .navigation, .primary-navigation .navbar-nav');
    if (primaryNav) {
        var li = document.createElement('li');
        li.className = 'nav-item';
        li.innerHTML = '<a href="/reporte_fase4.php" class="nav-link" style="color:white; font-weight:bold; background-color:#e3001b; padding: 8px 15px; border-radius: 4px; margin-left: 15px;">📊 Reporte Fase 4</a>';
        primaryNav.appendChild(li);
    }
});
</script>
HTML;

set_config('additionalhtmlfooter', $js_menu);
mtrace("- Script JS de inyección de menú guardado.");

purge_all_caches();
mtrace("Cachés limpiadas. ¡Listo!");

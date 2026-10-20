<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');

mtrace("Aplicando corrección visual final...");

$sence_blue = '#0f69b4';
$sence_red = '#e3001b';
$dark_blue = '#004b87';

// 1. Mover el botón al Menú de Usuario (desplegable superior derecho)
// Formato: Texto|URL|Icono
$user_menu = "Reporte Piloto Fase 4|/reporte_fase4.php|t/calc";
set_config('customusermenuitems', $user_menu, 'theme_boost');
mtrace("- Botón reubicado de forma elegante en el menú de usuario.");

// 2. Limpiar el JS feo que rompía la barra
set_config('additionalhtmlfooter', '');

// 3. SCSS Corregido para forzar la barra superior (Moodle 4.0/5.0)
$scss = <<<SCSS
@import url('https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap');

body {
    font-family: 'Roboto', sans-serif !important;
}

/* Forzar la barra de navegación principal */
nav.fixed-top, .navbar {
    background-color: {$sence_blue} !important;
    border-bottom: 4px solid {$sence_red} !important;
}

nav.fixed-top .navbar-brand,
nav.fixed-top .nav-link,
nav.fixed-top .site-name,
nav.fixed-top i.icon {
    color: #ffffff !important;
}

nav.fixed-top .nav-link:hover {
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
SCSS;

set_config('scsspre', $scss, 'theme_boost');
mtrace("- SCSS corregido inyectado.");

purge_all_caches();
mtrace("Cachés limpiadas. ¡Listo!");

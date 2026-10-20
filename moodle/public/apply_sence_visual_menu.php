<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');

mtrace("Iniciando aplicación de identidad visual SENCE (Gob.cl) 2026 y Botón de Reporte...");

// 1. Agregar el botón al Custom Menu
// El custommenuitems permite poner enlaces en el nav superior. 
// Formato: Texto|URL|Título (opcional)
$custom_menu = "📊 Reporte Piloto Fase 4|/reporte_fase4.php|Descargar Reporte";
set_config('custommenuitems', $custom_menu, 'theme_boost');
mtrace("- Botón de 'Reporte Piloto Fase 4' inyectado en el menú principal.");

// 2. Definir la paleta exacta corporativa
$sence_blue = '#0f69b4'; // Azul Gob.cl
$sence_red = '#e3001b';  // Rojo bandera
$dark_blue = '#004b87';  // Variante oscura para hover
$white = '#ffffff';

set_config('brandcolor', $sence_blue, 'theme_boost');

// 3. SCSS Avanzado para forzar la visual de SENCE (Roboto, NavBar gruesa, botones)
$scss = <<<SCSS
/* ==========================================================================
   IDENTIDAD VISUAL SENCE CHILE (GOB.CL)
   ========================================================================== */

/* 1. Tipografía Global y Fondos */
@import url('https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap');

body {
    font-family: 'Roboto', -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", Arial, sans-serif !important;
    background-color: #f8f9fa !important;
}

/* 2. Barra Superior (Navbar) - Estilo Gob.cl */
.navbar.fixed-top {
    background-color: {$sence_blue} !important;
    border-bottom: 4px solid {$sence_red} !important;
    padding-top: 10px;
    padding-bottom: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.navbar-light .navbar-brand,
.navbar-light .navbar-nav .nav-link,
.navbar-light .navbar-text {
    color: {$white} !important;
    font-weight: 500;
}

.navbar-light .navbar-nav .nav-link:hover,
.navbar-light .navbar-nav .nav-link:focus {
    color: rgba(255,255,255,0.8) !important;
    background-color: {$dark_blue};
    border-radius: 4px;
}

/* 3. Botones Corporativos */
.btn-primary {
    background-color: {$sence_blue} !important;
    border-color: {$sence_blue} !important;
    border-radius: 2px !important; /* Bordes cuadrados institucionales */
    font-weight: 500;
    text-transform: uppercase;
    font-size: 0.9rem;
    padding: 0.5rem 1.5rem;
    transition: background-color 0.2s ease;
}

.btn-primary:hover {
    background-color: {$dark_blue} !important;
    border-color: {$dark_blue} !important;
}

/* 4. Tarjetas de Cursos (Dashboard) */
.card.dashboard-card {
    border: none;
    border-radius: 4px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.card.dashboard-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.card.dashboard-card .card-body .text-muted {
    color: {$dark_blue} !important;
}

/* 5. Destacar Títulos Principales */
h1, h2, h3, h4, h5, h6 {
    color: {$dark_blue};
    font-weight: 700;
}

/* ========================================================================== */
SCSS;

set_config('scsspre', $scss, 'theme_boost');
mtrace("- Inyección de SCSS Institucional (SENCE 2026) guardada.");

mtrace("Limpiando cachés de Moodle para aplicar los cambios en vivo...");
purge_all_caches();

mtrace("¡Proceso finalizado! La visual de SENCE y el botón de reporte están operativos.");

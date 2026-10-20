<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');

$brandcolor = '#006FB3';
$scss = <<<EOF
// Colores SENCE / Gobierno
\$primary: #006FB3;
\$secondary: #FE6565;
\$dark: #4A4A4A;

// Botones Primarios
.btn-primary {
    background-color: \$secondary;
    border-color: \$secondary;
    color: #fff;
    &:hover, &:focus, &:active {
        background-color: darken(\$secondary, 10%);
        border-color: darken(\$secondary, 10%);
    }
}

// Botones Secundarios
.btn-secondary {
    background-color: transparent;
    border-color: \$primary;
    color: \$primary;
    &:hover, &:focus, &:active {
        background-color: \$primary;
        color: #fff;
    }
}

// Tipografía e Interfaz general
body {
    color: \$dark;
}

// Navbar Superior Institucional
.navbar.fixed-top {
    background-color: \$primary !important;
    border-bottom: 3px solid \$secondary;
    .navbar-brand, .nav-link, .navbar-nav .nav-link {
        color: #fff !important;
    }
}

// Tarjetas de Curso (Dashboard)
.card.dashboard-card {
    border: none;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    transition: transform 0.2s ease-in-out;
    &:hover {
        transform: translateY(-5px);
        box-shadow: 0 6px 12px rgba(0,0,0,0.15);
    }
}
EOF;

set_config('brandcolor', $brandcolor, 'theme_boost');
set_config('scss', $scss, 'theme_boost');

// Cambiar el nombre del sitio a algo más institucional
$DB->set_field('course', 'fullname', 'Portal de Capacitación - SENCE', ['id' => 1]);
$DB->set_field('course', 'shortname', 'SENCE Capacitación', ['id' => 1]);

mtrace("Tema Boost configurado exitosamente con la identidad de SENCE Chile.");

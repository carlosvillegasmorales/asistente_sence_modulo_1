<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');

mtrace("Aplicando corrección a prueba de fallos (Inyección JS Directa)...");

$sence_blue = '#0f69b4';
$sence_red = '#e3001b';

// Eliminar el menú de usuario fallido
set_config('customusermenuitems', '', 'theme_boost');

$js_injection = <<<HTML
<style>
/* Forzar fuente Roboto en todo el sitio */
@import url('https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap');
body, h1, h2, h3, h4, h5, h6, .h1, .h2, .h3, .h4, .h5, .h6 {
    font-family: 'Roboto', sans-serif !important;
}
/* Botón Flotante Moderno */
.sence-report-fab {
    position: fixed;
    bottom: 30px;
    left: 30px;
    background-color: {$sence_red};
    color: white !important;
    padding: 12px 24px;
    border-radius: 50px;
    font-weight: bold;
    box-shadow: 0 4px 15px rgba(227, 0, 27, 0.4);
    z-index: 9999;
    transition: transform 0.2s;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
}
.sence-report-fab:hover {
    transform: scale(1.05);
    color: white;
    text-decoration: none;
}
</style>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // 1. Teñir la barra superior sin importar la clase que use Moodle 5
    var header = document.querySelector('header') || document.querySelector('nav');
    if (header) {
        header.style.setProperty('background-color', '{$sence_blue}', 'important');
        header.style.setProperty('border-bottom', '4px solid {$sence_red}', 'important');
        
        // Pintar de blanco todos los enlaces de la cabecera
        var links = header.querySelectorAll('a, span, div, i');
        links.forEach(function(el) {
            el.style.setProperty('color', '#ffffff', 'important');
        });
    }

    // 2. Inyectar botón flotante elegante en la esquina inferior izquierda
    var fab = document.createElement('a');
    fab.href = '/reporte_fase4.php';
    fab.className = 'sence-report-fab';
    fab.innerHTML = '<span>📊</span> Descargar Reporte Piloto';
    document.body.appendChild(fab);
});
</script>
HTML;

set_config('additionalhtmlfooter', $js_injection);
mtrace("- Script JS a prueba de fallos guardado.");

purge_all_caches();
mtrace("Cachés limpiadas. Listo.");

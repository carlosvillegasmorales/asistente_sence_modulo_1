<?php
define('CLI_SCRIPT', true);
require(__DIR__.'/config.php');
require_once($CFG->libdir.'/adminlib.php');

mtrace('Purgando todas las cachés de Moodle...');
purge_all_caches();
theme_reset_all_caches();
mtrace('¡Cachés purgadas con éxito (incluida caché de temas)!');

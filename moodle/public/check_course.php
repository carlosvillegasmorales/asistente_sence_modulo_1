<?php
define('CLI_SCRIPT', true);
$html = file_get_contents('c:\ProyectoSence\moodle-latest-502\moodle\public\test_header.html');
$html = mb_convert_encoding($html, 'UTF-8', 'UTF-16LE');
echo substr($html, 0, 1000);






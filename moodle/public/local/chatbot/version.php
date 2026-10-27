<?php
/**
 * Version details for local_chatbot.
 *
 * El numero de version es la fecha con un correlativo, y es lo unico que
 * Moodle compara para decidir si corresponde una actualizacion: siempre sube.
 *
 * El release, en cambio, es lo que lee una persona. Lleva el modulo adelante
 * porque este plugin entrega tres modulos de la suite en el mismo componente
 * (ver docs/DECISIONES.md, DEC-01): el prefijo dice que alcance funcional trae
 * la instalacion y el semver dice que tan maduro esta ese alcance. Asi, quien
 * da soporte sabe de un vistazo si el problema que le reportan pertenece
 * siquiera al modulo instalado.
 *
 * La numeracion parte en 1.0.0 con la primera entrega del Modulo 1. Las
 * versiones v1.x y v2.x anteriores fueron exploracion previa a la ERTF, no
 * entregas, y aparecen en CHANGES.md como tales.
 */

defined('MOODLE_INTERNAL') || die();
$plugin->version   = 2026102602;
$plugin->requires  = 2023100900;
$plugin->component = 'local_chatbot';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = 'M1-v1.20.2';

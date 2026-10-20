<?php
/**
 * Verifica que los archivos de idioma declaren el mismo conjunto de cadenas.
 *
 * Moodle arma el conjunto de claves de un componente a partir del archivo en
 * inglés y encima aplica la traducción. Una cadena declarada solo en español
 * no existe para Moodle: la interfaz muestra [[nombre_de_la_clave]] en crudo.
 *
 * El proyecto se trabaja en español, así que es fácil agregar una cadena en
 * lang/es y olvidar lang/en. Pasó: diez cadenas del panel de la base de
 * conocimiento se mostraron como [[managekb_jobs_title]] en producción. El
 * fallo es silencioso —no hay error ni en el log ni en php -l—, por eso se
 * comprueba aquí.
 *
 * Uso:  php cli/verificar_idiomas.php
 * Sale con código 1 ante cualquier desajuste, para encadenarlo a CI.
 */

define('MOODLE_INTERNAL', true);

/**
 * Claves declaradas en un archivo de idioma.
 *
 * @param string $ruta
 * @return string[]
 */
function local_chatbot_claves_de(string $ruta): array {
    if (!file_exists($ruta)) {
        echo "  FALLA  no existe el archivo de idioma {$ruta}\n";
        return [];
    }
    preg_match_all('/^\$string\[\'([^\']+)\'\]/m', file_get_contents($ruta), $m);
    return $m[1];
}

$dir = __DIR__ . '/../lang';
$en = local_chatbot_claves_de($dir . '/en/local_chatbot.php');
$es = local_chatbot_claves_de($dir . '/es/local_chatbot.php');

$fallidos = 0;

// Cadenas que existen en la traducción pero no en el inglés: son las que la
// interfaz muestra como [[clave]].
$sinbase = array_values(array_diff($es, $en));
foreach ($sinbase as $clave) {
    echo "  FALLA  '{$clave}' está en lang/es pero no en lang/en: Moodle la mostrará como [[{$clave}]]\n";
    $fallidos++;
}

// Cadenas sin traducir: no rompen la interfaz, pero la dejan en inglés dentro
// de un producto que se entrega en español.
$sintraducir = array_values(array_diff($en, $es));
foreach ($sintraducir as $clave) {
    echo "  FALLA  '{$clave}' está en lang/en pero no en lang/es: se mostraría en inglés\n";
    $fallidos++;
}

// Una clave repetida dentro del mismo archivo hace que gane la última, que rara
// vez es la que se quiso escribir.
foreach (['en' => $en, 'es' => $es] as $idioma => $claves) {
    foreach (array_count_values($claves) as $clave => $veces) {
        if ($veces > 1) {
            echo "  FALLA  '{$clave}' está declarada {$veces} veces en lang/{$idioma}\n";
            $fallidos++;
        }
    }
}

printf("Claves verificadas: %d en inglés, %d en español | Fallidos: %d\n",
    count($en), count($es), $fallidos);

if ($fallidos === 0) {
    echo "Archivos de idioma conformes: mismo conjunto de claves, sin duplicados.\n";
    exit(0);
}

echo "Idiomas NO conformes: revisar lang/en y lang/es antes de desplegar.\n";
exit(1);

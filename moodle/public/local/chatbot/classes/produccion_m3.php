<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Modulo 3: materiales de la experiencia de aprendizaje de continuidad (ajuste de Interfases del 26-11-2026, «Ajuste Prompt
 * Maestro M3 - Produccion de Materiales de la Experiencia de Continuidad»).
 *
 *  - Bloques 1 a 3 y 5 (Etapa 6): catalogo de capacidades, recuperacion de los materiales del Modulo 2 (diseño validado,
 *    plan de produccion y repertorio del Centro), reglas 1 a 9 de los materiales de la continuidad y sus verificaciones. Se
 *    entregan al asistente en la Etapa 6 (calidad::build_prompt(), interruptor `materialesm3`) con el inventario del
 *    repertorio del diseño, que la plataforma lee en solo lectura.
 *  - Bloque 4: produccion de los materiales (Etapas P1 a P4, opcional, interruptor `produccionm3`) cuando la Etapa 8 esta
 *    consolidada. Usa el motor del Centro del Modulo 2 (centro_recursos: modos A, B y C, formatos, busqueda web con
 *    referencia, «Editar» y «Aceptar») con su propio contexto (resource_context()) y sus propias reglas: el plan se guarda en
 *    local_chatbot_recurso_plan con fichaid = −id de la ficha de implementacion, para no cruzarse con los diseños del
 *    Modulo 2, y cada material lleva su categoria (reutilizado, ajustado o nuevo) y su origen.
 *  - Principio 3 modificado (pedido del 26-11-2026, igual que en el Centro del Modulo 2): los contenidos tecnicos
 *    complementarios que no estan en las fuentes se buscan en internet, de acuerdo con la normativa tecnica, los codigos y
 *    la legislacion vigentes en Chile, y cada fragmento web y cada marca [REVISAR] se puede aceptar o editar en cada material.
 *
 * Todo lo que no consulta BD es funcion pura y lo ejercita cli/verificar_produccion_m3.php.
 */
class produccion_m3 {

    /** @var string Version de las reglas de produccion de la continuidad. */
    const PROMPT_VERSION = 'M3-PM-v1';

    /** Categorias de un material de la experiencia de continuidad (regla 1). */
    const CAT_REUTILIZADO = 'reutilizado';
    const CAT_AJUSTADO = 'ajustado';
    const CAT_NUEVO = 'nuevo';

    /** @var string Estado del plan: reutilizado y entregado en el Modulo 2, no se vuelve a producir (P1). */
    const MAT_DISPONIBLE = 'disponible';

    /** Fuente desde la que se recupera el material de origen (regla 7). */
    const FUENTE_REPERTORIO = 'repertorio';
    const FUENTE_ESPECIFICACION = 'especificacion';

    /** @var int Tope de caracteres de cada texto de contexto en los pedidos de produccion. */
    const CONTEXTO_MAX = 30000;

    /** @var string[] BLOQUE 1 · Catalogo de capacidades de produccion (textual). */
    const CATALOGO = [
        'CATÁLOGO DE CAPACIDADES DE PRODUCCIÓN',
        'Los materiales instruccionales de la experiencia de continuidad se proponen y producen con el mismo catálogo del '
            . 'Módulo 2. Cada material se clasifica en uno de tres modos de producción:',
        '- Modo A · Producción completa con IA: generas el contenido completo del material (textos, tablas, casos, guías, '
            . 'fichas, cuestionarios, guiones, diagramas descritos en texto o código) y lo entregas listo para copiar y pegar o '
            . 'en código HTML.',
        '- Modo B · Producción con IA y servicios de la plataforma: generas el contenido y un servicio de conversión de la '
            . 'plataforma lo entrega como archivo descargable y editable (documento Word o PDF, planilla Excel o presentación).',
        '- Modo C · Curaduría de recursos existentes con IA: cuando un material no puede generarse con calidad suficiente (por '
            . 'ejemplo, un video de un procedimiento real), buscas en internet recursos abiertos ya publicados y recomiendas '
            . 'hasta 5, los mejor contextualizados al aprendizaje esperado, para que la persona facilitadora los seleccione.',
        'Formatos de salida',
        '- Ningún material se carga ni se publica directamente en una plataforma: se entregan listos para copiar y pegar, en '
            . 'código HTML o como archivo descargable.',
        '- Los cuestionarios se entregan para su configuración más rápida: formato GIFT para importar en Moodle, script de '
            . 'Google Apps Script para crear un formulario de Google Forms o versión impresa con pauta de respuestas.',
        '- La forma de uso física (impresión en tablero, plastificado, papel autocopiativo u otra) no es un formato de salida: '
            . 'se registra como condición de uso del material, y los insumos que requiere se registran en los recursos del '
            . 'entorno formativo.',
        'Tipos de material y costo relativo de producción con IA, de menor a mayor:',
        '1. Texto y datos estructurados (guías, casos, fichas, glosarios, planillas, plantillas, cuestionarios).',
        '2. Presentaciones, esquemas y diagramas generados a partir de texto o código.',
        '3. Imágenes generadas.',
        '4. Audio generado.',
        '5. Video generado.',
        'La curaduría (Modo C) tiene un costo similar al del tipo 1 y suele ser preferible a generar audio o video.',
        'Fuera del catálogo quedan los recursos del entorno formativo, que son siempre recursos físicos (máquinas, equipos, '
            . 'herramientas, materiales e insumos de consumo, elementos de protección personal, talleres, laboratorios y '
            . 'espacios de práctica habilitados) y los proporciona el organismo ejecutor.',
    ];

    /** @var string[] BLOQUE 2 · Recuperacion de los materiales del Modulo 2 (textual). */
    const RECUPERACION = [
        'RECUPERACIÓN DE LOS MATERIALES DEL MÓDULO 2',
        'Fuentes que lees desde los módulos, sin que la persona facilitadora las vuelva a cargar:',
        '1. El diseño validado (Módulo 2, Etapa 4): definición de cada material (4.1) y lista de producción (4.2), con su '
            . 'código, fase y actividad, tipo, modo, formato de salida y criterios de evaluación. Esta fuente siempre está '
            . 'disponible y es la referencia de lo que se diseñó.',
        '2. El plan de producción del centro de producción (Módulo 2, Etapas 9 y 11): estado de cada material (Entregado, '
            . 'Entregado parcialmente, Pendiente, No producible por el centro o Instrumento de evaluación del diseño) y versión '
            . 'vigente. Indica qué se produjo.',
        '3. El repertorio de recursos del diseño (Módulo 2, Etapa 12): contenido de la versión vigente de cada material '
            . 'entregado, recursos seleccionados en Modo C con sus enlaces y fecha de verificación, materiales de referencia '
            . 'cargados por la persona facilitadora y marcas [REVISAR]. Es la base de lo que se reutiliza o se ajusta.',
        'Además, usas la evidencia sobre los materiales en la implementación: valoración y comentario 5.3 de la ficha de '
            . 'registro, cambios registrados en 3.1 y 3.4 que afectan materiales, patrones de desempeño de la Etapa 1, brechas '
            . 'de diseño de la Etapa 2 y componentes rediseñados en la Etapa 4.',
        'INVENTARIO DE MATERIALES DEL DISEÑO (OBLIGATORIO)',
        'Antes de proponer los materiales de la continuidad, presenta una tabla de inventario con una fila por material de la '
            . 'lista de producción del diseño y las columnas: «Código», «Material», «Modo», «Criterios de evaluación», «Estado '
            . 'en el centro», «Versión vigente», «Uso en la implementación» (evidencia de la ficha o «Sin observaciones») y '
            . '«Pertinencia para la continuidad» (criterios con brecha, pendientes o trayectoria a la que puede servir, o «No '
            . 'pertinente»).',
        'Base para cada material, según su estado:',
        '- Entregado: la versión vigente del repertorio.',
        '- Entregado parcialmente: las partes entregadas y, para el resto, la especificación de la Etapa 4 del diseño.',
        '- Pendiente, No producible por el centro, o diseño sin uso del centro: la especificación de la Etapa 4. Si la persona '
            . 'facilitadora usó en la implementación una versión propia, puede cargarla, y la tratas como material de '
            . 'referencia de la persona facilitadora.',
        '- Instrumento de evaluación del diseño: no forma parte de los materiales; si la continuidad lo usa, se trata en el '
            . 'plan de evaluación de la Etapa 6.',
        '- Recursos de Modo C: antes de reutilizarlos, verificas que los enlaces sigan disponibles y registras la nueva fecha '
            . 'de verificación.',
        'Lectura económica:',
        '1. Lee primero solo la lista de producción del diseño y la tabla del repertorio para construir el inventario.',
        '2. Lee el contenido completo solo de los materiales pertinentes para la continuidad y, dentro de ellos, solo las '
            . 'secciones pertinentes.',
        '3. No transcribas en la conversación el contenido de los materiales que se reutilizan sin cambios: remite a su código '
            . 'y versión.',
        '4. Usa siempre los códigos del diseño. Si un material citado en la ficha o en el informe no aparece en la lista de '
            . 'producción del diseño, no le asignes un código: márcalo con [REVISAR: material no encontrado en el diseño].',
        'El diseño y el repertorio del Módulo 2 son de solo lectura: nunca los modificas. Toda versión ajustada es un material '
            . 'de la experiencia de continuidad.',
    ];

    /** @var string[] BLOQUE 3 · Reglas para los materiales de la experiencia de continuidad (textual). */
    const REGLAS = [
        'REGLAS PARA LOS MATERIALES DE LA EXPERIENCIA DE CONTINUIDAD',
        'REGLA 1. CLASIFICACIÓN DE CADA MATERIAL',
        'Clasifica cada material de la experiencia de continuidad en una sola de estas categorías:',
        '- Reutilizado: material del diseño que se usa sin cambios, en su versión vigente o, si no se produjo, según su '
            . 'especificación.',
        '- Ajustado: material del diseño que se modifica en secciones identificadas.',
        '- Nuevo: material que no existe en el diseño y que se requiere para una actividad de la continuidad.',
        'Los materiales que usan las personas en evaluación recuperativa son materiales del diseño y se registran como '
            . 'reutilizados.',
        'REGLA 2. ORDEN DE PREFERENCIA',
        'Para cada actividad de la continuidad, elige en este orden: (1) reutilizar un material del diseño; (2) ajustarlo; (3) '
            . 'crear un material nuevo. Pasa a la opción siguiente solo si la anterior no permite trabajar el criterio con la '
            . 'calidad necesaria, e indica por qué.',
        'REGLA 3. AJUSTES FUNDADOS EN EVIDENCIA',
        'Un material solo se ajusta cuando hay evidencia o una necesidad de trayectoria que lo justifique:',
        '- Una brecha de aprendizaje con patrones de error registrados (Etapa 1) que el material puede atender, por ejemplo con '
            . 'ejemplos resueltos, pasos guiados o casos centrados en el error frecuente.',
        '- Una brecha de diseño o de implementación referida al material (Etapa 2, comentario 5.3, cambios en 3.1 o 3.4), por '
            . 'ejemplo un contenido insuficiente, un error o un recurso que no funcionó.',
        '- Un componente del diseño que la Etapa 4 decidió rediseñar y que corresponde al material.',
        '- Un pendiente que el material debe incorporar.',
        '- La necesidad de una trayectoria: para nivelación, simplificar, secuenciar en pasos o agregar apoyos; para '
            . 'profundización, aumentar la complejidad, plantear transferencia a nuevas situaciones o preparar el apoyo a pares.',
        '- La extensión que exige el tiempo de la actividad en la continuidad.',
        'No ajustes un material por razones de estilo o redacción, ni amplíes sus contenidos más allá de los criterios de la '
            . 'continuidad.',
        'REGLA 4. MATERIALES POR TRAYECTORIA',
        '- Prefiere un solo material con partes diferenciadas por trayectoria (nivelación y profundización) en lugar de dos '
            . 'materiales distintos, salvo que las actividades de cada trayectoria sean diferentes.',
        '- Indica en cada material la trayectoria o las trayectorias a las que sirve.',
        'REGLA 5. MODOS DE PRODUCCIÓN Y ECONOMÍA DE RECURSOS DE IA',
        'Aplica los mismos modos y reglas del Módulo 2:',
        '- Un material reutilizado conserva su modo, tipo y formato de salida del diseño.',
        '- Un material ajustado conserva el modo de su material de origen, salvo que el ajuste lo haga imposible; en ese caso, '
            . 'justifica el cambio.',
        '- Un material nuevo se propone en un solo modo (A, B o C), con un tipo y un formato de salida del catálogo; si '
            . 'requiere partes en distintos modos, divídelo.',
        '- Los materiales generados apoyan la práctica con recursos reales; nunca la reemplazan.',
        '- Si un material no puede generarse con calidad suficiente, busca un equivalente en Modo A o B, luego la curaduría en '
            . 'Modo C y, si ninguna es viable, reformula la actividad o el material. Los materiales instruccionales nunca se '
            . 'registran como requerimiento al organismo ejecutor.',
        '- Prefiere el tipo de menor costo y justifica un tipo de mayor costo.',
        '- No repitas contenidos entre materiales ni con los instrumentos de evaluación de la continuidad; remite al material '
            . 'que ya los contiene.',
        '- Ajusta la extensión al tiempo de uso previsto en la secuencia de la continuidad.',
        'La economía de recursos nunca puede reducir la cobertura de los criterios de la continuidad ni la seguridad de las '
            . 'actividades.',
        'REGLA 6. MATERIALES E INSTRUMENTOS',
        '- Toda pauta, lista de cotejo, rúbrica o prueba que se usa para calificar o asignar niveles a las personas '
            . 'participantes es un instrumento de evaluación de la continuidad: se redacta en la Etapa 6 como instrumento y no '
            . 'forma parte de la lista de materiales.',
        '- Las pautas de autochequeo, las guías de tutoría entre pares y las plantillas de trabajo que no califican a las '
            . 'personas son materiales.',
        'REGLA 7. ESPECIFICACIÓN LISTA PARA PRODUCIR',
        'Cada material debe quedar especificado para producirlo sin tomar nuevas decisiones de diseño:',
        '- Código y categoría (reutilizado, ajustado o nuevo).',
        '- Material de origen y versión, en reutilizados y ajustados, con la fuente desde la que se recupera (repertorio o '
            . 'especificación del diseño).',
        '- Destinatarios y trayectoria.',
        '- Propósito y actividad o bloque de la continuidad en que se usa.',
        '- Criterios de evaluación a los que sirve.',
        '- En materiales ajustados: secciones que se conservan, secciones que se modifican o agregan, cambio en cada una y '
            . 'evidencia que lo justifica.',
        '- En materiales nuevos: contenidos y su secuencia, estructura y extensión.',
        '- Tipo de material, modo de producción y formato de salida del catálogo; condición de uso física, si corresponde.',
        '- En Modo C, la especificación de la búsqueda (tipo de recurso, número de opciones hasta 5, criterio o actividad, '
            . 'idioma, duración máxima y criterios de selección).',
        'REGLA 8. CÓDIGOS',
        '- Material reutilizado: conserva el código del diseño (por ejemplo, M1).',
        '- Material ajustado: código del diseño seguido de «-C» (por ejemplo, M3-C), con indicación del material y la versión '
            . 'de origen.',
        '- Material nuevo: código MC seguido de un número correlativo (MC1, MC2…).',
        'No uses otros sufijos (por ejemplo, «-R» o «-V2»): las versiones (v1, v2…) se reservan para las nuevas producciones de '
            . 'un mismo material en el repertorio. Usa los mismos códigos en la secuencia instruccional, el plan de evaluación, '
            . 'la consolidación y el documento operativo.',
        'REGLA 9. RELACIÓN CON LAS ETAPAS 4 Y 5',
        '- Si la Etapa 4 decide rediseñar un material y la continuidad lo usa, el material rediseñado es el material ajustado '
            . 'de la continuidad: se especifica una sola vez, en la Etapa 6.',
        '- Los ajustes de materiales que la Etapa 5 recomienda para futuras implementaciones del diseño se registran como '
            . 'recomendaciones para el Módulo 2, con el código del material y el cambio propuesto. Si el ajuste ya se produjo '
            . 'para la continuidad, la recomendación remite a ese material ajustado. No modifican el diseño ni el repertorio '
            . 'del Módulo 2.',
        'REGISTRO OBLIGATORIO EN LA ETAPA 6',
        'Presenta los materiales de apoyo en este orden:',
        '1. El inventario de materiales del diseño (Bloque 2).',
        '2. La lista de materiales de la continuidad, con las columnas «Código», «Material», «Categoría», «Origen, versión y '
            . 'fuente», «Bloque y trayectoria», «Criterios de evaluación», «Tipo», «Modo de producción», «Formato de salida» y '
            . '«Justificación» (evidencia del ajuste o motivo del material nuevo).',
        '3. La descripción de cada material ajustado o nuevo, con los campos de la regla 7.',
        '4. La lista de recursos del entorno formativo, a cargo del organismo ejecutor, solo con recursos físicos para talleres, '
            . 'laboratorios o espacios de práctica.',
    ];

    /** @var string[] BLOQUE 5 · Verificaciones de la Etapa 6 (textual). */
    const VERIFICACIONES_ETAPA6 = [
        '- Verificar que el inventario incluya todos los materiales de la lista de producción del diseño, con su estado en el '
            . 'centro.',
        '- Verificar que cada material de la continuidad tenga una sola categoría y que se haya aplicado el orden de preferencia '
            . 'de la regla 2.',
        '- Verificar que cada material reutilizado o ajustado indique su origen, versión y fuente, y que cada ajuste tenga su '
            . 'evidencia o trayectoria.',
        '- Verificar que cada material tenga un solo modo y un tipo y formato de salida del catálogo, y que los reutilizados y '
            . 'ajustados conserven el modo de origen o justifiquen el cambio.',
        '- Verificar que los códigos sigan la regla 8 y sean los mismos en la secuencia, el plan de evaluación y la '
            . 'consolidación.',
        '- Verificar que la lista de materiales no incluya instrumentos de evaluación y que la lista de recursos del entorno '
            . 'formativo contenga solo recursos físicos.',
        '- Verificar que ningún material reemplace una práctica con recursos reales.',
    ];

    /** @var string[] BLOQUE 5 · Verificaciones de las Etapas P1 a P4 (textual). */
    const VERIFICACIONES_PRODUCCION = [
        '- Verificar que el material corresponde a la especificación de la Etapa 6 y a la propuesta aprobada.',
        '- En materiales ajustados, verificar que las secciones conservadas son idénticas a la versión de origen y que solo '
            . 'cambiaron las secciones aprobadas.',
        '- Verificar que no se modificó el diseño ni el repertorio del Módulo 2.',
        '- Verificar que los contenidos técnicos, normativos y de seguridad provienen de fuentes autorizadas o llevan la marca '
            . '[REVISAR], y que los datos ficticios están declarados.',
        '- Verificar que el formato de salida es válido (sintaxis GIFT, HTML compatible con Moodle, script ejecutable o archivo '
            . 'editable) y, en Modo C, que los enlaces están verificados.',
    ];

    /** @var string Mensaje textual al iniciar la produccion (Bloque 4, «Condicion de activacion»). */
    const MENSAJE_INICIO = 'Ahora podemos producir los materiales de tu experiencia de continuidad, uno a la vez. Los '
        . 'materiales que se reutilizan y ya se produjeron en el Módulo 2 están disponibles en el repertorio de tu diseño. Para '
        . 'los materiales ajustados te mostraré primero qué secciones cambian y por qué; para los nuevos, su estructura. Tú '
        . 'revisas cada propuesta y puedes pedir ajustes. Los materiales se entregan listos para copiar y pegar, en código HTML '
        . 'o como archivo descargable.';

    /** @var string Respuesta textual del principio 1 ante un cambio que altera la experiencia consolidada. */
    const RESPUESTA_NO_MODIFICA = 'Ese cambio modifica la experiencia de continuidad consolidada. Puedo ajustar la redacción, '
        . 'los ejemplos o el formato del material dentro de lo especificado.';

    /**
     * @var string Principio 3 de la produccion, modificado a pedido de Interfases (26-11-2026), igual que en el Centro del
     *      Modulo 2: busqueda web de contenidos tecnicos complementarios conforme a la normativa chilena vigente, con
     *      «Editar» y «Aceptar» de cada fragmento web y de cada marca [REVISAR] en cada material.
     */
    const PRINCIPIO_EXACTITUD = '3. Exactitud técnica: usa primero los valores técnicos, normativos y de seguridad que '
        . 'provengan del diseño, del material de origen, del plan formativo, del perfil ocupacional o de los materiales de '
        . 'referencia de la persona facilitadora. Cuando el material necesite contenidos técnicos complementarios que no están '
        . 'en esas fuentes, búscalos en internet: solo son válidos si están de acuerdo con la normativa técnica, las '
        . 'disposiciones de los códigos y la legislación vigentes en Chile, y cada fragmento obtenido de internet se marca con '
        . 'su fuente para que la persona facilitadora lo acepte o lo edite. Si no encuentras un dato en una fuente confiable y '
        . 'conforme a la normativa chilena vigente, usa la marca [REVISAR: motivo]. Las marcas [REVISAR] del material de '
        . 'origen que no se resolvieron se mantienen. La persona facilitadora puede aceptar o editar cada marca [REVISAR] y '
        . 'cada fragmento obtenido de internet en cada material. Nunca inventes normas, valores límite, capacidades ni '
        . 'procedimientos de seguridad. Los casos y datos que crees son ficticios y se declaran como tales.';

    /**
     * @var string Criterio de validez de lo que se busca en internet (pedido del 26-11-2026), que se agrega a las reglas de
     *      busqueda del Centro (centro_recursos::web_prompt_rules()).
     */
    const REGLA_NORMATIVA_CHILE = '- Un contenido técnico obtenido de internet solo es válido si está de acuerdo con la '
        . 'normativa técnica, las disposiciones de los códigos y la legislación vigentes en Chile. No uses una fuente que '
        . 'contradiga esa normativa, que se refiera a una norma derogada o que describa la práctica de otro país como si '
        . 'rigiera en Chile; si una norma internacional se aplica en Chile, indica cómo.';

    /** @var string[] Principios de la produccion (Bloque 4, textuales salvo el 3, que es el modificado). */
    const PRINCIPIOS = [
        'PRINCIPIOS DE LA PRODUCCIÓN',
        '1. No modificas la experiencia de continuidad consolidada: produces cada material tal como quedó especificado en la '
            . 'Etapa 6. Si la persona facilitadora pide un cambio que altera la experiencia (criterios, actividades, tiempos o '
            . 'trayectorias), responde: "' . self::RESPUESTA_NO_MODIFICA . '"',
        '2. No modificas el diseño ni el repertorio del Módulo 2: cada material ajustado se entrega como un material de la '
            . 'continuidad.',
        self::PRINCIPIO_EXACTITUD,
        '4. Revisión por la persona facilitadora: no avanzas al contenido completo ni al material siguiente sin su aprobación.',
        '5. Economía de recursos de IA: un material por turno; propuesta antes del contenido completo; en los ajustes, '
            . 'reescribe solo las secciones que cambian y conserva textualmente las demás; reutiliza contenidos de otros '
            . 'materiales del diseño o de la continuidad remitiendo a ellos; versiones impresa y digital desde una sola fuente.',
        '6. Materiales de referencia: la persona facilitadora puede cargar materiales técnicos propios para cada material, o '
            . 'usar los ya cargados en el repertorio del diseño. Se usan solo para los contenidos especificados, sin reproducir '
            . 'pasajes extensos ni datos personales o confidenciales, y se indica la fuente al final del material.',
        '7. Lenguaje: lenguaje inclusivo con perspectiva de género; terminología de formación de personas adultas y formación '
            . 'laboral, sin los conceptos «pedagógico» o «pedagogía»; «experiencia de aprendizaje», nunca «sesión»; nivel de '
            . 'lectura adecuado al grupo y, en nivelación, a las dificultades registradas.',
        '8. Sin conexión con el LMS del organismo ejecutor: no cargas ni publicas materiales en ninguna plataforma.',
    ];

    /** Textos de las Etapas P1 a P4 (textuales). */
    const CIERRE_P1 = 'Este es el plan de producción de tu experiencia de continuidad. ¿Con qué material quieres comenzar?';
    const PREGUNTA_REFERENCIAS = '¿Quieres cargar materiales técnicos propios para este material o usar los ya cargados en el '
        . 'repertorio del diseño? La carga es opcional.';
    const PREGUNTA_PROPUESTA = '¿Apruebas esta propuesta para producir el material?';
    const PREGUNTA_REVISION = 'Revisa el material. ¿Lo apruebas o quieres ajustar alguna sección?';
    const CIERRE_P3 = 'El material quedó registrado. ¿Quieres producir otro material del plan o cerrar la producción?';
    const RECORDATORIO_P4 = 'Recuerda revisar los contenidos marcados con [REVISAR] antes de usar los materiales.';
    const USO_REPERTORIO = 'El repertorio de la continuidad es de uso exclusivo de la persona facilitadora. Su estado alimenta '
        . 'la columna «Estado» del documento operativo de la continuidad y queda registrado en el ciclo formativo, como '
        . 'antecedente para la Fase 4.';
    const CIERRE_P4 = 'Cerramos la producción de materiales de tu experiencia de continuidad. Puedes volver antes de '
        . 'implementarla para producir los materiales pendientes o una nueva versión de un material.';

    // ------------------------------------------------------------------------------------------------------------
    // Funciones puras.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Clave del plan de la continuidad en local_chatbot_recurso_plan: menos el id de la ficha de implementacion (los
     * diseños del Modulo 2 usan ids positivos). Funcion pura.
     *
     * @param int $implid
     * @return int
     */
    public static function plan_key(int $implid): int {
        return -abs($implid);
    }

    /**
     * Ficha de implementacion de una clave de plan negativa (0 si la clave es de un diseño del Modulo 2). Funcion pura.
     *
     * @param int $fichaid
     * @return int
     */
    public static function impl_from_key(int $fichaid): int {
        return $fichaid < 0 ? -$fichaid : 0;
    }

    /**
     * Nombre de una categoria. Funcion pura.
     *
     * @param string $categoria
     * @return string
     */
    public static function category_label(string $categoria): string {
        return [self::CAT_REUTILIZADO => 'Reutilizado', self::CAT_AJUSTADO => 'Ajustado',
            self::CAT_NUEVO => 'Nuevo'][$categoria] ?? $categoria;
    }

    /**
     * Categoria desde el texto del asistente («Reutilizado», «ajustada», «Nuevo»…). Funcion pura.
     *
     * @param string $texto
     * @return string|null
     */
    public static function normalize_category(string $texto): ?string {
        $t = diseno::plain_lower($texto);
        if (strpos($t, 'reutiliz') !== false) {
            return self::CAT_REUTILIZADO;
        }
        if (strpos($t, 'ajust') !== false) {
            return self::CAT_AJUSTADO;
        }
        if (strpos($t, 'nuev') !== false) {
            return self::CAT_NUEVO;
        }
        return null;
    }

    /**
     * Revisa la regla 8 (codigos): reutilizado = codigo del diseño; ajustado = codigo del diseño + «-C»; nuevo = MC + numero.
     * Funcion pura.
     *
     * @param string $codigo
     * @param string $categoria
     * @param string $origen Codigo del material de origen.
     * @return string|null Observacion, o null si cumple.
     */
    public static function code_issue(string $codigo, string $categoria, string $origen = ''): ?string {
        $c = strtoupper(trim($codigo));
        $o = strtoupper(trim($origen));
        if ($c === '') {
            return 'Sin código.';
        }
        if (preg_match('/-(R|V\d+)$/', $c)) {
            return 'Usa un sufijo no permitido (regla 8: no se usan «-R» ni «-V2»).';
        }
        if ($categoria === self::CAT_NUEVO) {
            return preg_match('/^MC\d+$/', $c) ? null : 'Un material nuevo lleva el código MC seguido de un número (MC1, MC2…).';
        }
        if ($categoria === self::CAT_AJUSTADO) {
            if (!preg_match('/^(.+)-C$/', $c, $m)) {
                return 'Un material ajustado lleva el código del diseño seguido de «-C» (por ejemplo, M3-C).';
            }
            return ($o === '' || $m[1] === $o) ? null : 'El código no corresponde al material de origen ' . $origen . '.';
        }
        if ($categoria === self::CAT_REUTILIZADO) {
            if (preg_match('/^MC\d+$/', $c) || preg_match('/-C$/', $c)) {
                return 'Un material reutilizado conserva el código del diseño.';
            }
            return ($o === '' || $c === $o) ? null : 'Un material reutilizado conserva el código del diseño (' . $origen . ').';
        }
        return 'Categoría no reconocida.';
    }

    /**
     * Estado de un material del Centro del Modulo 2 para el inventario («Estado en el centro», Bloque 2). Funcion pura.
     *
     * @param \stdClass $r Fila de local_chatbot_recurso del diseño.
     * @return string
     */
    public static function m2_status_label(\stdClass $r): string {
        $entregada = centro_recursos::delivered_version($r);
        if ($entregada !== null) {
            return ($entregada['estado'] ?? '') === centro_recursos::MAT_ENTREGADO_PARCIAL ? 'Entregado parcialmente' : 'Entregado';
        }
        return [centro_recursos::MAT_NO_PRODUCIBLE => 'No producible por el centro',
            centro_recursos::MAT_INSTRUMENTO => 'Instrumento de evaluación del diseño'][(string) $r->estado] ?? 'Pendiente';
    }

    /**
     * Enlaces de los recursos seleccionados en Modo C de la version entregada de un material. Funcion pura.
     *
     * @param array $entregada Salida de centro_recursos::delivered_version().
     * @return array[] Cada uno ['titulo', 'enlace', 'indice'].
     */
    public static function curated_links(array $entregada): array {
        if (strtoupper(trim((string) ($entregada['modo'] ?? ''))) !== 'C') {
            return [];
        }
        $cur = (array) json_decode((string) ($entregada['estructurado'] ?? ''), true);
        $opciones = array_values((array) ($cur['opciones'] ?? []));
        $sel = array_map('intval', (array) (json_decode((string) ($entregada['seleccionados'] ?? ''), true) ?: []));
        if (!$sel) {
            $sel = array_keys($opciones);
        }
        $out = [];
        foreach ($sel as $i) {
            if (isset($opciones[$i]) && trim((string) ($opciones[$i]['enlace'] ?? '')) !== '') {
                $out[] = ['titulo' => (string) ($opciones[$i]['titulo'] ?? ''), 'enlace' => (string) $opciones[$i]['enlace'],
                    'indice' => $i];
            }
        }
        return $out;
    }

    /**
     * Inventario del plan de produccion y del repertorio del Centro del Modulo 2 que recibe el asistente (Etapa 6 y P1): una
     * linea por material, sin su contenido (lectura economica, Bloque 2). Funcion pura.
     *
     * @param \stdClass[] $recursos Filas de local_chatbot_recurso del diseño.
     * @param \stdClass[] $refs Materiales de referencia cargados en el diseño.
     * @return string
     */
    public static function inventory_text(array $recursos, array $refs = []): string {
        $l = ['PLAN DE PRODUCCIÓN Y REPERTORIO DEL CENTRO DE PRODUCCIÓN DEL DISEÑO (MÓDULO 2, ETAPAS 9 A 12; SOLO LECTURA):'];
        $filas = array_values(array_filter($recursos, static fn($r) => strpos((string) $r->codigo, '(ant.)') === false));
        if (!$filas) {
            $l[] = 'El diseño no se trabajó en el centro de producción: la base de cada material es su especificación en la '
                . 'Etapa 4 del diseño (estado «Pendiente», sin versión vigente).';
            return implode("\n", $l);
        }
        $l[] = 'Código | Material | Modo | Formato de salida | Estado en el centro | Versión vigente | Marcas [REVISAR] | '
            . 'Recursos de Modo C (enlace y fecha de verificación)';
        foreach ($filas as $r) {
            $entregada = centro_recursos::delivered_version($r);
            $enlaces = $entregada ? self::curated_links($entregada) : [];
            $modoC = $enlaces ? implode('; ', array_map(static fn($e) => $e['titulo'] . ' — ' . $e['enlace'], $enlaces))
                . (!empty($entregada['fecha_verificacion']) ? ' (verificados el ' . $entregada['fecha_verificacion'] . ')' : '')
                : '—';
            $l[] = implode(' | ', [(string) $r->codigo, (string) $r->nombre, (string) $r->modo, (string) $r->formato,
                self::m2_status_label($r), $entregada ? 'v' . (int) $entregada['version'] : '—',
                $entregada ? (string) (int) ($entregada['revisar_count'] ?? 0) : '—', $modoC]);
        }
        if ($refs) {
            $l[] = 'Materiales de referencia cargados por la persona facilitadora en el diseño: '
                . implode('; ', array_map(static fn($f) => (string) $f->filename, $refs)) . '.';
        }
        return implode("\n", $l);
    }

    /**
     * Reglas de los materiales de la continuidad que se agregan al prompt de la Etapa 6 (Bloques 1, 2, 3 y 5). Funcion pura.
     *
     * @return string[]
     */
    public static function stage6_rules(): array {
        return array_merge(self::CATALOGO, [''], self::RECUPERACION, [''], self::REGLAS, ['',
            'CONTROL DE CALIDAD DE LOS MATERIALES (además del control de calidad de la etapa):'], self::VERIFICACIONES_ETAPA6);
    }

    /**
     * Reglas que tambien rigen en las Etapas 4 y 5 (regla 8, codigos; regla 9, relacion con las Etapas 4 y 5). Funcion pura.
     *
     * @return string[]
     */
    public static function stage45_rules(): array {
        $out = ['MATERIALES DE LA EXPERIENCIA DE CONTINUIDAD (reglas 8 y 9 de las reglas para los materiales):'];
        $dentro = false;
        foreach (self::REGLAS as $linea) {
            if (strpos($linea, 'REGLA 8.') === 0) {
                $dentro = true;
            } else if (strpos($linea, 'REGISTRO OBLIGATORIO') === 0) {
                $dentro = false;
            }
            if ($dentro) {
                $out[] = $linea;
            }
        }
        return $out;
    }

    /**
     * Material de origen del Modulo 2 por su codigo (igual o con el mismo codigo base de las partes A, B, C). Funcion pura.
     *
     * @param \stdClass[] $recursos Filas del diseño.
     * @param string $codigo
     * @return \stdClass|null
     */
    public static function origin_row(array $recursos, string $codigo): ?\stdClass {
        $c = strtoupper(trim($codigo));
        if ($c === '') {
            return null;
        }
        $base = null;
        foreach ($recursos as $r) {
            $rc = strtoupper(trim((string) $r->codigo));
            if (strpos($rc, '(ANT.)') !== false) {
                continue;
            }
            if ($rc === $c) {
                return $r;
            }
            if ($base === null && strtoupper(centro_recursos::base_code((string) $r->codigo)) === $c) {
                $base = $r;
            }
        }
        return $base;
    }

    /**
     * Estado inicial de un material en el plan de produccion (P1). Funcion pura.
     *
     * @param string $categoria
     * @param array|null $entregada Version vigente del material de origen en el repertorio (null si no se produjo).
     * @param string[] $caidos Enlaces de Modo C que dejaron de estar disponibles.
     * @return array [estado, categoria, nota]
     */
    public static function initial_state(string $categoria, ?array $entregada, array $caidos = []): array {
        if ($categoria === self::CAT_REUTILIZADO && $entregada !== null) {
            if ($caidos) {
                return [centro_recursos::MAT_PENDIENTE, self::CAT_AJUSTADO, 'Recurso de Modo C con enlaces que dejaron de estar '
                    . 'disponibles: se ajusta solo para reemplazar esos enlaces con una nueva búsqueda.'];
            }
            return [self::MAT_DISPONIBLE, $categoria, 'Disponible en el repertorio (v' . (int) ($entregada['version'] ?? 0)
                . '): no se vuelve a producir.'];
        }
        if ($categoria === self::CAT_REUTILIZADO) {
            return [centro_recursos::MAT_PENDIENTE, $categoria, 'No se produjo en el Módulo 2: se produce según la especificación '
                . 'del diseño, sin cambios.'];
        }
        return [centro_recursos::MAT_PENDIENTE, $categoria, ''];
    }

    /**
     * Lee el plan de produccion que entrega el asistente (P1). Funcion pura.
     *
     * @param string|null $texto
     * @return array|null ['materiales' => array[], 'recursos_entorno' => string[]]
     */
    public static function parse_plan_json(?string $texto): ?array {
        $json = exante::decode_json((string) $texto);
        if (!is_array($json) || !is_array($json['materiales'] ?? null)) {
            return null;
        }
        $mats = [];
        $vistos = [];
        foreach ($json['materiales'] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $codigo = strtoupper(trim((string) ($m['codigo'] ?? '')));
            $categoria = self::normalize_category((string) ($m['categoria'] ?? ''));
            if ($codigo === '' || $categoria === null || isset($vistos[$codigo])) {
                continue;
            }
            $vistos[$codigo] = true;
            $modo = strtoupper(trim((string) ($m['modo'] ?? 'A')));
            if (!in_array($modo, ['A', 'B', 'C'], true)) {
                $modo = preg_match('/\bC\b/', $modo) ? 'C' : (preg_match('/\bB\b/', $modo) ? 'B' : 'A');
            }
            $origen = strtoupper(trim((string) ($m['origen_codigo'] ?? '')));
            if ($origen === '' && $categoria !== self::CAT_NUEVO) {
                $origen = $categoria === self::CAT_AJUSTADO ? (string) preg_replace('/-C$/', '', $codigo) : $codigo;
            }
            $t = static fn(string $k, int $max = 255): string => trim(mb_substr((string) ($m[$k] ?? ''), 0, $max, 'UTF-8'));
            $mats[] = [
                'codigo' => mb_substr($codigo, 0, 20, 'UTF-8'),
                'nombre' => $t('nombre') ?: $codigo,
                'categoria' => $categoria,
                'origen_codigo' => $categoria === self::CAT_NUEVO ? '' : mb_substr($origen, 0, 20, 'UTF-8'),
                'bloque' => $t('bloque', 120),
                'trayectoria' => $t('trayectoria', 120),
                'criterios' => $t('criterios', 400),
                'tipo' => $t('tipo', 120),
                'modo' => $modo,
                'formato' => $t('formato', 200),
                'justificacion' => $t('justificacion', 1500),
                'especificacion' => $t('especificacion', 8000),
            ];
        }
        if (!$mats) {
            return null;
        }
        $entorno = array_values(array_filter(array_map(static fn($x) => trim((string) $x),
            (array) ($json['recursos_entorno'] ?? [])), static fn($x) => $x !== ''));
        return ['materiales' => $mats, 'recursos_entorno' => array_slice($entorno, 0, 60)];
    }

    /**
     * Arma las filas del plan (P1) cruzando lo que propone el asistente con el repertorio del Modulo 2: origen, version y
     * fuente reales, regla 8 y estado inicial. Funcion pura (la verificacion de enlaces se inyecta).
     *
     * @param array[] $materiales Salida de parse_plan_json()['materiales'].
     * @param \stdClass[] $m2 Filas del Centro del diseño.
     * @param callable|null $verificar fn(string $url): array ['ok' => bool]; null = no se comprueban los enlaces.
     * @param string $fecha Fecha de verificacion (dd-mm-aaaa).
     * @return array[] Cada uno con codigo, nombre, fase, modo, formato, estado, categoria, origen (array) y entregada.
     */
    public static function plan_rows(array $materiales, array $m2, ?callable $verificar, string $fecha): array {
        $out = [];
        foreach (array_values($materiales) as $i => $m) {
            $orig = $m['categoria'] === self::CAT_NUEVO ? null : self::origin_row($m2, (string) $m['origen_codigo']);
            $entregada = $orig ? centro_recursos::delivered_version($orig) : null;
            $caidos = [];
            $verificados = false;
            if ($entregada && $verificar && $m['categoria'] === self::CAT_REUTILIZADO) {
                foreach (self::curated_links($entregada) as $e) {
                    $verificados = true;
                    if (empty($verificar($e['enlace'])['ok'])) {
                        $caidos[] = $e['enlace'];
                    }
                }
            }
            [$estado, $categoria, $nota] = self::initial_state($m['categoria'], $entregada, $caidos);
            $modo = $m['modo'];
            $formato = $m['formato'];
            if ($categoria === self::CAT_REUTILIZADO && $orig) {
                // Regla 5: un material reutilizado conserva su modo y su formato de salida del diseño.
                $modo = strtoupper(trim((string) $orig->modo)) ?: $modo;
                $formato = (string) $orig->formato ?: $formato;
            }
            $origen = [
                'origen_codigo' => $orig ? (string) $orig->codigo : (string) $m['origen_codigo'],
                'origen_version' => $entregada ? 'v' . (int) $entregada['version'] : '',
                'fuente' => $categoria === self::CAT_NUEVO ? '' : ($entregada ? self::FUENTE_REPERTORIO : self::FUENTE_ESPECIFICACION),
                'en_diseno' => $categoria === self::CAT_NUEVO || $orig !== null,
                'bloque' => $m['bloque'],
                'trayectoria' => $m['trayectoria'],
                'criterios' => $m['criterios'],
                'tipo' => $m['tipo'],
                'justificacion' => $m['justificacion'],
                'especificacion' => $m['especificacion'],
                'nota' => $nota,
                'regla8' => self::code_issue((string) $m['codigo'], (string) $m['categoria'], (string) $m['origen_codigo']),
                'enlaces_caidos' => $caidos,
                'fecha_verificacion' => $verificados ? $fecha : '',
            ];
            if ($categoria !== self::CAT_NUEVO && $orig === null) {
                $origen['nota'] = trim($origen['nota'] . ' [REVISAR: material no encontrado en el diseño]');
            }
            $out[] = [
                'orden' => $i + 1,
                'codigo' => (string) $m['codigo'],
                'nombre' => (string) $m['nombre'],
                'fase' => trim(implode(' · ', array_filter([(string) $m['bloque'], (string) $m['trayectoria']]))),
                'modo' => $modo,
                'formato' => $formato,
                'estado' => $estado,
                'categoria' => $categoria,
                'origen' => $origen,
                'entregada' => $estado === self::MAT_DISPONIBLE ? $entregada : null,
            ];
        }
        return $out;
    }

    /**
     * Si el material se produce como ajuste por secciones sobre su version de origen (ajustado, con origen entregado en
     * Markdown): el asistente entrega solo las secciones que cambian y la plataforma conserva textualmente las demas
     * (principio 5). Funcion pura.
     *
     * @param string $categoria
     * @param array|null $entregada
     * @param bool $esJson Si el material es estructurado (cuestionario, planilla, presentacion o Modo C).
     * @return bool
     */
    public static function partial_mode(string $categoria, ?array $entregada, bool $esJson): bool {
        return $categoria === self::CAT_AJUSTADO && !$esJson && $entregada !== null
            && trim((string) ($entregada['contenido'] ?? '')) !== '' && trim((string) ($entregada['estructurado'] ?? '')) === '';
    }

    /**
     * Secciones que el cuadro de cambios aprobado (P2.2) da como «Se elimina» o «Se conserva», por su titulo normalizado.
     * Lee la tabla «Sección | Se conserva / Se modifica / Se agrega / Se elimina | …». Funcion pura.
     *
     * @param string $html Propuesta aprobada (HTML).
     * @return array ['conserva' => string[], 'elimina' => string[]]
     */
    public static function change_table(string $html): array {
        $out = ['conserva' => [], 'elimina' => []];
        if (!preg_match_all('/<tr[^>]*>(.*?)<\/tr>/isu', $html, $filas)) {
            return $out;
        }
        foreach ($filas[1] as $fila) {
            if (!preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/isu', $fila, $celdas) || count($celdas[1]) < 2) {
                continue;
            }
            $accion = diseno::plain_lower(strip_tags($celdas[1][1]));
            $clave = centro_recursos::heading_key((string) $celdas[1][0]);
            if ($clave === '' || $clave === 'seccion') {
                continue;
            }
            if (preg_match('/^\s*se\s+elimina/u', $accion)) {
                $out['elimina'][] = $clave;
            } else if (preg_match('/^\s*se\s+conserva/u', $accion)) {
                $out['conserva'][] = $clave;
            }
        }
        return $out;
    }

    /**
     * Arma un material ajustado (P2.3): parte de la version de origen tal cual, reemplaza las secciones que trae el
     * asistente (por su titulo), agrega las nuevas al final, quita las que el cuadro aprobado elimina, pone al inicio la nota
     * para la persona facilitadora y el pie de la continuidad al final. Asi las secciones conservadas quedan identicas a la
     * version de origen (bloque 5). Funcion pura.
     *
     * @param string $origen HTML de la version de origen.
     * @param string $parcial HTML de las secciones modificadas o agregadas (sin la nota).
     * @param string $nota Nota breve con las secciones que cambiaron (HTML).
     * @param string[] $elimina Titulos normalizados de las secciones que se eliminan.
     * @param string $pie Linea de cierre.
     * @return string|null Null si el asistente no entrego ninguna seccion con titulo.
     */
    public static function merge_adjusted(string $origen, string $parcial, string $nota, array $elimina, string $pie): ?string {
        $sinpie = static fn(string $h): string => (string) preg_replace(
            '/<p>(?:(?!<\/p>).)*' . preg_quote(centro_recursos::PIE_MATERIAL, '/') . '(?:(?!<\/p>).)*<\/p>/isu', '', $h);
        $refsClave = centro_recursos::heading_key(centro_recursos::WEB_REFERENCIAS);
        // Secciones del asistente agrupadas: cada encabezado de primer nivel con sus subsecciones.
        $nuevas = [];
        foreach (centro_recursos::html_sections($sinpie($parcial)) as $s) {
            if ($s['nivel'] === 0) {
                continue;
            }
            $u = count($nuevas) - 1;
            if ($u >= 0 && $s['nivel'] > $nuevas[$u]['nivel']) {
                $nuevas[$u]['html'] .= $s['html'];
            } else {
                $nuevas[] = ['nivel' => $s['nivel'], 'clave' => $s['clave'], 'html' => $s['html']];
            }
        }
        if (!$nuevas) {
            return null;
        }
        $secciones = centro_recursos::html_sections($sinpie(centro_recursos::strip_web_marks($origen)));
        // Una seccion abarca sus subsecciones, salvo las referencias consultadas, que siempre son del material completo.
        $rango = static function(array $secs, int $i) use ($refsClave): int {
            $fin = $i + 1;
            while ($fin < count($secs) && $secs[$fin]['nivel'] > $secs[$i]['nivel'] && $secs[$fin]['clave'] !== $refsClave) {
                $fin++;
            }
            return $fin;
        };
        $buscar = static function(array $secs, string $clave): ?int {
            foreach ($secs as $k => $s) {
                if ($s['nivel'] > 0 && $s['clave'] !== '' && $s['clave'] === $clave) {
                    return $k;
                }
            }
            return null;
        };
        foreach ($elimina as $clave) {
            $i = $buscar($secciones, $clave);
            if ($i !== null) {
                array_splice($secciones, $i, $rango($secciones, $i) - $i);
            }
        }
        $agregadas = [];
        foreach ($nuevas as $n) {
            $i = $buscar($secciones, $n['clave']);
            if ($i === null) {
                $agregadas[] = $n;
                continue;
            }
            $fin = $rango($secciones, $i);
            if ($n['clave'] === $refsClave) {
                // Las referencias nuevas se suman a las de la version de origen.
                $cuerpo = (string) preg_replace('/^<h[1-6][^>]*>.*?<\/h[1-6]>/isu', '', $n['html']);
                $secciones[$fin - 1]['html'] .= $cuerpo;
                continue;
            }
            array_splice($secciones, $i, $fin - $i, [['nivel' => $n['nivel'], 'clave' => $n['clave'], 'html' => $n['html']]]);
        }
        // Las secciones nuevas van antes de las referencias y de las fuentes de la version de origen.
        $iref = $buscar($secciones, $refsClave);
        if ($agregadas) {
            $pos = $iref ?? count($secciones);
            array_splice($secciones, $pos, 0, array_map(static fn($n) => ['nivel' => $n['nivel'], 'clave' => $n['clave'],
                'html' => $n['html']], $agregadas));
        }
        $html = trim(implode('', array_map(static fn($s) => $s['html'], $secciones)));
        return $nota . $html . '<p><em>' . htmlspecialchars($pie, ENT_QUOTES, 'UTF-8') . '</em></p>';
    }

    /**
     * Separa la nota inicial para la persona facilitadora («> **Nota para la persona facilitadora:** …») del resto del
     * Markdown que entrega el asistente en un ajuste por secciones. Funcion pura.
     *
     * @param string $md
     * @return array [string $nota, string $resto]
     */
    public static function split_note(string $md): array {
        $lineas = explode("\n", trim($md));
        $nota = [];
        while ($lineas && (preg_match('/^\s*>/u', $lineas[0]) || ($nota && trim($lineas[0]) === ''))) {
            $nota[] = array_shift($lineas);
        }
        return [trim(implode("\n", $nota)), trim(implode("\n", $lineas))];
    }

    /**
     * Datos del material para los prompts. Funcion pura.
     *
     * @param array $m ['codigo', 'nombre', 'fase', 'modo', 'formato', 'categoria', 'origen' (array)].
     * @return string[]
     */
    private static function material_lines(array $m): array {
        $o = (array) ($m['origen'] ?? []);
        $l = ['- Código y nombre: ' . $m['codigo'] . ' · ' . $m['nombre'],
            '- Categoría: ' . self::category_label((string) ($m['categoria'] ?? ''))];
        if (($m['categoria'] ?? '') !== self::CAT_NUEVO && !empty($o['origen_codigo'])) {
            $l[] = '- Material de origen: ' . $o['origen_codigo'] . (!empty($o['origen_version']) ? ' ' . $o['origen_version'] : '')
                . ' (fuente: ' . (($o['fuente'] ?? '') === self::FUENTE_REPERTORIO ? 'repertorio del diseño'
                    : 'especificación de la Etapa 4 del diseño') . ')';
        }
        $l[] = '- Bloque y trayectoria: ' . ((string) ($m['fase'] ?? '') ?: 'según la Etapa 6');
        if (!empty($o['criterios'])) {
            $l[] = '- Criterios de evaluación a los que sirve: ' . $o['criterios'];
        }
        $l[] = '- Tipo: ' . ((string) ($o['tipo'] ?? '') ?: 'según la Etapa 6') . ' | Modo: ' . $m['modo']
            . ' | Formato de salida: ' . $m['formato'];
        if (!empty($o['justificacion'])) {
            $l[] = '- Justificación (Etapa 6): ' . $o['justificacion'];
        }
        if (!empty($o['enlaces_caidos'])) {
            $l[] = '- Enlaces de Modo C que dejaron de estar disponibles y se reemplazan con una nueva búsqueda: '
                . implode(', ', (array) $o['enlaces_caidos']) . '. Conserva las demás opciones del material de origen.';
        }
        return $l;
    }

    /**
     * Bloques de contexto comunes de los prompts de produccion. Funcion pura.
     *
     * @param array $m Material.
     * @param array $c Contexto: etapa6, origen (texto de la version de origen), diseno4 (Etapa 4 del diseño), ficha.
     * @return string[]
     */
    private static function context_lines(array $m, array $c): array {
        $o = (array) ($m['origen'] ?? []);
        $l = ['', guardrails::injection_clause(), ''];
        if (!empty($o['especificacion'])) {
            $l[] = 'ESPECIFICACIÓN DEL MATERIAL EN LA ETAPA 6 (CONSOLIDADA; NO LA MODIFIQUES):';
            $l[] = guardrails::isolate_user_content((string) $o['especificacion']);
            $l[] = '';
        }
        if (trim((string) ($c['origen'] ?? '')) !== '') {
            $l[] = 'MATERIAL DE ORIGEN ' . ($o['origen_codigo'] ?? '') . ' ' . ($o['origen_version'] ?? '')
                . ' (VERSIÓN VIGENTE DEL REPERTORIO DEL DISEÑO; SOLO LECTURA):';
            $l[] = guardrails::isolate_user_content(\core_text::substr((string) $c['origen'], 0, self::CONTEXTO_MAX));
            $l[] = '';
        }
        $l[] = 'EXPERIENCIA DE APRENDIZAJE DE CONTINUIDAD CONSOLIDADA (ETAPA 6, ACEPTADA):';
        $l[] = guardrails::isolate_user_content(\core_text::substr((string) ($c['etapa6'] ?? ''), 0, self::CONTEXTO_MAX));
        if (trim((string) ($c['diseno4'] ?? '')) !== '') {
            $l[] = '';
            $l[] = 'ETAPA 4 DEL DISEÑO VALIDADO (MÓDULO 2: DEFINICIÓN Y LISTA DE PRODUCCIÓN DE LOS MATERIALES; SOLO LECTURA):';
            $l[] = guardrails::isolate_user_content(\core_text::substr((string) $c['diseno4'], 0, self::CONTEXTO_MAX));
        }
        if (trim((string) ($c['ficha'] ?? '')) !== '') {
            $l[] = '';
            $l[] = 'FICHA DE REGISTRO DE LA IMPLEMENTACIÓN (para el nivel de lectura y las dificultades registradas):';
            $l[] = guardrails::isolate_user_content(\core_text::substr((string) $c['ficha'], 0, self::CONTEXTO_MAX));
        }
        return $l;
    }

    /**
     * Cabecera de rol de los prompts de produccion. Funcion pura.
     *
     * @param string $etapa
     * @return string[]
     */
    private static function header_lines(string $etapa): array {
        return [
            'Eres el ' . implementacion::ROL . ' operando en la PRODUCCIÓN DE MATERIALES DE LA EXPERIENCIA DE CONTINUIDAD ('
                . $etapa . '). No diseñas ni rediseñas la experiencia: produces sus materiales tal como quedaron especificados '
                . 'en la Etapa 6.',
            '',
            implode("\n", self::PRINCIPIOS),
            '',
            implode("\n", array_merge(['CONTROL DE CALIDAD INTERNO (antes de entregar):'], self::VERIFICACIONES_PRODUCCION)),
            '',
            centro_recursos::catalog_text(true),
        ];
    }

    /**
     * Reglas de busqueda web de la produccion de la continuidad: las del Centro con las fuentes de la continuidad y el
     * criterio de la normativa chilena vigente. Funcion pura.
     *
     * @return array Argumentos para centro_recursos::web_prompt_rules() y mode_instructions().
     */
    public static function web_args(): array {
        return ['la experiencia de continuidad consolidada, el material de origen, el diseño, el plan formativo, el perfil '
            . 'ocupacional y los materiales de referencia cargados', [self::REGLA_NORMATIVA_CHILE]];
    }

    /**
     * Prompt de la Etapa P1: plan de produccion de la continuidad (JSON). Funcion pura.
     *
     * @param string $etapa6 Etapa 6 aceptada (texto).
     * @param string $inventario Salida de inventory_text().
     * @return string
     */
    public static function build_plan_prompt(string $etapa6, string $inventario): string {
        return implode("\n", [
            'Eres el ' . implementacion::ROL . ' operando en la PRODUCCIÓN DE MATERIALES DE LA EXPERIENCIA DE CONTINUIDAD '
                . '(Etapa P1: plan de producción). No diseñas ni rediseñas: recuperas la lista de materiales de la continuidad '
                . 'que quedó en la Etapa 6 consolidada.',
            '',
            implode("\n", self::REGLAS),
            '',
            'TAREA — ETAPA P1: PLAN DE PRODUCCIÓN:',
            '- Recupera de la Etapa 6 la lista de materiales de la continuidad, en su orden, con exactamente sus códigos, '
                . 'categorías, origen, bloque y trayectoria, criterios, tipo, modo y formato de salida.',
            '- En «especificacion» copia la descripción del material de la Etapa 6 (los campos de la regla 7: en los ajustados, '
                . 'las secciones que se conservan, las que se modifican o agregan, el cambio y la evidencia; en los nuevos, los '
                . 'contenidos y su secuencia, la estructura y la extensión; en Modo C, la especificación de la búsqueda). En los '
                . 'reutilizados, déjala vacía.',
            '- No incluyas instrumentos de evaluación ni recursos físicos como materiales: los recursos del entorno formativo '
                . 'van en «recursos_entorno».',
            '- No cambies ningún código ni agregues materiales que no estén en la Etapa 6; la plataforma revisa la regla 8 y el '
                . 'estado de cada material en el repertorio del diseño.',
            '',
            guardrails::injection_clause(),
            '',
            'FORMATO DE RESPUESTA: únicamente un objeto JSON válido, sin texto antes ni después y sin bloques de código:',
            '{"materiales": [{"codigo": "M3-C", "nombre": "...", "categoria": "reutilizado|ajustado|nuevo", "origen_codigo": '
                . '"M3", "bloque": "Desarrollo", "trayectoria": "Nivelación|Profundización|Ambas", "criterios": "...", "tipo": '
                . '"Texto y datos estructurados", "modo": "A|B|C", "formato": "...", "justificacion": "...", '
                . '"especificacion": "..."}], "recursos_entorno": ["..."]}',
            '',
            $inventario,
            '',
            'EXPERIENCIA DE APRENDIZAJE DE CONTINUIDAD CONSOLIDADA (ETAPA 6, ACEPTADA):',
            guardrails::isolate_user_content($etapa6),
        ]);
    }

    /**
     * Prompt de la Etapa P2.2: propuesta del material (cuadro de cambios en los ajustados; estructura en los nuevos y en los
     * reutilizados que no se produjeron). Funcion pura.
     *
     * @param array $m Material.
     * @param array $c Contexto: datos (organismo, fechas…), etapa6, origen, diseno4, ficha, refs (string[]), producidos
     *        (string[]), ajuste (string).
     * @return string
     */
    public static function build_structure_prompt(array $m, array $c): string {
        $ajustado = ($m['categoria'] ?? '') === self::CAT_AJUSTADO;
        $d = (array) ($c['datos'] ?? []);
        $l = array_merge(self::header_lines('Etapa P2.2: propuesta'), [
            '',
            'MATERIAL:',
        ], self::material_lines($m), [
            '- Datos que no son de diseño: Organismo ejecutor: ' . (($d['organismo'] ?? '') ?: '(sin indicar)')
                . '; Fechas de la continuidad: ' . (($d['fechas'] ?? '') ?: '(sin indicar)')
                . '; Persona facilitadora: ' . (($d['facilitador'] ?? '') ?: '(sin indicar)') . '.',
            '',
            'TAREA — ETAPA P2.2: PROPUESTA (NO produzcas todavía el material completo). Presenta en Markdown claro y breve:',
        ]);
        if ($ajustado) {
            $l[] = '1. Material de origen, versión y fuente (repertorio o especificación del diseño).';
            $l[] = '2. Una tabla con las columnas «Sección», «Se conserva / Se modifica / Se agrega / Se elimina», «Cambio» y '
                . '«Evidencia o trayectoria que lo justifica», con una fila por sección del material de origen (con su título '
                . 'exacto) y una por cada sección que se agrega. En la segunda columna escribe exactamente una de estas cuatro '
                . 'opciones.';
            $l[] = '3. Extensión estimada y marcas [REVISAR] que se mantienen o se agregan.';
        } else {
            $l[] = '1. Nombre, código, modo y formato de salida.';
            $l[] = '2. Partes del material con su contenido en una línea cada una.';
            $l[] = '3. Extensión estimada y marcas [REVISAR] previstas.';
        }
        $l[] = '4. Materiales de referencia que se usarán y para qué contenidos (si se seleccionaron).';
        if (!empty($c['producidos'])) {
            $l[] = '5. Reutilización: materiales del diseño o de la continuidad a los que remitirás sin repetir: '
                . implode('; ', (array) $c['producidos']) . '.';
        }
        $l[] = 'Cierra con la pregunta exacta: «' . self::PREGUNTA_PROPUESTA . '»';
        if (!empty($c['refs'])) {
            $l[] = '';
            $l[] = 'MATERIALES TÉCNICOS DE REFERENCIA SELECCIONADOS: ' . implode(' | ', (array) $c['refs']);
        }
        if (trim((string) ($c['ajuste'] ?? '')) !== '') {
            $l[] = '';
            $l[] = 'AJUSTE SOLICITADO A LA PROPUESTA (si altera la experiencia consolidada, aplica el principio 1):';
            $l[] = guardrails::isolate_user_content((string) $c['ajuste']);
        }
        return implode("\n", array_merge($l, self::context_lines($m, $c)));
    }

    /**
     * Prompt de la Etapa P2.3: produccion del contenido. En un ajustado con origen en Markdown, solo las secciones que
     * cambian (la plataforma conserva textualmente las demas); en los demas casos, el material completo con las reglas de
     * cada modo del Modulo 2. Funcion pura.
     *
     * @param array $m Material.
     * @param array $c Contexto (como build_structure_prompt()) mas: estructura (propuesta aprobada), plataforma, web (bool),
     *        parcial (bool, ajuste por secciones), json (bool, el origen es JSON).
     * @return string
     */
    public static function build_content_prompt(array $m, array $c): string {
        $d = (array) ($c['datos'] ?? []);
        $pie = centro_recursos::footer_prefix((object) ['codigo' => $m['codigo'], 'categoria' => $m['categoria'] ?? '',
            'origen' => json_encode($m['origen'] ?? [])]) . ' · ' . centro_recursos::PIE_MATERIAL;
        $l = array_merge(self::header_lines('Etapa P2.3: producción del contenido'), [
            '',
            'MATERIAL A PRODUCIR:',
        ], self::material_lines($m), [
            '- Datos de cabecera: Organismo ejecutor: ' . ($d['organismo'] ?? '') . ' | Persona facilitadora: '
                . ($d['facilitador'] ?? '') . ' | Fechas de la continuidad: ' . ($d['fechas'] ?? '') . ' | Impresión: '
                . ($d['impresion'] ?? 'Carta'),
        ]);
        if (!empty($c['producidos'])) {
            $l[] = '- Materiales ya disponibles (remite a ellos en lugar de repetir): ' . implode('; ', (array) $c['producidos']);
        }
        $l[] = '';
        $l[] = 'PROPUESTA APROBADA POR LA PERSONA FACILITADORA (respétala íntegramente):';
        $l[] = guardrails::isolate_user_content((string) ($c['estructura'] ?? ''));
        $l[] = '';
        $l[] = '- Si el material tiene partes por trayectoria, identifícalas con claridad («Nivelación» y «Profundización»).';
        if (!empty($c['parcial'])) {
            $l[] = 'INSTRUCCIONES — MATERIAL AJUSTADO (solo las secciones que cambian):';
            $l[] = '- Empieza con una nota breve para la persona facilitadora, en una cita Markdown que empiece exactamente con '
                . '«> **Nota para la persona facilitadora:**», con las secciones que cambiaron.';
            $l[] = '- Después entrega SOLO las secciones que el cuadro aprobado marca «Se modifica» o «Se agrega», completas, cada '
                . 'una con su título exacto y con el mismo nivel de encabezado (## o ###) que tiene en el material de origen (las '
                . 'secciones agregadas, con ##). No entregues las secciones que se conservan ni las que se eliminan: la '
                . 'plataforma conserva textualmente las primeras desde la versión de origen y quita las segundas.';
            $l[] = '- Redacción formal, clara y estable, en español de Chile; listas y tablas Markdown; nunca dibujes cajas o '
                . 'flechas con caracteres. Para diagramas, usa bloques ```diagrama.';
            $l[] = diseno::REGLA_FORMULAS;
            if (!empty($c['web'])) {
                foreach (centro_recursos::web_prompt_rules(false, ...self::web_args()) as $regla) {
                    $l[] = $regla;
                }
            } else {
                $l[] = '- Incluye [REVISAR: motivo] en el lugar exacto cuando un dato técnico o normativo no provenga de las '
                    . 'fuentes autorizadas.';
            }
            $l[] = '- No escribas la línea de cierre del material: la agrega la plataforma.';
        } else {
            if (($m['categoria'] ?? '') === self::CAT_AJUSTADO && !empty($c['json'])) {
                $l[] = '- Parte del material de origen (JSON) y aplica solo los cambios aprobados: conserva igual todo lo demás.';
            }
            $l = array_merge($l, centro_recursos::mode_instructions($m, (string) ($c['plataforma'] ?? ''), !empty($c['web']),
                $pie, self::web_args()));
        }
        return implode("\n", array_merge($l, self::context_lines($m, $c)));
    }

    /**
     * Prompt de la Etapa P2.4: ajustes por seccion del material de la continuidad. Funcion pura.
     *
     * @param array $m Material.
     * @param string $actual Material actual (Markdown o JSON).
     * @param string $pedido Ajuste que pide la persona.
     * @param bool $esJson
     * @return string
     */
    public static function build_adjust_prompt(array $m, string $actual, string $pedido, bool $esJson): string {
        $pie = centro_recursos::footer_prefix((object) ['codigo' => $m['codigo'], 'categoria' => $m['categoria'] ?? '',
            'origen' => json_encode($m['origen'] ?? [])]) . ' · ' . centro_recursos::PIE_MATERIAL;
        return implode("\n", array_merge(self::header_lines('Etapa P2.4: revisión y ajustes'), [
            '',
            'REGLA DE AJUSTE:',
            '- Primero verifica si el pedido altera la experiencia de continuidad consolidada (criterios, actividades, tiempos '
                . 'o trayectorias). Si la altera, NO cambies el material y responde '
                . ($esJson ? 'un JSON {"rechazado": true, "mensaje": "' . self::RESPUESTA_NO_MODIFICA . '"}'
                    : 'exactamente: "' . self::RESPUESTA_NO_MODIFICA . '"'),
            '- Si corresponde a la redacción, los ejemplos o el formato dentro de lo especificado, modifica ÚNICAMENTE las '
                . 'secciones indicadas y conserva intacto el resto.',
            '- Conserva tal cual las marcas [[WEB:n|enlace]] y [[/WEB:n]] del texto obtenido de internet y no escribas la '
                . 'sección «' . centro_recursos::WEB_REFERENCIAS . '»: la arma la plataforma.',
            $esJson ? '- Entrega el JSON completo actualizado con exactamente la misma estructura.'
                : '- Entrega solo las secciones modificadas, en Markdown, cada una precedida por su título exacto y con el mismo '
                    . 'nivel de encabezado (## o ###): la plataforma las reemplaza en el material. Si la persona facilitadora pide '
                    . 'expresamente el material completo, entrégalo completo y termina con la línea de cierre «' . $pie . '».',
            '',
            guardrails::injection_clause(),
            '',
            'MATERIAL ACTUAL (' . $m['codigo'] . ' · ' . $m['nombre'] . '):',
            guardrails::isolate_user_content($actual),
            '',
            'AJUSTE SOLICITADO POR LA PERSONA FACILITADORA:',
            guardrails::isolate_user_content($pedido),
        ]));
    }

    /**
     * Repertorio de la experiencia de continuidad (Etapa P4). Funcion pura.
     *
     * @param array $meta ['experiencia', 'plan', 'modulo', 'organismo'].
     * @param \stdClass[] $recursos Filas del plan de la continuidad.
     * @return string HTML.
     */
    public static function repertory_html(array $meta, array $recursos): string {
        $e = static fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $html = '<h2>Repertorio de la experiencia de continuidad</h2>'
            . '<p><strong>Experiencia de continuidad:</strong> ' . $e((string) ($meta['experiencia'] ?? ''))
            . (!empty($meta['plan']) ? '<br><strong>Plan formativo / Módulo:</strong> ' . $e((string) $meta['plan'])
                . (!empty($meta['modulo']) ? ' · ' . $e((string) $meta['modulo']) : '') : '')
            . (!empty($meta['organismo']) ? '<br><strong>Organismo ejecutor:</strong> ' . $e((string) $meta['organismo']) : '')
            . '</p>';
        $html .= '<h3>1. Materiales del plan de producción</h3><table class="table table-sm"><thead><tr><th>Código</th>'
            . '<th>Nombre</th><th>Categoría</th><th>Origen y versión</th><th>Modo</th><th>Formatos entregados</th>'
            . '<th>Versión</th><th>Estado</th><th>Marcas [REVISAR]</th></tr></thead><tbody>';
        $total = 0;
        $modoC = [];
        foreach ($recursos as $r) {
            $o = (array) json_decode((string) ($r->origen ?? ''), true);
            $estado = self::status_label((string) $r->estado);
            $listo = in_array((string) $r->estado, [self::MAT_DISPONIBLE, centro_recursos::MAT_ENTREGADO,
                centro_recursos::MAT_ENTREGADO_PARCIAL], true);
            $rev = $listo ? (int) $r->revisar_count : 0;
            $total += $rev;
            $origen = ($r->categoria ?? '') === self::CAT_NUEVO || empty($o['origen_codigo']) ? '—'
                : $o['origen_codigo'] . (!empty($o['origen_version']) ? ' ' . $o['origen_version'] : '');
            $version = (string) $r->estado === self::MAT_DISPONIBLE ? 'Repertorio del diseño'
                : ((int) $r->version > 0 ? 'v' . (int) $r->version : '—');
            $html .= '<tr><td>' . $e((string) $r->codigo) . '</td><td>' . $e((string) $r->nombre) . '</td><td>'
                . $e(self::category_label((string) ($r->categoria ?? ''))) . '</td><td>' . $e($origen) . '</td><td>'
                . $e((string) $r->modo) . '</td><td>' . $e($listo ? (string) $r->formato : '—') . '</td><td>' . $e($version)
                . '</td><td>' . $e($estado) . '</td><td>' . ($listo ? $rev : '—') . '</td></tr>';
            if ($listo && strtoupper((string) $r->modo) === 'C' && !empty($r->estructurado)) {
                $cur = (array) json_decode((string) $r->estructurado, true);
                $sel = (array) (json_decode((string) ($r->seleccionados ?? '[]'), true) ?: []);
                foreach ($sel as $i) {
                    if (isset($cur['opciones'][$i])) {
                        $modoC[] = ['codigo' => (string) $r->codigo, 'op' => $cur['opciones'][$i],
                            'fecha' => (string) ($r->fecha_verificacion ?? '')];
                    }
                }
            }
        }
        $html .= '</tbody></table><h3>2. Recursos seleccionados en Modo C</h3>';
        if ($modoC) {
            $html .= '<ul>';
            foreach ($modoC as $x) {
                $html .= '<li><strong>' . $e($x['codigo']) . ' — ' . $e((string) ($x['op']['titulo'] ?? '')) . '</strong>: <a href="'
                    . $e((string) ($x['op']['enlace'] ?? '')) . '" target="_blank" rel="noopener">'
                    . $e((string) ($x['op']['enlace'] ?? '')) . '</a>'
                    . ($x['fecha'] !== '' ? ' · verificado el ' . $e($x['fecha']) : '') . '</li>';
            }
            $html .= '</ul>';
        } else {
            $html .= '<p>No hay recursos de curaduría seleccionados en la continuidad.</p>';
        }
        $html .= '<p><strong>' . $e(self::RECORDATORIO_P4) . '</strong>'
            . ($total > 0 ? ' Hay ' . $total . ' marca(s) [REVISAR] en los materiales disponibles o entregados.' : '') . '</p>'
            . '<p><em>' . $e(self::USO_REPERTORIO) . '</em></p>';
        return $html;
    }

    /**
     * Nombre de un estado del plan de la continuidad. Funcion pura.
     *
     * @param string $estado
     * @return string
     */
    public static function status_label(string $estado): string {
        return [self::MAT_DISPONIBLE => 'Disponible en el repertorio', centro_recursos::MAT_ENTREGADO => 'Entregado',
            centro_recursos::MAT_ENTREGADO_PARCIAL => 'Entregado parcialmente'][$estado] ?? 'Pendiente';
    }

    /**
     * Estado de cada material de la continuidad para la columna «Estado» del documento operativo (P4). Funcion pura.
     *
     * @param \stdClass[] $recursos Filas del plan de la continuidad.
     * @return array codigo => estado.
     */
    public static function status_map(array $recursos): array {
        $mapa = [];
        foreach ($recursos as $r) {
            $cod = strtoupper(trim((string) $r->codigo));
            if ($cod !== '' && strpos($cod, '(ANT.)') === false) {
                $mapa[$cod] = self::status_label((string) $r->estado);
            }
        }
        return $mapa;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Base de datos.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Condicion de activacion (Bloque 4): ficha validada y vigente, y Etapa 8 consolidada y al dia.
     *
     * @param int $userid
     * @param int $courseid
     * @return array ['ok' => bool, 'ficha', 'fase1', 'filas', 'diseno', 'implid']
     */
    public static function activation(int $userid, int $courseid): array {
        $ficha = implementacion::get_ficha($userid, $courseid);
        $fase1 = implementacion::fase1_ok($userid, $courseid);
        $filas = $ficha ? calidad::get_by_impl((int) $ficha->id) : [];
        $aceptadas = calidad::accepted($filas, $fase1 ? (string) $fase1->huella : '');
        $ok = $ficha && $fase1 && in_array(calidad::CONSOLIDACION, $aceptadas, true) && in_array(6, $aceptadas, true);
        $diseno = ($ok && (int) $ficha->disenoid > 0) ? implementacion::get_design((int) $ficha->disenoid, $userid) : null;
        return ['ok' => $ok && $diseno !== null, 'ficha' => $ficha, 'fase1' => $fase1, 'filas' => $filas, 'diseno' => $diseno,
            'implid' => $ficha ? (int) $ficha->id : 0];
    }

    /**
     * Crea o recupera el plan de produccion de la continuidad.
     *
     * @param int $implid
     * @param int $userid
     * @param int $courseid
     * @param array $fichaData Datos de la ficha de implementacion (organismo y facilitador).
     * @return \stdClass
     */
    public static function ensure_plan(int $implid, int $userid, int $courseid, array $fichaData = []): \stdClass {
        global $DB;
        $clave = self::plan_key($implid);
        $plan = centro_recursos::get_plan($clave);
        if ($plan) {
            return $plan;
        }
        $now = time();
        $datos = [
            'organismo' => (string) ($fichaData['organismo'] ?? ''),
            'curso' => (string) ($fichaData['plan'] ?? ''),
            'facilitador' => (string) ($fichaData['facilitador'] ?? ''),
            'fechas' => '',
            'impresion' => 'Carta',
        ];
        $rec = (object) ['fichaid' => $clave, 'userid' => $userid, 'courseid' => $courseid, 'huella' => '',
            'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE), 'estado' => centro_recursos::PLAN_SIN_INICIAR,
            'generacion' => '', 'error' => null, 'modelo' => '', 'promptversion' => self::PROMPT_VERSION, 'timeclosed' => 0,
            'timecreated' => $now, 'timemodified' => $now];
        $rec->id = $DB->insert_record('local_chatbot_recurso_plan', $rec);
        $rec->datos_arr = $datos;
        return $rec;
    }

    /**
     * Guarda los datos que no son de diseño (P1: se solicitan una sola vez).
     *
     * @param int $implid
     * @param int $userid
     * @param array $datos
     */
    public static function save_meta(int $implid, int $userid, array $datos): void {
        global $DB;
        $plan = $DB->get_record('local_chatbot_recurso_plan', ['fichaid' => self::plan_key($implid), 'userid' => $userid]);
        if (!$plan) {
            return;
        }
        $actual = (array) json_decode((string) ($plan->datos ?? '{}'), true);
        foreach (['organismo' => 200, 'curso' => 200, 'facilitador' => 200, 'fechas' => 200, 'impresion' => 60] as $k => $max) {
            $actual[$k] = trim(mb_substr((string) ($datos[$k] ?? ''), 0, $max, 'UTF-8'));
        }
        $actual['impresion'] = $actual['impresion'] ?: 'Carta';
        $DB->update_record('local_chatbot_recurso_plan', (object) ['id' => $plan->id,
            'datos' => json_encode($actual, JSON_UNESCAPED_UNICODE), 'timemodified' => time()]);
    }

    /**
     * Deja pendiente la generacion del plan (P1).
     *
     * @param int $implid
     * @param int $userid
     * @param int $courseid
     * @param array $fichaData
     * @return int Id del plan.
     */
    public static function request_plan(int $implid, int $userid, int $courseid, array $fichaData = []): int {
        global $DB;
        $plan = self::ensure_plan($implid, $userid, $courseid, $fichaData);
        if (!in_array($plan->generacion, [exante::PENDIENTE, exante::PROCESANDO], true)) {
            $DB->update_record('local_chatbot_recurso_plan', (object) ['id' => $plan->id, 'generacion' => exante::PENDIENTE,
                'error' => null, 'timemodified' => time()]);
        }
        return (int) $plan->id;
    }

    /**
     * Ejecuta la generacion del plan (P1): el asistente recupera la lista de la Etapa 6 y la plataforma la cruza con el
     * repertorio del diseño (origen, version, estado inicial, enlaces de Modo C).
     *
     * @param int $planid
     * @param int $userid
     * @return string Estado de la generacion.
     */
    public static function run_plan(int $planid, int $userid): string {
        global $DB;
        $candado = \core\lock\lock_config::get_lock_factory('local_chatbot_centro')->get_lock('plan' . $planid, 0);
        if (!$candado) {
            return exante::PROCESANDO;
        }
        $fallar = static function(string $motivo) use ($DB, $planid): string {
            $DB->update_record('local_chatbot_recurso_plan', (object) ['id' => $planid, 'generacion' => exante::ERROR,
                'error' => $motivo, 'timemodified' => time()]);
            return exante::ERROR;
        };
        try {
            $plan = $DB->get_record('local_chatbot_recurso_plan', ['id' => $planid, 'userid' => $userid]);
            if (!$plan || $plan->generacion !== exante::PENDIENTE || (int) $plan->fichaid >= 0) {
                return $plan ? (string) $plan->generacion : exante::ERROR;
            }
            $DB->update_record('local_chatbot_recurso_plan', (object) ['id' => $planid, 'generacion' => exante::PROCESANDO,
                'timemodified' => time()]);
            if (!ai\provider_factory::is_configured()) {
                return $fallar('sinclave');
            }
            $implid = self::impl_from_key((int) $plan->fichaid);
            $ficha = implementacion::get_ficha_by_id($implid, $userid);
            $filas = $ficha ? calidad::get_by_impl($implid) : [];
            $e6 = $filas[6] ?? null;
            if (!$ficha || !$e6 || $e6->estado !== calidad::ACEPTADO || trim((string) $e6->contenido) === '') {
                return $fallar('sindiseno');
            }
            $m2 = (int) $ficha->disenoid > 0 ? centro_recursos::get_resources((int) $ficha->disenoid) : [];
            $refs = (int) $ficha->disenoid > 0 ? centro_recursos::list_references((int) $ficha->disenoid) : [];
            $prompt = self::build_plan_prompt(html_to_text((string) $e6->contenido, 0, false), self::inventory_text($m2, $refs));
            $modelo = ai\provider_factory::get_configured_model();
            $parsed = null;
            for ($i = 0; $i < 2 && $parsed === null; $i++) {
                $res = exante::generate_steady([['role' => 'user', 'parts' => [['text' => $prompt]]]], 180, $modelo, false);
                if (isset($res['error'])) {
                    return $fallar('servicio');
                }
                $parsed = self::parse_plan_json(gemini_client::extract_text($res));
            }
            if ($parsed === null) {
                return $fallar('ilegible');
            }
            $filasPlan = self::plan_rows($parsed['materiales'], $m2, [centro_recursos::class, 'verify_url'],
                userdate(time(), '%d-%m-%Y'));
            self::store_plan_rows($plan, $filasPlan, $userid);
            $datos = (array) json_decode((string) ($plan->datos ?? '{}'), true);
            $datos['recursos_entorno'] = $parsed['recursos_entorno'];
            $c8 = $filas[calidad::CONSOLIDACION] ?? null;
            $DB->update_record('local_chatbot_recurso_plan', (object) ['id' => $planid, 'huella' => (string) ($c8->huella ?? ''),
                'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE), 'estado' => centro_recursos::PLAN_EN_PRODUCCION,
                'generacion' => exante::LISTO, 'modelo' => (string) $modelo, 'promptversion' => self::PROMPT_VERSION,
                'error' => null, 'timemodified' => time()]);
            return exante::LISTO;
        } finally {
            $candado->release();
        }
    }

    /**
     * Guarda las filas del plan: lo ya producido se conserva (se actualizan sus datos), lo pendiente se rehace y lo que
     * salio del plan sin producirse se quita. Los disponibles copian la version vigente del repertorio del diseño.
     *
     * @param \stdClass $plan
     * @param array[] $filasPlan Salida de plan_rows().
     * @param int $userid
     */
    private static function store_plan_rows(\stdClass $plan, array $filasPlan, int $userid): void {
        global $DB;
        $now = time();
        $existentes = [];
        foreach (centro_recursos::get_resources((int) $plan->fichaid) as $r) {
            $existentes[strtoupper(trim((string) $r->codigo))] = $r;
        }
        $vistos = [];
        foreach ($filasPlan as $f) {
            $cod = strtoupper($f['codigo']);
            $vistos[$cod] = true;
            $campos = ['orden' => $f['orden'], 'nombre' => mb_substr($f['nombre'], 0, 255, 'UTF-8'),
                'fase' => mb_substr($f['fase'], 0, 200, 'UTF-8'), 'modo' => $f['modo'], 'formato' => mb_substr($f['formato'], 0,
                200, 'UTF-8'), 'categoria' => $f['categoria'], 'origen' => json_encode($f['origen'], JSON_UNESCAPED_UNICODE),
                'especificacion' => 'completa', 'espec_detalle' => '', 'timemodified' => $now];
            if ($f['entregada'] !== null) {
                $ent = $f['entregada'];
                $campos += ['estado' => self::MAT_DISPONIBLE, 'version' => (int) $ent['version'],
                    'contenido' => (string) $ent['contenido'], 'estructurado' => (string) $ent['estructurado'] ?: null,
                    'html_moodle' => (string) $ent['html_moodle'], 'seleccionados' => (string) $ent['seleccionados'] ?: null,
                    'fecha_verificacion' => (string) ($f['origen']['fecha_verificacion'] ?: ($ent['fecha_verificacion'] ?? '')),
                    'revisar_count' => (int) ($ent['revisar_count'] ?? 0), 'generacion' => null, 'error' => null];
            }
            $ex = $existentes[$cod] ?? null;
            $producido = $ex && ((int) $ex->version > 0 || in_array((string) $ex->estado, [centro_recursos::MAT_ESTRUCTURA,
                centro_recursos::MAT_BORRADOR], true));
            if ($ex && $producido && (string) $ex->estado !== self::MAT_DISPONIBLE) {
                // Lo producido en la continuidad se conserva.
                unset($campos['estado']);
                $DB->update_record('local_chatbot_recurso', (object) (['id' => $ex->id] + array_intersect_key($campos,
                    array_flip(['orden', 'nombre', 'fase', 'categoria', 'origen', 'timemodified']))));
            } else if ($ex) {
                $campos += ['estado' => $f['estado'], 'version' => 0, 'contenido' => null, 'estructurado' => null,
                    'html_moodle' => null, 'seleccionados' => null, 'estructura' => null, 'revisar_count' => 0,
                    'generacion' => null, 'fase_gen' => null, 'ajustes' => null, 'error' => null];
                $DB->update_record('local_chatbot_recurso', (object) (['id' => $ex->id] + $campos));
            } else {
                $campos += ['estado' => $f['estado'], 'version' => 0, 'revisar_count' => 0];
                $DB->insert_record('local_chatbot_recurso', (object) ($campos + ['planid' => (int) $plan->id,
                    'fichaid' => (int) $plan->fichaid, 'userid' => $userid, 'courseid' => (int) $plan->courseid,
                    'codigo' => mb_substr($f['codigo'], 0, 20, 'UTF-8'), 'timecreated' => $now]));
            }
        }
        foreach ($existentes as $cod => $r) {
            if (isset($vistos[$cod])) {
                continue;
            }
            if ((int) $r->version > 0 && (string) $r->estado !== self::MAT_DISPONIBLE) {
                $DB->update_record('local_chatbot_recurso', (object) ['id' => $r->id, 'orden' => 900, 'timemodified' => $now]);
            } else {
                $DB->delete_records('local_chatbot_recurso', ['id' => $r->id]);
            }
        }
    }

    /**
     * Contexto de generacion de un material de la continuidad para centro_recursos::run_resource(): la Etapa 6 aceptada, la
     * version de origen del repertorio del diseño, la Etapa 4 del diseño, la ficha, los materiales de referencia (de la
     * continuidad y del diseño) y los constructores de prompt de P2.2 a P2.4.
     *
     * @param \stdClass $r Fila del plan de la continuidad.
     * @param int $userid
     * @return array|null Mismas claves que centro_recursos::resource_context().
     */
    public static function resource_context(\stdClass $r, int $userid): ?array {
        $implid = self::impl_from_key((int) $r->fichaid);
        $ficha = implementacion::get_ficha_by_id($implid, $userid);
        $plan = centro_recursos::get_plan((int) $r->fichaid);
        $filas = $ficha ? calidad::get_by_impl($implid) : [];
        $e6 = $filas[6] ?? null;
        if (!$ficha || !$plan || !$e6 || $e6->estado !== calidad::ACEPTADO) {
            return null;
        }
        $disenoid = (int) $ficha->disenoid;
        $diseno = $disenoid > 0 ? implementacion::get_design($disenoid, $userid) : null;
        $m2 = $disenoid > 0 ? centro_recursos::get_resources($disenoid) : [];
        $origen = (array) json_decode((string) ($r->origen ?? ''), true);
        $categoria = (string) ($r->categoria ?? '');
        $orig = $categoria === self::CAT_NUEVO ? null : self::origin_row($m2, (string) ($origen['origen_codigo'] ?? ''));
        $entregada = $orig ? centro_recursos::delivered_version($orig) : null;
        $origenTexto = '';
        if ($entregada) {
            $origenTexto = trim((string) $entregada['estructurado']) !== '' ? (string) $entregada['estructurado']
                : html_to_text(centro_recursos::headings_as_markdown(centro_recursos::strip_web_marks(
                    (string) $entregada['contenido'])), 0, false);
        }
        $refIds = (array) json_decode((string) ($r->refs_usadas ?? '[]'), true);
        [$p1, $n1] = centro_recursos::reference_parts((int) $r->fichaid, $refIds);
        [$p2, $n2] = $disenoid > 0 ? centro_recursos::reference_parts($disenoid, $refIds) : [[], []];
        $producidos = [];
        foreach (centro_recursos::get_resources((int) $r->fichaid) as $otro) {
            if ((int) $otro->id !== (int) $r->id && in_array((string) $otro->estado, [self::MAT_DISPONIBLE,
                    centro_recursos::MAT_ENTREGADO, centro_recursos::MAT_ENTREGADO_PARCIAL], true)) {
                $producidos[] = $otro->codigo . ': ' . $otro->nombre;
            }
        }
        $mat = ['codigo' => (string) $r->codigo, 'nombre' => (string) $r->nombre, 'fase' => (string) $r->fase,
            'modo' => (string) $r->modo, 'formato' => (string) $r->formato, 'especificacion' => 'completa',
            'espec_detalle' => '', 'categoria' => $categoria, 'origen' => $origen];
        $modo = strtoupper(trim((string) $r->modo));
        $formatos = centro_recursos::detect_formats($modo, (string) $r->formato, (string) $r->nombre,
            (string) ($r->plataforma_quiz ?? ''));
        $esJson = $modo === 'C' || centro_recursos::is_quiz_material((string) $r->nombre, (string) $r->formato)
            || in_array('xlsx', $formatos, true)
            || (in_array('pptx', $formatos, true) && !in_array('docx', $formatos, true) && !in_array('pdf', $formatos, true));
        $parcial = self::partial_mode($categoria, $entregada, $esJson);
        $c = [
            'datos' => (array) $plan->datos_arr,
            'etapa6' => html_to_text((string) $e6->contenido, 0, false),
            'origen' => $origenTexto,
            'diseno4' => (string) ($diseno['etapas'][4] ?? ''),
            'ficha' => pseudonymizer::scrub(implementacion::to_text((array) $ficha->data)),
            'refs' => array_merge($n1, $n2),
            'producidos' => $producidos,
            'json' => $entregada && trim((string) $entregada['estructurado']) !== '',
        ];
        $pie = centro_recursos::footer_prefix($r) . ' · ' . centro_recursos::PIE_MATERIAL;
        $ctx = [
            'material' => $mat,
            'adjuntos' => [],
            'refparts' => array_merge($p1, $p2),
            'version' => self::PROMPT_VERSION,
            // Principio 3 modificado: la busqueda de contenidos complementarios es parte de la regla, sin interruptor aparte.
            'web' => true,
            'fuentes' => 'la experiencia de continuidad, el material de origen, el diseño ni los materiales de referencia',
            'rechazos' => ['Ese cambio modifica la experiencia de continuidad consolidada'],
            'estructura' => static fn(string $ajuste): string => self::build_structure_prompt($mat, $c + ['ajuste' => $ajuste]),
            'contenido' => static fn(string $estructura, string $plataforma, bool $conWeb): string => self::build_content_prompt(
                $mat, $c + ['estructura' => $estructura, 'plataforma' => $plataforma, 'web' => $conWeb, 'parcial' => $parcial]),
            'ajuste' => static fn(string $actual, string $pedido, bool $enJson): string => self::build_adjust_prompt($mat,
                $actual, $pedido, $enJson),
        ];
        if ($parcial) {
            // Ajuste por secciones: la plataforma conserva textualmente la version de origen (principio 5 y bloque 5).
            $origenHtml = (string) $entregada['contenido'];
            $estructuraHtml = (string) ($r->estructura ?? '');
            $ctx['fusion'] = static function(string $raw) use ($origenHtml, $estructuraHtml, $pie): ?string {
                [$nota, $resto] = self::split_note(diseno::clean_markdown($raw));
                [$md, $bloques] = centro_recursos::prepare_web_markdown($resto, [centro_recursos::class, 'verify_url']);
                $parcialHtml = centro_recursos::web_restore(diseno::product_html($md), $bloques);
                $notaHtml = $nota !== '' ? diseno::product_html($nota) : '';
                return self::merge_adjusted($origenHtml, $parcialHtml, $notaHtml, self::change_table($estructuraHtml)['elimina'],
                    $pie);
            };
        }
        return $ctx;
    }
}

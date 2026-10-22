<?php
/**
 * Admin settings for local_chatbot: proveedor de IA y credenciales.
 *
 * El prompt del sistema ya no vive aqui: paso a ser un prompt por modo
 * funcional, versionado, editable en la pagina de instrumento. La regla de
 * gobernanza pide trazabilidad de cambios sobre los prompts, y un campo de
 * texto que se sobrescribe no la da.
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_chatbot', get_string('pluginname', 'local_chatbot'));
    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_chatbot_instrument',
        get_string('instrument_heading', 'local_chatbot'),
        new moodle_url('/local/chatbot/instrumento.php'),
        'local/chatbot:manageinstrument'
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_chatbot_kb',
        get_string('managekb_heading', 'local_chatbot'),
        new moodle_url('/local/chatbot/manage_kb.php'),
        'local/chatbot:managekb'
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_chatbot_analytics',
        get_string('analytics_heading', 'local_chatbot'),
        new moodle_url('/local/chatbot/indicadores.php'),
        'local/chatbot:viewanalytics'
    ));

    // Mejoras de experiencia: cada una se puede apagar sin desplegar nada. Es la forma de
    // retroceder si una no convence, porque bajar de version el codigo no es posible.
    $settings->add(new admin_setting_heading('local_chatbot/heading_ux',
        get_string('ux_heading', 'local_chatbot'), get_string('ux_heading_desc', 'local_chatbot')));
    foreach (\local_chatbot\ux::DEFAULTS as $feature => $default) {
        $settings->add(new admin_setting_configcheckbox(
            'local_chatbot/ux_' . $feature,
            get_string('ux_' . $feature, 'local_chatbot'),
            get_string('ux_' . $feature . '_desc', 'local_chatbot'),
            $default
        ));
    }
    // Cursos que se abren al cerrar el Modulo 1 (Modulos 2 y 3), por id, separados por coma.
    $settings->add(new admin_setting_configtext(
        'local_chatbot/nextmodules',
        get_string('nextmodules_setting', 'local_chatbot'),
        get_string('nextmodules_setting_desc', 'local_chatbot'),
        '',
        PARAM_TEXT
    ));

    // Cursos donde el asistente no aparece (por ejemplo, los Modulos 2 y 3), por id, separados por coma.
    $settings->add(new admin_setting_configtext(
        'local_chatbot/nochatcourses',
        get_string('nochatcourses_setting', 'local_chatbot'),
        get_string('nochatcourses_setting_desc', 'local_chatbot'),
        '',
        PARAM_TEXT
    ));

    // Indicadores: nombres de las personas detenidas. Apagado por defecto: el panel es anonimo.
    $settings->add(new admin_setting_configcheckbox(
        'local_chatbot/dashpeople',
        get_string('dashpeople_setting', 'local_chatbot'),
        get_string('dashpeople_setting_desc', 'local_chatbot'),
        0
    ));

    // Cursos donde el asistente SI aparece, y solo en ellos (por ejemplo, el Modulo 1), por id.
    $settings->add(new admin_setting_configtext(
        'local_chatbot/chatonlycourses',
        get_string('chatonlycourses_setting', 'local_chatbot'),
        get_string('chatonlycourses_setting_desc', 'local_chatbot'),
        '',
        PARAM_TEXT
    ));

    // Ruta de derivacion a soporte humano. La gobernanza del modulo la exige
    // para cuando hay falla tecnica, incertidumbre metodologica relevante o una
    // solicitud que excede el alcance del modulo: el asistente debe poder
    // decir a donde acudir, no solo que no puede ayudar.
    $settings->add(new admin_setting_heading('local_chatbot/heading_support',
        get_string('support_heading', 'local_chatbot'),
        get_string('support_heading_desc', 'local_chatbot')));
    $settings->add(new admin_setting_configtext(
        'local_chatbot/support_contact',
        get_string('support_contact', 'local_chatbot'),
        get_string('support_contact_desc', 'local_chatbot'),
        '',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_chatbot/support_url',
        get_string('support_url', 'local_chatbot'),
        get_string('support_url_desc', 'local_chatbot'),
        '',
        PARAM_URL
    ));

    // Transparencia del tratamiento de datos (RU-CONS-004). La declaracion de
    // consentimiento tiene que decir quien procesa, donde, por cuanto tiempo y
    // a quien reclamar; nada de eso se puede deducir del codigo, asi que se
    // configura por sitio y se muestra literalmente antes de aceptar.
    $settings->add(new admin_setting_heading('local_chatbot/heading_transparency',
        get_string('transparency_heading', 'local_chatbot'),
        get_string('transparency_heading_desc', 'local_chatbot')));
    $settings->add(new admin_setting_configtext(
        'local_chatbot/provider_name',
        get_string('provider_name', 'local_chatbot'),
        get_string('provider_name_desc', 'local_chatbot'),
        '',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_chatbot/data_region',
        get_string('data_region', 'local_chatbot'),
        get_string('data_region_desc', 'local_chatbot'),
        '',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_chatbot/message_retention_months',
        get_string('retention_months', 'local_chatbot'),
        get_string('retention_months_desc', 'local_chatbot'),
        '12',
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_chatbot/rights_contact',
        get_string('rights_contact', 'local_chatbot'),
        get_string('rights_contact_desc', 'local_chatbot'),
        '',
        PARAM_TEXT
    ));

    // Version del instrumento diagnostico (RU-COA-DATA-001 y RU-COA-OUT-001).
    // El instrumento es editable: sin version, dos resultados obtenidos con
    // items distintos serian indistinguibles en la matriz y en la analitica.
    $settings->add(new admin_setting_configtext(
        'local_chatbot/diag_version',
        get_string('diag_version', 'local_chatbot'),
        get_string('diag_version_desc', 'local_chatbot'),
        'DIAG-M1-v1.0',
        PARAM_TEXT
    ));

    // El plugin no depende de un proveedor concreto: la capa classes/ai/
    // traduce el formato interno al de cada API.
    $settings->add(new admin_setting_configselect(
        'local_chatbot/ai_provider',
        get_string('ai_provider', 'local_chatbot'),
        get_string('ai_provider_desc', 'local_chatbot'),
        'gemini',
        [
            'gemini' => 'Gemini (Google)',
            'anthropic' => 'Claude (Anthropic)',
            'openai' => 'OpenAI',
            'azure_foundry' => 'Azure AI Foundry',
        ]
    ));

    $settings->add(new admin_setting_heading('local_chatbot/heading_gemini',
        'Gemini (Google)', get_string('provider_heading_desc', 'local_chatbot')));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_chatbot/gemini_api_key',
        get_string('gemini_api_key', 'local_chatbot'),
        get_string('gemini_api_key_desc', 'local_chatbot'),
        '',
        PARAM_RAW
    ));
    $settings->add(new admin_setting_configtext(
        'local_chatbot/gemini_model',
        get_string('provider_model', 'local_chatbot'),
        get_string('provider_model_desc', 'local_chatbot', 'gemini-3.5-flash-lite'),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_heading('local_chatbot/heading_anthropic',
        'Claude (Anthropic)', get_string('provider_heading_desc', 'local_chatbot')));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_chatbot/anthropic_api_key',
        get_string('anthropic_api_key', 'local_chatbot'),
        get_string('anthropic_api_key_desc', 'local_chatbot'),
        '',
        PARAM_RAW
    ));
    $settings->add(new admin_setting_configtext(
        'local_chatbot/anthropic_model',
        get_string('provider_model', 'local_chatbot'),
        get_string('provider_model_desc', 'local_chatbot', 'claude-haiku-4-5'),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_heading('local_chatbot/heading_openai',
        'OpenAI', get_string('provider_heading_desc', 'local_chatbot')));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_chatbot/openai_api_key',
        get_string('openai_api_key', 'local_chatbot'),
        get_string('openai_api_key_desc', 'local_chatbot'),
        '',
        PARAM_RAW
    ));
    $settings->add(new admin_setting_configtext(
        'local_chatbot/openai_model',
        get_string('provider_model', 'local_chatbot'),
        get_string('provider_model_desc', 'local_chatbot', 'gpt-4o-mini'),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_heading('local_chatbot/heading_azure',
        'Azure AI Foundry', get_string('azure_heading_desc', 'local_chatbot')));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_chatbot/azure_api_key',
        get_string('azure_api_key', 'local_chatbot'),
        '',
        '',
        PARAM_RAW
    ));
    $settings->add(new admin_setting_configtext(
        'local_chatbot/azure_endpoint',
        get_string('azure_endpoint', 'local_chatbot'),
        get_string('azure_endpoint_desc', 'local_chatbot'),
        '',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configtext(
        'local_chatbot/azure_deployment',
        get_string('azure_deployment', 'local_chatbot'),
        get_string('azure_deployment_desc', 'local_chatbot'),
        '',
        PARAM_RAW
    ));
    $settings->add(new admin_setting_configtext(
        'local_chatbot/azure_api_version',
        get_string('azure_api_version', 'local_chatbot'),
        '',
        '2024-08-01-preview',
        PARAM_RAW
    ));
}

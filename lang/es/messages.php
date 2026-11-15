<?php

declare(strict_types=1);

return [
    'title' => 'Boletín',

    'permissions' => [
        'configure' => 'Configurar el boletín',
    ],

    'settings' => [
        'navigation' => 'Boletín',
        'title' => 'Boletín',
        'save' => 'Guardar',
        'test_connection' => 'Probar conexión',

        'connection' => 'Conexión con Mailchimp',
        'connection_description' => 'La web no guarda suscriptores: cada alta va a la lista de Mailchimp, que manda el correo de confirmación. Las campañas se siguen preparando en Mailchimp.',
        'consent' => 'Consentimiento',
        'consent_description' => 'Lo que acepta quien se suscribe desde la web.',

        'fields' => [
            'provider' => 'Proveedor',
            'api_key' => 'Clave de API',
            'server' => 'Servidor',
            'list' => 'Lista',
            'consent_text' => 'Texto del consentimiento',
            'privacy_url' => 'Enlace a la política de privacidad',
        ],

        'helpers' => [
            'api_key' => 'Se crea en Mailchimp, en Profile → Extras → API keys.',
            'api_key_stored' => 'Hay una clave guardada, que no se vuelve a mostrar. Déjalo vacío para conservarla o escribe otra para cambiarla.',
            'server' => 'Sale de la clave: lo que va tras el último guion.',
            'list' => 'Pulsa «Probar conexión» para cargar las listas de la cuenta.',
            'consent_text' => 'Acompaña a la casilla que hay que marcar para suscribirse.',
            'privacy_url' => 'Una dirección completa (https://…) o una ruta de esta web (/privacidad).',
        ],

        'consent_default' => 'Quiero recibir el boletín por correo. Puedo darme de baja en cualquier momento desde el enlace de cada envío.',

        'errors' => [
            'api_key_required' => 'Escribe la clave de API.',
            'api_key_malformed' => 'No parece una clave de Mailchimp: termina en un guion y el servidor (por ejemplo, -us21).',
            'privacy_url' => 'Escribe una dirección que empiece por https:// o una ruta de esta web que empiece por /.',
        ],

        'last_check' => [
            'never' => 'La conexión todavía no se ha comprobado',
            'never_description' => 'El boletín no aparece en la web hasta que se guarden los ajustes con una conexión correcta.',
            'ok' => 'Conexión correcta (comprobada el :date)',
            'failed' => 'La última comprobación falló (:date)',
        ],

        'failures' => [
            'unreachable' => 'Mailchimp no responde. Inténtalo de nuevo en unos minutos.',
            'invalid_api_key' => 'Mailchimp no acepta la clave de API.',
            'list_not_found' => 'Falta elegir la lista o ya no existe en la cuenta.',
            'request_failed' => 'Mailchimp ha rechazado la petición.',
            'not_configured' => 'Faltan la clave de API o la lista.',
            'impersonation' => 'No se llama a Mailchimp mientras impersonas a otra cuenta.',
        ],

        'notifications' => [
            'connected' => 'Conexión correcta',
            'choose_list' => 'Elige la lista y guarda.',
            'not_connected' => 'No se ha podido conectar',
            'saved' => 'Ajustes guardados',
            'saved_available' => 'La conexión funciona: el boletín ya puede aparecer en la web.',
            'saved_unavailable' => 'Ajustes guardados sin conexión',
            'hidden_until_connected' => 'El boletín no aparece en la web hasta que la conexión funcione.',
        ],
    ],

    'block' => [
        'label' => 'Suscripción al boletín',
        'button_default' => 'Suscribirme',
        'fields' => [
            'intro' => 'Texto de presentación',
            'button_label' => 'Texto del botón',
            'show_name' => 'Pedir el nombre',
        ],
        'helpers' => [
            'intro' => 'Una frase sobre el boletín, encima del formulario. Opcional.',
            'show_name' => 'Añade un campo opcional para el nombre.',
        ],
    ],

    'subscribe' => [
        'page_title' => 'Boletín',
        'page_description' => 'Suscríbete al boletín de la asociación y recibe las novedades por correo.',
        'check_your_email' => 'Revisa tu correo: te hemos enviado un enlace para confirmar la suscripción.',
        'fields' => [
            'email' => 'correo electrónico',
            'name' => 'nombre',
        ],
        'errors' => [
            'consent' => 'Marca la casilla para aceptar recibir el boletín.',
            'too_many_attempts' => 'Has hecho demasiados intentos. Espera unos minutos y vuelve a probar.',
        ],
    ],

    'registration' => [
        'label' => 'Quiero recibir el boletín',
        'description' => 'Te enviaremos un correo para confirmar la suscripción. Puedes darte de baja cuando quieras.',
    ],

    'profile' => [
        'errors' => [
            'verify_email' => 'Verifica el correo de tu cuenta para gestionar la suscripción.',
            'too_many_attempts' => 'Has hecho demasiados intentos. Espera unos minutos y vuelve a probar.',
        ],
    ],
];

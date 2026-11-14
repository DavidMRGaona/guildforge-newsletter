# Boletín

Suscripción al boletín de la asociación desde la web, con una lista de Mailchimp.

> Estado: conexión con Mailchimp y ajustes. El formulario de suscripción, el bloque del pie, el perfil y la casilla del registro llegan en las siguientes issues del hito.

La web no guarda suscriptores: cada alta va a una lista (audiencia) de Mailchimp con doble confirmación, y las campañas se siguen preparando en Mailchimp.

El módulo ofrece el permiso `newsletter.configure` («Configurar el boletín»), sin rol por defecto y sin reservarlo a la administración.

## Ajustes

En **Sistema → Configuración → Boletín** (necesita `newsletter.configure`; el botón «Configuración» de *Módulos* lleva aquí):

- **Proveedor**: solo Mailchimp.
- **Clave de API**: se crea en Mailchimp (*Profile → Extras → API keys*). Se guarda cifrada con la clave de la aplicación y no se vuelve a mostrar; dejar el campo vacío al guardar conserva la guardada. Si `APP_KEY` cambia, la clave deja de poder leerse y hay que volver a escribirla.
- **Servidor**: sale de la clave (lo que va tras el último guion, por ejemplo `us21`). Solo se aceptan claves con esa forma, porque el servidor es el nombre del host al que se envía la clave.
- **Lista**: desplegable con las listas de la cuenta, que se cargan con **Probar conexión**. Probar no guarda nada.
- **Texto del consentimiento** y **enlace a la política de privacidad** (una dirección `https://…` o una ruta de la web, como `/privacidad`).

Guardar comprueba siempre la conexión y guarda la fecha y el resultado de la última comprobación, que se ven arriba de la página. Los ajustes se guardan aunque la conexión falle, pero entonces el boletín no se ofrece en la web.

## Disponibilidad

`NewsletterAvailabilityInterface::isAvailable()` decide si el boletín se puede ofrecer: el módulo está habilitado, hay clave y lista guardadas y la última comprobación fue correcta. No llama a Mailchimp y falla cerrado: cualquier cosa que no se pueda leer cuenta como no disponible. Todas las superficies del módulo preguntan aquí antes de mostrarse.

## Proveedor

`NewsletterProviderInterface` (`subscribe`, `status`, `unsubscribe`, `checkConnection`) usa los ajustes guardados, que lee en cada llamada. `MailchimpProvider` habla con la API de Marketing v3 con el cliente HTTP de Laravel (sin SDK), con tiempos de espera cortos (3 s para conectar, 5 s en total). Los miembros se identifican con el MD5 del email en minúsculas. Al pedir el alta, según el estado previo en Mailchimp:

| Estado previo | Qué hace |
|---|---|
| No está en la lista | `PUT` con `status_if_new: pending`: Mailchimp manda la confirmación |
| `unsubscribed`, `archived` o `transactional` | Vuelve a `pending`, que reenvía la confirmación |
| `subscribed` o `pending` | Nada |
| `cleaned` (rebotado) | Nada: la API no permite reactivarlo; se registra solo el hash |

Si Mailchimp rechaza el email (`400`, por ejemplo uno borrado para siempre), el resultado es `Rejected` y se registra el hash con el título del error. Los demás errores llegan como `NewsletterProviderException` con un `ConnectionFailure` (`unreachable`, `invalid_api_key`, `list_not_found`, `request_failed`, `not_configured`, `impersonation`). `checkConnection()` nunca lanza: devuelve el fallo en el resultado, y cualquier error inesperado cuenta como `request_failed` (en el log va solo su clase).

`status()` devuelve `subscribed`, `pending`, `unsubscribed` (también `archived`, que se puede reactivar), `not_found` (también `transactional`) o `blocked` para un email rebotado (`cleaned`), al que no se le debe ofrecer suscribirse porque la API no lo deja volver.

Los ajustes se leen y se descifran una vez por petición o trabajo en cola (repositorio y disponibilidad con binding `scoped`); guardar descarta la copia.

## Privacidad y seguridad

- Los emails no se guardan en la web ni aparecen en los logs: en los errores solo va el hash del suscriptor, la operación, el código HTTP y el título del error de Mailchimp (nunca su detalle, que suele repetir el email).
- El email, el nombre y la clave llevan `#[\SensitiveParameter]` en el proveedor, en `SubscriberHash` y en `MailchimpApiKey`, así que no salen en las trazas aunque `zend.exception_ignore_args` esté desactivado.
- Durante una impersonación no se llama a Mailchimp, tampoco desde **Probar conexión** ni al guardar: el proveedor falla con `impersonation`.

## Requisitos

- GuildForge core `^3.75`
- PHP >= 8.5, Laravel >= 13, Filament 5

El core rechaza el módulo si no se cumplen (`module.json` → `requires`).

## Instalación

1. Descarga `newsletter-X.Y.Z.zip` de la última release de `DavidMRGaona/guildforge-newsletter` en GitHub.
2. En el panel, activa el modo mantenimiento (**Sistema → Configuración → Configuración del sitio** → pestaña **Mantenimiento**).
3. En **Módulos**, pulsa **Instalar / actualizar** y sube el ZIP.
4. Pulsa **Habilitar** en el módulo: la primera vez ejecuta sus migraciones.
5. Desactiva el modo mantenimiento.

Las versiones nuevas se aplican desde **Actualizaciones de módulos** (también con el modo mantenimiento activo) o con `php artisan module:update newsletter`.

### En desarrollo

Con el repositorio clonado en `src/modules/newsletter`, desde `src/`:

```bash
php artisan module:discover
php artisan module:enable newsletter
```

Los componentes Vue se compilan con las herramientas del core:

```bash
docker exec guildforge_node npm run build:module -- newsletter
```

En las releases los assets los compila el CI y van dentro del ZIP.

## Tests

Desde `src/` (o dentro del contenedor `guildforge_app`):

```bash
XDEBUG_MODE=off vendor/bin/phpunit -c modules/newsletter/phpunit.xml
```

`php artisan test` no ejecuta los tests de los módulos.

Los componentes Vue tienen sus tests en `tests/js` (Vitest, Vue Test Utils y happy-dom) y se ejecutan con las herramientas del core, porque importan sus fuentes con `@/`:

```bash
docker exec guildforge_node sh -c 'cd modules/newsletter && ../../node_modules/.bin/vitest run'
```

`tests/js/setup.ts` carga los textos del core y del módulo en castellano y sustituye Inertia (router y enlaces) y el layout del sitio.

## Calidad del código

El módulo pasa las mismas herramientas que el core, con su configuración: Rector, Pint, PHPStan, ESLint, Prettier, `vue-tsc` y Vitest. En cada pull request las ejecuta el job *Quality* de `tests.yml`. Desde la raíz del core:

```bash
make check MODULE=newsletter
```

## Publicar una versión

1. Sube `version` en `module.json` (un módulo nuevo empieza en `0.0.0`, sin publicar).
2. Crea y empuja la etiqueta `vX.Y.Z` (debe coincidir con `version`).
3. El workflow `release.yml` ejecuta los tests contra el core (`main`, o la variable `GUILDFORGE_HOST_REF`), compila los assets y publica `newsletter-X.Y.Z.zip` con su `.sha256` en la release.

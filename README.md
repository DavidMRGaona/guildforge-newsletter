# Boletín

Suscripción al boletín de la asociación desde la web, con una lista de Mailchimp.

> Estado: conexión con Mailchimp, ajustes y formulario de suscripción (bloque del pie y de las ventanas emergentes, página `/newsletter`), sección «Boletín» del perfil y casilla del registro. La salud del proveedor llega en la siguiente issue del hito.

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

## Formulario de suscripción

El mismo formulario aparece en tres sitios, y en ninguno mientras el boletín no esté disponible:

- **Bloque `newsletter.subscribe`** para el pie (*Sitio web → Pie de página*) y para las ventanas emergentes de anuncios. Sus ajustes son planos: texto de presentación, texto del botón y si se pide el nombre. Con el proveedor caído el bloque no se pinta.
- **Página `/newsletter`** (`newsletter.show`), que responde 404 si el boletín no está disponible (`EnsureNewsletterIsAvailable`, en las dos rutas, antes del límite y de la validación). Las ventanas emergentes nunca salen en ella. El enlace del menú se pone a mano (*Menú público*, enlace a `/newsletter`).

Pide email, nombre opcional y una casilla de consentimiento obligatoria con el texto y el enlace de los ajustes. Lleva un campo trampa (`website`) que la gente no ve: con cualquier valor en él (texto, una lista…) la petición no se valida, recibe la misma respuesta y no se envía nada. Con sesión, el email y el nombre de la cuenta ya vienen puestos. Dentro de una columna del pie configurable (`data-footer-column`) toma los colores oscuros del pie; en una ventana o en la página, los de la web. Cada error va bajo su campo y lo nombra (`aria-describedby`), el foco va al primero, y el resultado se anuncia y recibe el foco.

`POST /newsletter` (`newsletter.store`) acepta JSON (el formulario lo envía así, sin recargar la página) y formularios normales (vuelve atrás con el mensaje). Tiene un límite por IP (5 por minuto y 20 por hora, que no cuenta mientras el boletín no está disponible) y `email.verified-if-required`: una cuenta que debe verificar su email lo hace antes. La verificación del core va en el grupo `web`, antes que las rutas, así que una cuenta sin verificar recibe su 403 incluso con el boletín no disponible. La respuesta es siempre «Revisa tu correo…», sin decir si el email ya estaba en la lista.

### Alta en cola

El alta se pide a Mailchimp en un trabajo en cola, `SubscribeToNewsletterJob`:

- Implementa `ShouldBeEncrypted` y solo lleva el email y el nombre: el proveedor, con su clave, se resuelve en `handle()`, así que la clave nunca se serializa.
- Hasta 4 intentos, con esperas de 30 s, 2 min y 10 min. Con una clave rechazada, una lista que ya no existe o unos ajustes vacíos se rinde al momento, porque reintentar no lo arregla.
- Se rinde dentro de `handle()`: registra solo el hash del suscriptor y el tipo de fallo, suma un fallo y termina sin lanzar, así que el worker nunca lo da por fallido ni escribe nada en `failed_jobs`.
- `failed()` queda como red para lo que `handle()` no puede capturar (un tiempo agotado, un worker que muere a mitad): hace lo mismo y borra la copia de `failed_jobs`, que el worker escribe después de llamarlo, con un listener de `JobFailed` que corre detrás del suyo.
- Durante una impersonación no se encola nada (`SubscriptionRequesterInterface` comprueba `ImpersonationContextInterface::isActive()`), aunque la respuesta sea la misma.

`SubscribeFailureCounterInterface` cuenta las altas fallidas seguidas, en la caché compartida por los workers: suma uno por cada trabajo que se rinde y por cada email que Mailchimp rechaza (`Rejected`), y solo vuelve a cero cuando Mailchimp escribe en la lista (`ConfirmationRequested`). Las respuestas que no escriben nada (ya suscrito o pendiente, rebotado) lo dejan como está, para que las altas repetidas de quien ya está en la lista no tapen un proveedor que rechaza a todos los nuevos. Es atómico sin bloqueo: `add()` crea la clave con caducidad solo si falta o ha caducado e `increment()` suma sin tocar la caducidad (con el store `database`, un insert y un update con la fila bloqueada); la racha caduca un día después de su primer fallo. Lo usará la comprobación de salud del proveedor; por ahora solo cuenta.

## Perfil y registro

**Sección «Boletín» del perfil** (pestaña en el hueco `profile-sections`). La página del perfil no consulta a Mailchimp: la sección pide el estado a `GET /perfil/boletin` al abrirse. El estado de cada cuenta se recuerda 5 minutos (clave con el hash del id de la cuenta y el hash del email, nunca el email) y los fallos del proveedor no se recuerdan. Según el estado:

| Estado | La sección dice | Botón |
|--------|-----------------|-------|
| `subscribed` | Estás suscrito | Darme de baja |
| `pending` | Te hemos enviado un correo para confirmar | — |
| `unsubscribed` (también no encontrado o archivado) | No estás suscrito | Suscribirme |
| `blocked` (dirección rebotada) | Esa dirección no puede recibir el boletín | — |
| `unavailable` (el proveedor no responde) | No hemos podido consultar | Volver a probar |

**Solo con el email verificado**, sea cual sea el ajuste de verificación del sitio: cualquiera puede registrarse con una dirección que no es suya y no debe poder leer ni cambiar su suscripción. Las tres rutas responden 403 `email_verification_required` a una cuenta sin verificar y la sección solo muestra un aviso con el enlace a `verification.notice`.

`POST` y `DELETE /perfil/boletin` usan siempre el email de la cuenta con la sesión iniciada, nunca uno de la petición, y solo actúan si el estado lo permite (no se encola un alta para una dirección rebotada ni una baja para quien no está en la lista). Ambos van en cola con trabajos cifrados (`SubscribeToNewsletterJob`, `UnsubscribeFromNewsletterJob`). Tras pedir el alta se recuerda «pendiente» solo 60 segundos (el resto de estados, 5 minutos) y se anota por hash que la persona volvió a apuntarse: una baja que esperaba un reintento comprueba la marca y se descarta si el alta fue posterior. Las rutas llevan `auth`, `email.verified-if-required` y `throttle:newsletter-profile` (30 lecturas o 6 cambios por minuto y cuenta) y no existen mientras el boletín no esté disponible. Durante una impersonación la pestaña se oculta (props compartidas) y el servicio y el requester no consultan ni escriben nada. Los trabajos de alta y baja borran de `failed_jobs` su copia cifrada si se rinden (`ForgetsFailedJobCopy`).

**Casilla del registro** (`newsletter.subscribe`, contrato de campos del registro del core): «Quiero recibir el boletín», siempre desmarcada y solo disponible mientras lo esté el boletín. Si se marca, `SubscribeAfterRegistration` (en cola, `afterCommit`) lee la cuenta por su id, vuelve a comprobar la disponibilidad y pide el alta con `SubscriptionRequesterInterface`, que manda la confirmación de Mailchimp.

## Privacidad y seguridad

- Los emails no se guardan en la web ni aparecen en los logs: en los errores solo va el hash del suscriptor, la operación, el código HTTP y el título del error de Mailchimp (nunca su detalle, que suele repetir el email).
- El email, el nombre y la clave llevan `#[\SensitiveParameter]` en el proveedor, en `SubscriberHash`, en `MailchimpApiKey`, en el trabajo de alta y en quien lo encola, así que no salen en las trazas aunque `zend.exception_ignore_args` esté desactivado.
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

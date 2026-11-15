---
id: newsletter.panel.settings
title: Conectar el boletín con Mailchimp
summary: Cómo conectar la web con tu lista de Mailchimp, probar la conexión y qué hacer si el boletín se oculta o Mailchimp rechaza altas.
category: site-settings
audience: panel
order: 90
keywords: [boletín, newsletter, mailchimp, clave de api, api key, lista, audiencia, id de la lista, probar conexión, suscriptores, doble confirmación, consentimiento, el boletín no aparece, boletin oculto, mailchimp falla, mailchimp rechaza altas, campos obligatorios, mailchim, nesletter]
related: [newsletter.site.subscribe]
conditions:
  permissions:
    all: ['newsletter:newsletter.configure']
---
La web no guarda suscriptores. Cada alta va a una lista (llamada **audiencia** en Mailchimp), que manda el correo de confirmación. Las campañas se siguen preparando y enviando desde Mailchimp. Aquí solo conectas la web con tu lista.

Hace falta el permiso **Configurar el boletín**, que un administrador da a mano en el editor de roles (ningún rol lo tiene por defecto).

## Qué necesitas de Mailchimp

1. **Una clave de API.** En Mailchimp, abre tu perfil y ve a **Extras → API keys**. Crea una clave y cópiala. Acaba en un guion y el servidor, por ejemplo `-us21`: de ahí sale el servidor al que se conecta la web.
2. **Una audiencia (lista)** donde se apunte la gente. No tienes que copiar su identificador: la web carga las listas de tu cuenta y eliges una de un desplegable. Si quieres comprobar que es la correcta, el identificador está en los ajustes de la audiencia, en Mailchimp.

> [!IMPORTANT]
> Antes de conectar, mira los **campos obligatorios** de la audiencia en Mailchimp. El formulario de la web solo pide el correo y, si lo activas, el nombre. Si la audiencia exige otro campo, Mailchimp rechazará todas las altas.

## Conectar la web

1. En el menú lateral, abre **Sistema**, entra en **Configuración** y elige **Boletín**.
2. En **Conexión con Mailchimp**, pega la **Clave de API**. El campo **Servidor** se rellena solo, a partir de la clave.
3. Pulsa **Probar conexión**. Si la clave vale, se cargan las listas de tu cuenta. Probar no guarda nada.
4. Elige la **Lista** en el desplegable.
5. En **Consentimiento**, revisa el **Texto del consentimiento**, que va junto a la casilla que hay que marcar para suscribirse, y escribe el **Enlace a la política de privacidad**: una dirección completa (`https://…`) o una ruta de la web, como `/privacidad`.
6. Pulsa **Guardar**.

Al guardar, la web comprueba siempre la conexión y anota la fecha y el resultado. Los ajustes se guardan aunque la conexión falle, pero entonces el boletín no se ofrece en la web.

La clave se guarda cifrada y no se vuelve a mostrar. Para conservar la guardada, deja el campo vacío al guardar; para cambiarla, escribe otra.

## Qué dice el aviso de arriba

- **La conexión todavía no se ha comprobado**: el boletín no aparece en la web hasta que guardes los ajustes con una conexión correcta.
- **Conexión correcta**: el boletín está disponible. Se vuelve a comprobar cada día.
- **La última comprobación falló**: el boletín está oculto en la web. Debajo se explica qué ha fallado.
- **Mailchimp ha rechazado o fallado varias altas seguidas**: la conexión funciona, así que el boletín sigue visible, pero algo impide dar de alta a la gente. Mira más abajo.

Los fallos que puede explicar son: Mailchimp no responde, no acepta la clave, falta elegir la lista o ya no existe, o ha rechazado la petición.

## Dónde se ofrece el boletín

Mientras la conexión funciona, el boletín aparece en estos sitios, y en ninguno cuando está oculto:

- **El bloque «Suscripción al boletín»**, que añades al pie (*Sitio web → Pie de página*) o a una ventana emergente. Sus ajustes son el **Texto de presentación**, el **Texto del botón** y **Pedir el nombre**.
- **La página `/newsletter`.** Para enlazarla, crea un enlace a `/newsletter` en el menú público.
- **La sección «Boletín» del perfil** de cada persona y una casilla en el registro.

## Si Mailchimp falla

Qué hace la web cuando algo va mal:

- **Se oculta el boletín.** Cuando una comprobación falla, desaparecen el bloque, la página, la sección del perfil y la casilla del registro. Si Mailchimp solo tarda en responder, no se oculta a la primera: hacen falta dos respuestas fallidas seguidas, separadas al menos diez minutos. Una clave rechazada o una lista que ya no existe lo ocultan a la primera.
- **Se avisa a quien configura el boletín**, una sola vez por caída, por la campana y por correo según las preferencias de cada persona (el aviso se llama «Aviso de que Mailchimp ha dejado de funcionar»).
- **Vuelve solo.** La web comprueba la conexión cada día y, mientras el boletín está oculto, cada 30 minutos. En cuanto Mailchimp responde bien, el boletín reaparece sin que hagas nada. También puedes arreglar la clave o la lista y guardar los ajustes: si la conexión funciona, vuelve al momento.
- **Tres altas fallidas seguidas** piden una comprobación extra, pero nunca ocultan el boletín por sí solas.

> [!TIP]
> Si la clave se ha revocado o la lista se ha borrado en Mailchimp, el boletín seguirá oculto hasta que guardes una clave y una lista válidas.

## Si Mailchimp rechaza las altas

Si la conexión funciona pero llegan varias altas seguidas rechazadas, la página muestra el aviso **Mailchimp ha rechazado o fallado varias altas seguidas** y se avisa una vez, a quien configura el boletín, con «Mailchimp rechaza las altas del boletín». El boletín sigue visible. Un alta que sale bien reinicia la cuenta.

Casi siempre es que la audiencia tiene campos obligatorios que el formulario no pide. Para arreglarlo:

1. En Mailchimp, abre la audiencia y revisa sus campos. Quita la obligatoriedad de los que no sean el correo.
2. Haz un alta de prueba desde la web con una dirección tuya.
3. Cuando una sale bien, el aviso desaparece.

## Si algo no sale bien

- **No me deja guardar la clave**: no parece de Mailchimp. Debe acabar en un guion y el servidor, por ejemplo `-us21`.
- **El desplegable de listas está vacío**: pulsa **Probar conexión**. Si falla, revisa la clave.
- **Cambió la clave de la aplicación y la clave de Mailchimp ya no se lee**: vuelve a escribirla y guarda.
- **No pasa nada al probar mientras impersonas a otra cuenta**: durante una impersonación la web no llama a Mailchimp.

[[action:filament.admin.configuracion.pages.boletin|Abrir los ajustes del boletín]]

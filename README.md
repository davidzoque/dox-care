# Dox Care

Plugin de WordPress de Dox Studio para las webs de sus clientes de hosting y mantenimiento (Essentials Care, Pro Care y Elite Care).

## Qué hace

- **Escritorio propio dentro del panel.** Al entrar a WordPress se ve el escritorio de Dox Care en lugar del de siempre: el plan del cliente, el estado de su web, su cuenta en clients.doxstudio.com, lo que puede resolver solo y los planes superiores. El escritorio clásico sigue en `index.php?dox_classic=1`.
- **Pedir un cambio.** Formulario (página de la web, texto de hasta 5.000 caracteres y hasta 5 archivos, 20 MB en total, comprobando que su contenido es del tipo que dice) que llega por correo a support@doxstudio.com, con Reply-To del cliente. Lo pueden usar Editor y Administrador (filtro `dox_care_request_cap` para abrirlo a otros roles), hasta 5 solicitudes al día por web, y cada usuario ve solo las suyas. También desde la barra superior de la web ("Pedir un cambio aquí"), con la página que se está viendo ya elegida.
- **Contador de actualizaciones del mes**, según la sección F de los Términos del Servicio: cada solicitud cuenta 1; Dox Studio la ajusta y añade a mano las que llegan por correo o ticket. El cliente ve "Este mes: X de 3".
- **Estado de la web** leído de la propia web: SSL y su renovación, actualizaciones pendientes y último cambio publicado.
- **Aviso central**: el cron de WordPress lee `https://my.doxstudio.com/dox-care.json` dos veces al día y el escritorio pinta lo guardado, sin esperar nunca a my.doxstudio.com (avisos para todos los clientes y precios de los planes). Se puede cambiar con la constante `DOX_CARE_FEED` en wp-config.php.
- **Entrar con un código por correo**, además de con la contraseña (que sigue igual): código de 6 números, 10 minutos, un solo uso, 5 intentos, 5 códigos por usuario y 20 por IP cada hora, y no revela qué correos tienen cuenta. Sale bajo el formulario de entrada de WordPress (`wp-login.php?action=dox_code`, también con Hide My WP) y en la caja de Dox POS desde la 0.45.0, que lo pide por sus filtros `dox_pos_login_code_*`. Se apaga por web en los ajustes ("Entrar con código") o con el filtro `dox_care_login_code`.

## Ajustes

En **Dox Plugins > Dox Care**: plan, nombre del cliente, idioma (automático, español o inglés) y correo de soporte, más la tabla del contador. Solo los cambia el equipo de Dox Studio (usuario `support` o correo @doxstudio.com / @paradoxstudio.co); el cliente solo ve su plan y su contador. Se puede ampliar con el filtro `dox_care_is_staff`.

**Ojo: esto no frena a un administrador del sitio** (puede crearse un usuario @doxstudio.com o ejecutar código). Por eso cada cambio de los ajustes o del contador manda un aviso a `DOX_CARE_ALERT_TO` (support@doxstudio.com por defecto) con quién lo hizo, y el contador de la web informa al cliente pero no vale como prueba de lo consumido.

## Estructura

- `dox-care.php`: arranque, idioma, actualizador y registro en el menú "Dox Plugins".
- `includes/`: ajustes, planes, aviso central, estado, contador, solicitudes, escritorio y barra superior.
- `assets/`: CSS y JS del escritorio y de la ventana.
- `languages/`: textos en inglés en el código y traducción al español (`dox-care-es_ES.po/.mo`).
- `dox-core/`: copia del menú común de los plugins de Dox Studio (se edita en `Plugins/dox-core/` y se sincroniza con `sync-dox-core.sh`).
- `vendor/plugin-update-checker/`: actualizaciones desde las releases de este repositorio.

## Publicar una versión

1. Subir la versión en la cabecera de `dox-care.php` y en `DOX_CARE_VERSION`.
2. Si cambian textos: regenerar `languages/dox-care.pot`, traducir en el `.po` y compilar el `.mo` con `msgfmt`.
3. Commit en `main`, tag `vX.Y.Z` y push del tag. El workflow comprueba que versión y tag coinciden, construye `dox-care.zip` y lo adjunta a la release. Las webs lo ven como una actualización normal de WordPress (y en Modular DS).

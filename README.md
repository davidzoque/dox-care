# Dox Care

Plugin de WordPress de Dox Studio para las webs de sus clientes de hosting y mantenimiento (Essentials Care, Pro Care y Elite Care).

## Qué hace

- **Escritorio propio dentro del panel.** Al entrar a WordPress se ve el escritorio de Dox Care en lugar del de siempre: el plan del cliente, el estado de su web, su cuenta en clients.doxstudio.com, lo que puede resolver solo y los planes superiores. El escritorio clásico sigue en `index.php?dox_classic=1`.
- **Pedir un cambio.** Formulario (página, texto exacto y hasta 5 archivos de 10 MB) que llega por correo a support@doxstudio.com, con Reply-To del cliente. También desde la barra superior de la web ("Pedir un cambio aquí"), con la página que se está viendo ya elegida.
- **Contador de actualizaciones del mes**, según la sección F de los Términos del Servicio: cada solicitud cuenta 1; Dox Studio la ajusta y añade a mano las que llegan por correo o ticket. El cliente ve "Este mes: X de 3".
- **Estado de la web** leído de la propia web: SSL y su renovación, actualizaciones pendientes y último cambio publicado.
- **Aviso central**: lee `https://doxstudio.com/dox-care.json` cada 6 horas (avisos para todos los clientes y precios de los planes). Se puede cambiar con la constante `DOX_CARE_FEED` en wp-config.php.

## Ajustes

En **Dox Plugins > Dox Care**: plan, nombre del cliente, idioma (automático, español o inglés) y correo de soporte, más la tabla del contador. Solo los cambia el equipo de Dox Studio (usuario `support` o correo @doxstudio.com / @paradoxstudio.co); el cliente solo ve su plan y su contador. Se puede ampliar con el filtro `dox_care_is_staff`.

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

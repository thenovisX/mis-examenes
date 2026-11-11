# Mis Exámenes (app web)

Versión web instalable de Mis Exámenes: notas, medias ponderadas, horario, calendario y entregas.

Ábrela en tu web y pulsa **Instalar** (Android) o **Compartir → Añadir a pantalla de inicio** (iPhone).

Los datos se guardan en el propio móvil. Usa el mismo formato de copia que la app de Android.

## Sincronizar con el Android y el calendario

`sync.php` guarda una copia de los datos por cada enlace de sincronización (en la carpeta `datos-sync/`, que crea sola y que no se sube al repositorio). Necesita un hosting con PHP, como Hostinger; en GitHub Pages no funciona. También sirve el calendario para suscribirse (`sync.php?ics=…`) y el botón «Añadir al calendario».

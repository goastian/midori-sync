# Reporte de Seguridad — midori-sync

**Fecha:** 2026-05-07  
**Alcance:** Análisis completo del proyecto  
**Rama:** main

---

## Resumen Ejecutivo

| Severidad | Confirmados | Falsos Positivos |
|-----------|-------------|------------------|
| HIGH      | 1           | —                |
| MEDIUM    | 2           | 1                |
| LOW       | —           | 3                |

---

## Hallazgos Confirmados

---

### [HIGH-01] Contenedor Docker ejecuta procesos como `root`

**Archivo:** `Dockerfile:57`  
**Score:** 9.0 / 10  
**Categoría:** Privilege Escalation / Misconfiguration

**Descripción:**  
El `Dockerfile` nunca declara una directiva `USER`. Aunque se aplica `chown www-data:www-data` a los directorios de almacenamiento (línea 49), el proceso raíz del contenedor — `supervisord`, y por ende `nginx` y `php-fpm` — corre como `root`. Cualquier RCE explotable dentro del contenedor otorga acceso root inmediato sin necesidad de escalada de privilegios adicional.

**Escenario de explotación:**  
Un atacante que logre RCE a través de la aplicación (e.g. deserialización PHP, vulnerabilidad en una dependencia) obtiene un shell root dentro del contenedor, pudiendo leer secretos de entorno, modificar binarios, o pivotar a otros contenedores en la red Docker.

**Solución:**  
Agregar al final del `Dockerfile`, antes de `CMD`:

```dockerfile
RUN addgroup --system app \
    && adduser --system --ingroup app --no-create-home app \
    && chown -R app:app /var/www/html/storage /var/www/html/bootstrap/cache

USER app
```

Verificar que `supervisord` y sus procesos hijos (`nginx`, `php-fpm`) soporten ejecución como usuario no privilegiado, ajustando los puertos a `>1024` si fuera necesario.

---

### [MEDIUM-01] Headers de seguridad eliminados para rutas `/build/*`

**Archivo:** `docker/nginx.dev.conf:35-38`  
**Score:** 7.5 / 10  
**Categoría:** Security Headers / Configuration

**Descripción:**  
El bloque `server` define cuatro headers de seguridad en las líneas 12–15 (`X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`, `Referrer-Policy`). El bloque `location /build/` en la línea 35 introduce un nuevo `add_header Cache-Control`. Por comportamiento de nginx, cualquier `add_header` en un bloque hijo anula completamente todos los headers del bloque padre para esas rutas. Las respuestas a `/build/*` (assets JS/CSS) se sirven sin headers de seguridad.

El mismo patrón está presente en `docker/nginx.prod.conf` — revisar también.

**Escenario de explotación:**  
Assets servidos sin `X-Content-Type-Options: nosniff` quedan expuestos a MIME sniffing. Sin `X-Frame-Options`, recursos individuales pueden ser enmarcados. El impacto es bajo para assets estáticos pero viola la política de seguridad definida en el servidor.

**Solución:**  
Incluir explícitamente todos los headers dentro del bloque `location /build/` en ambos archivos de configuración:

```nginx
location /build/ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
}
```

---

### [MEDIUM-02] `v-html` con contenido de paginación — riesgo de XSS

**Archivo:** `resources/js/Pages/Audit/Index.vue:244`  
**Score:** 6.5 / 10  
**Categoría:** XSS / Frontend

**Descripción:**  
El componente usa `v-html="link.label"` para renderizar las etiquetas de los botones de paginación. Laravel genera estos labels con caracteres especiales (`« Previous`, `Next »`) que requieren HTML para mostrarse correctamente. El riesgo es bajo en la implementación actual ya que los labels vienen del framework — pero si el endpoint de paginación es extendido o la respuesta es interceptada, el vector queda abierto.

**Escenario de explotación:**  
Si un atacante puede influir en el valor de `link.label` (e.g. manipulación de respuesta API o endpoint personalizado), puede inyectar HTML/JS arbitrario que se ejecutará en el contexto de la sesión autenticada del usuario.

**Solución:**  
Sanitizar con DOMPurify antes de pasar a `v-html`, o reemplazar con texto plano:

```js
import DOMPurify from 'dompurify';
const sanitize = (html) => DOMPurify.sanitize(html, { ALLOWED_TAGS: [] });
```

```vue
<button v-html="sanitize(link.label)" />
```

---

## Falsos Positivos Documentados

| ID    | Archivo                                  | Hallazgo reportado                     | Motivo de descarte                                                                                                                            |
|-------|------------------------------------------|----------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------|
| FP-01 | `extension/popup/popup.js:27`            | DOM XSS via `appendChild`              | El nodo se crea con `document.createElement` y el contenido se asigna con `textContent`, no `innerHTML`. Sin riesgo de XSS.                   |
| FP-02 | `extension/lib/argon2-worker.js:31`      | `postMessage` sin validación de origen | Es un Worker interno de la extensión. Los Workers solo reciben mensajes del script que los instanció; páginas externas no pueden contactarlos. |
| FP-03 | `extension/lib/midori-sync-crypto.js:82` | `postMessage` sin validación de origen | Es el padre escuchando respuestas del Worker (`worker.addEventListener`), no una superficie de ataque externa.                                 |
| FP-04 | `extension/lib/sodium.js:2`              | API key detectada                      | Nombre de función de la API de libsodium (`crypto_aead_aegis128l_keygen`). No es un secreto.                                                  |
| FP-05 | `extension/options/options.js:213`       | API key detectada                      | Variable de UI que muestra la clave de cifrado al propio usuario en la página de opciones. Comportamiento esperado por diseño.                 |

---

## Plan de Remediación

| Prioridad | ID        | Esfuerzo | Acción                                                                          |
|-----------|-----------|----------|---------------------------------------------------------------------------------|
| P1        | HIGH-01   | ~10 min  | Agregar `USER app` al final del `Dockerfile`                                    |
| P2        | MEDIUM-01 | ~15 min  | Duplicar headers en `location /build/` en `nginx.dev.conf` y `nginx.prod.conf`  |
| P3        | MEDIUM-02 | ~20 min  | Reemplazar `v-html` por `textContent` o sanitizar con DOMPurify                 |

---

*midori-sync · Reporte de Seguridad · 2026-05-07*

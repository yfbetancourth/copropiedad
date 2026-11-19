# Inventario de enlaces y funcionalidades — Copropiedad

Recorrido de cada enlace visible de la aplicación, agrupado por área (sitio público, portal de usuario y panel administrativo). Para cada uno se indica la ruta, quién puede entrar y qué se puede hacer en la pantalla.

- **URL base local:** `http://localhost/Copropiedad/public`
- **Fecha de inspección:** 2026-11-18
- **Método:** se revisaron las rutas registradas (`app/Modules/*/routes.php`, `app/Scenarios/*/routes.php`), los menús (`app/Scenarios/*/layout.php`) y las vistas. Cada URL GET se probó contra el servidor local sin sesión iniciada; la columna "Respuesta sin sesión" muestra el resultado.

> Las pantallas protegidas se documentaron a partir del código, porque la inspección se hizo sin credenciales.

---

## 1. Sitio público

No requiere sesión. Se configura desde *Parametrización* y *Galería pública*.

| Enlace / ruta | Respuesta sin sesión | Funcionalidad |
|---|---|---|
| `/` — Inicio | 200 | Portada pública de la copropiedad: nombre, galería de fotos de las zonas comunes, secciones *Comunidad*, *Espacios*, *Ubicación* (botón **Ver ubicación en Google Maps**, generado a partir de la dirección) y una muestra de los últimos *Clasificados*. Los anclajes del menú (`#comunidad`, `#espacios`, `#ubicacion`, `#clasificados`) desplazan la página a cada sección. |
| `/clasificados` — Clasificados | 200 | Catálogo público de las publicaciones de venta ("Ofrezco en venta") y de búsqueda ("Busco comprar") que crean los residentes. Muestra título, tipo de inmueble, sector, precio, fotos y los datos de contacto que el autor autorizó publicar (enlaces `mailto:` y `tel:`). Si no hay publicaciones, invita a publicar desde el portal. |
| `/clasificados/imagen?…` | — | Sirve las imágenes de los clasificados. No es una página. |
| **Portal de usuario / Entrar a mi portal / Acceso de residentes** → `/login?destino=usuario` | 200 | Abre el inicio de sesión y, al autenticarse, lleva al portal de usuario. |
| `/robots.txt` | 200 | Permite indexar todo excepto `/admin` y enlaza el sitemap. |
| `/sitemap.xml` | 200 | Lista `/` y `/clasificados`. Responde 404 si no hay `APP_URL` configurada. |

## 2. Autenticación

Las mismas rutas existen bajo `/admin/...` (por ejemplo `/admin/login`) para el panel administrativo.

| Enlace / ruta | Respuesta sin sesión | Funcionalidad |
|---|---|---|
| `/login` | 200 | Formulario **Usuario o correo electrónico + Contraseña**. Después del login redirige según los permisos: al panel (`panel`) o al portal (`usuario.portal`). Con `?destino=usuario`, prioriza el portal cuando el usuario tiene ambos accesos. |
| **Olvidé mi contraseña** → `/forgot-password` | 200 | Pide el correo y envía un enlace de recuperación. Usa el SMTP configurado en *Correo*. |
| `/reset-password?token=…` | 200 | Formulario **Nueva contraseña** (mínimo 12 caracteres) y **Confirmar contraseña**. |
| **Cerrar sesión** (POST `/logout`) | — | Cierra la sesión. Está en el menú del avatar y en el panel. |

## 3. Portal de usuario (residentes)

Requiere el permiso `usuario.portal` (lectura). Sin sesión, redirige a `/login` (303).

**Barra superior:** botón **Clasificados**, campana de notificaciones (contador de no leídos, mensajes recientes y avisos de cobro, con el enlace **Ver todos** a `/usuario/mensajes`) y menú del avatar (**Panel de Usuario**, **Cerrar sesión**).
**Menú lateral:** Inicio y perfil · Pagos · Mis mensajes · Clasificados.

| Enlace / ruta | Funcionalidad |
|---|---|
| **Inicio y perfil** → `/usuario` | **Indicadores:** inmuebles asignados, canon mensual actual (y la fecha desde la que aplica), multas generadas y ahorro por pronto pago.<br>**Mis inmuebles asignados:** tabla de inmueble y categoría.<br>**Mis documentos de cobro:** nota, período, saldo, estado (Pendiente / Pago en proceso / Pago aprobado / Pago rechazado / Saldo trasladado a otro período) y acción de pago.<br>**Personalizar mi cuenta:** cambiar el nombre de usuario, el correo y la contraseña (pide la contraseña actual).<br>**Mis clasificados de inmuebles:** crear una publicación (tipo de publicación, título, tipo de inmueble, sector, precio, descripción, imágenes, datos de contacto y autorización para publicarlos), cambiar su estado o eliminarla. |
| **Pagos** → `/usuario/pagos` | **Opciones de pago disponibles:** los métodos que habilitó la administración. Los pagos en línea se procesan con Bold.<br>**Mis notas de cobro:** nota, inmueble, período, saldo pendiente, estado y acción. Solo se puede pagar la nota más reciente; las anteriores aparecen como "Incluido en <período>".<br>Botones: **pago en línea** (POST `/usuario/pagar`, crea un link de pago de Bold) y **Datáfono Bold** (POST `/usuario/pagar-datafono`, envía el cobro al terminal configurado). El saldo solo cambia cuando Bold confirma la transacción. |
| **Mis mensajes** → `/usuario/mensajes` | Bandeja de los mensajes que envía la administración. Botón **Marcar como leído**. Los avisos de cobro se marcan como leídos con POST `/usuario/notificaciones/cobro`. |
| **Clasificados** → `/clasificados` | Abre el catálogo público (sección 1). |
| `/pagos/bold/retorno?reference=…` | Página a la que vuelve el usuario después de pagar en Bold. Sin referencia responde 400. |
| POST `/pagos/bold/webhook` | Endpoint de Bold que confirma las ventas aprobadas. No es navegable. |

## 4. Panel administrativo

Todas las rutas están bajo `/admin/...`. Sin sesión, redirigen a `/admin/login` (303 comprobado en todas). Cada pantalla exige un permiso de módulo con nivel **lectura**, **escritura** (crear y editar) o **total** (además, anular, desactivar o eliminar).

**Barra superior:** menú del avatar con **Panel administrativo**, **Portal de usuario** y **Cerrar sesión**.

### 4.1 Panel — `/admin`
**Permiso:** `panel`.
Bienvenida con indicadores de cartera, visibles si el usuario también tiene `cartera.cobros`:
- Cartera por cobrar.
- Recaudo del mes.
- Inmuebles con nota vencida (de los asignados).
- Notas emitidas.
- Saldos a favor de residentes.

Incluye la tabla **Mayores saldos pendientes** (inmueble, saldo, antigüedad), la lista **Pendientes** y estos accesos rápidos:
- **Ver reporte** y **Ver morosos**: abren `/cartera/reportes`.
- **Descargar pagos**: CSV con los pagos del mes en curso.
- **Ir a cobros**: abre `/cartera/cobros`.

### 4.2 ADMINISTRACIÓN › Configuración

| Enlace | Ruta | Permiso | Funcionalidad |
|---|---|---|---|
| Parametrización | `/admin/configuracion/parametrizacion` | `configuracion.parametrizacion` | **Información general:** nombre de la copropiedad, dirección y NIT.<br>**Información bancaria:** titular, banco, tipo y número de cuenta.<br>**Fechas de cobro mensual:** día límite de pronto pago y día de vencimiento ordinario.<br>Botón **Guardar parametrización**. |
| Galería pública | `/admin/configuracion/galeria-publica` | `configuracion.parametrizacion` | Sube las fotos de las zonas comunes que aparecen en la portada (JPG, PNG o WebP; máximo 3 MB y 24 MP por foto; hasta 8 fotos). Botón **Retirar** por foto. Enlace **Volver a parametrización**. |
| Financiera | `/admin/configuracion/financiera` | `configuracion.financiera` | **Administración y descuento:** base mensual de liquidación, fecha desde la que aplica y descuento por pronto pago.<br>**Mora y multas:** días después del vencimiento, tipo de mora (porcentaje o valor fijo), valor de la mora y valor base de las multas.<br>**Cuota extraordinaria:** valor y fechas de vigencia. |
| Equipo | `/admin/configuracion/equipo` | `configuracion.equipo` | Crear o editar cuentas: usuario, correo, rol y contraseña (mínimo 12 caracteres).<br>Tabla con usuario, correo, rol, estado y las acciones **Editar** y **Activar/Desactivar**. Desactivar una cuenta cierra sus sesiones. Las cuentas Superadmin están protegidas. |
| Roles y permisos | `/admin/configuracion/roles` | `configuracion.roles` | Crear o editar roles (nombre, descripción y nivel por módulo: ninguno, lectura, escritura o total). Nadie puede conceder un nivel superior al propio.<br>Tabla con rol, número de usuarios, permisos y las acciones **Editar** y **Eliminar**. El rol Superadmin no se puede modificar. |

### 4.3 CONFORMACIÓN

| Enlace | Ruta | Permiso | Funcionalidad |
|---|---|---|---|
| Caracterización | `/admin/configuracion/caracterizacion` | `configuracion.caracterizacion` | Tipo de vivienda (**Casa**, **Torres de apartamentos** o **Mixto**) y nomenclatura de las torres (numérica: Torre 1, 2… o alfabética: Torre A, B…). |
| Conformación de la copropiedad | `/admin/configuracion/conformacion` | `configuracion.conformacion` | Asistente por pasos (`?paso=N`) que pide, según la caracterización, la cantidad de casas, torres, pisos por torre, apartamentos por piso e inmuebles comerciales.<br>**Revisa las cantidades** (con un enlace **Cambiar** por paso) → **Generar estructura para revisar** → tabla editable de categoría, nombre y coeficiente (%), con **Agregar inmueble** y **Quitar** → **Finalizar**. |
| Asignación de inmuebles | `/admin/inmuebles/asignaciones` | `inmuebles.asignaciones` | Elegir un usuario (**Ver asignaciones**) y marcar sus inmuebles con buscador, **Seleccionar visibles**, **Quitar selección visible** y **Guardar asignaciones**. Incluye la tabla de usuarios con el número de inmuebles de cada uno y enlaces a *Conformación* y *Equipo*. |

### 4.4 PAGOS

| Enlace | Ruta | Permiso | Funcionalidad |
|---|---|---|---|
| Métodos de pago | `/admin/configuracion/metodos-pago` | `configuracion.pagos` | **Bold · Link de pagos:** ambiente (sandbox o producción), API key (se guarda cifrada) y métodos habilitados. **Guardar y verificar conexión** comprueba los métodos con Bold. También muestra la URL del webhook que hay que registrar en Bold.<br>**Bold · Datáfono:** ambiente, llave de identidad, llave secreta del webhook, método de pago (o selección manual en el datáfono) y terminal habilitado. Botón **Guardar y validar API Datáfono**. |

### 4.5 FINANCIERO

| Enlace | Ruta | Permiso | Funcionalidad |
|---|---|---|---|
| Cartera / Cobros | `/admin/cartera/cobros` | `cartera.cobros` | Ciclo mensual de facturación:<br>1. **Consultar período** (mes).<br>2. **Registrar conceptos del período** por inmueble: Multas, Cuota extra u Otros cobros, con descripción y valor. Cada concepto se puede **Eliminar antes de emitir**.<br>3. **Previsualización**: inmueble, usuarios asignados, saldo anterior, cuota, otros conceptos, total y estado.<br>4. **Emitir**: genera las notas y envía los correos (requiere el SMTP configurado).<br>5. **Notas emitidas**: consecutivo, total, pagos, estado de los correos (enviados, fallidos, pendientes), **Ver / imprimir** (`/cartera/cobros/nota?id=`) y **Enviar / reintentar correos pendientes**.<br>6. **Registrar un pago recibido**: nota, valor, fecha, referencia y notas (requiere escritura).<br>7. **Pagos registrados**: lista de pagos con **Anular** y motivo (requiere nivel total). Los pagos confirmados por Bold no se anulan aquí. |
| Nota de cobro | `/admin/cartera/cobros/nota?id=N` | `cartera.cobros` | Documento imprimible de la nota: datos de la copropiedad y del banco, conceptos, saldos y destinatarios con el estado de cada envío. |
| Reportes de cartera | `/admin/cartera/reportes` | `cartera.cobros` | **Antigüedad de saldos** (rango, número de inmuebles y saldo, más el total por cobrar).<br>**Recaudo por mes**.<br>Aviso de pagos que requieren ajuste.<br>Tabla de cartera por inmueble: última nota, total, pagos, saldo, vencimiento y antigüedad.<br>Exportación: `/cartera/reportes/exportar?tipo=cartera` (CSV de cartera) y `?tipo=pagos&mes=AAAA-MM` (botón **Pagos CSV**). |

### 4.6 COMUNICACIÓN

| Enlace | Ruta | Permiso | Funcionalidad |
|---|---|---|---|
| Correo | `/admin/configuracion/correo` | `configuracion.correo` | **Proveedor SMTP:** servidor, puerto, cifrado (STARTTLS o SSL/TLS), usuario, contraseña (se guarda cifrada y se puede mostrar u ocultar al escribirla), correo remitente y nombre del remitente.<br>**Probar envío** manda un correo de prueba al correo del administrador. Esta cuenta se usa para las notas de cobro y para recuperar contraseñas. |
| Mensajes | `/admin/mensajes` | `mensajes` | **Redactar mensaje** a un usuario o a **Todos los usuarios activos** (asunto y mensaje; requiere escritura). Tabla **Mensajes enviados** con asunto, fecha, destinatarios y leídos. Los mensajes llegan a *Mis mensajes* del portal de usuario. |

---

## 5. Observaciones de la inspección

1. **Sin sesión, todas las rutas se comportan como se espera:** el sitio público responde 200, el portal redirige a `/login` y el panel a `/admin/login`.
2. **Solo hay enlaces internos.** Los únicos destinos externos son Google Maps, `mailto:` y `tel:` en los clasificados, y la redirección a Bold al pagar.
3. **`http://localhost/Copropiedad/` muestra el listado del directorio** ("Index of /Copropiedad"), con lo que expone archivos como `composer.json` y los `.md`. Conviene apuntar el DocumentRoot a `public/` o agregar una redirección en la raíz.
4. **Algunas etiquetas del menú y de las vistas no tienen tildes:** "Parametrizacion", "Caracterizacion", "Metodos de pago", "Contrasena", "Iniciar sesion", "Configuracion".
5. **Pantallas que dependen de otras:**
   - *Conformación* necesita la *Caracterización*.
   - *Asignación de inmuebles* necesita la *Conformación* y usuarios creados en *Equipo*.
   - Emitir y enviar notas en *Cobros* necesita *Correo* y *Financiera*.
   - El pago en línea del portal necesita *Métodos de pago*.

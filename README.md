# Copropiedad

Aplicacion PHP 8.2+ con MVC modular, AdminLTE 4 y MySQL mediante PDO.

## Preparacion local

1. Instala PHP 8.2 o superior con `pdo_mysql`, Composer y MySQL.
2. Desde la carpeta del proyecto instala las dependencias:

   ```powershell
   composer install
   ```

3. Crea la configuracion local a partir de la plantilla y define una contrasena fuerte:

   ```powershell
   Copy-Item .env.example .env
   ```

   Edita `.env`; el archivo esta excluido de Git.

4. Crea la base de datos y un usuario de aplicacion separado. Ejecuta como administrador de MySQL, cambiando la contrasena de ejemplo:

   ```sql
   CREATE DATABASE copropiedad CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'copropiedad_app'@'127.0.0.1' IDENTIFIED BY 'reemplazar-por-una-contrasena-larga';
   GRANT SELECT, INSERT, UPDATE, DELETE ON copropiedad.* TO 'copropiedad_app'@'127.0.0.1';
   ```

   Usa esas credenciales en `.env`. No uses el usuario `root` desde la aplicacion.

5. Comprueba la conexion:

   ```powershell
   php bin/check-database.php
   ```

6. Para una prueba local, inicia el servidor con `public` como raiz web:

   ```powershell
   php -S 127.0.0.1:8080 -t public public/router.php
   ```

   La raiz (`http://127.0.0.1:8080/`) es la portada pública, el panel administrativo está en `/admin` y el panel de usuario en `/usuario`. En Apache, configura el `DocumentRoot` del sitio hacia la carpeta `public`, no hacia la raíz del proyecto. Si se usa XAMPP en `http://localhost/Copropiedad/public`, configura `APP_URL=http://localhost/Copropiedad/public` en `.env`; los paneles quedan en `/Copropiedad/public/admin` y `/Copropiedad/public/usuario`.

## Estructura modular

- `app/Core`: infraestructura compartida (enrutador, vistas y conexion).
- `app/Modules/<Modulo>`: controladores, servicios y registro de rutas por dominio funcional.
- `app/Scenarios/Admin`: rutas de composición, layout y vistas exclusivas de administración.
- `app/Scenarios/User`: rutas, layout y vistas del panel de usuario.
- `app/Scenarios/Public`: rutas, layout y vistas de la portada y clasificados públicos.
- `public`: raiz expuesta al servidor web y pagina publica de la copropiedad.
- `public/admin`: punto de entrada del panel administrativo; las solicitudes bajo `/admin` se enrutan a esta carpeta.
- `public/usuario`: punto de entrada del panel de usuario; las solicitudes bajo `/usuario` se enrutan a esta carpeta.
- `public/assets/portada`: estilos e ilustración de respaldo de la portada.

Cada modulo debe registrar solo sus rutas y mantener su logica y vistas dentro de su propia carpeta. Los modulos acceden a MySQL a traves de `App\Core\Database`; usa consultas preparadas y nunca construyas SQL concatenando datos recibidos.

La conexion usa PDO con errores como excepciones, consultas preparadas nativas, `utf8mb4` y credenciales externas al codigo. Para un servidor MySQL remoto se requiere configurar `DB_SSL_CA` con el certificado CA legible para PHP; el certificado del servidor se valida.

## Acceso administrativo y recuperacion

Aplica una vez las migraciones de autenticacion y roles, en este orden, desde la raiz del proyecto:

```powershell
Get-Content .\database\migrations\001_create_admin_auth.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\002_create_configuration_roles.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

Configura `APP_URL` para construir los enlaces de recuperacion; en produccion debe usar HTTPS. El modulo **Configuracion / Correo** permite configurar el proveedor SMTP para notificaciones y recuperacion de acceso. Antes de guardar la contrasena, define `APP_KEY` en `.env` con una clave aleatoria de 32 bytes codificada en base64, generada con `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`. Conserva esta clave fuera del repositorio y no la cambies mientras haya credenciales SMTP almacenadas, pues se usa para cifrarlas con AES-256-GCM. Para Gmail usa SMTP `smtp.gmail.com:587`, STARTTLS y una contrasena de aplicacion de Google (no la contrasena de inicio de sesion). El enlace de recuperacion expira en una hora, solo se almacena el hash del token y el token se invalida al usarlo. La respuesta no revela si el correo esta registrado.

Con ambas migraciones aplicadas, crea el primer usuario administrativo definiendo `SUPERADMIN_PASSWORD` temporalmente en el entorno del proceso:

```powershell
php .\bin\create-superadmin.php
```

La cuenta se crea como `Superadmin` con el rol `Superadmin`; la contrasena se almacena con `password_hash` y el comando no reemplaza una cuenta existente. No guardes la contrasena en archivos del proyecto. El login bloquea temporalmente la cuenta despues de cinco intentos fallidos y las sesiones expiran por inactividad.

## Configuracion de equipo y permisos

La migracion 002 crea el esquema de roles y permisos, conserva las cuentas existentes y convierte el rol actual en una relacion con `admin_roles`. En el panel, abre **Configuracion / Equipo** para crear, editar, activar y desactivar cuentas, y **Configuracion / Roles y permisos** para definir roles personalizados. Las cuentas desactivadas conservan su registro y sus datos; su acceso se revoca inmediatamente.

Los niveles son **Lectura** (consultar), **Escritura** (consultar, crear y editar) y **Total** (todas las acciones, incluida la desactivacion/eliminacion). La autorizacion se verifica del lado del servidor en cada ruta. Un administrador no puede conceder permisos por encima de los propios; el rol de sistema Superadmin no se puede editar, eliminar, asignar desde el formulario ni desactivar.

## Parametrizacion de copropiedad y financiera

Aplica la migracion una sola vez con una cuenta MySQL administradora:

```powershell
Get-Content .\database\migrations\003_create_property_settings.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

En **Configuracion / Parametrizacion** se guardan el nombre, direccion, NIT y, opcionalmente, los datos completos del titular, banco, tipo y numero de cuenta. En bases donde ya se aplico la migracion 003, aplica una sola vez la migracion adicional `004_add_bank_account_number.sql` para agregar el campo bancario sin afectar los demas datos. En **Configuracion / Financiera** se guardan la base mensual de liquidacion, su fecha de vigencia, el descuento fijo por pronto pago, la mora en porcentaje o valor fijo con sus dias de espera, el valor base de las multas y, opcionalmente, el valor de la cuota extraordinaria con sus fechas inicial y final de vigencia. En **Configuracion / Caracterizacion** se define si la copropiedad es una casa, un conjunto de torres de apartamentos o una copropiedad mixta.

Aplica tambien las migraciones `004_add_bank_account_number.sql` (si aun no se ha aplicado) y `005_add_housing_type.sql` una sola vez:

```powershell
Get-Content .\database\migrations\004_add_bank_account_number.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\005_add_housing_type.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\006_add_property_composition.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\007_add_tower_nomenclature.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\008_rename_tower_to_apartment_nomenclature.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\009_split_tower_and_apartment_nomenclature.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\010_create_property_unit_assignments.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\011_add_user_portal_permission.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\012_create_mail_settings.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\013_create_billing_module.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\014_create_user_notifications.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\015_add_extraordinary_fee_settings.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\016_create_bold_payment_settings.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\017_create_classifieds.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\018_add_coefficient_snapshot_to_billing_statements.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\019_add_bold_api_integrations.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\020_add_payment_voiding.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

En **Configuracion / Metodos de pago**, configura la llave de identidad del **Boton de pagos** de Bold (ambiente de pruebas o produccion) y los metodos habilitados para el comercio. La llave se cifra usando `APP_KEY`. El portal genera un link de pago cerrado por cada nota recibida; la cartera solo registra el abono despues de verificar que Bold reporta el link como pagado y que su monto coincide con el saldo. Para produccion, configura `APP_URL` con el dominio publico HTTPS y registra `{APP_URL}/pagos/bold/webhook` en Integraciones / Webhooks del Panel de Comercios de Bold. La API Link de pagos utiliza la llave de identidad de Boton de pagos, no las llaves para conectar datáfonos.

La migracion 005 agrega el tipo de vivienda como un dato opcional y un permiso independiente para Caracterizacion; la migracion 006 habilita el tipo Mixto y crea el almacenamiento de la estructura de bienes; las migraciones 007 a 009 agregan y separan la nomenclatura de torres y apartamentos. En **Configuracion / Caracterizacion**, para **Torres de apartamentos** y **Mixto** se puede elegir nomenclatura de torres numerica (Torre 1, Torre 2…) o alfabetica (Torre A, Torre B… Torre Z, Torre AA…). Los apartamentos usan siempre numeracion de dos digitos, del 01 al 99; 00 no es valido. En **Configuracion / Conformación de la copropiedad**, el administrador completa un asistente que guarda el avance, genera inmuebles a partir de las cantidades y permite corregir o agregar categorias (por ejemplo, locales u oficinas) antes de finalizar. La estructura admite hasta 5.000 inmuebles. En cada inmueble se registra un coeficiente porcentual entre 0 y 100, de hasta cuatro decimales; 0 es válido (el inmueble no paga cuota de administración) y solo el valor vacío cuenta como «sin coeficiente». La cuota mensual se calcula como base mensual × coeficiente / 100 y se redondea al centavo; la emisión se bloquea si falta un coeficiente. La migración 018 agrega a cada nota una instantánea del coeficiente aplicado; aplícala una sola vez en bases existentes. Si se cambia la caracterizacion o la nomenclatura despues de comenzar, se solicita confirmacion y se reinician los datos de conformacion. El permiso de Conformacion se inicializa a partir del permiso de Caracterizacion; los permisos se pueden ajustar en **Configuracion / Roles y permisos**. Guardar parametros no emite facturas, crea cobros ni aplica mora o multas automaticamente.

**Coeficiente por categoría.** En Conformación, el panel *Coeficiente por categoría* desglosa la suma del coeficiente por categoría (Apartamento, Parqueadero, Inmueble comercial…): inmuebles, suma, participación, coeficiente por inmueble (valor único o rango, y cuántos no lo tienen) y la cuota mensual aproximada según la base o el presupuesto de Configuración / Financiera. Se recalcula mientras se edita. La lista tiene filtro por categoría; *Ver* filtra la lista y *Seleccionar* marca la categoría para aplicar *Asignar coeficiente* o *Repartir un total* de una vez. En la vista de solo lectura se muestra el mismo desglose, sin *Seleccionar*.

**Menú «Coeficientes».** Todas sus opciones actúan sobre las filas filtradas (búsqueda, categoría o «Con errores», en todas las páginas). El botón muestra a cuántos se aplica («8 de 40» si hay filtro) y el menú abre con el alcance, cuánto suman y cuántos no tienen coeficiente. Opciones: *Mismo coeficiente a cada uno*; *Repartir un total en partes iguales* (sugiere lo que deja el total en 100 %); *Completar lo que falta* (reparte lo que falta para 100 % solo entre los filtrados sin coeficiente); *Pegar una columna desde Excel*; *Borrar coeficientes*.

**Lista de inmuebles como tabla de datos.** En Conformación, la lista de inmuebles tiene paginación (10, 25, 50, 100 o todas las filas, recordado por navegador), orden por columna (el tercer clic restaura el orden original; el orden guardado de la estructura no cambia), columnas visibles, selección de varias filas (la página o todas las filtradas) con acciones masivas —cambiar categoría, asignar coeficiente, repartir un porcentaje total, borrar coeficiente y quitar (los inmuebles con notas de cobro se conservan)— y exportación CSV de las filas filtradas o seleccionadas. Los cambios siguen aplicándose solo al pulsar Guardar. El componente es reutilizable: `public/assets/js/data-grid.js` (`new DataGrid(tabla, opciones)`), configurado con atributos `data-column`, `data-sortable`, `data-hideable` y `data-export` en el encabezado. **Reiniciar conformación:** en la guía de pasos, el paso 2 tiene el botón *Reiniciar* (POST `/configuracion/conformacion/reiniciar`), que borra las respuestas del asistente, los inmuebles, coeficientes y asignaciones vigentes (cerradas en Trazabilidad) y vuelve a la primera pregunta; conserva la caracterización. Solo el Superadmin puede hacerlo, exige escribir REINICIAR y se bloquea si ya hay notas de cobro emitidas.

**Lista de residentes como tabla de datos.** Comunidad / Residentes usa la misma tabla: paginación, orden, columnas visibles y exportación CSV. Las pestañas Activos / Inactivos / Todos, la búsqueda (sin tildes ni mayúsculas) y los filtros por tipo de residente y por inmueble se aplican sin recargar y quedan en la dirección (`?estado=…&q=…`). Con filas seleccionadas se puede enviar el acceso, activar, desactivar o eliminar en lote (`POST /comunidad/residentes/lote`, hasta 1000 por lote; eliminar exige el permiso total). Cada residente se procesa por separado: quien ya está en el estado pedido se omite y quien tiene historial de cartera o mensajes no se elimina, sin detener a los demás; las invitaciones salen después de responder.

La migracion 010 crea el registro de asignaciones entre usuarios e inmuebles y agrega el permiso independiente **Inmuebles / Asignacion de usuarios** (inicializado con el nivel de **Configuracion / Equipo**). En la seccion **Asignacion de inmuebles**, selecciona un usuario y marca uno o varios inmuebles; las asignaciones se mantienen al editar el nombre del inmueble y se eliminan al quitarlo de la estructura.

La migracion 011 habilita el permiso de lectura **Usuario / Portal de inmuebles asignados** para las cuentas existentes. El acceso **Iniciar sesión de usuario** aparece en el pie de pagina; cada cuenta puede consultar unicamente los inmuebles asignados a su usuario en el **Panel de Usuario** y actualizar su nombre de usuario, correo y contrasena confirmando su contrasena actual. El panel muestra el canon y descuento configurados; las notas de cobro se notifican por correo y se consultan desde **Cartera / Cobros** en el panel administrativo. Al iniciar desde el acceso del portal, se prioriza el Panel de Usuario; el ingreso administrativo sigue disponible en `/login`.

La migracion 012 crea el almacenamiento del proveedor de correo e incorpora el permiso **Configuracion / Correo**. En el panel administrativo, configura el host, puerto, cifrado, usuario y remitente; la contraseña se cifra antes de almacenarse, nunca se vuelve a mostrar y puede conservarse al editar dejando el campo vacío. Define `APP_KEY` en `.env` como una clave base64 de 32 bytes (usa `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"` para generarla) y mantenla estable: se necesita para descifrar la contraseña guardada. Guarda los cambios antes de probar; el botón de prueba envía un mensaje a la dirección de correo de la cuenta administradora que lo solicita y utiliza la configuración guardada. Las contraseñas SMTP antiguas de `.env` se admiten como compatibilidad hasta que se configure el nuevo módulo.

La migracion 013 incorpora **Cartera / Cobros**, los días configurables de pronto pago y vencimiento, conceptos por inmueble, notas mensuales con consecutivo, pagos y estado de notificación por destinatario. Aplica la migracion una sola vez después de la 012. En **Configuracion / Parametrizacion**, define ambos días (del 1 al 28), además de los datos bancarios; en **Configuracion / Financiera** define la cuota y vigencia; y en **Configuracion / Correo** valida SMTP. En **Cartera / Cobros**, revisa el período y los conceptos manuales, luego confirma la emisión: cada inmueble/mes se emite una sola vez, los datos quedan como instantánea y los correos fallidos o pendientes se pueden reintentar en lotes de hasta 50 destinatarios. El pronto pago se reconoce solo cuando los pagos registrados alcanzan el total con descuento antes de la fecha límite. Registra los pagos de un período antes de emitir el siguiente para que se reflejen en el saldo. Intereses, procesos jurídicos, multas, retroactivos, cuotas extras y otros conceptos se ingresan manualmente para que la administración verifique soporte y valor; no se calculan automáticamente. No se prorratea una cuota con vigencia a mitad de mes: la emisión se bloquea para evitar asumir un tratamiento no aprobado. Se requiere emitir períodos en orden para arrastrar saldos; si no hay nota anterior, el saldo inicial se captura como concepto **Saldo inicial / ajuste de saldo anterior**. Se notifica a cada cuenta activa asignada al inmueble; el sistema no distingue ni almacena propietarios frente a residentes. La nota es imprimible desde el navegador y no reemplaza una factura electrónica ni la revisión contable.

La migracion 014 agrega la bandeja de mensajes internos y el registro de lectura de notificaciones de cartera. En el encabezado del **Panel de Usuario**, el avatar muestra avisos de notas emitidas con cuota de administración o multas y mensajes nuevos. Los cobros se marcan como leídos desde el menú del avatar; los mensajes se consultan en **Mis mensajes**. Administración puede enviar mensajes de texto a un usuario o a todos los usuarios activos desde **Mensajes**; el permiso **Mensajes internos** controla lectura y envío.

La migracion 017 agrega clasificados de inmuebles. El usuario puede publicar una oferta en venta o una búsqueda de compra con descripción, precio/presupuesto opcional y hasta cinco imágenes JPEG, PNG o WebP de máximo 4 MB cada una. Configura PHP para permitir esos archivos (`upload_max_filesize=4M`, `post_max_size=25M` y `max_file_uploads=8`). Las imágenes se guardan fuera del directorio público y se sirven mediante una ruta que valida la publicación; el diseño se adapta a pantallas móviles y de escritorio. Las publicaciones se consultan sin iniciar sesión en `/clasificados` y el usuario puede cerrarlas, volver a publicarlas o eliminarlas desde su panel. Los datos de contacto no se almacenan para difusión ni se muestran sin autorización explícita en cada clasificado; el usuario solo expone los campos que ingresa y autoriza.

La página pública de `/` usa los datos de **Configuración / Parametrización**, muestra la dirección con un enlace a Google Maps, presenta hasta tres clasificados recientes y permite entrar al panel de usuario. En **Configuración / Galería pública**, administra hasta ocho fotos JPG, PNG o WebP (3 MB y 24 megapíxeles por imagen); estas imágenes quedan en `public/uploads/property-gallery` y deben incluirse en los respaldos del sitio. Para subir varias fotos en una solicitud configura PHP con `upload_max_filesize=4M`, `post_max_size=25M` y `max_file_uploads=8`. La portada incluye títulos y descripciones para buscadores, URL canónica, datos estructurados WebSite, `robots.txt` y `sitemap.xml`. Define `APP_URL` con el dominio público HTTPS para que los enlaces canónicos y el sitemap usen la dirección correcta.

El diseño sigue el ciclo de vida de documentos de cobro usados habitualmente: previsualización, emisión con datos congelados, estados de pago y seguimiento/reintento de notificaciones. Referencia general: [Stripe Invoicing: ciclo de vida de las facturas](https://docs.stripe.com/invoicing/overview) y [notificaciones y recordatorios](https://docs.stripe.com/invoicing/send-email).

## Documentación de requisitos, seguridad y operación

- [Análisis funcional y propuestas](ANALISIS_FUNCIONAL.md): defectos corregidos, funciones agregadas (panel, reportes, anulación de pagos) y pendientes priorizados.
- [Inventario funcional para migración](INVENTARIO_MIGRACION.md): matriz para seleccionar funciones, procesos, datos y reglas que se conservarán o excluirán.
- [Requisitos de la solución actual](REQUISITOS_SOLUCION_ACTUAL.md): funciones aplicadas, pendientes, reglas de negocio y criterios de aceptación sugeridos.
- [Mapa técnico y flujos](MAPA_TECNICO_Y_FLUJOS.md): responsabilidades archivo por archivo, recorridos de solicitudes y convenciones para cambios.
- [Revisión de seguridad y operación](REVISION_SEGURIDAD_Y_OPERACION.md): cambios aplicados, riesgos pendientes, instalación, actualización, backups, cartera e incidentes.

API Integrations de Bold (datáfono): la migración 019 crea el almacenamiento separado para llave, terminal, método y pagos pendientes. En Configuración / Métodos de pago guarda primero la llave API Datáfono y selecciona el método activo; recarga para elegir un terminal Smart o Smart Pro vinculado. Configura APP_URL en HTTPS, registra la URL de webhook mostrada y guarda la llave secreta de producción para verificar x-bold-signature. El residente puede iniciar el cobro desde Panel de Usuario / Pagos; no debe volver a enviarlo si el servidor indica que Bold pudo haberlo recibido. La cartera solo registra el abono cuando llega una venta aprobada, firmada, con la referencia y el monto correctos.

La migración 020 permite **anular pagos manuales** registrados por error desde **Cartera / Cobros** (requiere nivel Total): el pago se conserva con fecha, usuario y motivo y deja de contar en el saldo. Cuando se emite el período siguiente, el saldo de la nota anterior queda trasladado: esa nota ya no recibe pagos manuales ni en línea, y el portal indica pagar la nota más reciente. **Reportes de cartera** (`/cartera/reportes`) muestra el estado de cada inmueble, la antigüedad estimada de saldos y el recaudo mensual, y exporta cartera y pagos a CSV; el **Panel** administrativo resume estos indicadores y los pendientes operativos.

## Multicliente: conjuntos residenciales

La migración 023 convierte la aplicación en multicliente: cada **conjunto residencial** es un cliente con sus propios datos, usuarios, roles, configuración, cartera, mensajes y clasificados. Todo vive en una sola base de datos; cada tabla de negocio lleva `tenant_id` y cada consulta filtra por el conjunto de la solicitud. La copropiedad existente queda como el conjunto `principal`.

```powershell
Get-Content .\database\migrations\023_create_tenants.sql | & C:\xampp84\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

**Cómo se identifica el conjunto.** Por el subdominio, configurado en `.env`:

- `APP_BASE_DOMAIN=midominio.com`: `midominio.com/admin` es la **consola del Superadmin**; `{slug}.midominio.com` es el conjunto (página pública, `/usuario` y `/admin`).
- En local (configuración actual): `APP_BASE_DOMAIN=localhost`. La consola del Superadmin queda en `http://localhost/Copropiedad/public/admin` (también `http://localhost/Copropiedad/`) y cada conjunto en su copia propia, `http://localhost/Copropiedad/conjuntos/{slug}/public`. Alternativa sin copias: `APP_BASE_DOMAIN=localtest.me`, que resuelve cualquier subdominio a la máquina (`http://principal.localtest.me/Copropiedad/public/`).
- En local, `localtest.me` resuelve cualquier subdominio a la máquina: `http://localtest.me/Copropiedad/public/admin` (consola) y `http://principal.localtest.me/Copropiedad/public/` (conjunto), sin tocar el archivo hosts.
- En producción se necesita un DNS comodín (`*.midominio.com`) y un certificado SSL comodín. Los enlaces de correo, el retorno y el webhook de Bold usan el subdominio de cada conjunto: registra en Bold la URL que muestra *Métodos de pago* de cada conjunto.

**Consola del Superadmin** (`/admin/clientes`, solo en el dominio principal): crea conjuntos con su subdominio, habilita o deshabilita módulos (portal de residentes, cartera, pagos en línea Bold, mensajes, clasificados), asigna uno o más administradores, cambia su contraseña, los desactiva y suspende o reactiva el servicio de un conjunto. Un módulo deshabilitado desaparece para todos en ese conjunto (incluido su administrador) sin borrar datos. Los módulos de núcleo (parametrización, equipo, roles, caracterización, conformación y asignaciones) siempre están disponibles.

**Cuentas.** Cada cuenta pertenece a un solo conjunto; el usuario y el correo son únicos dentro del conjunto, así que una misma persona puede tener cuenta en dos conjuntos con el mismo correo. El administrador asignado tiene el rol de sistema `Administrador` (acceso total en su conjunto, no editable desde Equipo ni Roles). El Superadmin no pertenece a ningún conjunto, puede entrar al de cualquiera con sus credenciales y es el único que reinicia la conformación. La sesión queda ligada al conjunto donde se inició.

## Vínculo con el inmueble y trazabilidad

En **Asignación de inmuebles** cada usuario se vincula a sus inmuebles como *Propietario*,
*Propietario residente*, *Inquilino* o *Residente*, con una **fecha del cambio** (hoy por defecto, nunca futura).

- `property_unit_assignments` guarda los vínculos **vigentes** (portal, cobro y notificaciones).
- `property_unit_relationship_history` guarda **todos** los vínculos con fecha de inicio, fecha de fin,
  motivo de cierre y quién registró cada cambio. Nunca se borra y conserva los nombres del inmueble y
  de la persona.
- Un cambio de tipo de vínculo cierra el registro anterior y abre uno nuevo; quitar un inmueble lo cierra
  con motivo *Se desvinculó*. Eliminar inmuebles de la estructura o reiniciar la conformación también
  cierra los vínculos afectados.
- **Trazabilidad de inmuebles** (`/admin/inmuebles/historial`) muestra la línea de tiempo de cada inmueble,
  incluso si ya salió de la estructura. El portal del residente muestra su vínculo y desde cuándo.

Migración: `database/migrations/024_add_unit_relationship_history.sql` (los vínculos existentes quedan como
*Propietario* desde la fecha en que se asignaron).

### Liquidación por base mensual o por presupuesto (migración 033)

En **Configuración / Financiera → Administración y descuento** se elige cómo se interpreta el valor:

- **Base mensual de liquidación** (como antes): cada inmueble paga base × su coeficiente (%). Si los coeficientes no
  suman 100 %, lo recaudado es base × suma de coeficientes.
- **Valor mensual del presupuesto:** el valor es el total a recaudar. Se reparte entre todos los inmuebles de la
  conformación en proporción a su coeficiente (`BillingService::budgetShares()`, método del mayor residuo, solo
  aritmética entera), y las cuotas suman exactamente el presupuesto. Los inmuebles sin residente asignado no se cobran,
  pero su parte tampoco se carga a los demás.

La pantalla muestra cuánto se recauda al mes con cada opción. Sin la migración, todo conjunto liquida sobre la base
mensual y la opción de presupuesto pide aplicarla. Las notas ya emitidas conservan su cuota.

```powershell
Get-Content .\database\migrations\033_add_fee_basis.sql | & C:\xampp84\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

### Cartera / Cobros: lista de conceptos y vista previa de la nota

- **Preparar:** la pestaña *Preparar y emitir* muestra la lista de chequeo de los conceptos que irán en las notas por
  emitir (saldo del mes anterior, cuota de administración, cada tipo de concepto adicional, descuento por pronto pago y
  saldo a favor), con cuántos inmuebles lo llevan y su total, y los tipos no incluidos ese mes. Cada concepto se marca
  como revisado; lo marcado se recuerda en el navegador por período y se reinicia si cambia algún valor.
- **Tablas de datos:** *Preparar y emitir*, *Notas emitidas* y *Pagos* usan la misma tabla que Residentes (paginación, orden —los montos por su valor—, columnas visibles, exportación CSV y búsqueda sin tildes). En Preparar se pueden seleccionar inmuebles y **agregar el mismo concepto a todos** (`POST /cartera/cobros/concepto/lote`, `BillingService::addItemToUnits()`, una sola transacción; los ya emitidos se omiten y se informan). Pagos tiene filtro Vigentes / Anulados.
- **Emitir solo los seleccionados:** junto a *Emitir y enviar* (todo el período) está *Emitir y enviar seleccionados* (también en la barra de selección). Basta con que la configuración general esté lista (Parametrización, Correo, Financiera); los inmuebles con problemas propios no bloquean a los demás. Solo se emiten los seleccionados que están «Listos» y solo se envían sus correos; los demás se omiten y se informan (`POST /cartera/cobros/emitir` con `unit_ids[]`, `BillingService::issue($period, $actor, $unitIds)`). El modal muestra cantidad, total y vista previa solo de esos inmuebles.
- **Emitir:** el botón *Emitir y enviar* solo se habilita con todos los conceptos revisados y la confirmación marcada.
  El modal muestra la **vista previa del documento** que recibirá cada residente (destinatarios, asunto y la nota con sus
  valores), inmueble por inmueble. Es un borrador: no se guarda nada y el número se asigna al emitir
  (`GET /cartera/cobros/vista-previa?periodo=AAAA-MM&inmueble=…`, `BillingService::draftStatement()`, con los mismos
  datos que usa la emisión). Se muestra en un marco del mismo origen, aislado y sin scripts.

### Avisos de pago, pagos reportados y arriendos (migración 025)

- **Aviso de pago:** cada pago registrado (pago en línea Bold, datáfono, pago manual de cartera o pago
  reportado y aprobado) genera un aviso en la campana del portal y un correo con el **comprobante**
  (`RC-000123`) a las cuentas de la nota, y un correo a la administración (remitente de la cuenta general de
  correo). Los avisos se envían después de responder, así que no frenan el webhook ni la redirección; una falla
  de SMTP queda en el log y no afecta el pago. El comprobante imprimible está en **Pagos / Mis pagos y
  comprobantes** del portal y en la pestaña **Pagos** de **Cartera / Cobros**.
- **Reportar un pago:** en **Pagos** el residente informa un pago por transferencia, consignación o efectivo
  con su soporte (imagen o PDF de máximo 5 MB, guardado fuera del directorio público en
  `storage/payment-proofs`). La administración lo revisa en **Cartera / Pagos reportados** (el menú muestra los
  pendientes): al aprobar, puede corregir valor y fecha, y el pago se registra en la nota vigente del inmueble;
  al rechazar, el residente recibe el motivo. Revisar requiere el permiso de cartera en nivel Escritura.
- **Clasificados:** se pueden publicar inmuebles **en arriendo** (el valor se muestra como canon mensual) y el
  autor puede **editar** sus avisos: datos, contacto e imágenes (entre 1 y 5).

Migración (con una cuenta MySQL administradora):

```powershell
Get-Content .\database\migrations\025_add_payment_notifications_reports_and_rentals.sql | & C:\xampp84\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

### Enlaces de pago temporales y QR (migración 026)

- **Desde la administración:** en **Cartera / Cobros**, cada nota con saldo tiene **Enlace / QR**. Se elige a
  nombre de qué cuenta destinataria queda el pago y la vigencia (24 h, 3, 7 o 15 días). El enlace se puede
  enviar por correo (desde el correo personal del administrador o la cuenta general), copiar, compartir por
  WhatsApp o mostrar/descargar como QR. Se ve cuándo se envió y cuántas veces se abrió, y se puede revocar.
- **Desde el portal:** en **Pagos**, **QR / compartir** genera un enlace de 24 horas para pagar desde el
  celular o para que otra persona pague; el pago queda a nombre del residente.
- El enlace abre `/pagar?t=…` sin iniciar sesión: muestra la nota y el saldo vigente y lleva al checkout de Bold.
  Al volver, quien no tiene sesión ve `/pagar/resultado`. Requiere el módulo de pagos en línea y Bold configurado.
- El token se guarda como hash (para buscarlo) y cifrado con `APP_KEY` (para volver a mostrar el enlace y su
  QR). El QR se dibuja en el navegador con `qrcode-generator` 1.4.4 servido localmente desde `public/assets/vendor/`, con verificación SRI.
- La política CSP ahora permite `form-action https://checkout.bold.co`: sin eso, Chrome bloquea la redirección
  al checkout después de enviar el formulario de pago.

```powershell
Get-Content .\database\migrations\026_create_billing_payment_links.sql | & C:\xampp84\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

### Reiniciar un conjunto desde cero (solo Superadmin)

En la consola del Superadmin (dominio principal), la ficha de cada conjunto tiene la **Zona de peligro**: una tabla
con casillas para elegir qué reiniciar (cartera y pagos, avisos, comunicados, atención a residentes, reservas, espacios comunes,
clasificados, asignaciones, conformación, parametrización y caracterización, correo, credenciales de pasarelas, galería,
cuentas de residentes o todas las cuentas y roles), con lo que se borraría de cada una. Al marcar una categoría se marcan
las que necesita borrar antes (por ejemplo, Conformación arrastra Asignaciones y Cartera) y el servidor rechaza
selecciones incompletas (`TenantResetService::CATEGORIES` / `validateSelection`). Pide escribir el subdominio del conjunto
y la contraseña del Superadmin. Con todo marcado (reinicio completo) borra en una sola transacción todas las cuentas (administradores, equipo y residentes), roles, parametrización,
caracterización y conformación, asignaciones e historial, cartera (notas, pagos, reportes, enlaces), mensajes,
avisos, clasificados, configuración de correo y de Bold; después elimina sus archivos (fotos, soportes y galería).
Conserva el conjunto, su subdominio, su estado y sus módulos, y lo deja como recién creado con el rol
Administrador vacío: luego se asigna el nuevo administrador. No se puede deshacer; haz una copia de la base antes.
Cada reinicio queda en el registro de errores de PHP con el Superadmin, el conjunto y los archivos eliminados.

### Wompi como pasarela adicional (migración 027)

- **Módulo propio:** «Pagos en línea (Wompi)» aparece en la consola del Superadmin junto a «Pagos en línea (Bold)»
  y se habilita o deshabilita por conjunto. Ambos requieren «Cartera y cobros» y comparten el permiso
  **Configuración / Métodos de pago**, disponible si al menos una pasarela está habilitada.
- **Configuración:** en **Métodos de pago** se registran el ambiente (pruebas o producción), la llave pública y,
  cifrados con `APP_KEY`, la llave privada y los secretos de eventos y de integridad (Wompi › Desarrolladores ›
  Programación). Al guardar se verifica la llave pública con Wompi. Registra en Wompi la **URL de eventos**
  que muestra la pantalla (`/pagos/wompi/eventos`, HTTPS público), una por ambiente.
- **Cobro:** el residente elige Bold o Wompi en **Pagos**; los enlaces temporales y el QR (`/pagar?t=…`) ofrecen
  las pasarelas disponibles. Cada intento usa una referencia única `CPW-…` y el Web Checkout firmado
  (`signature:integrity`). El pago se registra en cartera solo cuando llega un evento `transaction.updated` con
  checksum válido y la consulta a `/v1/transactions/{id}` con la llave privada confirma `APPROVED`, la referencia,
  el valor y la moneda; el retorno del navegador también consulta a Wompi, nunca confía en la URL.
- Los pagos de Wompi no se anulan desde cartera (como los de Bold) y generan aviso y comprobante como los demás.

```powershell
Get-Content .\database\migrations\027_create_wompi_payments.sql | & C:\xampp84\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

### Zonas comunes (migración 028)

- **Módulo:** «Zonas comunes», que el Superadmin habilita por conjunto (requiere el portal de residentes). En el
  panel lo usa el permiso **Zonas comunes / Espacios y reservas**: Total para el rol de sistema; los roles
  personalizados empiezan sin acceso.
- **Espacios** (`/admin/zonas-comunes/espacios`): nombre, horario de uso, duración máxima por reserva, anticipación
  máxima, aforo opcional, descripción y reglamento. Un espacio con reservas no se elimina: se desactiva.
- **Residente** (`/usuario/zonas-comunes`): ve la agenda de dos semanas de cada espacio (sin nombres de otros
  residentes), solicita fecha y horas dentro del horario permitido y cancela sus solicitudes o reservas futuras.
  No puede pedir un horario ya aprobado ni duplicar una solicitud propia que se cruce.
- **Aprobación** (`/admin/zonas-comunes`, con contador en el menú): cada solicitud muestra si está libre, si
  compite con otras pendientes o si se cruza con una aprobada; en ese caso no se puede aprobar. Al aprobar, las
  pendientes que se cruzan se rechazan automáticamente. Las decisiones se toman bajo bloqueo del espacio, así que
  dos aprobaciones simultáneas no pueden quedar cruzadas.
- **Avisos:** la administración recibe un correo por cada solicitud y cancelación de una reserva aprobada; el
  residente recibe aviso en el portal y correo con cada decisión. Los avisos del portal ahora admiten tipos de
  cualquier módulo (columna `kind` de texto) y los gestiona `App\Modules\Auth\UserNotifier`.

```powershell
Get-Content .\database\migrations\028_create_common_areas.sql | & C:\xampp84\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

### Comunidad / Residentes (migración 029)

- **Sección Comunidad** del panel: **Residentes**, **Reservas de zonas comunes** y **Espacios comunes**.
- **Residentes** (`/admin/comunidad/residentes`, permiso **Comunidad / Residentes**: Total para el rol de sistema,
  los demás roles empiezan sin acceso): listado con búsqueda (nombre, documento, correo, teléfono, inmueble) y
  filtro de activos/inactivos; alta y edición de la ficha (nombre, documento, teléfono, notas internas) y de la
  cuenta del portal; invitación por correo para crear la contraseña (vence en 72 horas, reenviable); activar o
  desactivar; eliminar (requiere nivel Total y solo si no tiene cobros, pagos o mensajes: si los tiene, se
  desactiva). Los inmuebles se vinculan en **Inmuebles / Asignación**, con acceso directo desde cada residente.
- **Separación de cuentas:** los roles tienen tipo (`admin_roles.kind`): `staff` para el personal, que se gestiona en
  **Configuración / Equipo de trabajo**, y `resident` para el rol **Residente** de cada conjunto (acceso al portal),
  que no se puede eliminar ni asignar desde Equipo. Todo conjunto nuevo o reiniciado nace con los roles
  Administrador y Residente.
- La recuperación de contraseña y la invitación comparten `App\Modules\Auth\AccessLinkService`.

```powershell
Get-Content .\database\migrations\029_create_community_residents.sql | & C:\xampp84\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

### Tipos de residente e importación CSV (migración 031)

- **Comunidad / Tipos de residente** (`/comunidad/tipos-residente`): catálogo por conjunto para clasificar a los
  residentes. Cada conjunto empieza con *Propietario*, *Inquilino* y *Familiar o acompañante* (también al crearlo o
  al reiniciar esa categoría desde la consola). Se crean y editan con permiso de escritura; un tipo en uso no se
  elimina: se desactiva y deja de ofrecerse, pero quien ya lo tiene lo conserva.
- La ficha del residente tiene el campo **Tipo de residente**, visible en el listado y usado por el buscador.
- **Importar CSV** (`/comunidad/residentes/importar`): se sube el archivo, se revisa fila por fila sin escribir nada
  (nuevo, ya registrado por correo o documento, o con error) y se confirma. Columnas, en cualquier orden:
  `nombre_apellidos`, `documento` (cédula o NIT) y `correo` obligatorias; `tipo_documento`, `tipo_residente` y
  `telefono` opcionales. Sin `tipo_documento`, un número con dígito de verificación (`900123456-7`) se toma como NIT
  y los demás como cédula. Acepta `;`, `,` o tabulador y archivos UTF-8 o de Excel (Windows-1252); hasta 1 MB y
  2.000 filas. Hay plantilla descargable. Las cuentas se crean sin invitación, salvo que se marque enviarla (los
  correos salen después de responder).

```powershell
Get-Content .\database\migrations\031_create_resident_types.sql | & C:\xampp84\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

### Inquilino solo con propietario registrado (migración 032)

- Regla: un residente de tipo **Inquilino** se registra si, y solo si, el inmueble que arrienda ya tiene un
  propietario vigente. La regla vive en el catálogo como la marca *Exige propietario en el inmueble*
  (`resident_types.requires_unit_owner`, activa para Inquilino y editable), no en el nombre del tipo.
- **Formulario del residente:** al elegir un tipo con la marca aparece *Inmueble que arrienda* (obligatorio); los
  inmuebles sin propietario aparecen deshabilitados. La cuenta y su vínculo como inquilino se guardan en una sola
  transacción: si el inmueble no tiene propietario, no se crea nada.
- **Importación CSV:** columna `inmueble` (nombre como en la conformación), obligatoria para esos tipos y validada en
  la revisión; al confirmar el residente queda vinculado como inquilino.
- **Asignación de inmuebles:** cualquier vínculo nuevo como *inquilino* exige que el inmueble tenga propietario (otra
  persona). Los vínculos de inquilino ya existentes se conservan aunque después se retire el propietario.

```powershell
Get-Content .\database\migrations\032_add_resident_type_owner_rule.sql | & C:\xampp84\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

### Portal exclusivo para residentes y Atención a residentes (migración 030)

- **Solo los residentes** (cuentas con el rol de tipo `resident`) entran al portal `/usuario`; el equipo de la
  administración trabaja solo en el panel. Una cuenta de residente nunca obtiene permisos del panel, aunque se
  editen en su rol, y el rol Residente ya no aparece en **Roles y permisos**. Al iniciar sesión cada cuenta va a su
  lugar, sin importar la pantalla de acceso. El personal edita sus datos y contraseña en **Mi cuenta** (`/admin/mi-cuenta`).
- **Atención a residentes:** desde el portal (**Comunicación › Escribir a la administración**) el residente abre una
  consulta, petición, queja, reclamo o sugerencia y conversa con el equipo. En el panel (**Comunicación › Atención a
  residentes**, permiso **Mensajes**, con contador de pendientes) el equipo responde, responde y cierra, cierra o
  reabre. Cada respuesta llega al residente en su portal (aviso) y por correo; cada mensaje del residente llega por
  correo a la administración.
- **Comunicados a residentes:** los avisos que envía la administración (a uno o a todos) ahora solo se dirigen a
  residentes, porque se leen en el portal.

```powershell
Get-Content .\database\migrations\030_create_resident_conversations.sql | & C:\xampp84\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

## Directorio propio por conjunto residencial

Cada conjunto puede tener su propia copia en `conjuntos/{slug}/`, a cuya carpeta `public/` se apunta su dominio.
El código se edita **solo** en este proyecto (el repositorio); `bin/deploy-conjunto.php` lo replica en cada copia:

```powershell
C:\xampp84\php\php.exe bin\deploy-conjunto.php st-trope                            # crea o actualiza una copia
C:\xampp84\php\php.exe bin\deploy-conjunto.php st-trope --url=https://st-trope.com # fija su dominio (APP_URL)
C:\xampp84\php\php.exe bin\deploy-conjunto.php --all                               # actualiza todas tras un cambio
```

- Cada copia tiene su `.env` (con `APP_TENANT={slug}`), `storage/` y `public/uploads/`; el script nunca los sobrescribe.
  `conjuntos/` no se versiona en git.
- La copia atiende solo a su conjunto con cualquier dominio. La base de datos es compartida.
- Este proyecto sigue siendo la consola del Superadmin. Los enlaces, archivos y reinicios de un conjunto con copia
  apuntan a su directorio, y las visitas por su subdominio se redirigen a la copia (los POST de webhooks se siguen atendiendo).
- Las migraciones se aplican una sola vez sobre la base compartida; después basta con `--all`.
- Después de cada cambio de código hay que ejecutar `--all`; si no, las copias siguen con la versión anterior
  (el archivo `DEPLOYED` de cada copia indica qué versión tiene).
- Para apuntar un dominio: crear el VirtualHost (o el dominio adicional del hosting) con `DocumentRoot`
  en `conjuntos/{slug}/public` y ejecutar el script con `--url=https://dominio`.

## Privacidad, cookies y autorización de datos (migración 037)

Cumple la Ley 1581 de 2012 y el Decreto 1377 de 2013 (compilado en el Decreto 1074 de 2015), vigentes a noviembre
de 2026; la reforma a la Ley 1581 (PL 274/2025C) seguía en trámite.

- **Módulo `privacidad`**: el Superadmin lo habilita por conjunto (Consola › Conjuntos › Módulos «Privacidad y contacto web»). Deshabilitado, el panel responde 404 y la página pública no muestra políticas, aviso ni formulario; las versiones y autorizaciones ya guardadas se conservan.
- **Panel › Personalización › Privacidad y cookies** (permiso `privacidad`): datos del responsable, política de
  tratamiento de datos, texto de la autorización del formulario y política de cookies. Cada publicación crea una
  versión nueva e inmutable con fecha, autor y descripción del cambio. Se ofrecen plantillas iniciales completadas
  con los datos del conjunto, que el conjunto debe revisar (idealmente con un abogado) antes de publicarlas.
- **Panel › Personalización › Registro de autorizaciones**: prueba de cada autorización (titular, fecha, IP,
  navegador y versiones aceptadas), búsqueda, exportación CSV y registro de revocaciones. Las autorizaciones no se borran.
- **Panel › Comunicación › Contacto del sitio web** (permiso `contacto`): mensajes del formulario de la página pública.
- **Página pública**: `/politica-de-datos`, `/autorizacion-de-datos`, `/politica-de-cookies`, enlaces en el pie,
  aviso informativo de cookies (el sitio solo usa cookies necesarias) y formulario de contacto con casilla de
  autorización obligatoria y sin marcar. El formulario aparece cuando están publicadas la política y la autorización.
  Se protege sin cookies: token firmado con tiempo mínimo, campo trampa y máximo 5 mensajes por hora por IP.

## Referencia de pago y consignaciones por identificar (migración 038)

- **Referencia de pago por inmueble**: número corto y estable (secuencia del conjunto + dígito de verificación Luhn,
  p. ej. `1016`) que aparece en la nota de cobro (HTML y correo) y en el portal del residente, con la instrucción de
  escribirlo en la consignación o transferencia. Solo dígitos, porque muchos bancos no aceptan letras. Se asigna la
  primera vez que se necesita; el reinicio de la conformación las borra junto con los inmuebles.
- **Cartera › Consignaciones por identificar** (permiso `cartera.cobros`): registra el dinero que llegó sin saber de
  qué inmueble es (valor, fecha, medio, referencia y descripción del banco, soporte opcional). Sugiere el inmueble por
  referencia de pago escrita en los datos del banco, por un pago reportado con el mismo valor (±5 días) o por saldo
  igual al valor (incluido el pronto pago). Al identificarla se registra como pago de la nota más reciente del
  inmueble, con comprobante y aviso; si coincide con un pago reportado, aprueba ese reporte en lugar de duplicarlo.
  Las descartadas quedan con su motivo.
- **«Es mía» en el portal (migración 039)**: las consignaciones por identificar se muestran a los residentes en
  *Pagos › Consignaciones sin identificar* solo con fecha, valor y medio (nunca la descripción del banco). El residente
  la reclama eligiendo su nota y adjuntando el soporte; la administración aprueba (se identifica y se registra el pago)
  o rechaza con motivo desde la misma tarjeta de la consignación. Cada consignación se puede ocultar del portal. Si se
  identifica o descarta por otra vía, los reclamos abiertos se cierran y se avisa a cada residente.

## Documentos de cartera (migración 040)

- **Cartera › Estados de cuenta y paz y salvo**: por inmueble, estado de cuenta imprimible (últimos 12 períodos con
  pagos y saldo) y paz y salvo, que solo se expide si el inmueble está al día, vence el último día del mes y lleva un
  código de verificación. Cualquiera lo comprueba en la página pública `/verificar-paz-y-salvo` (no muestra datos
  personales, solo el inmueble, las fechas y si está vigente).
- **Portal › Pagos › Documentos**: el residente descarga su estado de cuenta, expide su paz y salvo cuando está al día
  y ve cada nota de cobro («Ver nota»).
- **Atención a residentes**: al responder, la administración puede adjuntar un comprobante, una nota de cobro, el
  estado de cuenta o un paz y salvo (que se expide al enviar). Solo se ofrecen los documentos de los inmuebles del
  residente, se exige acceso a Cartera y el enlace llega también en el correo.
- **Cartera › Cobros › Pagos**: botón «Reenviar» para enviar otra vez el comprobante por correo a las cuentas del inmueble.

## Importar extracto bancario (migración 041)

**Cartera › Importar extracto bancario** carga los movimientos de la cuenta y toma solo los ingresos:

- **Formatos**: movimientos de la banca virtual (pensado para AV Villas, sirve para la mayoría de bancos) en Excel
  `.xlsx`, CSV o TXT con una fila de títulos (fecha, descripción/concepto, referencia, documento, crédito/débito o un
  valor con signo; valores colombianos `1.234.567,89` o internacionales). También el archivo de recaudo
  **Asobancaria 2001** de un convenio de recaudo con referencia. No sirven el PDF ni el `.xls` antiguo (se pide
  guardarlo como `.xlsx` o CSV).
- **Propuesta por ingreso** antes de registrar nada: con la referencia de pago de un inmueble → pago de su nota; igual a
  un único pago reportado → se aprueba el reporte; abonos de Bold/Wompi/PayU y otras dispersiones, o un pago ya
  registrado del mismo inmueble y valor → se omite; el resto → Consignaciones por identificar. El administrador puede
  cambiar cada acción y confirma.
- Cargar el mismo extracto dos veces es seguro: cada movimiento confirmado queda con una huella y se omite después.

## Correo con Google y Microsoft (migración 042)

En **Configuración › Correo** (cuenta del conjunto) y en **Mi correo** (cuenta personal) el administrador pulsa
**Conectar con Google** o **Conectar con Microsoft** e inicia sesión con el correo que ya usa. La plataforma recibe un
permiso solo para **enviar** (`gmail.send` en Google; `Mail.Send` en Microsoft Graph): no lee el buzón y nunca ve la
contraseña. Los permisos se guardan cifrados y se revocan con **Desconectar**. Una cuenta conectada tiene prioridad sobre
el SMTP, que sigue disponible para otros proveedores. Todos los envíos (notas de cobro, avisos, recuperación de acceso,
comprobantes, pruebas) la usan sin más cambios.

Límites de los proveedores: unos 500 correos/día en Gmail personal, 2.000 en Google Workspace y 300 en Outlook.com.

### Configuración (una sola vez, la hace el dueño de la plataforma)

Dirección de retorno, la misma para todos los conjuntos (en producción, con el dominio de la plataforma y HTTPS):
`MAIL_OAUTH_REDIRECT_URI=https://plataforma.com/correo-oauth.php` (en local: `http://localhost/Copropiedad/public/correo-oauth.php`).

**Google** (Google Cloud Console):
1. Crea un proyecto y habilita la **Gmail API**.
2. *Pantalla de consentimiento de OAuth*: tipo **Externo**, nombre de la aplicación, correo de soporte, dominio y enlaces
   a la política de privacidad; agrega los permisos `openid`, `email` y `.../auth/gmail.send`.
3. *Credenciales › Crear ID de cliente de OAuth › Aplicación web*, con la dirección de retorno en «URI de redireccionamiento autorizados».
4. Copia el ID y el secreto a `GOOGLE_OAUTH_CLIENT_ID` y `GOOGLE_OAUTH_CLIENT_SECRET`.
5. Mientras la app esté en modo **Prueba**, solo pueden conectarse los «usuarios de prueba» que agregues (hasta 100) y el
   permiso vence a los 7 días. Para producción, solicita la **verificación** de Google: `gmail.send` es un permiso
   *sensible* (requiere verificación, no la auditoría de seguridad anual que exigen los permisos restringidos).

**Microsoft** (portal de Microsoft Entra › Registros de aplicaciones):
1. *Nuevo registro*, tipo de cuenta **«Cuentas en cualquier directorio organizativo y cuentas personales de Microsoft»**;
   URI de redireccionamiento de tipo **Web** con la dirección de retorno.
2. *Permisos de API › Microsoft Graph › Delegados*: `openid`, `email`, `offline_access`, `User.Read`, `Mail.Send`.
3. *Certificados y secretos › Nuevo secreto de cliente* (anota su vencimiento para renovarlo).
4. Copia el *Id. de aplicación (cliente)* y el secreto a `MICROSOFT_OAUTH_CLIENT_ID` y `MICROSOFT_OAUTH_CLIENT_SECRET`
   (`MICROSOFT_OAUTH_AUTHORITY=common`). Algunas organizaciones de Microsoft 365 exigen que su administrador apruebe la app.

Después ejecuta `php bin/deploy-conjunto.php --all`: el script agrega a cada copia las variables nuevas que le falten
(nunca cambia las que ya tiene). Los botones aparecen solo para los proveedores configurados.

## Modernización: recursos locales y accesibilidad (fase 1)

- **Sin CDN de terceros.** Bootstrap 5.3.9, AdminLTE 4.10.2, Bootstrap Icons 1.13.2, qrcode-generator 1.4.4 y las
  fuentes (Source Sans 3, Inter, Fraunces) se sirven desde `public/assets/vendor/<paquete>@<versión>/`, con las mismas
  versiones que antes se cargaban desde jsDelivr. La CSP quedó en `'self'` para scripts, estilos y fuentes; ya no
  depende de `cdn.jsdelivr.net`. Para actualizar un paquete, se copia la nueva versión a una carpeta con su número y
  se cambia la ruta en `app/Scenarios/Shared/panel-head.php`, `panel-scripts.php`, `auth-layout.php` y
  `Public/layout.php`.
- Se quitó OverlayScrollbars: se cargaba en cada página del panel pero nunca se inicializaba.
- **Tablas accesibles.** Cada tabla de datos tiene un `<caption>` (oculto a la vista y leído por lectores de
  pantalla), encabezados con `scope="col"` y su contenedor desplazable es una región con nombre y foco por teclado
  (`role="region" tabindex="0" aria-labelledby`), para que quien navega con teclado pueda desplazarla.
- Se añadió la cabecera `Cross-Origin-Opener-Policy: same-origin`.

## Modernización: CSP estricta para scripts (fase 2)

- La CSP ya no admite `'unsafe-inline'` en `script-src`. La arma `App\Core\Csp::policy()` con un nonce aleatorio
  por petición y `script-src-attr 'none'` (ningún atributo `on*="…"` se ejecuta).
- Un `<script>` en línea en una plantilla debe llevar `nonce="<?= \App\Core\Csp::nonce() ?>"`; sin él, el navegador
  lo bloquea. Lo preferible es un archivo en `public/assets/js/`.
- Los comportamientos simples se declaran con atributos y los atiende `public/assets/js/app.js`, que cargan todas
  las plantillas: `data-confirm="¿…?"` (en el formulario o su botón), `data-autosubmit` (enviar al cambiar),
  `data-print`, `data-disable-on-submit` y `data-back`.
- Los documentos imprimibles (estado de cuenta, recibo, paz y salvo) se entregan con `Csp::printable($html)`, que
  añade `app.js` solo a la versión web; el HTML que va por correo no lleva scripts.
- Los textos que se pintan con JavaScript se insertan con `textContent` / `replaceChildren()` en lugar de
  `innerHTML`. `style-src` conserva `'unsafe-inline'` por los atributos `style` (no ejecutan código).

## Modernización: componentes nativos sin Bootstrap JS ni AdminLTE JS (fase 3)

Bootstrap y AdminLTE se usan solo como CSS. Su JavaScript (≈ 130 KB) se reemplazó por elementos nativos del
navegador atendidos por `public/assets/js/app.js`, con estilos en `public/assets/css/app.css`:

| Antes (Bootstrap/AdminLTE JS) | Ahora |
| --- | --- |
| `.modal` + `data-bs-toggle="modal"` | `<dialog class="modal">` (mismo marcado interior) abierto con `command="show-modal" commandfor="id"` o `dialog.showModal()`; se cierra con `command="close"`, Esc o clic en el fondo |
| `window.confirm()` | Diálogo propio: `data-confirm="¿…?"` en formularios, `await appConfirm(msg)` y `appConfirm.submit(event, msg)` en scripts. El foco inicial queda en «Cancelar» |
| `data-bs-toggle="dropdown"` | `popover` + `popovertarget`, ubicado con anchor positioning (respaldo en JS). Esc y clic fuera lo cierran |
| `data-bs-toggle="collapse"` | `data-collapse aria-controls="id"` con `aria-expanded` |
| `data-bs-toggle="tab"` | `data-tab` en `role="tab"`: flechas, Inicio y Fin; evento `tab:shown` |
| `data-lte-toggle="sidebar"` | `data-sidebar-toggle` (contrae en escritorio, abre con fondo en móvil, Esc cierra) |
| `div.progress[role=progressbar]` | `<progress class="app-progress">` con variantes `is-success`, `is-danger`, `is-warning`, `is-info` |
| `role="switch"` | Además el atributo nativo `switch` (Safari); el resto de navegadores conserva `role="switch"` |

**Iconos.** La fuente completa de Bootstrap Icons (2.078 iconos, 220 KB) se reemplazó por
`public/assets/css/icons.css`, que contiene solo los iconos usados como máscaras CSS (≈ 21 KB comprimido). El marcado
no cambia (`<i class="bi bi-house" aria-hidden="true">`). **Al usar un icono nuevo** hay que regenerarlo:

```
npm pack bootstrap-icons@1.13.2 && tar -xzf bootstrap-icons-1.13.2.tgz
php bin/build-icons.php package/icons
```

Requisitos de navegador: `<dialog>`, popover y comandos de invocación (Baseline 2025). En navegadores sin comandos de
invocación, `app.js` los emula; sin anchor positioning, ubica los menús por script.

## Sesiones (cookie por sitio)

- La plataforma usa la cookie `COPROPIEDAD_SID` (ruta `/`) y cada copia de conjunto `COPROPIEDAD_SID_<SLUG>` (ruta de
  su `APP_URL`). Antes todas usaban `PHPSESSID`: la cookie de la raíz llegaba también a los conjuntos, compartían una
  sesión, y al iniciar o cerrar sesión en uno se borraba la del otro («La sesión del formulario venció»).
- Los datos de sesión duran al menos 2 horas en el servidor (`session.gc_maxlifetime`), por encima de los 30 minutos de
  inactividad con que la aplicación cierra la sesión.
- Un token CSRF inválido responde 403 con una página que explica qué pasó (419 no es estándar y Apache lo devolvía
  como 500).

## Normalización de la base de datos (migraciones 043 a 048)

Toda la información vive en tablas y columnas: no se guarda JSON ni listas separadas por comas.

| Migración | Cambio |
|---|---|
| 043 | Inmuebles en `property_units` (antes JSON en `copropiedad_composition.units`). Llave `(tenant_id, id)` y llaves foráneas compuestas `RESTRICT` desde notas, cargos, paz y salvo, referencias de pago y vínculos. `unit_id` en `ascii_bin` en todas las tablas. |
| 044 | Respuestas del asistente de conformación en columnas (`houses`, `towers`, … `storage_units`, NULL = sin responder). Se elimina `copropiedad_composition.housing_type`: el tipo vive solo en `copropiedad_settings`. |
| 045 | Textos del sitio público en `public_site_texts` (una fila por texto cambiado, versión publicada o borrador). `public_site_content` queda con los metadatos; hay borrador cuando `draft_updated_at` no es NULL. |
| 046 | Métodos de pago de Bold en `bold_payment_methods` (antes lista separada por comas). |
| 047 | Llaves foráneas faltantes: `billing_statement_recipients.sent_by` y las propuestas de conciliación bancaria. |
| 048 | 34 llaves foráneas compuestas `(tenant_id, columna) → padre (tenant_id, id)`: la base garantiza que cada registro y su padre sean del mismo conjunto. Quedan simples, a propósito, las de actor (`*_by`, `sender_id`: también las llena el Superadmin) y las `ON DELETE SET NULL`. |

Desnormalizaciones intencionales que se conservan: las notas de cobro guardan una copia de los datos del conjunto, del
inmueble y de los saldos (son documentos emitidos que no deben cambiar), el historial de vínculos guarda `unit_name` y
`user_name`, y las tablas hijas repiten `tenant_id` para aislar cada conjunto.

Las migraciones son idempotentes. Aplicar en orden, con respaldo previo:

```powershell
043..048 | ForEach-Object { Get-Content (Get-Item ".\database\migrations\0$($_)_*.sql") | & C:\xampp84\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad }
```

**Modo SQL estricto.** `App\Core\Database` fija `STRICT_ALL_TABLES` (y `ERROR_FOR_DIVISION_BY_ZERO`, `NO_ZERO_DATE`…) en
cada conexión: un valor que no cabe es un error y no se guarda recortado o vacío. XAMPP arranca MariaDB sin modo
estricto; MariaDB de producción lo trae por defecto.

## Renovación visual (tema «confianza»)

Dirección definida con la skill ui-ux-pro-max y verificada contra el nicho: administración de propiedad horizontal en
Colombia. Compran administradores y consejos (dinero, documentos, Ley 675): confianza y claridad. Usan residentes de
todas las edades, sobre todo desde el celular.

- **Estilo**: minimalismo suizo (sin vidrio ni degradados, que la skill marca como riesgo de accesibilidad).
- **Color**: verde azulado de confianza `#0F766E` + azul profesional `#0369A1`; barra lateral `#0D3B38`.
- **Tipografía**: Lexend (títulos, diseñada para legibilidad) + Source Sans 3 (texto, 16 px). Servidas localmente.
- **Accesibilidad**: texto ≥ 4.5:1, bordes de campos y foco ≥ 3:1, foco con `outline` en `:focus-visible`, botones y
  campos de 44 px, validación con `:user-invalid`, soporte de Colores forzados.

Todo vive en `public/assets/css/theme.css` como tokens (`--cp-*`) que redefinen las variables de Bootstrap 5.3 y
AdminLTE 4; las vistas no cambian. Para ajustar la marca basta con cambiar la paleta del nivel 1.

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

La migracion 005 agrega el tipo de vivienda como un dato opcional y un permiso independiente para Caracterizacion; la migracion 006 habilita el tipo Mixto y crea el almacenamiento de la estructura de bienes; las migraciones 007 a 009 agregan y separan la nomenclatura de torres y apartamentos. En **Configuracion / Caracterizacion**, para **Torres de apartamentos** y **Mixto** se puede elegir nomenclatura de torres numerica (Torre 1, Torre 2…) o alfabetica (Torre A, Torre B… Torre Z, Torre AA…). Los apartamentos usan siempre numeracion de dos digitos, del 01 al 99; 00 no es valido. En **Configuracion / Conformación de la copropiedad**, el administrador completa un asistente que guarda el avance, genera inmuebles a partir de las cantidades y permite corregir o agregar categorias (por ejemplo, locales u oficinas) antes de finalizar. La estructura admite hasta 5.000 inmuebles. En cada inmueble se registra un coeficiente porcentual mayor que cero, de hasta cuatro decimales. La cuota mensual se calcula como base mensual × coeficiente / 100 y se redondea al centavo; la emisión se bloquea si falta un coeficiente. La migración 018 agrega a cada nota una instantánea del coeficiente aplicado; aplícala una sola vez en bases existentes. Si se cambia la caracterizacion o la nomenclatura despues de comenzar, se solicita confirmacion y se reinician los datos de conformacion. El permiso de Conformacion se inicializa a partir del permiso de Caracterizacion; los permisos se pueden ajustar en **Configuracion / Roles y permisos**. Guardar parametros no emite facturas, crea cobros ni aplica mora o multas automaticamente.

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
- `APP_DEFAULT_TENANT=principal`: solo fuera de producción, el conjunto que se abre con un host que no es del dominio base (por ejemplo `http://localhost/Copropiedad/public`).
- En local, `localtest.me` resuelve cualquier subdominio a la máquina: `http://localtest.me/Copropiedad/public/admin` (consola) y `http://principal.localtest.me/Copropiedad/public/` (conjunto), sin tocar el archivo hosts.
- En producción se necesita un DNS comodín (`*.midominio.com`) y un certificado SSL comodín. Los enlaces de correo, el retorno y el webhook de Bold usan el subdominio de cada conjunto: registra en Bold la URL que muestra *Métodos de pago* de cada conjunto.

**Consola del Superadmin** (`/admin/clientes`, solo en el dominio principal): crea conjuntos con su subdominio, habilita o deshabilita módulos (portal de residentes, cartera, pagos en línea Bold, mensajes, clasificados), asigna uno o más administradores, cambia su contraseña, los desactiva y suspende o reactiva el servicio de un conjunto. Un módulo deshabilitado desaparece para todos en ese conjunto (incluido su administrador) sin borrar datos. Los módulos de núcleo (parametrización, equipo, roles, caracterización, conformación y asignaciones) siempre están disponibles.

**Cuentas.** Cada cuenta pertenece a un solo conjunto; el usuario y el correo son únicos dentro del conjunto, así que una misma persona puede tener cuenta en dos conjuntos con el mismo correo. El administrador asignado tiene el rol de sistema `Administrador` (acceso total en su conjunto, no editable desde Equipo ni Roles). El Superadmin no pertenece a ningún conjunto, puede entrar al de cualquiera con sus credenciales y es el único que reinicia la conformación. La sesión queda ligada al conjunto donde se inició.

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
   php -S 127.0.0.1:8080 -t public
   ```

   Abre `http://127.0.0.1:8080/login`. En Apache, configura el `DocumentRoot` del sitio hacia la carpeta `public`, no hacia la raiz del proyecto. Si se usa XAMPP en `http://localhost/Copropiedad/public`, ajusta `APP_URL` a esa direccion en `.env`.

## Estructura modular

- `app/Core`: infraestructura compartida (enrutador, vistas y conexion).
- `app/Modules/<Modulo>`: rutas, controlador y vistas propias del modulo.
- `app/Views`: plantilla compartida de AdminLTE.
- `public`: unico directorio expuesto al servidor web.

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

En **Configuracion / Parametrizacion** se guardan el nombre, direccion, NIT y, opcionalmente, los datos completos del titular, banco, tipo y numero de cuenta. En bases donde ya se aplico la migracion 003, aplica una sola vez la migracion adicional `004_add_bank_account_number.sql` para agregar el campo bancario sin afectar los demas datos. En **Configuracion / Financiera** se guardan el valor de administracion, su fecha de vigencia, el descuento fijo por pronto pago, la mora en porcentaje o valor fijo con sus dias de espera, y el valor base de las multas. En **Configuracion / Caracterizacion** se define si la copropiedad es una casa, un conjunto de torres de apartamentos o una copropiedad mixta.

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
```

La migracion 005 agrega el tipo de vivienda como un dato opcional y un permiso independiente para Caracterizacion; la migracion 006 habilita el tipo Mixto y crea el almacenamiento de la estructura de bienes; las migraciones 007 a 009 agregan y separan la nomenclatura de torres y apartamentos. En **Configuracion / Caracterizacion**, para **Torres de apartamentos** y **Mixto** se puede elegir nomenclatura de torres numerica (Torre 1, Torre 2…) o alfabetica (Torre A, Torre B… Torre Z, Torre AA…). Los apartamentos usan siempre numeracion de dos digitos, del 01 al 99; 00 no es valido. En **Configuracion / Conformación de la copropiedad**, el administrador completa un asistente que guarda el avance, genera inmuebles a partir de las cantidades y permite corregir o agregar categorias (por ejemplo, locales u oficinas) antes de finalizar. La estructura admite hasta 5.000 inmuebles. Si se cambia la caracterizacion o la nomenclatura despues de comenzar, se solicita confirmacion y se reinician los datos de conformacion. El permiso de Conformacion se inicializa a partir del permiso de Caracterizacion; los permisos se pueden ajustar en **Configuracion / Roles y permisos**. Guardar parametros no emite facturas, crea cobros ni aplica mora o multas automaticamente.

La migracion 010 crea el registro de asignaciones entre usuarios e inmuebles y agrega el permiso independiente **Inmuebles / Asignacion de usuarios** (inicializado con el nivel de **Configuracion / Equipo**). En la seccion **Asignacion de inmuebles**, selecciona un usuario y marca uno o varios inmuebles; las asignaciones se mantienen al editar el nombre del inmueble y se eliminan al quitarlo de la estructura.

La migracion 011 habilita el permiso de lectura **Usuario / Portal de inmuebles asignados** para las cuentas existentes. El acceso **Iniciar sesión de usuario** aparece en el pie de pagina; cada cuenta puede consultar unicamente los inmuebles asignados a su usuario en el **Panel de Usuario** y actualizar su nombre de usuario, correo y contrasena confirmando su contrasena actual. El panel muestra el canon y descuento configurados; las notas de cobro se notifican por correo y se consultan desde **Cartera / Cobros** en el panel administrativo. Al iniciar desde el acceso del portal, se prioriza el Panel de Usuario; el ingreso administrativo sigue disponible en `/login`.

La migracion 012 crea el almacenamiento del proveedor de correo e incorpora el permiso **Configuracion / Correo**. En el panel administrativo, configura el host, puerto, cifrado, usuario y remitente; la contraseña se cifra antes de almacenarse, nunca se vuelve a mostrar y puede conservarse al editar dejando el campo vacío. Define `APP_KEY` en `.env` como una clave base64 de 32 bytes (usa `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"` para generarla) y mantenla estable: se necesita para descifrar la contraseña guardada. Guarda los cambios antes de probar; el botón de prueba envía un mensaje a la dirección de correo de la cuenta administradora que lo solicita y utiliza la configuración guardada. Las contraseñas SMTP antiguas de `.env` se admiten como compatibilidad hasta que se configure el nuevo módulo.

La migracion 013 incorpora **Cartera / Cobros**, los días configurables de pronto pago y vencimiento, conceptos por inmueble, notas mensuales con consecutivo, pagos y estado de notificación por destinatario. Aplica la migracion una sola vez después de la 012. En **Configuracion / Parametrizacion**, define ambos días (del 1 al 28), además de los datos bancarios; en **Configuracion / Financiera** define la cuota y vigencia; y en **Configuracion / Correo** valida SMTP. En **Cartera / Cobros**, revisa el período y los conceptos manuales, luego confirma la emisión: cada inmueble/mes se emite una sola vez, los datos quedan como instantánea y los correos fallidos o pendientes se pueden reintentar en lotes de hasta 50 destinatarios. El pronto pago se reconoce solo cuando los pagos registrados alcanzan el total con descuento antes de la fecha límite. Registra los pagos de un período antes de emitir el siguiente para que se reflejen en el saldo. Intereses, procesos jurídicos, multas, retroactivos, cuotas extras y otros conceptos se ingresan manualmente para que la administración verifique soporte y valor; no se calculan automáticamente. No se prorratea una cuota con vigencia a mitad de mes: la emisión se bloquea para evitar asumir un tratamiento no aprobado. Se requiere emitir períodos en orden para arrastrar saldos; si no hay nota anterior, el saldo inicial se captura como concepto **Saldo inicial / ajuste de saldo anterior**. Se notifica a cada cuenta activa asignada al inmueble; el sistema no distingue ni almacena propietarios frente a residentes. La nota es imprimible desde el navegador y no reemplaza una factura electrónica ni la revisión contable.

La migracion 014 agrega la bandeja de mensajes internos y el registro de lectura de notificaciones de cartera. En el encabezado del **Panel de Usuario**, el avatar muestra avisos de notas emitidas con cuota de administración o multas y mensajes nuevos. Los cobros se marcan como leídos desde el menú del avatar; los mensajes se consultan en **Mis mensajes**. Administración puede enviar mensajes de texto a un usuario o a todos los usuarios activos desde **Mensajes**; el permiso **Mensajes internos** controla lectura y envío.

El diseño sigue el ciclo de vida de documentos de cobro usados habitualmente: previsualización, emisión con datos congelados, estados de pago y seguimiento/reintento de notificaciones. Referencia general: [Stripe Invoicing: ciclo de vida de las facturas](https://docs.stripe.com/invoicing/overview) y [notificaciones y recordatorios](https://docs.stripe.com/invoicing/send-email).

# Proyecto base en Laravel para API

Este proyecto es una base para crear un proyecto de API.

- Laravel 13 · PHP 8.5 · MySQL 8.4
- Repositorios y servicios genéricos en `app/Core`

## Requisitos

Solo [Docker](https://www.docker.com/) (con `make`, que ya viene en macOS y Linux). No hace falta instalar PHP ni Composer.

## Ejecución del proyecto

Primera vez:

```
make setup
```

Construye la imagen, instala las dependencias, crea el `.env`, genera la clave y ejecuta las migraciones.

El `.env` usa `DB_HOST=mysql` (el nombre del servicio de Docker). Si ya tenías un `.env` de una versión anterior con `DB_HOST=127.0.0.1`, cámbialo: `php artisan serve` no recibe las variables de Docker y lee este archivo.

Después, para trabajar:

```
make up      # levanta API, MySQL y phpMyAdmin
make down    # detiene todo
```

| Servicio   | URL                   | Credenciales (del `.env`)          |
|------------|-----------------------|------------------------------------|
| API        | http://localhost:8000 |                                    |
| phpMyAdmin | http://localhost:8080 | `DB_USERNAME` / `DB_PASSWORD`      |
| MySQL      | localhost:3306        | `DB_USERNAME` / `DB_PASSWORD`      |

Los puertos se pueden cambiar con `APP_PORT`, `PMA_PORT` y `DB_FORWARD_PORT` en el `.env`.

## Comandos útiles

```
make test                        # ejecuta los tests (SQLite en memoria)
make test ARGS=tests/Unit/Core   # solo un grupo de tests
make stan                        # análisis estático (Larastan, nivel 9)
make lint                        # verifica el estilo (Pint) sin modificar
make format                      # corrige el estilo con Pint
make artisan cmd="route:list"    # cualquier comando de artisan
make composer cmd="require x/y"  # cualquier comando de composer
make shell                       # terminal dentro del contenedor
make help                        # lista todos los comandos
```

## Roles y permisos

La tabla `roles` (`id` tinyint, `nombre`) y la tabla pivote `role_user` vienen en las migraciones. Es una relación de muchos a muchos: `User` tiene muchos `Role` (`$user->roles`) y `Role` tiene muchos `User` (`$role->users`).

El middleware `permission` recibe los ids de rol permitidos y responde `403` si ninguno de los roles del usuario está en la lista, o si no tiene roles:

```php
Route::middleware(['auth:sanctum', 'permission:1'])->post('/products', ...);   // solo el rol 1
Route::middleware(['auth:sanctum', 'permission:1,2'])->get('/reports', ...);    // roles 1 o 2
```

Los roles no vienen sembrados: se crean en un seeder o en la migración de cada proyecto.

## Eliminación lógica

`User` y `Role` usan `SoftDeletes`: `delete()` no borra la fila, solo llena `deleted_at`. Los métodos de `BaseRepository` (`delete`, `bulkDelete`, `findAll`, `findById`, etc.) ya funcionan así sin cambios adicionales. Para incluir o filtrar los eliminados: `User::withTrashed()`, `User::onlyTrashed()`, y `$user->restore()` para deshacer el borrado.

## Ejemplo completo: una entidad con login por token

Esto se probó de punta a punta con MySQL (login, tokens, permisos por rol, CRUD, filtros, orden y paginación). El código no se incluye en la base para no traer entidades que borrar en cada proyecto; estos son los pasos:

```
make artisan cmd="make:model Product -m"     # y completa la migración
make artisan cmd="make:repository Product"
make artisan cmd="make:service Product"
```

El modelo usa los traits y declara qué se puede filtrar y ordenar:

```php
class Product extends Model
{
    use AdvancedFilter, Sortable;

    protected $fillable = ['nombre', 'precio', 'stock'];

    /** @var array<int, string> */
    public array $allowedFilters = ['id', 'nombre', 'precio', 'stock'];

    /** @var array<int, string> */
    public array $allowedSorts = ['id', 'nombre', 'precio', 'stock'];
}
```

Un DTO para crear y otro con `Optional` para actualizar parcialmente (PATCH), y un `Resource` con `@mixin` para que Larastan conozca las propiedades:

```php
class ProductData extends Data
{
    public function __construct(public string $nombre, public float $precio, public int $stock = 0) {}
}

class ProductPatchData extends Data
{
    public function __construct(
        public string|Optional $nombre,
        public float|Optional $precio,
        public int|Optional $stock,
    ) {}
}

/** @mixin Product */
class ProductResource extends JsonResource { /* toArray(): id, nombre, precio, stock */ }
```

El controller extiende `BaseApiController` y solo habla con el servicio:

```php
class ProductController extends BaseApiController
{
    public function __construct(private readonly ProductServiceInterface $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->showAll(ProductResource::collection(
            $this->service->findAllPaginated(ListQuery::fromRequest($request))
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $product = $this->service->create(ProductData::validateAndCreate($request));

        return $this->showOne(new ProductResource($product), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->showOne(new ProductResource(
            $this->service->update($id, ProductPatchData::validateAndCreate($request))
        ));
    }

    public function destroy(int $id): Response
    {
        $this->service->delete($id);

        return $this->noContentResponse();
    }
}
```

Las rutas: lectura para cualquier usuario autenticado y escritura solo para el rol 1:

```php
Route::post('/login', [AuthController::class, 'login'])     // devuelve $user->createToken('api')->plainTextToken
    ->middleware('throttle:login');                          // ver "Limitar los intentos de login"

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::middleware('permission:1')->group(function () {
        Route::post('/products', [ProductController::class, 'store']);
        Route::patch('/products/{id}', [ProductController::class, 'update']);
        Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    });
});
```

Uso desde la terminal:

```
curl -s -H Accept:application/json -d '{"email":"admin@example.com","password":"password"}' localhost:8000/api/login
curl -s -H Accept:application/json -H "Authorization: Bearer <token>" \
  "localhost:8000/api/products?per_page=2&sort=-precio&q=%7B%22filters%22%3A%5B%5D%7D"
```

Un listado paginado responde con `data`, `links` (`first`, `last`, `prev`, `next`, que conservan `per_page`, `sort` y `q`) y `meta` (`currentPage`, `from`, `lastPage`, `perPage`, `to`, `total`). Un listado sin paginar responde solo con el arreglo de registros.

### Limitar los intentos de login

El grupo `api` ya limita a 60 peticiones por minuto, pero eso no protege un login: alguien podría probar 60 contraseñas por minuto. Define un límite más estricto por correo e IP en `RouteServiceProvider::boot()` y aplícalo a la ruta con `throttle:login`:

```php
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by($request->input('email').'|'.$request->ip());
});
```

Al superarlo responde `429` con `{"code": 429, "error": "Muchos intentos realizados."}` y el encabezado `Retry-After`. Al usar el correo en la clave, un atacante no bloquea a otros usuarios que comparten su IP. Esto está cubierto por un test en `ExceptionHandlerTest`.

## Integración continua

El workflow `.github/workflows/ci.yml` corre Pint, Larastan y los tests en cada push a `main` y en cada pull request.

## Cómo crear un repositorio y un servicio

Con los comandos (por separado):

```
make artisan cmd="make:repository Product"
make artisan cmd="make:service Product"
```

Cada uno crea la interfaz en `app/Contracts/...`, la implementación en `app/Repositories` o `app/Services`, y registra el binding en `RepositoryServiceProvider` o `ServiceLogicServiceProvider`. Opciones:

| Opción                     | Efecto                                                                  |
|----------------------------|-------------------------------------------------------------------------|
| `--model=Inventory/Part`   | modelo a usar (repositorio y servicio); por defecto `App\Models\<Nombre>` |
| `--repository=Customer`    | (servicio) repositorio a inyectar; por defecto el mismo nombre           |
| `--force`                  | sobrescribe los archivos si ya existen                                  |

Se pueden usar subcarpetas (`Inventory/Part`) y el sufijo `Repository`/`Service` es opcional. Las plantillas están en `stubs/` y se pueden editar directamente.

El resultado es equivalente a hacerlo a mano. Para un repositorio:

```php
/**
 * @extends BaseRepository<Product>
 */
class ProductRepository extends BaseRepository implements ProductRepositoryInterface
{
    public function __construct(Product $entity)
    {
        parent::__construct($entity);
    }
}
```

y para un servicio:

```php
/**
 * @extends BaseService<Product>
 */
class ProductService extends BaseService implements ProductServiceInterface
{
    public function __construct(ProductRepositoryInterface $repository)
    {
        parent::__construct($repository);
    }
}
```

Las interfaces extienden `BaseRepositoryInterface` / `BaseServiceInterface`; se puede reducir a `ReadableRepositoryInterface`, `WritableRepositoryInterface`, `BulkRepositoryInterface` o `RelationSyncRepositoryInterface` si no se necesita todo.

### Tipos genéricos

`BaseRepository`, `BaseService` y sus interfaces son genéricos (`@template TModel`). La anotación `@extends BaseRepository<Product>` (que ya generan los comandos) hace que `findById`, `create`, `update`, `findAll`, etc. devuelvan `Product` y no `Model`, con autocompletado y análisis estático. Si se escribe a mano, no olvidar la anotación en la clase y en su interfaz (`@extends BaseRepositoryInterface<Product>`).

Los ids pueden ser `int` o `string` (UUID/ULID).

## Listados en el controller

El servicio no conoce `Request`. El controller arma un `ListQuery` (filtros, orden y tamaño de página) y se lo pasa:

```php
$products = $this->service->findAllPaginated(ListQuery::fromRequest($request));
```

Desde un job o un comando se puede construir directamente: `new ListQuery($filters, '-name', 20)`.

## Filtros y ordenamiento

Cada modelo que use los traits `AdvancedFilter` y `Sortable` declara qué campos se pueden filtrar y ordenar:

```php
/** @var array<int, string> */
public array $allowedFilters = ['id', 'name', 'status'];

/** @var array<int, string> */
public array $allowedSorts = ['id', 'name', 'status'];
```

Un campo fuera de la lista responde `400`. Si el modelo no declara la propiedad y se pide filtrar u ordenar, responde `500` con un mensaje que indica cuál falta.

Los filtros se envían en el parámetro `q` (JSON) y por defecto se combinan con AND. Para usar OR en un filtro se indica `"boolean": "or"`:

```json
{"filters": [
  {"field": "status", "operator": "=", "value": 1},
  {"field": "name", "operator": "%LIKE%", "value": "ana", "boolean": "or"}
]}
```

`IS NULL` e `IS NOT NULL` no necesitan `value`.

## Actualizaciones parciales (PATCH)

`BaseService::update` guarda todo lo que devuelve `Data::toArray()`. Para que un campo no enviado no se sobrescriba, decláralo como `Optional` en el DTO:

```php
public function __construct(
    public string|Optional $name,
    public int|null|Optional $status,
) {}
```

<?php

namespace Tests\Feature\Core;

use App\Core\BaseRepository;
use App\Core\BaseService;
use App\Providers\RepositoryServiceProvider;
use App\Providers\ServiceLogicServiceProvider;
use Illuminate\Support\Facades\File;
use Tests\Support\CreatesItemsTable;
use Tests\Support\Item;
use Tests\Support\ItemData;
use Tests\TestCase;

/**
 * Los comandos escriben en app_path(); se redirige a un directorio temporal
 * para no crear archivos dentro del proyecto real.
 */
class MakeCommandsTest extends TestCase
{
    use CreatesItemsTable;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/make-commands-'.uniqid();
        File::makeDirectory($this->tmp.'/Providers', 0777, true);
        foreach (['RepositoryServiceProvider', 'ServiceLogicServiceProvider'] as $provider) {
            File::copy(app_path("Providers/$provider.php"), $this->tmp."/Providers/$provider.php");
        }

        $this->app->useAppPath($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    private function read(string $path): string
    {
        return File::get("$this->tmp/$path");
    }

    public function test_both_providers_are_registered_in_the_app(): void
    {
        $this->assertNotNull($this->app->getProvider(RepositoryServiceProvider::class));
        $this->assertNotNull($this->app->getProvider(ServiceLogicServiceProvider::class));
    }

    public function test_make_repository_creates_class_interface_and_binding(): void
    {
        $this->artisan('make:repository', ['name' => 'Widget'])->assertSuccessful();

        $class = $this->read('Repositories/WidgetRepository.php');
        $this->assertStringContainsString('namespace App\Repositories;', $class);
        $this->assertStringContainsString('class WidgetRepository extends BaseRepository implements WidgetRepositoryInterface', $class);
        $this->assertStringContainsString('use App\Models\Widget;', $class);
        $this->assertStringContainsString('public function __construct(Widget $entity)', $class);

        $interface = $this->read('Contracts/Repositories/WidgetRepositoryInterface.php');
        $this->assertStringContainsString('namespace App\Contracts\Repositories;', $interface);
        $this->assertStringContainsString('interface WidgetRepositoryInterface extends BaseRepositoryInterface', $interface);

        $provider = $this->read('Providers/RepositoryServiceProvider.php');
        $this->assertStringContainsString("'WidgetRepositoryInterface' => 'WidgetRepository',", $provider);
        $this->assertStringNotContainsString('//', str_replace('/**', '', $this->providerRepositoriesBlock($provider)));
    }

    private function providerRepositoriesBlock(string $provider): string
    {
        preg_match('/protected array \$repositories = \[(.*?)\n    \];/s', $provider, $match);

        return $match[1];
    }

    public function test_generated_repository_is_valid_php_and_extends_the_base(): void
    {
        $this->artisan('make:repository', ['name' => 'Gadget', '--model' => 'App\Models\Gadget'])->assertSuccessful();

        require_once $this->tmp.'/Contracts/Repositories/GadgetRepositoryInterface.php';
        require_once $this->tmp.'/Repositories/GadgetRepository.php';

        $this->assertTrue(is_subclass_of('App\Repositories\GadgetRepository', BaseRepository::class));
        $this->assertTrue(interface_exists('App\Contracts\Repositories\GadgetRepositoryInterface'));
        $this->assertSame(
            'App\Models\Gadget',
            (new \ReflectionMethod('App\Repositories\GadgetRepository', '__construct'))->getParameters()[0]->getType()->getName()
        );
    }

    public function test_make_repository_does_not_duplicate_the_suffix(): void
    {
        $this->artisan('make:repository', ['name' => 'SprocketRepository'])->assertSuccessful();

        $this->assertFileExists("$this->tmp/Repositories/SprocketRepository.php");
        $this->assertFileDoesNotExist("$this->tmp/Repositories/SprocketRepositoryRepository.php");
    }

    public function test_make_repository_accepts_a_custom_model(): void
    {
        $this->artisan('make:repository', ['name' => 'Bolt', '--model' => 'Hardware/Screw'])->assertSuccessful();

        $class = $this->read('Repositories/BoltRepository.php');
        $this->assertStringContainsString('use App\Models\Hardware\Screw;', $class);
        $this->assertStringContainsString('__construct(Screw $entity)', $class);
    }

    public function test_make_repository_supports_nested_names(): void
    {
        $this->artisan('make:repository', ['name' => 'Inventory/Part'])->assertSuccessful();

        $class = $this->read('Repositories/Inventory/PartRepository.php');
        $this->assertStringContainsString('namespace App\Repositories\Inventory;', $class);
        $this->assertStringContainsString('use App\Contracts\Repositories\Inventory\PartRepositoryInterface;', $class);
        $this->assertStringContainsString('use App\Models\Inventory\Part;', $class);
        $this->assertFileExists("$this->tmp/Contracts/Repositories/Inventory/PartRepositoryInterface.php");
        $this->assertStringContainsString(
            "'Inventory\PartRepositoryInterface' => 'Inventory\PartRepository',",
            $this->read('Providers/RepositoryServiceProvider.php')
        );
    }

    public function test_it_refuses_to_overwrite_without_force_and_does_not_duplicate_the_binding(): void
    {
        $this->artisan('make:repository', ['name' => 'Nut'])->assertSuccessful();
        File::put("$this->tmp/Repositories/NutRepository.php", '<?php // editado');

        $this->artisan('make:repository', ['name' => 'Nut'])->assertFailed();
        $this->assertSame('<?php // editado', $this->read('Repositories/NutRepository.php'));

        $this->artisan('make:repository', ['name' => 'Nut', '--force' => true])->assertSuccessful();
        $this->assertStringContainsString('class NutRepository', $this->read('Repositories/NutRepository.php'));

        $this->assertSame(1, substr_count($this->read('Providers/RepositoryServiceProvider.php'), "'NutRepositoryInterface'"));
    }

    public function test_several_repositories_keep_all_bindings(): void
    {
        $this->artisan('make:repository', ['name' => 'One'])->assertSuccessful();
        $this->artisan('make:repository', ['name' => 'Two'])->assertSuccessful();

        $provider = $this->read('Providers/RepositoryServiceProvider.php');
        $this->assertStringContainsString("'OneRepositoryInterface' => 'OneRepository',", $provider);
        $this->assertStringContainsString("'TwoRepositoryInterface' => 'TwoRepository',", $provider);
    }

    public function test_make_repository_warns_when_the_model_does_not_exist(): void
    {
        $this->artisan('make:repository', ['name' => 'Ghost'])
            ->expectsOutputToContain('no existe todavía')
            ->assertSuccessful();
    }

    public function test_make_service_creates_class_interface_and_binding(): void
    {
        $this->artisan('make:repository', ['name' => 'Order'])->assertSuccessful();
        $this->artisan('make:service', ['name' => 'Order'])->assertSuccessful();

        $class = $this->read('Services/OrderService.php');
        $this->assertStringContainsString('namespace App\Services;', $class);
        $this->assertStringContainsString('class OrderService extends BaseService implements OrderServiceInterface', $class);
        $this->assertStringContainsString('use App\Contracts\Repositories\OrderRepositoryInterface;', $class);
        $this->assertStringContainsString('public function __construct(OrderRepositoryInterface $repository)', $class);

        $interface = $this->read('Contracts/Services/OrderServiceInterface.php');
        $this->assertStringContainsString('interface OrderServiceInterface extends BaseServiceInterface', $interface);

        $this->assertStringContainsString(
            "'OrderServiceInterface' => 'OrderService',",
            $this->read('Providers/ServiceLogicServiceProvider.php')
        );
    }

    public function test_generated_service_is_valid_php_and_extends_the_base(): void
    {
        $this->artisan('make:repository', ['name' => 'Invoice'])->assertSuccessful();
        $this->artisan('make:service', ['name' => 'Invoice'])->assertSuccessful();

        require_once $this->tmp.'/Contracts/Repositories/InvoiceRepositoryInterface.php';
        require_once $this->tmp.'/Contracts/Services/InvoiceServiceInterface.php';
        require_once $this->tmp.'/Services/InvoiceService.php';

        $this->assertTrue(is_subclass_of('App\Services\InvoiceService', BaseService::class));
        $this->assertSame(
            'App\Contracts\Repositories\InvoiceRepositoryInterface',
            (new \ReflectionMethod('App\Services\InvoiceService', '__construct'))->getParameters()[0]->getType()->getName()
        );
    }

    public function test_make_service_accepts_a_different_repository(): void
    {
        $this->artisan('make:repository', ['name' => 'Customer'])->assertSuccessful();
        $this->artisan('make:service', ['name' => 'Billing', '--repository' => 'Customer'])->assertSuccessful();

        $class = $this->read('Services/BillingService.php');
        $this->assertStringContainsString('use App\Contracts\Repositories\CustomerRepositoryInterface;', $class);
    }

    public function test_make_service_warns_when_the_repository_does_not_exist(): void
    {
        $this->artisan('make:service', ['name' => 'Lonely'])
            ->expectsOutputToContain('no existe todavía')
            ->assertSuccessful();
    }

    public function test_generated_code_and_bindings_work_end_to_end_with_the_real_providers(): void
    {
        class_alias(Item::class, 'App\Models\Piece');
        $this->createItemsTable();

        $this->artisan('make:repository', ['name' => 'Piece'])->assertSuccessful();
        $this->artisan('make:service', ['name' => 'Piece'])->assertSuccessful();

        foreach ([
            'Contracts/Repositories/PieceRepositoryInterface',
            'Repositories/PieceRepository',
            'Contracts/Services/PieceServiceInterface',
            'Services/PieceService',
        ] as $file) {
            require_once "$this->tmp/$file.php";
        }

        // Aplica los bindings generados con los providers reales de la app.
        foreach ([
            [RepositoryServiceProvider::class, 'repositories'],
            [ServiceLogicServiceProvider::class, 'services'],
        ] as [$providerClass, $property]) {
            $provider = new $providerClass($this->app);
            (new \ReflectionProperty($provider, $property))
                ->setValue($provider, $this->generatedBindings(class_basename($providerClass), $property));
            $provider->register();
        }

        $service = $this->app->make('App\Contracts\Services\PieceServiceInterface');

        $this->assertInstanceOf('App\Services\PieceService', $service);
        $created = $service->create(new ItemData('Tornillo', 1));
        $this->assertSame('Tornillo', $service->findById($created->id)->name);
    }

    /**
     * Lee del provider generado el array de bindings.
     *
     * @return array<string, string>
     */
    private function generatedBindings(string $provider, string $property): array
    {
        preg_match('/protected array \$'.$property.' = \[(.*?)\n    \];/s', $this->read("Providers/$provider.php"), $block);
        preg_match_all("/'([^']+)' => '([^']+)',/", $block[1], $pairs, PREG_SET_ORDER);

        return array_column($pairs, 2, 1);
    }

    public function test_generated_repository_and_interface_are_typed_with_the_model(): void
    {
        $this->artisan('make:repository', ['name' => 'Cog'])->assertSuccessful();

        $class = $this->read('Repositories/CogRepository.php');
        $this->assertStringContainsString('@extends BaseRepository<Cog>', $class);

        $interface = $this->read('Contracts/Repositories/CogRepositoryInterface.php');
        $this->assertStringContainsString('use App\Models\Cog;', $interface);
        $this->assertStringContainsString('@extends BaseRepositoryInterface<Cog>', $interface);
    }

    public function test_generated_service_and_interface_are_typed_with_the_model(): void
    {
        $this->artisan('make:repository', ['name' => 'Lever'])->assertSuccessful();
        $this->artisan('make:service', ['name' => 'Lever'])->assertSuccessful();

        $class = $this->read('Services/LeverService.php');
        $this->assertStringContainsString('use App\Models\Lever;', $class);
        $this->assertStringContainsString('@extends BaseService<Lever>', $class);

        $this->assertStringContainsString(
            '@extends BaseServiceInterface<Lever>',
            $this->read('Contracts/Services/LeverServiceInterface.php')
        );
    }

    public function test_make_service_accepts_a_custom_model(): void
    {
        $this->artisan('make:service', ['name' => 'Gear', '--model' => 'Hardware/Cog'])->assertSuccessful();

        $class = $this->read('Services/GearService.php');
        $this->assertStringContainsString('use App\Models\Hardware\Cog;', $class);
        $this->assertStringContainsString('@extends BaseService<Cog>', $class);
    }
}

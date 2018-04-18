<?php declare(strict_types=1);

namespace Shopware;

use Shopware\Framework\DependencyInjection\TestingCompilerPass;
use Shopware\Framework\Doctrine\DatabaseConnector;
use Shopware\Framework\Framework;
use Shopware\Framework\Plugin\Plugin;
use Shopware\Framework\Plugin\PluginCollection;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Kernel as HttpKernel;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollectionBuilder;
use Shopware\Rest\Controller\ApiController;

class Kernel extends HttpKernel
{
    use MicroKernelTrait;

    const CONFIG_EXTS = '.{php,xml,yaml,yml}';

    /**
     * @var \PDO
     */
    protected static $connection;

    /**
     * @var PluginCollection
     */
    protected static $plugins;

    /**
     * {@inheritdoc}
     */
    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);

        self::$plugins = new PluginCollection();
    }

    public function registerBundles()
    {
        $contents = require $this->getProjectDir() . '/config/bundles.php';

        foreach (self::$plugins->getActivePlugins() as $plugin) {
            $contents[get_class($plugin)] = ['all' => true];
        }

        foreach ($contents as $class => $envs) {
            if (isset($envs['all']) || isset($envs[$this->environment])) {
                yield new $class();
            }
        }
    }

    public function boot($withPlugins = true)
    {
        if ($this->booted === true) {
            return;
        }

        if ($withPlugins) {
            $this->initializePluginSystem();
        }

        // init bundles
        $this->initializeBundles();

        // init container
        $this->initializeContainer();

        foreach ($this->getBundles() as $bundle) {
            $bundle->setContainer($this->container);
            $bundle->boot();
        }

        $this->booted = true;
    }

    /**
     * @return PluginCollection
     */
    public static function getPlugins(): PluginCollection
    {
        return self::$plugins;
    }

    public static function getConnection(): \PDO
    {
        if (!self::$connection) {
            self::$connection = DatabaseConnector::createPdoConnection();
        }

        return self::$connection;
    }

    public function getCacheDir()
    {
        return sprintf(
            '%s/var/cache/%s_%s',
            $this->getProjectDir(),
            $this->getEnvironment(),
            Framework::REVISION
        );
    }

    public function getLogDir()
    {
        return $this->getProjectDir() . '/var/logs';
    }

    public function getPluginDir()
    {
        return $this->getProjectDir() . '/custom/plugins';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader)
    {
        $container->setParameter('container.dumper.inline_class_loader', true);

        $confDir = $this->getProjectDir() . '/config';

        $loader->load($confDir . '/{packages}/*' . self::CONFIG_EXTS, 'glob');
        $loader->load($confDir . '/{packages}/' . $this->environment . '/**/*' . self::CONFIG_EXTS, 'glob');
        $loader->load($confDir . '/{services}' . self::CONFIG_EXTS, 'glob');
        $loader->load($confDir . '/{services}_' . $this->environment . self::CONFIG_EXTS, 'glob');
    }

    protected function configureRoutes(RouteCollectionBuilder $routes)
    {
        $confDir = $this->getProjectDir() . '/config';

        $routes->import($confDir . '/{routes}/*' . self::CONFIG_EXTS, '/', 'glob');
        $routes->import($confDir . '/{routes}/' . $this->environment . '/**/*' . self::CONFIG_EXTS, '/', 'glob');
        $routes->import($confDir . '/{routes}' . self::CONFIG_EXTS, '/', 'glob');

        $this->addApiRoutes($routes);
    }

    private function addApiRoutes(RouteCollectionBuilder $routes): void
    {
        $class = ApiController::class;
        $uuidRegex = '.*\/[0-9a-f]{32}\/?$';

        // detail routes
        $route = new Route('/api/{path}');
        $route->setMethods(['GET']);
        $route->setDefault('_controller', $class . '::detail');
        $route->addRequirements(['path' => $uuidRegex]);
        $routes->addRoute($route, 'api_controller.detail');

        $route = new Route('/api/{path}');
        $route->setMethods(['PATCH']);
        $route->setDefault('_controller', $class . '::update');
        $route->addRequirements(['path' => $uuidRegex]);
        $routes->addRoute($route, 'api_controller.update');

        $route = new Route('/api/{path}');
        $route->setMethods(['DELETE']);
        $route->setDefault('_controller', $class . '::delete');
        $route->addRequirements(['path' => $uuidRegex]);
        $routes->addRoute($route, 'api_controller.delete');

        // list routes
        $route = new Route('/api/{path}');
        $route->setMethods(['GET']);
        $route->setDefault('_controller', $class . ':list');
        $route->addRequirements(['path' => '.*']);
        $routes->addRoute($route, 'api_controller.list');

        $route = new Route('/api/search/{path}');
        $route->setMethods(['POST']);
        $route->setDefault('_controller', $class . '::search');
        $route->addRequirements(['path' => '.*']);
        $routes->addRoute($route, 'api_controller.search');

        $route = new Route('/api/{path}');
        $route->setMethods(['POST']);
        $route->setDefault('_controller', $class . '::create');
        $route->addRequirements(['path' => '.*']);
        $routes->addRoute($route, 'api_controller.create');
    }

    /**
     * {@inheritdoc}
     */
    protected function getKernelParameters(): array
    {
        $parameters = parent::getKernelParameters();

        $activePluginMeta = [];

        foreach (self::getPlugins()->getActivePlugins() as $namespace => $plugin) {
            $pluginName = $plugin->getName();
            $activePluginMeta[$pluginName] = [
                'name' => $pluginName,
                'path' => $plugin->getPath(),
            ];
        }

        return array_merge(
            $parameters,
            [
                'kernel.plugin_dir' => $this->getPluginDir(),
                'kernel.active_plugins' => $activePluginMeta,
            ]
        );
    }

    protected function getContainerClass()
    {
        $pluginHash = sha1(implode('', array_keys(self::getPlugins()->getActivePlugins())));

        return $this->name
            . ucfirst($this->environment)
            . $pluginHash
            . ($this->debug ? 'Debug' : '')
            . 'ProjectContainer';
    }

    protected function build(ContainerBuilder $container)
    {
        parent::build($container);

        if ($this->getEnvironment() === 'test') {
            $container->addCompilerPass(new TestingCompilerPass(), PassConfig::TYPE_OPTIMIZE);
        }
    }

    protected function initializePlugins(): void
    {
        $stmt = self::$connection->query(
            'SELECT `name` FROM `plugin` WHERE `active` = 1 AND `installation_date` IS NOT NULL'
        );
        $activePlugins = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $finder = new Finder();
        $iterator = $finder->directories()->depth(0)->in($this->getPluginDir())->getIterator();

        foreach ($iterator as $pluginDir) {
            $pluginName = $pluginDir->getFilename();
            $pluginFile = $pluginDir->getPath() . '/' . $pluginName . '/' . $pluginName . '.php';
            if (!is_file($pluginFile)) {
                continue;
            }

            $namespace = $pluginName;
            $className = '\\' . $namespace . '\\' . $pluginName;

            if (!class_exists($className)) {
                throw new \RuntimeException(
                    sprintf('Unable to load class %s for plugin %s in file %s', $className, $pluginName, $pluginFile)
                );
            }

            $isActive = in_array($pluginName, $activePlugins, true);

            /** @var Plugin $plugin */
            $plugin = new $className($isActive);

            if (!$plugin instanceof Plugin) {
                throw new \RuntimeException(
                    sprintf('Class %s must extend %s in file %s', get_class($plugin), Plugin::class, $pluginFile)
                );
            }

            self::$plugins->add($plugin);
        }
    }

    private function initializePluginSystem(): void
    {
        if (!self::$connection) {
            self::$connection = DatabaseConnector::createPdoConnection();
        }

        $this->initializePlugins();
    }
}

<?php

declare(strict_types=1);

namespace Codeception\Module;

use Codeception\Exception\ModuleException;
use Codeception\Lib\Interfaces\DataMapper;
use Codeception\Lib\Interfaces\DependsOnModule;
use Codeception\Lib\Interfaces\ORM;
use Codeception\Lib\Interfaces\RequiresPackage;
use Codeception\Module;
use Codeception\TestInterface;
use League\FactoryMuffin\Definition;
use League\FactoryMuffin\Exceptions\DefinitionAlreadyDefinedException as FactoryMuffinDefinitionAlreadyDefinedException;
use League\FactoryMuffin\FactoryMuffin;
use League\FactoryMuffin\Stores\RepositoryStore;
use League\FactoryMuffin\Stores\StoreInterface;

/**
 * DataFactory allows you to easily generate and create test data using [**FactoryMuffin**](https://github.com/thephpleague/factory-muffin).
 * DataFactory uses an ORM of your application to define, save and cleanup data. Thus, should be used with ORM or Framework modules.
 *
 * This module requires packages installed:
 *
 * ```json
 * {
 *  "league/factory-muffin": "^3.0",
 * }
 * ```
 *
 * Generation rules can be defined in a factories file.
 * Create a folder for factories files: `tests/_support/factories`.
 *
 * Create an empty PHP file inside that folder `factories.php`.
 * Follow [FactoryMuffin documentation](https://github.com/thephpleague/factory-muffin) to set valid rules.
 * Randomly generated data provided by [Faker](https://github.com/fzaninotto/Faker) library.
 *
 * Here is the sample factory file:
 * ```php
 * <?php
 * use League\FactoryMuffin\Faker\Facade as Faker;
 *
 * $fm->define(User::class)->setDefinitions([
 *  'name'   => Faker::name(),
 *
 *     // generate email
 *    'email'  => Faker::email(),
 *    'body'   => Faker::text(),
 *
 *    // generate a profile and return its Id
 *    'profile_id' => 'factory|Profile'
 * ]);
 * ```
 *
 * Configure this module to load factory definitions from a directory.
 * You should also specify a module with an ORM as a dependency.
 *
 * ```yaml
 * modules:
 *     enabled:
 *         - Yii2:
 *             configFile: path/to/config.php
 *         - DataFactory:
 *             factories: tests/_support/factories
 *             depends: Yii2
 * ```
 *
 * (you can also use Laravel and Phalcon).
 *
 * In cases you want to use data from database inside your factory definitions you can define them in a Helper.
 * For instance, if you use Doctrine, this allows you to access `EntityManager` inside a definition.
 *
 * To proceed you should create Factories helper via `generate:helper` command and enable it:
 *
 * ```
 * modules:
 *     enabled:
 *         - DataFactory:
 *             depends: Doctrine2
 *         - \Helper\Factories
 *
 * ```
 *
 * In this case you can define factories from a Helper class with `_define` method.
 *
 * ```php
 * <?php
 * public function _beforeSuite()
 * {
 *      $factory = $this->getModule('DataFactory');
 *      // let us get EntityManager from Doctrine
 *      $em = $this->getModule('Doctrine2')->_getEntityManager();
 *
 *      $factory->_define(User::class, [
 *
 *          // generate random user name
 *          // use League\FactoryMuffin\Faker\Facade as Faker;
 *          'name' => Faker::name(),
 *
 *          // get real company from database
 *          'company' => $em->getRepository(Company::class)->find(),
 *
 *          // let's generate a profile for each created user
 *          // receive an entity and set it via `setProfile` method
 *          // UserProfile factory should be defined as well
 *          'profile' => 'entity|'.UserProfile::class
 *      ]);
 * }
 * ```
 *
 * Factory Definitions are described in official [Factory Muffin Documentation](https://github.com/thephpleague/factory-muffin)
 *
 * ### Related Models Generators
 *
 * If your module relies on other model you can generate them both.
 * To create a related module you can use either `factory` or `entity` prefix, depending on ORM you use.
 *
 * In case your ORM expects an Id of a related record (Eloquent) to be set use `factory` prefix:
 *
 * ```php
 * 'user_id' => 'factory|User'
 * ```
 *
 * In case your ORM expects a related record itself (Doctrine) then you should use `entity` prefix:
 *
 * ```php
 * 'user' => 'entity|User'
 * ```
 *
 * ### Custom store
 *
 * You can define a custom store for Factory Muffin using `customStore` parameter. It can be a simple class or a factory with `create` method.
 * The instantiated object must implement `\League\FactoryMuffin\Stores\StoreInterface`.
 *
 * Store factory example:
 * ```yaml
 * modules:
 *     enabled:
 *         - DataFactory:
 *             customStore: \common\tests\store\MyCustomStoreFactory
 * ```
 *
 * ```php
 * use League\FactoryMuffin\Stores\StoreInterface;
 *
 * class MyCustomStoreFactory
 * {
 *     public function create(): StoreInterface
 *     {
 *         return CustomStore();
 *     }
 * }
 *
 * class CustomStore implements StoreInterface
 * {
 *     // ...
 * }
 * ```
 */
class DataFactory extends Module implements DependsOnModule, RequiresPackage
{
    protected string $dependencyMessage = <<<EOF
ORM module (like Doctrine2) or Framework module with ActiveRecord support is required:
--
modules:
    enabled:
        - DataFactory:
            depends: Doctrine2
--
EOF;

    /**
     * ORM module on which we we depend on.
     *
     * @var Module
     */
    public ?ORM $ormModule = null;

    public ?FactoryMuffin $factoryMuffin = null;

    protected array $config = ['factories' => null, 'customStore' => null];

    public function _requires(): array
    {
        return [
            FactoryMuffin::class => '"league/factory-muffin": "^3.0"',
        ];
    }

    public function _beforeSuite($settings = [])
    {
        $store = $this->getStore();
        $this->factoryMuffin = new FactoryMuffin($store);

        if ($this->config['factories']) {
            foreach ((array)$this->config['factories'] as $factoryPath) {
                $realpath = realpath(codecept_root_dir() . $factoryPath);
                if ($realpath === false) {
                    throw new ModuleException($this, 'The path to one of your factories is not correct. Please specify the directory relative to the codeception.yml file (ie. _support/factories).');
                }

                $this->factoryMuffin->loadFactories($realpath);
            }
        }
    }

    protected function getStore(): ?StoreInterface
    {
        if (!empty($this->config['customStore'])) {
            $store = new $this->config['customStore'];
            if (method_exists($store, 'create')) {
                return $store->create();
            }

            return $store;
        }

        return $this->ormModule instanceof DataMapper
            ? new RepositoryStore($this->ormModule->_getEntityManager()) // for Doctrine
            : null;
    }

    public function _inject(ORM $orm): void
    {
        $this->ormModule = $orm;
    }

    public function _after(TestInterface $test)
    {
        $skipCleanup = array_key_exists('cleanup', $this->config) && $this->config['cleanup'] === false;
        $cleanupOrmModuleConfig = $this->ormModule->_getConfig('cleanup');
        if ($skipCleanup) {
            return;
        }

        if ($cleanupOrmModuleConfig) {
            return;
        }

        $this->factoryMuffin->deleteSaved();
    }

    public function _depends(): array
    {
        return [ORM::class => $this->dependencyMessage];
    }

    /**
     * @throws ModuleException
     */
    public function onReconfigure($settings = [])
    {
        $skipCleanup = array_key_exists('cleanup', $this->config) && $this->config['cleanup'] === false;
        if (!$skipCleanup && !$this->ormModule->_getConfig('cleanup')) {
            $this->factoryMuffin->deleteSaved();
        }

        $this->_beforeSuite($settings);
    }

    /**
     * Creates a model definition. This can be used from a helper:.
     *
     * ```php
     * $this->getModule('{{MODULE_NAME}}')->_define('User', [
     *     'name' => $faker->name,
     *     'email' => $faker->email
     * ]);
     * ```
     *
     * @throws FactoryMuffinDefinitionAlreadyDefinedException
     */
    public function _define(string $model, array $fields): Definition
    {
        return $this->factoryMuffin->define($model)->setDefinitions($fields);
    }

    /**
     * Generates and saves a record,.
     *
     * ```php
     * $I->have('User'); // creates user
     * $I->have('User', ['is_active' => true]); // creates active user
     * ```
     *
     * Returns an instance of created user.
     */
    public function have(string $name, array $extraAttrs = []): object
    {
        return $this->factoryMuffin->create($name, $extraAttrs);
    }

    /**
     * Generates a record instance.
     *
     * This does not save it in the database. Use `have` for that.
     *
     * ```php
     * $user = $I->make('User'); // return User instance
     * $activeUser = $I->make('User', ['is_active' => true]); // return active user instance
     * ```
     *
     * Returns an instance of created user without creating a record in database.
     */
    public function make(string $name, array $extraAttrs = []): object
    {
        return $this->factoryMuffin->instance($name, $extraAttrs);
    }

    /**
     * Generates and saves a record multiple times.
     *
     * ```php
     * $I->haveMultiple('User', 10); // create 10 users
     * $I->haveMultiple('User', 10, ['is_active' => true]); // create 10 active users
     * ```
     *
     * @return object[]
     */
    public function haveMultiple(string $name, int $times, array $extraAttrs = []): array
    {
        return $this->factoryMuffin->seed($times, $name, $extraAttrs);
    }
}

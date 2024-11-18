<?php

namespace Bootstrap;

use App\Core\Services\PdoService;

// use Swoole\Runtime;

/**
 * Class ServiceContainer
 *
 * This class implements a service container and allows registering and retrieving instances of registered services
 * It is implements the Singleton Design Pattern
 */
class ServiceContainer
{
    // The $instance will hold the instance of ServiceContainer. Although it is any array but will have only one value.
    private static $instance = [];

    // Services will contain the registered Service Classes
    private static $services = [];

    // Processes will contain the registered Process Callbacks
    private static $processes = [];

    // Global Constructor Parameter
    protected static $server = null;
    protected static $process = null;

    /**
     * Protected contructor is used to prevent creating the object of ServiceContainer (Singleton)
     *
     * @return void
     */
    protected function __construct() {}

    /**
     * Singletons should not be cloneable.
     */
    protected function __clone() {}

    /**
     * Singletons should not be restorable from strings.
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    /**
     * Retrieves the singleton instance of the ServiceContainer.
     *
     * @return self The singleton instance of ServiceContainer.
     */
    public static function get_instance($server = null, $process = null)
    {
        // // Check if Key is provided to register the service
        // if (trim($key) !== "" && $service) {
        //     self::$services[$key] = $service;
        // } else if (trim($key) !== "") {
        //     self::$services[$key] = 'defaultServices';
        // }

        // // Add the global parameters in the ServiceContainer for using them in custom User Processes
        if (!is_null($server)) {
            self::$server = $server;
        }

        if (!is_null($process)) {
            self::$process = $process;
        }

        self::registerServices();
        self::registerProcesses();

        $cls = static::class; // string name of the class 'ServiceContainer'
        if (!isset(self::$instance[$cls])) {
            self::$instance[$cls] = new static();
        }

        return self::$instance[$cls];
    }

    /**
     * Registers a service with a specified alias.
     *
     * @param string $alias The key/alias to register the service.
     * @param string $serviceClassName The qualified class name of the service class to register.
     */
    public function __set(string $alias, string $serviceClassName)
    {
        // If Service class is already registered than throw the exception instead of overriding
        if (array_key_exists($alias, self::$services)) {
            throw new \RuntimeException('Service class is already registered on provided alias (' . $alias . ') in ' . __CLASS__);
        }

        self::$services[$alias] = $serviceClassName;
    }


    // Note: __get() can only take one parameter and so we cannot use it to return the instance of service class (if service class contructor needs params)
    // I am keeping it so in-case consumer does not want the Service Container to create the instance of service. He/she can use it.
    /**
     * Get the service instances by Service Name
     *
     * @param string $alias The key/alias of the service to retrieve.
     * @return mixed The service class qualified name
     */
    public function __get(string $alias): mixed
    {
        if (array_key_exists($alias, self::$services)) {
            return self::$services[$alias];
        }

        throw new \RuntimeException('Undefined property via __get(): ' . $alias . ' in ' . __CLASS__);
        // $trace = debug_backtrace();
        // trigger_error(
        //     'Undefined property via __get(): ' . $alias .
        //     ' in ' . $trace[0]['file'] .
        //     ' on line ' . $trace[0]['line'],
        //     E_USER_NOTICE);
        // return null;
    }

    /**
     * Registers a service with a specified alias.
     *
     * @param string $alias The key/alias to register the service.
     * @param string $serviceClassName The qualified class name of the service class to register.
     * @param mixed $factory Optional: The default factory that will be used to create the service class instance.
     *
     * @return void
     */
    public function add_service(string $alias, string $serviceClassName, mixed $factory = null): void
    {
        // If Service class is already registered than throw the exception instead of overriding
        if (array_key_exists($alias, self::$services)) {
            throw new \RuntimeException('Service class is already registered on provided alias (' . $alias . ') in ' . __CLASS__);
        }

        self::$services[$alias] = [
            'class' => $serviceClassName,
            'factory' => $factory
        ];
    }

    /**
     * Get the instance of the Service Class
     *
     * @param  string $serviceClassAlias Name of the Service Class
     * @param  mixed $constructorParams Optional parameters if required by the Service Class
     * @return mixed;
     */
    public function create_service_object(string $serviceClassAlias, ...$constructorParams): mixed
    {
        if (array_key_exists($serviceClassAlias, self::$services)) {
            $serviceData = self::$services[$serviceClassAlias];
            $factory = $serviceData['factory'] ?? null;

            try {
                // If a factory is provided, use it to create the instance
                if ($factory !== null) {
                    if (is_array($factory)) {
                        // Create the object of the factory and pass it as callback
                        $factoryInstance = new $factory[0]();
                        return call_user_func_array([$factoryInstance, $factory[1]], $constructorParams);
                    } else if (is_callable($factory)) {
                        // If Factory is a callable function instead of class
                        return call_user_func_array($factory, $constructorParams);
                    }

                    throw new \RuntimeException('Invalid Factory given for service class having alias (' . $serviceClassAlias . ') in ' . __CLASS__);
                }

                // Otherwise create service class object directly
                $class = $serviceData['class'];

                // If constructor parameters are given we create object with Params otherwise create simple object
                if (count($constructorParams) > 0) {
                    return new $class(...$constructorParams);
                } else {
                    return new $class();
                }
            } catch (\Throwable $e) {
                echo PHP_EOL;
                echo 'Error Code: ' . $e->getCode() . PHP_EOL;
                echo 'In File: ' . $e->getFile() . PHP_EOL;
                echo 'On Line: ' . $e->getLine() . PHP_EOL;
                echo 'Error Message: ' . $e->getMessage() . PHP_EOL;
                echo PHP_EOL;
                throw $e;
            }
        }

        throw new \RuntimeException('Service class is not registered on provided alias (' . $serviceClassAlias . ') in ' . __CLASS__);
    }


    /**
     * __invoke function returns the instance of the registered service class or call the callback of custom User Process.
     * Additionaly you can pass the custom factory as callback to override the default funcationality
     *
     * @param  mixed $classAlias
     * @param  mixed $customFactoryAsCallback
     * @param  mixed $constructorParams
     * @return mixed
     */
    public function __invoke(string $classAlias, callable $customFactoryAsCallback = null, ...$constructorParams): mixed
    {
        // First we check if the classAlias exists in Registered ServiceContainer
        if (array_key_exists($classAlias, self::$services)) {
            $classType = 'service';
        } else if (array_key_exists($classAlias, self::$processes)) {
            $classType = 'process';
        } else {
            throw new \RuntimeException('Class is not registered on provided alias (' . $classAlias . ') in ' . __CLASS__);
        }

        if ($classType == 'process') {
            $processInfo = self::$processes[$classAlias];

            // Prioritize constructorParams parameter if passed otherwise use default $server and $process
            $constructorParams = count($constructorParams) ? $constructorParams : [self::$server, self::$process];

            // Execute the callback function if provided to create the Process Service Instance/Object
            if (!is_null($customFactoryAsCallback)) {
                $processServiceInstance = $this->createObjFromCallback($customFactoryAsCallback, $constructorParams);
            } else {
                // Default code to create the Process Service Instance
                if (count($constructorParams)) {
                    $processServiceInstance = new $processInfo['callback'][0](...$constructorParams);
                } else {
                    // Default process with no constructor params
                    $processServiceInstance = new $processInfo['callback'][0]();
                }
            }

            // Call the callback of the Process Service Class registered
            return call_user_func([$processServiceInstance, $processInfo['callback'][1]]);
        } else {
            // Execute the callback function if provided, otherwise run the default functionality
            if (!is_null($customFactoryAsCallback)) {
                return $this->createObjFromCallback($customFactoryAsCallback, $constructorParams);
            }

            // Default case we will create the object of the Service Class and return it
            return $this->create_service_object($classAlias, ...$constructorParams);
        }

        // Old Code
        // if (isset($serviceKey)) {
        //     if (isset(self::$services[$serviceKey])) {
        //         $service = self::$services[$serviceKey];

        //         // Check if the provided method exists in the registered Service Class
        //         if (!method_exists($service, $fnName)) {
        //             throw new \RuntimeException("Call to undefined function ($fnName) of class ($serviceKey) in " . __CLASS__);
        //         }

        //         try {
        //             if (is_array($args)) {
        //                 return call_user_func_array([$service, $fnName], $args);
        //             } else {
        //                 return call_user_func([$service, $fnName]);
        //             }
        //         } catch (\Throwable $e) {
        //             echo PHP_EOL;
        //             echo $e->getMessage();
        //             throw $e;

        //             // $trace = debug_backtrace();
        //             // trigger_error(
        //             //     'call_user_func('.$serviceKey.', '.$args.')'.
        //             //     ' in ' . $trace[0]['file'] .
        //             //     ' on line ' . $trace[0]['line'],
        //             //     E_USER_NOTICE);
        //             // return null;

        //             // $logger->log(
        //             //     'Error occurred',
        //             //     ['exception' => $e, 'callable' => $this->getCallableContext(self::$services[$key])]
        //             // );
        //             // In case we can use string only
        //             // $logger->log('Error occurred: ' . \print_r($this->getCallableContext(self::$services[$key]), true));
        //         }
        //     } else {
        //         throw new \RuntimeException('No factory / callback provided for the key ' . $serviceKey . '.');
        //     }
        // } else {
        //     throw new \RuntimeException('Key ' . $serviceKey . ' does not registered in ' . __CLASS__ . '. Please, set the key');
        // }
    }

    /**
     * This function is used to register the services. You can add your services here to register.
     *
     * @return void
     */
    private static function registerServices(): void
    {
        try {
            $rootDir = dirname(__DIR__, 1);
            $registryFile = $rootDir . DIRECTORY_SEPARATOR . 'registry/ServiceRegistry.php';

            // Check if Registry File Exists
            if (!file_exists($registryFile)) {
                throw new \RuntimeException("Service registry file not found: {$registryFile}");
            }

            self::$services = include($registryFile);
        } catch (\Throwable $e) {
            echo PHP_EOL;
            echo 'Error Message: ' . $e->getMessage() . PHP_EOL;
            echo 'In File: ' . $e->getFile() . PHP_EOL;
            echo 'On Line: ' . $e->getLine() . PHP_EOL;
            echo 'Error Code: ' . $e->getCode() . PHP_EOL;
            echo PHP_EOL;
            throw $e;
        }
    }

    /**
     * Get the list of the registered classes
     *
     * @return mixed
     */
    public function get_registered_services(): mixed
    {
        return self::$services;
    }

    /**
     * This function is used to register the Resident (Keep-alive) processes. You can add your processes callbacks here to register.
     *
     * @return void
     */
    private static function registerProcesses(): void
    {
        try {
            // Fetch the Registered Processes from ProcessRegistry
            $processesRegisterPath = dirname(__DIR__) . '/app/Core/Processes/ProcessesRegister.json';
            $registeredProcesses = readJsonFile($processesRegisterPath);

            // Format the fetched data
            $formattedProcesesData = [];

            foreach ($registeredProcesses as $processData) {
                $formattedProcesesData[$processData['name']] = [
                    // Note for Now using App\Services, but it should be from App\Processes namespace
                    // Callback of Process you want to call when the process will be created
                    'callback' => ['\\App\Processes\\' . $processData['name'], 'handle'],

                    // Process Options
                    'process_options' => [
                        'redirect_stdin_and_stdout' => $processData['redirect_stdin_and_stdout'],
                        'pipe_type' => $processData['pipe_type'],
                        'enable_coroutine' => $processData['enable_coroutine'],
                    ]
                ];
            }

            self::$processes = $formattedProcesesData;
        } catch (\Throwable $e) {
            output(data: $e, shouldExit: true);
            throw $e;
        }
    }

    /**
     * Get the list of the registered processes
     *
     * @return mixed
     */
    public function get_registered_processes(): mixed
    {
        return self::$processes;
    }

    private function getCallableType($callable): string
    {
        switch (true) {
                // string: MyClass::myCallbackMethod
            case \is_string($callable) && \strpos($callable, '::'):
                return 'static_method';
                // string: 'my_callback_function'
            case \is_string($callable):
                return 'function';
                // array($obj, 'myCallbackMethod')
            case \is_array($callable) && \is_object($callable[0]):
                return 'class_method';
                // array('MyClass', 'myCallbackMethod')
            case \is_array($callable):
                return 'class_static method';
            case $callable instanceof \Closure:
                return 'closure';
            case \is_object($callable):
                return 'invokable';
            default:
                return 'unknown';
        }
    }

    /**
     * Retrieve the context of callable for debugging purposes
     *
     * @param callable $callable
     * @return array
     */
    private function getCallableContext($callable): array
    {
        switch (true) {
            case \is_string($callable) && \strpos($callable, '::'):
                return ['static method' => $callable];
            case \is_string($callable):
                return ['function' => $callable];
            case \is_array($callable) && \is_object($callable[0]):
                return ['class' => \get_class($callable[0]), 'method' => $callable[1]];
            case \is_array($callable):
                return ['class' => $callable[0], 'static method' => $callable[1]];
            case $callable instanceof \Closure:
                try {
                    $reflectedFunction = new \ReflectionFunction($callable);
                    $closureClass = $reflectedFunction->getClosureScopeClass();
                    $closureThis = $reflectedFunction->getClosureThis();
                } catch (\ReflectionException $e) {
                    return ['closure' => 'closure'];
                }

                return [
                    'closure this'  => $closureThis ? \get_class($closureThis) : $reflectedFunction->name,
                    'closure scope' => $closureClass ? $closureClass->getName() : $reflectedFunction->name,
                    'static variables' => $this->formatVariablesArray($reflectedFunction->getStaticVariables()),
                ];
            case \is_object($callable):
                return ['invokable' => \get_class($callable)];
            default:
                return ['unknown' => 'unknown'];
        }
    }

    /**
     * Format variables array for debugging purposes in order to avoid huge objects dumping
     *
     * @param array $data
     * @return array
     */
    private function formatVariablesArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_object($value)) {
                $data[$key] = \get_class($value);
            } elseif (\is_array($value)) {
                $data[$key] = $this->formatVariablesArray($value);
            }
        }

        return $data;
    }

    /**
     * This function creates the class instance from callback provided by consumer
     *
     * @param  mixed $customFactoryAsCallback
     * @param  mixed $constructorParams
     * @return mixed
     */
    private function createObjFromCallback($customFactoryAsCallback, ...$constructorParams): mixed
    {
        // Here we will pass constructorParams to callback;
        if (isset($constructorParams)) {
            return call_user_func_array($customFactoryAsCallback, $constructorParams);
        } else {
            return call_user_func($customFactoryAsCallback);
        }
    }
}

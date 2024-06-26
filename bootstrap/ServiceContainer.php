<?php

use Swoole\Runtime;

class ServiceContainer {
    private static $instances = [];
    private static $callback;

    protected function __construct()  {
    }

    /**
     * Singletons should not be cloneable.
     */
    protected function __clone() { }

    /**
     * Singletons should not be restorable from strings.
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    public static function getInstance($key=null, $factory = null) {
        self::$callback[$key] = $factory ?? 'defaultFactory';
        $cls = static::class; // string name of the class 'ServiceContainer'
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }

        return self::$instances[$cls];
    }

    public function __set($key, $factory)
    {
        self::$callback[$key] = $factory;
    }

    public function __get($key) {
        if (array_key_exists($key, self::$callback)) {
            return self::$callback[$key];
        }

        $trace = debug_backtrace();
        trigger_error(
            'Undefined property via __get(): ' . $key .
            ' in ' . $trace[0]['file'] .
            ' on line ' . $trace[0]['line'],
            E_USER_NOTICE);
        return null;
    }

    public function __invoke($key, $val=null) {
        if (isset($key)) {
            if (isset(self::$callback[$key])) {
                try {
                    if (isset($val)) {
                        if (is_array($val)) {
                            call_user_func_array(self::$callback[$key], $val);
                        } else {
                            call_user_func(self::$callback[$key]($val));
                        }
                    }

                } catch (\Throwable $e) {
                    $trace = debug_backtrace();
                    trigger_error(
                        'call_user_func('.$key.', '.$val.')'.
                        ' in ' . $trace[0]['file'] .
                        ' on line ' . $trace[0]['line'],
                        E_USER_NOTICE);
                    return null;
//                    $logger->log(
//                        'Error occurred',
//                        ['exception' => $e, 'callable' => $this->getCallableContext(self::$callback[$key])]
//                    );
//                    //In case we can use string only
//                    $logger->log('Error occurred: ' . \print_r($this->getCallableContext(self::$callback[$key]), true));
                }
            } else {
                throw new RuntimeException('No factory / callback provided for the key '.$key.'.');
            }
        } else {
            throw new RuntimeException('Key '.$key.' does not exist. Please, set the key');
        }
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
}

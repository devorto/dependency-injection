<?php

namespace Devorto\DependencyInjection;

use ReflectionClass;
use ReflectionException;
use RuntimeException;

/**
 * Class DependencyInjection
 *
 * @package Devorto\DependencyInjection
 */
class DependencyInjection
{
    /**
     * @var KeyValueStorage[] Holds the normal configuration (like int|array|string parameters) for classes.
     */
    protected static $configuration = [];

    /**
     * @var array Holds all the loaded classes (static/cached).
     */
    protected static $loadedClasses = [];

    /**
     * @var string[]
     */
    protected static $interfaceImplementations = [];

    /**
     * @param string $class
     * @param KeyValueStorage $storage
     */
    public static function addConfiguration(string $class, KeyValueStorage $storage): void
    {
        static::$configuration[$class] = $storage;
    }

    /**
     * When requesting a interface or abstract class with instantiate function it with will be
     * replaced with given class.
     *
     * @param string $interface
     * @param string $class
     */
    public static function addInterfaceImplementation(string $interface, string $class): void
    {
        static::$interfaceImplementations[$interface] = $class;
    }

    /**
     * @param string $class
     *
     * @return mixed
     */
    public static function instantiate(string $class)
    {
        if (isset(static::$loadedClasses[$class])) {
            return static::$loadedClasses[$class];
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $exception) {
            throw new RuntimeException(sprintf('Class "%s" not found.', $class), 0, $exception);
        }

        // Is it a interface or abstract class, instead of a normal class? If so load that instead.
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            if (!isset(static::$interfaceImplementations[$class])) {
                throw new RuntimeException("No implementation found for interface or abstract class '$class'.");
            }

            // Overwrite interface with class.
            $interface = $class;
            $class = static::$interfaceImplementations[$class];

            try {
                $reflection = new ReflectionClass($class);
            } catch (ReflectionException $exception) {
                throw new RuntimeException(sprintf('Class "%s" not found.', $class), 0, $exception);
            }
        }

        $arguments = $reflection->getConstructor();
        if (empty($arguments)) {
            // If the current class is an interface implementation also save the interface class.
            if (isset($interface)) {
                return static::$loadedClasses[$interface] = static::$loadedClasses[$class] = new $class;
            }

            return static::$loadedClasses[$class] = new $class;
        }

        $parameters = [];
        $configuration = static::getConfiguration($class);

        foreach ($arguments->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType()->getName();

            if (!$parameter->getType()->isBuiltin()) {
                // Instantiate the class and store it internally and in parameters array.
                $parameters[] = static::instantiate($type);

                continue;
            }

            if ($configuration->has($name)) {
                $parameters[] = $configuration->get($name);

                continue;
            }

            if (!$parameter->isOptional()) {
                throw new RuntimeException(
                    sprintf(
                        'Class "%s" is missing configuration for parameter "%s".',
                        $class,
                        $name
                    )
                );
            }

            if ($parameter->isDefaultValueAvailable()) {
                try {
                    $parameters[] = $parameter->getDefaultValue();
                } catch (ReflectionException $exception) {
                    /**
                     * The try catch is here to prevent phpstorm errors on "uncaught exceptions".
                     * This exception is thrown when a parameter is not optional, but this is already checked.
                     */
                }
            } else {
                $parameters[] = null;
            }
        }

        // If the current class is an interface implementation also save the interface class.
        if (isset($interface)) {
            return static::$loadedClasses[$interface] = static::$loadedClasses[$class] = new $class(...$parameters);
        }

        return static::$loadedClasses[$class] = new $class(...$parameters);
    }

    /**
     * @param string $parent
     *
     * @return KeyValueStorage
     */
    protected static function getConfiguration(string $parent): KeyValueStorage
    {
        $tree = [];
        foreach (get_declared_classes() as $class) {
            if (is_subclass_of($parent, $class, true)) {
                $tree[] = $class;
            }
        }
        usort($tree, function (string $a, string $b) {
            return is_subclass_of($a, $b, true) ? 1 : -1;
        });
        $tree[] = $parent;

        $config = new KeyValueStorage();

        foreach ($tree as $class) {
            if (!isset(static::$configuration[$class])) {
                continue;
            }

            foreach (static::$configuration[$class] as $key => $value) {
                $config->add($key, $value);
            }
        }

        return $config;
    }
}

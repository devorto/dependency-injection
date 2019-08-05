<?php

namespace Devorto\DependencyInjection;

use ReflectionClass;
use ReflectionException;
use RuntimeException;

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
        $parameters = [];

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $exception) {
            throw new RuntimeException(sprintf('Class "%s" not found.', $class), 0, $exception);
        }

        $arguments = $reflection->getConstructor();
        if (!empty($arguments)) {
            foreach ($arguments->getParameters() as $parameter) {
                $name = $parameter->getName();
                $type = $parameter->getType()->getName();
                // Is it a class or parameter (does it contain a "\")?
                if(strpos($type, '\\') !== false) {
                    // Early exit, is it already loaded?
                    if(isset(static::$loadedClasses[$type])) {
                        $parameters[] = static::$loadedClasses[$type];
                        continue;
                    }

                    // Is it a interface instead of a normal class? If so load that instead.
                    if(isset(static::$interfaceImplementations[$type])) {
                        // Overwrite interface with class.
                        $type = static::$interfaceImplementations[$type];
                    }

                    // Lets check if the class exists (triggers autoload if not loaded yet).
                    if (!class_exists($type)) {
                        throw new RuntimeException(
                            sprintf(
                                'Unsupported type "%s" for parameter "%s" of class "%s".',
                                $type,
                                $name,
                                $class
                            )
                        );
                    }

                    // Instantiate the class and store it internally and in parameters array.
                    $parameters[] = static::$loadedClasses[$type] = static::instantiate($type);
                } else {
                    if (!isset(static::$configuration[$class]) || !static::$configuration[$class]->has($name)) {
                        throw new RuntimeException(
                            sprintf(
                                'Class "%s" is missing configuration for parameter "%s".',
                                $class,
                                $name
                            )
                        );
                    }

                    $parameters[] = static::$configuration[$class]->get($name);
                }
            }
        }

        // Return the new object.
        return empty($parameters) ? new $class : new $class(...$parameters);
    }
}

<?php

declare(strict_types=1);

namespace Cms\BaseModule\Helper;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;

final class ArrayToDto
{
    /**
     * Retrieves and transforms data array into a class instance.
     *
     * @param class-string $class The class name to instantiate.
     * @param ?array $data data source array
     * @param bool $isNested Determines if the data is nested.
     * @throws ReflectionException
     * return mixed An instance of the specified class or null.
     */
    public function getDto(string $class, ?array $data, bool $isNested = false): mixed
    {
        if ($data === null) {
            return null;
        }

        $this->validateClassExists($class);
        return $isNested
            ? array_map(fn($item) => $this->instantiateClass($class, $item), $data)
            : $this->instantiateClass($class, $data);
    }

    /**
     * Validates if a class exists.
     *
     * @param class-string $class The class name to check.
     * @throws RuntimeException If the class does not exist.
     */
    protected function validateClassExists(string $class): void
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Class $class does not exist");
        }
    }

    /**
     * Instantiates a class with data.
     *
     * @param class-string $class The class to instantiate.
     * @param array $data Data to populate the class instance.
     * @throws ReflectionException If the reflection process fails.
     * return mixed An instance of the specified class.
     */
    protected function instantiateClass(string $class, array $data): mixed
    {
        $reflection = new ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructorParameters = $this->getConstructorParameters($reflection);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $this->setPropertyFromData(
                $property,
                $data,
                $instance,
                $constructorParameters[$property->getName()]
            );
        }

        return $instance;
    }

    /**
     * Gets the constructor parameters of a class.
     *
     * @param ReflectionClass $reflection The reflection of the class.
     * @return ReflectionParameter[] The constructor parameters.
     */
    private function getConstructorParameters(ReflectionClass $reflection): array
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $constructorParameters = $constructor->getParameters();
        $params = [];
        foreach ($constructorParameters as $constructorParameter) {
            $params[$constructorParameter->getName()] = $constructorParameter;
        }
        return $params;
    }

    /**
     * Sets a property of an instance based on provided data.
     *
     * @param ReflectionProperty $property The property to set.
     * @param array $data The data array.
     * @param object $instance The instance to modify.
     * @throws ReflectionException If the reflection process fails.
     */
    protected function setPropertyFromData(
        ReflectionProperty $property,
        array $data,
        object $instance,
        ReflectionParameter $parameter
    ): void {
        $propName = $property->getName();

        // Check if property exists in data or if it's acceptable for it not to be set
        $this->ensurePropertyCanBeSet($propName, $data, $parameter);

        // not set but nullable or is optional
        if (!array_key_exists($propName, $data)) {
            $data[$propName] = $this->getDefaultValue($parameter);
        }

        $propertyValue = $this->getPropertyValue($property, $data[$propName]);
        $property->setValue($instance, $propertyValue);
    }

    /**
     * Checks if a parameter is nullable or optional.
     *
     * @param string $propName
     * @param array $data
     * @param ReflectionParameter $parameter The parameter to check.
     * @return void True if the parameter is nullable or optional.
     */
    private function ensurePropertyCanBeSet(string $propName, array $data, ReflectionParameter $parameter): void
    {
        if (!array_key_exists($propName, $data) &&
            !$this->isNullable($parameter) &&
            !$this->isOptional($parameter)) {
            throw new RuntimeException("Property $propName is required but not provided.");
        }
    }

    /**
     * Gets the default value of a parameter.
     *
     * @param ReflectionParameter $parameter The parameter to check.
     * return mixed The default value.
     */
    private function getDefaultValue(ReflectionParameter $parameter): mixed
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        return null;
    }

    /**
     * Checks if a parameter is nullable.
     *
     * @param ReflectionParameter $parameter The parameter to check.
     * @return bool True if the property is nullable.
     */
    private function isNullable(ReflectionParameter $parameter): bool
    {
        return $parameter->allowsNull();
    }

    /**
     * Checks if a parameter is optional
     *
     * @param ReflectionParameter $parameter The parameter to check.
     * @return bool True if the property has a default value.
     */
    private function isOptional(ReflectionParameter $parameter): bool
    {
        return $parameter->isOptional();
    }

    /**
     * Gets the value to be set for a property.
     *
     * @param ReflectionProperty $property The property.
     * @throws ReflectionException If the reflection process fails.
     * param mixed $value The value to process.
     * return mixed The processed value.
     */
    protected function getPropertyValue(ReflectionProperty $property, mixed $value): mixed
    {
        if ($this->isClassProperty($property) && is_array($value)) {
            $type = $property->getType();
            if ($type === null) {
                throw new RuntimeException("Property {$property->getName()} has no type");
            }
            /** @var ReflectionNamedType $type */
            $class = $type->getName();
            /** @var class-string $class */
            $propClass = new ReflectionClass($class);
            return $this->instantiateClass($propClass->getName(), $value);
        }

        return $value;
    }

    /**
     * Checks if a property is of a class type.
     *
     * @param ReflectionProperty $property The property to check.
     * @return bool True if the property is of a class type.
     */
    protected function isClassProperty(ReflectionProperty $property): bool
    {
        /** @var ReflectionNamedType $type */
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType) {
            return false;
        }

        return !$type->isBuiltin();
    }
}

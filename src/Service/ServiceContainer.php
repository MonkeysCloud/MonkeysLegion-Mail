<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Service;

use Exception;

class ServiceContainer
{
    private static ?ServiceContainer $instance = null;

    /**
     * @var array<string, callable> Registered service factories
     */
    private array $factories = [];

    /**
     * @var array<string, object> Registered service instances
     */
    private array $instances = [];

    /**
     * @var array<string, array<string, mixed>> Configuration for services
     */
    private array $config = [];

    private function __construct() {}

    /**
     * Get the singleton instance of the ServiceContainer.
     *
     * @return ServiceContainer
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set a service factory in the container.
     *
     * @param string $name The name of the service.
     * @param callable(self): object $factory A callable that returns the service instance.
     */
    public function set(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
        // Clear cached instance on factory replace
        unset($this->instances[$name]);
    }

    /**
     * Get a service instance from the container.
     *
     * @param string $name The name of the service.
     * @return object The service instance.
     * @throws Exception If the service is not found.
     */
    public function get(string $name): object
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (isset($this->factories[$name])) {
            $instance = ($this->factories[$name])($this);

            if (!is_object($instance)) {
                throw new \RuntimeException("Factory for service '{$name}' did not return an object.");
            }

            $this->instances[$name] = $instance;
            return $instance;
        }

        throw new Exception("Service '{$name}' not found.");
    }

    /**
     * Set configuration for a service.
     *
     * @param array<string, mixed> $config The configuration array.
     * @param string $name The name of the service.
     */
    public function setConfig(array $config, string $name): void
    {
        $this->config[$name] = $config;
    }

    /**
     * Get configuration for a service.
     *
     * @param string $name The name of the service.
     * @return array<string, mixed> The configuration array.
     */
    public function getConfig(string $name): array
    {
        return $this->config[$name] ?? [];
    }

    /**
     * Check if a service is registered.
     *
     * @param string $key The service name.
     * @return bool True if the service is registered.
     */
    public function has(string $key): bool
    {
        return isset($this->factories[$key]) || isset($this->instances[$key]);
    }

    /**
     * Reset the singleton instance and all stored services.
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->instances = [];
            self::$instance->factories = [];
            self::$instance->config = [];
            self::$instance = null;
        }
    }

    /**
     * Reset a specific cached service instance.
     *
     * @param string $name Service name/key to reset
     */
    public function resetInstance(string $name): void
    {
        unset($this->instances[$name]);
    }
}

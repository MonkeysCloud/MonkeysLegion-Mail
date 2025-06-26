<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Service;

use Exception;
use Dotenv\Dotenv;

class ServiceContainer
{
    private static ?ServiceContainer $instance = null;

    private array $instances = [];
    private array $factories = [];
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
     * Set a service in the container.
     *
     * @param string $name The name of the service.
     * @param callable $factory A callable that returns the service instance.
     */
    public function set(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
    }

    /**
     * Get a service from the container.
     *
     * @param string $name The name of the service.
     * @return mixed The service instance.
     * @throws Exception If the service is not found.
     */
    public function get(string $name)
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (isset($this->factories[$name])) {
            $this->instances[$name] = ($this->factories[$name])($this);
            return $this->instances[$name];
        }

        throw new Exception("Service '{$name}' not found.");
    }

    /**
     *  Set configuration for a service.
     *  @param array $config The configuration array.
     *  @param string $name The name of the service.
     * @return void
     */
    public function setConfig(array $config, string $name): void
    {
        $this->config[$name] = $config;
    }

    /**
     * Get configuration for a service.
     *
     * @param string $name The name of the service.
     * @return array The configuration array.
     */
    public function getConfig(string $name): array
    {
        return $this->config[$name] ?? [];
    }
}

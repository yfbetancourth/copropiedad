<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable $handler): void
    {
        if (isset($this->routes[$path][$method])) {
            throw new LogicException(sprintf('La ruta %s "%s" ya esta registrada.', $method, $path));
        }

        $this->routes[$path][$method] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || !isset($this->routes[$path])) {
            http_response_code(404);
            echo 'Pagina no encontrada.';
            return;
        }

        if (!isset($this->routes[$path][$method])) {
            header('Allow: ' . implode(', ', array_keys($this->routes[$path])));
            http_response_code(405);
            echo 'Metodo no permitido.';
            return;
        }

        ($this->routes[$path][$method])();
    }
}

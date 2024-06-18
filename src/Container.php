<?php

declare(strict_types=1);

namespace PHPOMG\Psr11;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionParameter;

class Container implements ContainerInterface
{
    private $callbacks = [];
    private $items = [];
    private $caches = [];
    private $args = [];

    public function has(string $id): bool
    {
        return class_exists($id);
    }

    public function get(string $id, bool $new = false)
    {
        if (!$new) {
            if (array_key_exists($id, $this->caches)) {
                return $this->caches[$id];
            }
        }

        if (isset($this->items[$id])) {
            $args = $this->reflectArguments($this->items[$id]);
            $obj = call_user_func($this->items[$id], ...$args);
            if (!is_a($obj, $id)) {
                throw new ContainerException(sprintf(
                    'return value must be subof `%s`',
                    $id,
                ));
            }
        } elseif (class_exists($id)) {
            $reflector = new ReflectionClass($id);
            $args = $reflector->getConstructor() === null ? [] : $this->reflectArguments([$id, '__construct'], self::$args[$id] ?? []);
            $obj = $reflector->newInstanceArgs($args);
        } else {
            throw new NotFoundException(
                sprintf('Alias (%s) is not an existing class and therefore cannot be resolved', $id)
            );
        }

        foreach ($this->callbacks[$id] ?? [] as $vo) {
            $args = $this->reflectArguments($vo, [$id => $obj]);
            $temp = call_user_func($vo, ...$args);
            $obj =  is_null($temp) ? $obj : $temp;
            if (!is_a($obj, $id)) {
                throw new ContainerException(sprintf(
                    'return value must be subof `%s`',
                    $id,
                ));
            }
        }

        $this->caches[$id] = $obj;

        return $obj;
    }

    public function set(string $id, Closure $fn): self
    {
        $reflector = new ReflectionFunction($fn);
        $params = $reflector->getParameters();

        $find = false;
        foreach ($params as $param) {
            $type = $param->getType();
            if (null === $type) {
                continue;
            }
            if ($type->getName() === $id) {
                $find = true;
                break;
            }
        }

        if ($find) {
            $this->callbacks[$id][] = $fn;
        } else {
            $this->items[$id] = $fn;
        }
        unset($this->caches[$id]);

        return $this;
    }

    public function setArgument(string $id, array $args): self
    {
        $this->args[$id] = $args;
        unset($this->caches[$id]);
        return $this;
    }

    public function reflectArguments($callable, array $default = []): array
    {
        if (is_array($callable) && is_string($callable[0]) && class_exists($callable[0])) {
            $reflector = new ReflectionClass($callable[0]);
            $params = $reflector->getMethod($callable[1])->getParameters();
        } else {
            $reflector = new ReflectionFunction(Closure::fromCallable($callable));
            $params = $reflector->getParameters();
        }

        $res = [];
        foreach ($params as $param) {
            $res[] = $this->getParam($param, $default);
        }
        return $res;
    }

    private function getParam(ReflectionParameter $param, array $default = [])
    {
        if (isset($default[$param->getName()])) {
            return $default[$param->getName()];
        }

        $type = $param->getType();
        if ($type !== null) {
            $type_name = $type->getName();

            if (!$type->isBuiltin()) {
                if (isset($default[$type_name])) {
                    return $default[$type_name];
                }
                if ($this->has($type_name)) {
                    $result = $this->get($type_name);
                    if ($result instanceof $type_name) {
                        return $result;
                    }
                }
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->isOptional()) {
            return null;
        }

        throw new ContainerException(sprintf(
            'Unable to resolve a value for parameter `$%s` at %s on line %s-%s',
            $param->getName(),
            $param->getDeclaringFunction()->getFileName(),
            $param->getDeclaringFunction()->getStartLine(),
            $param->getDeclaringFunction()->getEndLine(),
        ));
    }
}

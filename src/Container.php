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
    private $aliass = [];
    private $args = [];
    private $callbacks = [];
    private $objs = [];

    public function has(string $id): bool
    {
        $id = $this->getTrueId($id);
        return class_exists($id);
    }

    public function get(string $id, bool $new = false)
    {
        $id = $this->getTrueId($id);

        if ($new) {
            if (!class_exists($id)) {
                throw new NotFoundException(
                    sprintf('`%s` is not an existing class and therefore cannot be resolved', $id)
                );
            }
            if (isset($this->callbacks[$id])) {
                $args = $this->reflectArguments($this->callbacks[$id]);
                $obj = call_user_func($this->callbacks[$id], ...$args);
                if (!is_a($obj, $id)) {
                    throw new ContainerException(sprintf(
                        'return value must be instanseof `%s`',
                        $id,
                    ));
                }
            } else {
                $reflector = new ReflectionClass($id);
                $args = $reflector->getConstructor() === null ? [] : $this->reflectArguments([$id, '__construct'], $this->args[$id] ?? []);
                $obj = $reflector->newInstanceArgs($args);
            }
            return $obj;
        } else {
            if (array_key_exists($id, $this->objs)) {
                return $this->objs[$id];
            } else {
                if (!class_exists($id)) {
                    throw new NotFoundException(
                        sprintf('`%s` is not an existing class and therefore cannot be resolved', $id)
                    );
                }

                if (isset($this->callbacks[$id])) {
                    $args = $this->reflectArguments($this->callbacks[$id]);
                    $obj = call_user_func($this->callbacks[$id], ...$args);
                    if (!is_a($obj, $id)) {
                        throw new ContainerException(sprintf(
                            'return value must be instanseof `%s`',
                            $id,
                        ));
                    }
                } else {
                    $reflector = new ReflectionClass($id);
                    $args = $reflector->getConstructor() === null ? [] : $this->reflectArguments([$id, '__construct'], $this->args[$id] ?? []);
                    $obj = $reflector->newInstanceArgs($args);
                }

                $this->objs[$id] = $obj;
                return $obj;
            }
        }
    }

    public function setAlias(string $from, string $to): self
    {
        $this->aliass[$from] = $to;
        if (isset($this->objs[$from])) {
            $this->objs[$to] = $this->objs[$from];
            unset($this->objs[$from]);
        }
        if (isset($this->args[$from])) {
            $this->args[$to] = $this->args[$from];
            unset($this->args[$from]);
        }
        if (isset($this->callbacks[$from])) {
            $this->callbacks[$to] = $this->callbacks[$from];
            unset($this->callbacks[$from]);
        }
        $id = $this->getTrueId($to);
        if ($id != $to) {
            if (isset($this->objs[$to])) {
                $this->objs[$id] = $this->objs[$to];
                unset($this->objs[$to]);
            }
            if (isset($this->args[$to])) {
                $this->args[$id] = $this->args[$to];
                unset($this->args[$to]);
            }
            if (isset($this->callbacks[$to])) {
                $this->callbacks[$id] = $this->callbacks[$to];
                unset($this->callbacks[$to]);
            }
        }
        return $this;
    }

    public function setArguments(string $id, array $args): self
    {
        $id = $this->getTrueId($id);
        $this->args[$id] = $args;
        unset($this->objs[$id]);
        return $this;
    }

    public function set(string $id, object $object)
    {
        $id = $this->getTrueId($id);
        if ($object instanceof Closure) {
            $this->callbacks[$id] = $object;
        } else {
            if (!is_a($object, $id)) {
                throw new ContainerException(sprintf(
                    'the param $obj_or_callable must be instanseof `%s`',
                    $id,
                ));
            }
            $this->objs[$id] = $object;
        }
    }

    private function getTrueId(string $id, string $from = null): string
    {
        if ($id === $from) {
            throw new ContainerException(sprintf(
                'Unable to resolve `%s` alias endless loop',
                $from,
            ));
        }
        if (isset($this->aliass[$id])) {
            return $this->getTrueId($this->aliass[$id], is_null($from) ? $id : $from);
        }
        return $id;
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
            $res[] = $default[$param->getName()] ?? $this->getParam($param);
        }
        return $res;
    }

    private function getParam(ReflectionParameter $param)
    {
        $type = $param->getType();
        if ($type !== null) {
            $type_name = $type->getName();

            if (!$type->isBuiltin()) {
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

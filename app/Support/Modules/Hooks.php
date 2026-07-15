<?php

namespace App\Support\Modules;

// The actions/filters bus (WordPress-style). Modules register listeners in their service provider.
class Hooks
{
    /** @var array<string, list<callable>> */
    protected array $listeners = [];

    public function listen(string $hook, callable $cb): void
    {
        $this->listeners[$hook][] = $cb;
    }

    public function fire(string $hook, mixed ...$args): void
    {
        foreach ($this->listeners[$hook] ?? [] as $cb) {
            $cb(...$args);
        }
    }

    public function filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->listeners[$hook] ?? [] as $cb) {
            $value = $cb($value, ...$args);
        }

        return $value;
    }
}

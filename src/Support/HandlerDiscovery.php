<?php

declare(strict_types=1);

namespace TransistorizedCmd\StripeToolkit\Webhooks\Support;

use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use TransistorizedCmd\StripeToolkit\Webhooks\Attributes\StripeEvent;

class HandlerDiscovery
{
    /** @var array<string,array<int,class-string>>|null */
    private ?array $cache = null;

    /**
     * Resolve handler class names for a given event type. Looks up both
     * the raw event type Stripe sent and its normalized form (with the
     * leading `v1.` / `v2.core.` stripped) so a handler registered as
     * `customer.created` matches both snapshot and thin variants.
     *
     * @return array<int,class-string>
     */
    public function for(string $eventType): array
    {
        $map = $this->build();
        $normalized = TypeNormalizer::normalize($eventType);

        $matches = array_merge(
            $map[$eventType] ?? [],
            $eventType !== $normalized ? ($map[$normalized] ?? []) : [],
        );

        return array_values(array_unique($matches));
    }

    /**
     * @return array<string,array<int,class-string>>
     */
    public function build(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $map = [];

        foreach ((array) config('stripe-webhooks.handlers', []) as $type => $handlers) {
            foreach ((array) $handlers as $handler) {
                $map[$type][] = $handler;
            }
        }

        if (config('stripe-webhooks.discover_attributes', true)) {
            foreach ($this->discoverFromAttributes() as $type => $handlers) {
                foreach ($handlers as $handler) {
                    $map[$type][] = $handler;
                }
            }
        }

        return $this->cache = $map;
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string,array<int,class-string>>
     */
    protected function discoverFromAttributes(): array
    {
        $path = config('stripe-webhooks.discover_path');

        if ($path === null && function_exists('app_path')) {
            $path = app_path('Stripe/Handlers');
        }

        if (! is_string($path) || ! is_dir($path)) {
            return [];
        }

        $map = [];

        foreach ((new Finder)->files()->in($path)->name('*.php') as $file) {
            $class = $this->classFromFile($file);
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            foreach ($reflection->getAttributes(StripeEvent::class) as $attribute) {
                /** @var StripeEvent $instance */
                $instance = $attribute->newInstance();
                $map[$instance->type][] = $class;
            }
        }

        return $map;
    }

    protected function classFromFile(SplFileInfo $file): ?string
    {
        $contents = (string) file_get_contents($file->getRealPath());

        if (! preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatch)) {
            return null;
        }

        if (! preg_match('/^(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $classMatch)) {
            return null;
        }

        return trim($nsMatch[1]).'\\'.$classMatch[1];
    }
}

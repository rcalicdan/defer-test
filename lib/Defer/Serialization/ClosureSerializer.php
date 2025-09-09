<?php

namespace Library\Defer\Serialization;

/**
 * Serializes closure callbacks using opis/closure
 */
class ClosureSerializer implements CallbackSerializerInterface
{
    private bool $opisCleusureAvailable;

    public function __construct()
    {
        $this->opisCleusureAvailable = class_exists('Opis\\Closure\\SerializableClosure');
    }

    public function canSerialize(callable $callback): bool
    {
        return $callback instanceof \Closure && $this->opisCleusureAvailable;
    }

    public function serialize(callable $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Cannot serialize closure - requires opis/closure package');
        }

        try {
            $wrapper = new \Opis\Closure\SerializableClosure($callback);
            $serialized = serialize($wrapper);
            return sprintf('unserialize(%s)', var_export($serialized, true));
        } catch (\Throwable $e) {
            throw new SerializationException('Failed to serialize closure: ' . $e->getMessage(), $e);
        }
    }

    public function getPriority(): int
    {
        return 80;
    }
}
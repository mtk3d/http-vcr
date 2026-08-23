<?php

declare(strict_types=1);

namespace HttpVcr\Bridge\PHPUnit;

use HttpVcr\Config;
use HttpVcr\Persistence\FilesystemCassettePersister;
use HttpVcr\VcrClient;
use Psr\Http\Client\ClientInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Turns what a test declared into the client it will use (§3.12).
 *
 * One place for the mapping, because there are two ways in — the attribute the extension
 * reads, and the trait's closure form — and both have to build the same thing.
 *
 * @internal
 */
final class CassetteFactory
{
    public function open(UseCassette $cassette, ?string $directory = null): VcrClient
    {
        $warnings = RunWarnings::current();

        return new VcrClient(
            new DeferredClient(static fn (): ClientInterface => Config::global()->innerClient()),
            $cassette->name,
            $cassette->mode,
            strictMode: $cassette->strictMode,
            staleAfter: $cassette->staleAfter,
            requiresEnv: $cassette->requiresEnv,
            locked: $cassette->locked,
            persister: $directory === null ? null : new FilesystemCassettePersister($directory),
            warn: $warnings === null ? null : $warnings->report(...),
        );
    }

    /**
     * The cassette this test declared, if it declared one: the method's own attribute,
     * then the class chain. A method-level attribute replaces a class-level one outright
     * rather than merging with it.
     *
     * @param  class-string  $class
     */
    public function declaredBy(string $class, string $method): ?UseCassette
    {
        try {
            $declared = $this->attribute((new ReflectionMethod($class, $method))->getAttributes(UseCassette::class));
        } catch (ReflectionException) {
            return null;
        }

        return $declared ?? $this->upTheChain($class, UseCassette::class);
    }

    /**
     * The directory this class's cassettes live in, looked for up the inheritance chain
     * because PHP does not carry attributes to a subclass on its own.
     *
     * @param  class-string  $class
     */
    public function directoryFor(string $class): ?string
    {
        return $this->upTheChain($class, CassetteDirectory::class)?->path;
    }

    /**
     * @template T of object
     *
     * @param  class-string  $class
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    private function upTheChain(string $class, string $attribute): ?object
    {
        $reflection = new ReflectionClass($class);

        while ($reflection !== false) {
            $found = $this->attribute($reflection->getAttributes($attribute));

            if ($found !== null) {
                return $found;
            }

            $reflection = $reflection->getParentClass();
        }

        return null;
    }

    /**
     * @template T of object
     *
     * @param  list<ReflectionAttribute<T>>  $attributes
     * @return T|null
     */
    private function attribute(array $attributes): ?object
    {
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}

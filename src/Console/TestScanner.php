<?php

declare(strict_types=1);

namespace HttpVcr\Console;

use DateInterval;
use Exception;
use HttpVcr\Bridge\PHPUnit\CassetteDirectory;
use HttpVcr\Bridge\PHPUnit\UseCassette;
use HttpVcr\CassetteDirectoryMap;
use HttpVcr\Config;
use HttpVcr\RecordMode;
use HttpVcr\Stale;
use HttpVcr\StrictMode;
use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Error as ParseError;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads `#[UseCassette]` out of a project's test files without running them (§3.12).
 *
 * Loading the classes to reflect over them would mean every test file having to be
 * loadable — its parents on the autoloader, its constants defined, its environment sane —
 * which is the situation the CLI exists to stay out of. So it parses the syntax instead,
 * and the ceiling on what an argument may be follows from that: literals and arrays of
 * them, enum cases, `__DIR__`, and `new DateInterval(...)`, which is what `staleAfter`
 * looks like in practice. Anything computed is reported, never guessed.
 *
 * The scan reads every `.php` file under the configured directories, not only `*Test.php`:
 * `#[CassetteDirectory]` belongs on a module's base test case, and a base case is
 * conventionally named `…TestCase.php`, which that pattern misses. What decides whether a
 * class is a test here is the same thing PHPUnit uses at run time — a concrete class with
 * public test methods — not the name of the file it sits in.
 */
final class TestScanner
{
    /**
     * Constructor order of the two attributes, for arguments written without a name.
     */
    private const POSITIONAL = [
        UseCassette::class => ['name', 'mode', 'strictMode', 'staleAfter', 'requiresEnv', 'locked'],
        CassetteDirectory::class => ['path'],
    ];

    private readonly Parser $parser;

    /** @var list<string> */
    private array $unanalyzed = [];

    private ?CassetteDirectoryMap $map = null;

    /**
     * @param  list<string>  $directories  where the project keeps its tests — normally
     *                                     {@see Config::testDirectories()}
     */
    public function __construct(
        private readonly array $directories,
        private readonly ?CassetteDirectoryMap $cassetteDirectories = null,
    ) {
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    public function scan(): ScannedTests
    {
        $this->unanalyzed = [];

        $classes = [];

        foreach ($this->files() as $file) {
            foreach ($this->classesIn($file) as $class) {
                $classes[$class->name] = $class;
            }
        }

        $declarations = [];

        foreach ($classes as $class) {
            if ($class->abstract) {
                continue;
            }

            $inherited = $this->inherited($class, $classes, static fn (ScannedClass $found): ?UseCassette => $found->cassette);
            $directory = $this->inherited($class, $classes, static fn (ScannedClass $found): ?string => $found->directory)
                ?? $this->cassetteDirectoryMap()->directoryFor($class->file);

            foreach ($this->methodsOf($class, $classes) as $method => $own) {
                $declared = $own ?? $inherited;

                if ($declared === null) {
                    continue;
                }

                $declarations[] = new CassetteDeclaration(
                    $class->name,
                    $method,
                    $declared,
                    $directory,
                    $class->file,
                    $class->line,
                );
            }
        }

        return new ScannedTests($declarations, $this->unanalyzed);
    }

    /**
     * The class's own test methods first, then whatever it inherits and did not override —
     * an inherited test runs under the subclass's name, so it belongs to the subclass here
     * too.
     *
     * @param  array<string, ScannedClass>  $classes
     * @return array<string, UseCassette|null>
     */
    private function methodsOf(ScannedClass $class, array $classes): array
    {
        $methods = $class->methods;
        $seen = [$class->name => true];
        $parent = $class->parent;

        while ($parent !== null && isset($classes[$parent]) && ! isset($seen[$parent])) {
            $seen[$parent] = true;

            foreach ($classes[$parent]->methods as $name => $declared) {
                $methods[$name] ??= $declared;
            }

            $parent = $classes[$parent]->parent;
        }

        return $methods;
    }

    /**
     * PHP does not carry a class attribute down to a subclass, so both `#[UseCassette]` on
     * a base test case and `#[CassetteDirectory]` are looked for up the chain, first one
     * wins — the same walk the PHPUnit bridge does by reflection.
     *
     * @template T
     *
     * @param  array<string, ScannedClass>  $classes
     * @param  callable(ScannedClass): ?T  $read
     * @return T|null
     */
    private function inherited(ScannedClass $class, array $classes, callable $read): mixed
    {
        $seen = [];
        $current = $class;

        while (true) {
            $found = $read($current);

            if ($found !== null) {
                return $found;
            }

            $seen[$current->name] = true;
            $parent = $current->parent;

            if ($parent === null || ! isset($classes[$parent]) || isset($seen[$parent])) {
                return null;
            }

            $current = $classes[$parent];
        }
    }

    /**
     * @return list<ScannedClass>
     */
    private function classesIn(string $file): array
    {
        $code = @file_get_contents($file);

        if ($code === false) {
            $this->note($file, 0, 'could not be read');

            return [];
        }

        try {
            $statements = $this->parser->parse($code);
        } catch (ParseError $error) {
            $this->note($file, $error->getStartLine(), 'could not be parsed: '.$error->getRawMessage());

            return [];
        }

        if ($statements === null) {
            $this->note($file, 0, 'could not be parsed');

            return [];
        }

        $statements = (new NodeTraverser(new NameResolver))->traverse($statements);

        $classes = [];

        foreach ((new NodeFinder)->findInstanceOf($statements, Class_::class) as $node) {
            $name = $node->namespacedName?->toString();

            if ($name === null) {
                continue;
            }

            $classes[] = new ScannedClass(
                $name,
                $node->extends?->toString(),
                $node->isAbstract(),
                $file,
                $node->getStartLine(),
                $this->useCassette($node->attrGroups, $file, $name),
                $this->cassetteDirectory($node->attrGroups, $file, $name),
                $this->testMethods($node, $file, $name),
            );
        }

        return $classes;
    }

    /**
     * @return array<string, UseCassette|null>
     */
    private function testMethods(Class_ $node, string $file, string $class): array
    {
        $methods = [];

        foreach ($node->getMethods() as $method) {
            if ($this->isTest($method)) {
                $methods[$method->name->toString()] = $this->useCassette($method->attrGroups, $file, $class);
            }
        }

        return $methods;
    }

    /**
     * What PHPUnit itself will run: a public method named `test…`, or one carrying
     * `#[Test]`. The attribute is matched on its short name, since a project may import it
     * under an alias and the scan has no interest in which PHPUnit version defines it.
     */
    private function isTest(ClassMethod $method): bool
    {
        if (! $method->isPublic() || $method->isAbstract()) {
            return false;
        }

        if (str_starts_with($method->name->toString(), 'test')) {
            return true;
        }

        foreach ($this->attributes($method->attrGroups) as $attribute) {
            if ($attribute->name->getLast() === 'Test') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<AttributeGroup>  $groups
     */
    private function useCassette(array $groups, string $file, string $class): ?UseCassette
    {
        $node = $this->attributeNamed($groups, UseCassette::class);

        if ($node === null) {
            return null;
        }

        $arguments = $this->arguments($node, UseCassette::class, $file, $class);

        $name = $arguments['name'] ?? null;

        if (! is_string($name)) {
            return null;
        }

        $mode = $arguments['mode'] ?? RecordMode::RecordIfAbsent;
        $strictMode = $arguments['strictMode'] ?? null;
        $staleAfter = $arguments['staleAfter'] ?? null;
        $requiresEnv = $arguments['requiresEnv'] ?? [];
        $locked = $arguments['locked'] ?? false;

        return new UseCassette(
            $name,
            $mode instanceof RecordMode ? $mode : RecordMode::RecordIfAbsent,
            $strictMode instanceof StrictMode ? $strictMode : null,
            $staleAfter instanceof DateInterval || $staleAfter instanceof Stale ? $staleAfter : null,
            $this->strings($requiresEnv),
            $locked === true,
        );
    }

    /**
     * @param  array<AttributeGroup>  $groups
     */
    private function cassetteDirectory(array $groups, string $file, string $class): ?string
    {
        $node = $this->attributeNamed($groups, CassetteDirectory::class);

        if ($node === null) {
            return null;
        }

        $path = $this->arguments($node, CassetteDirectory::class, $file, $class)['path'] ?? null;

        return is_string($path) ? $path : null;
    }

    /**
     * Every argument the attribute was written with, keyed by parameter name, minus the
     * ones that turned out not to be constant expressions — those leave a note instead, so
     * a report can say the threshold is unknown rather than absent.
     *
     * @param  class-string  $attribute
     * @return array<string, mixed>
     */
    private function arguments(Attribute $node, string $attribute, string $file, string $class): array
    {
        $evaluator = $this->evaluator($file, $class);
        $arguments = [];

        foreach ($node->args as $position => $argument) {
            $name = $this->parameterName($argument, $attribute, $position);

            if ($name === null) {
                $this->note($file, $argument->getStartLine(), sprintf(
                    '#[%s] has more arguments than the attribute takes',
                    $node->name->getLast(),
                ));

                continue;
            }

            try {
                $arguments[$name] = $evaluator->evaluateSilently($argument->value);
            } catch (ConstExprEvaluationException) {
                $this->note($file, $argument->getStartLine(), sprintf(
                    '#[%s] argument "%s" is not a constant expression, so the scan could not read it',
                    $node->name->getLast(),
                    $name,
                ));
            }
        }

        return $arguments;
    }

    /**
     * @param  class-string  $attribute
     */
    private function parameterName(Arg $argument, string $attribute, int $position): ?string
    {
        if ($argument->name !== null) {
            return $argument->name->toString();
        }

        return self::POSITIONAL[$attribute][$position] ?? null;
    }

    /**
     * The constant-expression evaluator php-parser ships, plus the three things an
     * attribute in a test file actually uses beyond it: `__DIR__` (resolved against the
     * file the attribute is written in, not the class that inherits it), enum cases and
     * class constants, and `new DateInterval(...)`.
     */
    private function evaluator(string $file, string $class): ConstExprEvaluator
    {
        $evaluator = null;

        $evaluator = new ConstExprEvaluator(function (Expr $expression) use ($file, $class, &$evaluator): mixed {
            if ($expression instanceof MagicConst\Dir) {
                return dirname($file);
            }

            if ($expression instanceof MagicConst\File) {
                return $file;
            }

            if ($expression instanceof MagicConst\Class_) {
                return $class;
            }

            if ($expression instanceof ClassConstFetch) {
                return $this->classConstant($expression);
            }

            if ($expression instanceof New_ && $evaluator instanceof ConstExprEvaluator) {
                return $this->instantiate($expression, $evaluator);
            }

            throw new ConstExprEvaluationException(sprintf(
                'Expression of type %s cannot be evaluated',
                $expression->getType(),
            ));
        });

        return $evaluator;
    }

    private function classConstant(ClassConstFetch $expression): mixed
    {
        $class = $expression->class;
        $name = $expression->name;

        if (! $class instanceof Name || ! $name instanceof Identifier) {
            throw new ConstExprEvaluationException('A dynamic class constant cannot be evaluated');
        }

        if ($name->toString() === 'class') {
            return $class->toString();
        }

        $constant = $class->toString().'::'.$name->toString();

        if (! defined($constant)) {
            throw new ConstExprEvaluationException(sprintf('%s is not defined here', $constant));
        }

        return constant($constant);
    }

    /**
     * Only `DateInterval`: it is the one class an attribute argument in this library is
     * ever written as, and instantiating whatever a test file names would mean running the
     * project's own constructors from a command that promises not to.
     */
    private function instantiate(New_ $expression, ConstExprEvaluator $evaluator): DateInterval
    {
        $class = $expression->class;

        if (! $class instanceof Name || $class->toString() !== DateInterval::class) {
            throw new ConstExprEvaluationException('Only new DateInterval(...) can be evaluated');
        }

        $arguments = [];

        foreach ($expression->args as $argument) {
            if (! $argument instanceof Arg) {
                throw new ConstExprEvaluationException('new DateInterval(...) cannot be evaluated from a spread');
            }

            $arguments[] = $evaluator->evaluateSilently($argument->value);
        }

        $duration = $arguments[0] ?? null;

        if (! is_string($duration)) {
            throw new ConstExprEvaluationException('new DateInterval(...) takes a duration string');
        }

        try {
            return new DateInterval($duration);
        } catch (Exception $error) {
            throw new ConstExprEvaluationException($error->getMessage(), 0, $error);
        }
    }

    /**
     * @param  array<AttributeGroup>  $groups
     */
    private function attributeNamed(array $groups, string $type): ?Attribute
    {
        foreach ($this->attributes($groups) as $attribute) {
            if ($attribute->name->toString() === $type) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * @param  array<AttributeGroup>  $groups
     * @return list<Attribute>
     */
    private function attributes(array $groups): array
    {
        $attributes = [];

        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributes[] = $attribute;
            }
        }

        return $attributes;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $strings[] = $entry;
            }
        }

        return $strings;
    }

    /**
     * The project's path-to-directory map, read once per scan. Taken from the global
     * configuration unless one was handed in, the way every other command-side default is.
     */
    private function cassetteDirectoryMap(): CassetteDirectoryMap
    {
        return $this->map ??= $this->cassetteDirectories ?? Config::global()->cassetteDirectories();
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [];

        foreach ($this->directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($entries as $entry) {
                if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function note(string $file, int $line, string $reason): void
    {
        $this->unanalyzed[] = $line > 0
            ? sprintf('%s:%d %s', $file, $line, $reason)
            : sprintf('%s %s', $file, $reason);
    }
}

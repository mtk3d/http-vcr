<?php

declare(strict_types=1);

namespace HttpVcr\Serializer;

use HttpVcr\Exception\CassetteFormatException;
use HttpVcr\Exception\MissingDependencyException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The same cassette schema written as YAML, for a project that would rather read one
 * (§3.2, decision 2).
 *
 * What it buys is a body with newlines in it: JSON has to escape them onto one line, YAML
 * writes it as a literal block, so an HTML or XML response stays readable in a diff. What
 * it costs is `symfony/yaml`, which is why JSON stays the default and this is opt-in —
 * passed as `serializer:` to `VcrClient` or set once in `http-vcr.php`.
 *
 * A cassette does not change meaning across the two: same fields, same defaults left out,
 * same base64 for bytes text cannot hold.
 */
final class YamlCassetteSerializer extends ArrayCassetteSerializer
{
    /**
     * Deep enough that nothing in a cassette collapses onto one line: the deepest nesting
     * is a header value inside a list inside the header map, and inline flow style there
     * would undo the reason for choosing YAML.
     */
    private const EXPAND_TO_DEPTH = 10;

    public function __construct()
    {
        if (! class_exists(Yaml::class)) {
            throw MissingDependencyException::noYaml();
        }
    }

    public function fileExtension(): string
    {
        return 'yaml';
    }

    protected function encode(array $data): string
    {
        return Yaml::dump($data, self::EXPAND_TO_DEPTH, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    protected function decode(string $content): array
    {
        try {
            $data = Yaml::parse($content);
        } catch (ParseException $exception) {
            throw CassetteFormatException::malformed('is not valid YAML ('.$exception->getMessage().')');
        }

        if (! is_array($data)) {
            throw CassetteFormatException::malformed('is not a YAML mapping');
        }

        return $data;
    }
}

<?php

declare(strict_types=1);

use HttpVcr\Config;
use HttpVcr\Serializer\JsonCassetteSerializer;

/**
 * This repository's own configuration — the library testing itself, not an example to copy.
 *
 * The cassette format is pinned because symfony/yaml is a dev dependency here, so the
 * default would resolve to YAML (§7 decision 2), while the suite's fixtures and the
 * cassettes committed under tests/Integration/Cassettes/ are JSON, and a good many tests
 * read the file that landed on disk to assert what was written into it.
 *
 * The report about interactions nothing replayed (§7 decision 70) is off for the same kind
 * of reason: dozens of tests here replay one interaction out of a cassette on purpose, and
 * every one of them would print a warning about doing what it was written to do. The tests
 * that are about the report turn it back on for themselves.
 */
return Config::create(
    serializer: new JsonCassetteSerializer,
    reportUnplayedInteractions: false,
);

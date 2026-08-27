<?php

declare(strict_types=1);

use HttpVcr\Config;
use HttpVcr\Serializer\JsonCassetteSerializer;

/**
 * This repository's own configuration — the library testing itself, not an example to copy.
 *
 * The one thing it declares is the cassette format. Since symfony/yaml is a dev dependency
 * here, the default would resolve to YAML (§7 decision 2), while the suite's fixtures and
 * the cassettes committed under tests/Integration/Cassettes/ are JSON, and a good many
 * tests read the file that landed on disk to assert what was written into it. Pinning the
 * format states that outright instead of letting it follow the vendor directory.
 */
return Config::create(
    serializer: new JsonCassetteSerializer,
);

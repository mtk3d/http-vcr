<?php

declare(strict_types=1);

namespace HttpVcr\Hook;

/**
 * What a redaction rule looks for: a literal value anywhere in the interaction, or one
 * named field in a particular place.
 *
 * @internal
 */
enum RedactionTarget
{
    case Value;
    case Header;
    case JsonField;
    case QueryParam;
    case FormField;
}

<?php

declare(strict_types=1);

namespace HttpVcr\Hook;

use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use HttpVcr\JsonPointer;

/**
 * Keeps credentials out of a cassette, and puts them back on the way out (§3.4).
 *
 * Not a mechanism of its own: this is one pair of {@see HookRegistry} hooks, registered
 * ahead of anything a project adds itself. What it does at each end:
 *
 * - **recording**: the real value becomes a placeholder, in the request *and* the
 *   response, including the message of a recorded transport failure — HTTP client
 *   exceptions routinely quote the full URL, query string and all
 * - **replaying**: the placeholder becomes the real value again, but only for rules that
 *   were given a way to produce one. Applied before the matchers compare (so a recorded
 *   request can be matched against a live one at all) and before the response reaches the
 *   code under test (so it receives a usable token rather than the string `<REDACTED-…>`)
 *
 * A rule without a value provider is write-only, and the field it covers therefore stops
 * telling two interactions apart — {@see forMatching()} is what keeps that from breaking
 * replay outright.
 */
final class RedactionHooks
{
    /**
     * The headers redacted with no configuration at all. They almost always carry a
     * credential and almost never carry what a test asserts on, so a false positive costs
     * nothing and a miss costs a token in git history, which is permanent.
     */
    public const SENSITIVE_HEADERS = ['Authorization', 'Proxy-Authorization', 'Cookie', 'Set-Cookie'];

    /** @var list<Redaction> */
    private array $rules = [];

    /** @var list<string> */
    private array $included = [];

    /**
     * Replaces a literal value with $placeholder wherever it occurs in an interaction.
     *
     * @param  callable(): mixed  $value  the real value, read when it is needed rather than
     *                                    when the rule is declared
     */
    public function redact(string $placeholder, callable $value): void
    {
        $this->rules[] = Redaction::of(RedactionTarget::Value, '', $placeholder, $value);
    }

    /**
     * @param  (callable(): mixed)|null  $value  without it the rule is write-only: the header
     *                                           goes to disk redacted and nothing can restore
     *                                           it, so it also stops distinguishing
     *                                           interactions for matching
     */
    public function redactHeader(string $name, ?callable $value = null): void
    {
        $this->rules[] = Redaction::of(RedactionTarget::Header, $name, Redaction::placeholderFor($name), $value);
    }

    /**
     * @param  string  $pointer  a JSON Pointer: `/customer/email`
     * @param  (callable(): mixed)|null  $value
     */
    public function redactJsonField(string $pointer, ?callable $value = null): void
    {
        $this->rules[] = Redaction::of(RedactionTarget::JsonField, $pointer, Redaction::placeholderFor($pointer), $value);
    }

    /**
     * @param  (callable(): mixed)|null  $value
     */
    public function redactQueryParam(string $name, ?callable $value = null): void
    {
        $this->rules[] = Redaction::of(RedactionTarget::QueryParam, $name, Redaction::placeholderFor($name), $value);
    }

    /**
     * @param  (callable(): mixed)|null  $value
     */
    public function redactFormField(string $name, ?callable $value = null): void
    {
        $this->rules[] = Redaction::of(RedactionTarget::FormField, $name, Redaction::placeholderFor($name), $value);
    }

    /**
     * Takes one of the automatically redacted headers back out of redaction, for a test
     * that verifies the authorization header itself. It returns to the pool matching looks
     * at as well — one deliberate decision rather than two settings that have to agree.
     *
     * @param  list<string>  $names
     */
    public function includeSensitiveHeaders(array $names): void
    {
        foreach ($names as $name) {
            $this->included[] = $name;
        }
    }

    /**
     * The record-direction hook: real values out, placeholders in.
     */
    public function beforeRecord(Interaction $interaction): Interaction
    {
        return $this->apply($interaction, $this->all(), restore: false);
    }

    /**
     * The playback-direction hook: placeholders back to real values, for the rules that
     * know one.
     */
    public function beforePlayback(Interaction $interaction): Interaction
    {
        return $this->apply($interaction, $this->twoWay(), restore: true);
    }

    /**
     * The incoming request as the matchers should see it: run through the write-only rules,
     * so that a field the cassette holds as `<REDACTED-…>` is a placeholder on both sides
     * rather than a placeholder against a live token that could never match (§3.3).
     *
     * Two-way rules are deliberately absent — those are handled from the other end, by
     * restoring the recorded request before the comparison.
     */
    public function forMatching(RecordedRequest $request): RecordedRequest
    {
        $rules = $this->oneWay();

        return $rules === [] ? $request : $this->request($request, $rules, restore: false);
    }

    /**
     * @param  list<Redaction>  $rules
     */
    private function apply(Interaction $interaction, array $rules, bool $restore): Interaction
    {
        if ($rules === []) {
            return $interaction;
        }

        $interaction = $interaction->withRequest($this->request($interaction->request, $rules, $restore));

        if ($interaction->response !== null) {
            $interaction = $interaction->withResponse($this->response($interaction->response, $rules, $restore));
        }

        if ($interaction->error !== null) {
            $interaction = $interaction->withError(
                $interaction->error->withMessage($this->text($interaction->error->message, $rules, $restore)),
            );
        }

        return $interaction;
    }

    /**
     * @param  list<Redaction>  $rules
     */
    private function request(RecordedRequest $request, array $rules, bool $restore): RecordedRequest
    {
        return $request
            ->withHeaders($this->headers($request->headers, $rules, $restore))
            ->withUri($this->uri($request->uri, $rules, $restore))
            ->withBody(
                $this->body($request->body, $request->bodyEncoding, $request->header('Content-Type'), $rules, $restore),
                $request->bodyEncoding,
            );
    }

    /**
     * @param  list<Redaction>  $rules
     */
    private function response(RecordedResponse $response, array $rules, bool $restore): RecordedResponse
    {
        return $response
            ->withHeaders($this->headers($response->headers, $rules, $restore))
            ->withBody(
                $this->body($response->body, $response->bodyEncoding, $response->header('Content-Type'), $rules, $restore),
                $response->bodyEncoding,
            );
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @param  list<Redaction>  $rules
     * @return array<string, list<string>>
     */
    private function headers(array $headers, array $rules, bool $restore): array
    {
        foreach ($rules as $rule) {
            foreach ($headers as $name => $values) {
                $headers[$name] = match (true) {
                    $rule->target === RedactionTarget::Header && strcasecmp($name, $rule->name) === 0 => $this->replaceValues($values, $rule, $restore),
                    $rule->target === RedactionTarget::Value => array_map(fn (string $value): string => $this->literal($value, $rule, $restore), $values),
                    default => $values,
                };
            }
        }

        return $headers;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function replaceValues(array $values, Redaction $rule, bool $restore): array
    {
        return array_map(
            fn (string $value): string => $this->substitute($value, $rule, $restore) ?? $value,
            $values,
        );
    }

    /**
     * @param  list<Redaction>  $rules
     */
    private function uri(string $uri, array $rules, bool $restore): string
    {
        foreach ($rules as $rule) {
            $uri = match ($rule->target) {
                RedactionTarget::Value => $this->literal($uri, $rule, $restore),
                RedactionTarget::QueryParam => $this->queryParam($uri, $rule, $restore),
                default => $uri,
            };
        }

        return $uri;
    }

    /**
     * @param  list<string>  $contentType
     * @param  list<Redaction>  $rules
     */
    private function body(string $body, ?string $encoding, array $contentType, array $rules, bool $restore): string
    {
        // Bytes hold no fields and no readable secrets — and a substitution inside them
        // would corrupt the content it was meant to protect.
        if ($body === '' || $encoding !== null) {
            return $body;
        }

        $form = str_contains(strtolower($contentType[0] ?? ''), 'application/x-www-form-urlencoded');

        foreach ($rules as $rule) {
            $body = match ($rule->target) {
                RedactionTarget::Value => $this->literal($body, $rule, $restore),
                RedactionTarget::JsonField => $this->jsonField($body, $rule, $restore),
                RedactionTarget::FormField => $form ? $this->pairs($body, $rule, $restore) : $body,
                default => $body,
            };
        }

        return $body;
    }

    /**
     * @param  list<Redaction>  $rules
     */
    private function text(string $text, array $rules, bool $restore): string
    {
        foreach ($rules as $rule) {
            $text = match ($rule->target) {
                RedactionTarget::Value => $this->literal($text, $rule, $restore),
                RedactionTarget::QueryParam => $this->queryParamInText($text, $rule, $restore),
                default => $text,
            };
        }

        return $text;
    }

    private function queryParam(string $uri, Redaction $rule, bool $restore): string
    {
        $mark = strpos($uri, '?');

        if ($mark === false) {
            return $uri;
        }

        $rest = substr($uri, $mark + 1);
        $fragment = strpos($rest, '#');
        $query = $fragment === false ? $rest : substr($rest, 0, $fragment);

        return substr($uri, 0, $mark + 1)
            .$this->pairs($query, $rule, $restore)
            .($fragment === false ? '' : substr($rest, $fragment));
    }

    /**
     * A `name=value&…` list — a query string or a form-encoded body, which are the same
     * shape in two places. Order and repeated names are left exactly as they were.
     */
    private function pairs(string $encoded, Redaction $rule, bool $restore): string
    {
        $pairs = explode('&', $encoded);

        foreach ($pairs as $index => $pair) {
            $separator = strpos($pair, '=');

            if ($separator === false || urldecode(substr($pair, 0, $separator)) !== $rule->name) {
                continue;
            }

            $replacement = $this->substitute(urldecode(substr($pair, $separator + 1)), $rule, $restore);

            if ($replacement !== null) {
                // The placeholder goes in literally: percent-encoded it would be neither
                // readable in a diff nor recognizable to a secret scanner.
                $pairs[$index] = substr($pair, 0, $separator + 1)
                    .($restore ? rawurlencode($replacement) : $replacement);
            }
        }

        return implode('&', $pairs);
    }

    /**
     * The same substitution as in a URL, but inside free text — an exception message that
     * quoted the request URL.
     */
    private function queryParamInText(string $text, Redaction $rule, bool $restore): string
    {
        // The value runs to the next parameter or to whatever closed the URL — a message
        // quoting one usually wraps it in brackets or quotes, and swallowing those would
        // redact the punctuation along with the secret.
        $pattern = '/([?&]'.preg_quote($rule->name, '/').'=)([^&\s"\'<>)\]}]*)/';

        $replaced = preg_replace_callback($pattern, function (array $match) use ($rule, $restore): string {
            $replacement = $this->substitute(urldecode((string) $match[2]), $rule, $restore);

            return (string) $match[1].match (true) {
                $replacement === null => (string) $match[2],
                $restore => rawurlencode($replacement),
                default => $replacement,
            };
        }, $text);

        return $replaced ?? $text;
    }

    /**
     * A field inside a JSON body. The document is re-encoded, so a redacted body comes out
     * in canonical JSON rather than the exact bytes the API sent — the alternative is
     * editing JSON as a string, which is how one ends up redacting a field that happens to
     * share a name with something inside a string literal.
     */
    private function jsonField(string $body, Redaction $rule, bool $restore): string
    {
        $document = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $body;
        }

        $tokens = JsonPointer::tokens($rule->name);
        $current = JsonPointer::read($document, $tokens);

        if ($current === null) {
            return $body;
        }

        $value = $current[0];
        $replacement = $this->substitute(is_scalar($value) ? (string) $value : '', $rule, $restore);

        if ($replacement === null) {
            return $body;
        }

        $encoded = json_encode(
            JsonPointer::with($document, $tokens, $replacement),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        return $encoded === false ? $body : $encoded;
    }

    /**
     * A literal value anywhere in a string, in whichever direction is being applied. With
     * no provider there is nothing to search for, so the rule does nothing.
     */
    private function literal(string $text, Redaction $rule, bool $restore): string
    {
        $value = $rule->value();

        if ($value === null) {
            return $text;
        }

        return $restore
            ? str_replace($rule->placeholder, $value, $text)
            : str_replace($value, $rule->placeholder, $text);
    }

    /**
     * @return string|null null meaning "leave this one alone"
     */
    private function substitute(string $current, Redaction $rule, bool $restore): ?string
    {
        if (! $restore) {
            return $current === $rule->placeholder ? null : $rule->placeholder;
        }

        $value = $rule->value();

        return $value !== null && $current === $rule->placeholder ? $value : null;
    }

    /**
     * Every rule in force, automatic redaction first: a rule nobody had to ask for should
     * not depend on what else was declared.
     *
     * @return list<Redaction>
     */
    private function all(): array
    {
        $automatic = [];

        foreach (self::SENSITIVE_HEADERS as $name) {
            if (! $this->isIncluded($name)) {
                $automatic[] = Redaction::of(RedactionTarget::Header, $name, Redaction::placeholderFor($name));
            }
        }

        return array_merge($automatic, $this->rules);
    }

    private function isIncluded(string $name): bool
    {
        foreach ($this->included as $included) {
            if (strcasecmp($included, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Redaction>
     */
    private function twoWay(): array
    {
        return array_values(array_filter($this->all(), static fn (Redaction $rule): bool => $rule->isTwoWay()));
    }

    /**
     * Automatic redaction is among these: the library never knew those headers' real
     * values, so lining both sides up is the only way a request carrying one can still be
     * matched against a cassette that holds a placeholder.
     *
     * @return list<Redaction>
     */
    private function oneWay(): array
    {
        return array_values(array_filter($this->all(), static fn (Redaction $rule): bool => ! $rule->isTwoWay()));
    }
}

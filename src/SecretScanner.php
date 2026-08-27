<?php

declare(strict_types=1);

namespace HttpVcr;

use HttpVcr\Cassette\Interaction;
use HttpVcr\Cassette\RecordedRequest;
use HttpVcr\Cassette\RecordedResponse;
use stdClass;

/**
 * "Does this look like a credential?" — the heuristic behind the warning printed after a
 * recording session and behind the `scan-secrets` command (§3.4/§3.12).
 *
 * Everything but the four automatically redacted headers is opt-in, so the first cassette
 * recorded by someone who hasn't configured `redact()` yet is exactly where a token
 * reaches a repository. This is the net under that.
 */
final class SecretScanner
{
    /**
     * Shapes that are credentials wherever they appear, including in the middle of prose —
     * an exception message quoting a request, say.
     */
    private const PATTERNS = [
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+/',
        '/\b[a-z]{2,4}_(?:live|test)_[A-Za-z0-9]{8,}/',
        '/\bgh[pousr]_[A-Za-z0-9]{16,}/',
        '/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
        '/\bAIza[0-9A-Za-z_-]{35}\b/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/\b[Bb]earer\s+[A-Za-z0-9._\-+\/=]{8,}/',
        '/\b[Bb]asic\s+[A-Za-z0-9+\/=]{12,}/',
    ];

    /**
     * Field names that make an ordinary-looking value suspicious: whatever sits under
     * `client_secret` is a credential whether or not it looks like one.
     */
    private const CREDENTIAL_NAMES = '/key|token|secret|password|passwd|auth|credential|signature|session|cookie/i';

    /**
     * @return list<SecretFinding>
     */
    public function scan(Interaction $interaction): array
    {
        $findings = [];

        $this->scanRequest($interaction->request, $findings);

        if ($interaction->response !== null) {
            $this->scanResponse($interaction->response, $findings);
        }

        if ($interaction->error !== null) {
            $this->scanText($interaction->error->message, 'error.message', $findings);
        }

        return $this->deduplicate($findings);
    }

    /**
     * The warning a recording session prints for what it found. It reports and stops there:
     * the cassette is already on disk, and what to do about a finding depends on context
     * the library doesn't have.
     *
     * @param  list<SecretFinding>  $findings
     */
    public static function warning(string $cassette, int $recorded, array $findings): string
    {
        $warning = sprintf(
            "%s recorded %d interaction%s → %s\n",
            Ansi::yellow('http-vcr:'),
            $recorded,
            $recorded === 1 ? '' : 's',
            $cassette,
        );

        foreach ($findings as $finding) {
            $warning .= sprintf(
                "  %s carries a credential-shaped value, stored unredacted:\n    %s (%d chars)\n",
                Ansi::bold($finding->location),
                Ansi::red('"'.$finding->excerpt().'"'),
                $finding->length(),
            );
        }

        return $warning;
    }

    /**
     * @param  list<SecretFinding>  $findings
     */
    private function scanRequest(RecordedRequest $request, array &$findings): void
    {
        $this->scanHeaders($request->headers, 'request.headers', $findings);
        $this->scanQuery($request->uri, $findings);
        $this->scanBody($request->body, $request->bodyEncoding, $request->header('Content-Type'), 'request.body', $findings);
    }

    /**
     * @param  list<SecretFinding>  $findings
     */
    private function scanResponse(RecordedResponse $response, array &$findings): void
    {
        $this->scanHeaders($response->headers, 'response.headers', $findings);
        $this->scanBody($response->body, $response->bodyEncoding, $response->header('Content-Type'), 'response.body', $findings);
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @param  list<SecretFinding>  $findings
     */
    private function scanHeaders(array $headers, string $where, array &$findings): void
    {
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                if ($this->isCredential($name, $value)) {
                    $findings[] = new SecretFinding($where.'.'.strtolower($name), $value);
                }
            }
        }
    }

    /**
     * @param  list<SecretFinding>  $findings
     */
    private function scanQuery(string $uri, array &$findings): void
    {
        $query = parse_url($uri, PHP_URL_QUERY);

        if (! is_string($query)) {
            return;
        }

        foreach ($this->pairs($query) as [$name, $value]) {
            if ($this->isCredential($name, $value)) {
                $findings[] = new SecretFinding(sprintf('request.uri (%s)', $name), $value);
            }
        }
    }

    /**
     * @param  list<string>  $contentType
     * @param  list<SecretFinding>  $findings
     */
    private function scanBody(string $body, ?string $encoding, array $contentType, string $where, array &$findings): void
    {
        // Base64 in a cassette is bytes, and bytes are not a place a reader would spot a
        // token anyway.
        if ($body === '' || $encoding !== null) {
            return;
        }

        $document = json_decode($body);

        if (json_last_error() === JSON_ERROR_NONE) {
            $this->scanJson($document, '', $where, $findings);
        } elseif (str_contains(strtolower($contentType[0] ?? ''), 'application/x-www-form-urlencoded')) {
            foreach ($this->pairs($body) as [$name, $value]) {
                if ($this->isCredential($name, $value)) {
                    $findings[] = new SecretFinding(sprintf('%s (%s)', $where, $name), $value);
                }
            }
        }

        $this->scanText($body, $where, $findings);
    }

    /**
     * @param  list<SecretFinding>  $findings
     */
    private function scanJson(mixed $document, string $path, string $where, array &$findings): void
    {
        if ($document instanceof stdClass) {
            foreach (get_object_vars($document) as $key => $value) {
                $this->scanJson($value, $path.'/'.$key, $where, $findings);
            }

            return;
        }

        if (is_array($document)) {
            foreach ($document as $index => $value) {
                $this->scanJson($value, $path.'/'.$index, $where, $findings);
            }

            return;
        }

        $name = strrchr($path, '/');

        if (is_string($document) && $this->isCredential($name === false ? $path : substr($name, 1), $document)) {
            $findings[] = new SecretFinding(sprintf('%s (%s)', $where, $path), $document);
        }
    }

    /**
     * Free text — an exception message, or a body that is neither JSON nor a form. Only the
     * unmistakable shapes apply here: without a field name to go on, anything looser would
     * report every long identifier in every payload.
     *
     * @param  list<SecretFinding>  $findings
     */
    private function scanText(string $text, string $where, array &$findings): void
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                $findings[] = new SecretFinding($where, $match[0]);
            }
        }
    }

    private function isCredential(string $name, string $value): bool
    {
        $value = trim($value);

        // A placeholder is the redaction having worked — the one thing a scan must not
        // report, or the warning would follow every properly redacted cassette forever.
        if ($value === '' || $this->isPlaceholder($value)) {
            return false;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        if (strlen($value) >= 8 && preg_match(self::CREDENTIAL_NAMES, $name) === 1) {
            return true;
        }

        return $this->looksLikeToken($value);
    }

    /**
     * Long, unbroken, and mixing letters with digits — the shape of something generated
     * rather than written.
     */
    private function looksLikeToken(string $value): bool
    {
        return strlen($value) >= 32
            && preg_match('/^[A-Za-z0-9_\-+\/=.]+$/', $value) === 1
            && preg_match('/[A-Za-z]/', $value) === 1
            && preg_match('/\d/', $value) === 1;
    }

    private function isPlaceholder(string $value): bool
    {
        return preg_match('/<[A-Z0-9_-]+>/', $value) === 1;
    }

    /**
     * @return list<array{string, string}>
     */
    private function pairs(string $encoded): array
    {
        $pairs = [];

        foreach (explode('&', $encoded) as $pair) {
            $separator = strpos($pair, '=');

            if ($separator !== false) {
                $pairs[] = [urldecode(substr($pair, 0, $separator)), urldecode(substr($pair, $separator + 1))];
            }
        }

        return $pairs;
    }

    /**
     * @param  list<SecretFinding>  $findings
     * @return list<SecretFinding>
     */
    private function deduplicate(array $findings): array
    {
        $seen = [];
        $unique = [];

        foreach ($findings as $finding) {
            // Keyed by the value alone: the same token found again under a looser rule, or
            // in the response as well as the request, is one finding to act on.
            $key = $finding->value;

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $finding;
            }
        }

        return $unique;
    }
}

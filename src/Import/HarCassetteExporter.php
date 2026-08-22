<?php

declare(strict_types=1);

namespace HttpVcr\Import;

use Composer\InstalledVersions;
use DateTimeInterface;
use HttpVcr\Cassette\Cassette;
use HttpVcr\Cassette\Interaction;
use HttpVcr\Exception\CassetteFormatException;
use JsonException;
use stdClass;

/**
 * A cassette written out as HAR, for handing to a tool that speaks it (§3.2).
 *
 * The direction that loses something, and says so here rather than in a surprise: HAR has
 * no field for `locked`, `repeatablePlayback` or `schemaVersion`, so those stay behind. A
 * recorded transport failure is written the way a browser writes one it saw — `status: 0`
 * — with the message in HAR's own convention for custom fields, `_error`, which
 * {@see HarCassetteImporter} reads back.
 *
 * The cassette itself is unaffected: this produces a copy for somewhere else, it does not
 * change the format anything is stored in.
 */
final class HarCassetteExporter
{
    public function export(Cassette $cassette): string
    {
        $entries = [];

        foreach ($cassette->interactions as $interaction) {
            $entries[] = $this->entry($interaction);
        }

        try {
            return json_encode([
                'log' => [
                    'version' => '1.2',
                    'creator' => ['name' => 'http-vcr', 'version' => $this->version()],
                    'entries' => $entries,
                ],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        } catch (JsonException $exception) {
            throw CassetteFormatException::malformed('could not be written as HAR: ' . $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(Interaction $interaction): array
    {
        $request = $interaction->request;
        $response = $interaction->response;

        $entry = [
            'startedDateTime' => $interaction->recordedAt->format(DateTimeInterface::ATOM),
            // Nothing was measured — a cassette records what was exchanged, not how long it
            // took — and HAR says -1 for a duration that is not known.
            'time' => -1,
            'request' => [
                'method' => $request->method,
                'url' => $request->uri,
                'httpVersion' => 'HTTP/1.1',
                'cookies' => [],
                'headers' => $this->headers($request->headers),
                'queryString' => $this->queryString($request->uri),
                'headersSize' => -1,
                'bodySize' => strlen($request->body),
            ],
            'cache' => new stdClass(),
            'timings' => ['send' => -1, 'wait' => -1, 'receive' => -1],
        ];

        if ($request->body !== '') {
            $entry['request']['postData'] = [
                'mimeType' => $request->header('Content-Type')[0] ?? 'application/octet-stream',
                'text' => $request->body,
            ];
        }

        if ($response === null) {
            $entry['response'] = [
                'status' => 0,
                'statusText' => '',
                'httpVersion' => 'HTTP/1.1',
                'cookies' => [],
                'headers' => [],
                'content' => ['size' => 0, 'mimeType' => ''],
                'redirectURL' => '',
                'headersSize' => -1,
                'bodySize' => -1,
            ];
            // Response and error are the two halves of one outcome: no response means this
            // interaction is a recorded failure and carries the error instead.
            $error = $interaction->error;
            $entry['_error'] = $error === null ? '' : $error->message;

            return $entry;
        }

        $content = [
            'size' => strlen($response->body),
            'mimeType' => $response->header('Content-Type')[0] ?? '',
        ];

        if ($response->body !== '') {
            $content['text'] = $response->bodyEncoding === null
                ? $response->body
                : base64_encode($response->body);

            if ($response->bodyEncoding !== null) {
                $content['encoding'] = 'base64';
            }
        }

        $entry['response'] = [
            'status' => $response->status,
            'statusText' => '',
            'httpVersion' => 'HTTP/1.1',
            'cookies' => [],
            'headers' => $this->headers($response->headers),
            'content' => $content,
            'redirectURL' => $response->header('Location')[0] ?? '',
            'headersSize' => -1,
            'bodySize' => strlen($response->body),
        ];

        return $entry;
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return list<array{name: string, value: string}>
     */
    private function headers(array $headers): array
    {
        $pairs = [];

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $pairs[] = ['name' => $name, 'value' => $value];
            }
        }

        return $pairs;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function queryString(string $uri): array
    {
        $query = parse_url($uri, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return [];
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = ['name' => urldecode($name), 'value' => urldecode($value)];
        }

        return $pairs;
    }

    /**
     * HAR wants the tool's version, and the tool is a Composer package — so it asks
     * Composer, and settles for saying it does not know rather than pulling in anything to
     * find out.
     */
    private function version(): string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('mtk3d/http-vcr')) {
            return 'unknown';
        }

        return InstalledVersions::getPrettyVersion('mtk3d/http-vcr') ?? 'unknown';
    }
}

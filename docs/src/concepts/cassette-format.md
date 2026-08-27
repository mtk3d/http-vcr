# The Cassette Format

A cassette is a JSON file — human-readable on purpose, so a change to it shows up as an honest diff in a pull request, and so it can be hand-edited when needed (see [Locked Interactions](../safety/locked-interactions.md) for one example of why that matters).

```json
{
  "schemaVersion": 1,
  "interactions": [
    {
      "request": {
        "method": "GET",
        "uri": "https://shop.myshopify.com/admin/api/2024-01/products/123.json",
        "headers": {
          "accept": ["application/json"],
          "authorization": ["<REDACTED-AUTHORIZATION>"]
        },
        "body": ""
      },
      "response": {
        "status": 200,
        "headers": {
          "content-type": ["application/json"]
        },
        "body": "{\"id\":123,\"title\":\"T-Shirt\"}"
      },
      "outcome": "success",
      "recordedAt": "2026-08-01T10:15:00Z",
      "locked": false
    }
  ]
}
```

## Field reference (short version)

| Field | Meaning |
|---|---|
| `schemaVersion` | Format version of the file. Lets http-vcr detect and migrate old cassettes instead of silently misreading them. |
| `outcome` | `"success"` for a normal response, `"error"` for a recorded transport failure — see [Transport Errors](../advanced/transport-errors.md) |
| `recordedAt` | Timestamp used by [`staleAfter`](../advanced/stale-after.md) to flag old recordings |
| `locked` | When `true`, this interaction can never be re-recorded — see [Locked Interactions](../safety/locked-interactions.md) |

The full field-by-field reference, including large-body sidecar files and binary encoding, lives in the [Cassette Format Reference](../reference/cassette-format.md).

## Large or binary bodies

Bodies over 1 MiB (configurable) aren't inlined as base64 in the main file — they're written to a separate sidecar file next to the cassette, named after a hash of their content (`{cassette}.{hash}.bin`), and referenced from the interaction instead of embedded inline. This keeps the main JSON file readable and diffable even when one response happens to be a large download, and content-hash naming means reordering interactions by hand never breaks a sidecar reference.

Sidecars are written as raw bytes, so `bodyEncoding` and `bodyRef` never appear together — base64 exists only to fit arbitrary bytes into JSON, which a separate file doesn't need. Sidecars nobody references any more (after a re-record, or after deleting an interaction by hand) are cleaned up when the cassette is next written. Full details in the [Cassette Format Reference](../reference/cassette-format.md#sidecar-files).

## Other formats

JSON is one of two canonical formats — `YamlCassetteSerializer` stores the same model in YAML, and is the default wherever `symfony/yaml` is installed; and HAR is supported as an import/export format for exchanging captures with browser DevTools, Postman, or Charles Proxy — but deliberately not as a storage format. See [Storage & Formats](../advanced/storage-and-formats.md).

## It's meant to be hand-edited

Redacting a field that wasn't configured up front, deleting one interaction out of several, locking a sensitive one — all of that is a normal text edit, not something that needs a special tool. `vendor/bin/http-vcr lock`/`unlock` exist for convenience, but they're not the only way in.

# ADR-0016: The Laravel bridge lives in its own repository

**Status:** Accepted · **Reference:** `PLAN.md` §7 decision 13

## Context

A Laravel integration wants a service provider, `Http` facade interception, and `vcr:*`
artisan commands. All of it needs `illuminate/*` packages, and all of it is tied to a
framework release cycle that moves faster than a PSR-18 decorator needs to.

The Guzzle and Symfony bridges, by contrast, live in this repository — their dependencies
are `suggest`ed, and the adapter classes are a few hundred lines that only load if the user
constructs them.

## Decision

Guzzle and Symfony bridges stay in `src/Bridge/`. Laravel goes to a separate package,
`mtk3d/laravel-http-vcr`.

That package is built but not published yet, so nothing here `suggest`s it until it is on
Packagist. Laravel is served in the meantime by the recipe in
[Laravel](../../integrations/laravel.md) — `Http::` goes through Guzzle, so the middleware
bridge already covers it, at the cost of wiring the handler by hand.

Building it corrected three things this repository had written down in advance and could not
check without the framework installed (`PLAN.md` §7 decision 82): the hook is
`Factory::globalMiddleware()` rather than `globalOptions(['handler' => ...])`, the supported
range is Laravel 12–13 rather than 11–12, and no `config/http-vcr.php` is published.

## Consequences

**Good.** The core keeps a dependency list of PSR interfaces plus Symfony Console and
php-parser for the CLI, and its CI matrix is about PHP versions rather than framework
versions. A Laravel 12 release is a version bump in one small repository, not a constraint on
this one. Users not on Laravel carry nothing.

**Bad.** Two repositories to release, and a version-compatibility table to keep honest. The
line is drawn at *does this need the framework installed to be useful* — `HandlerStack` and
`HttpClientInterface` are single interfaces from libraries a project may already have, while
a service provider is meaningless without the framework around it.

# lws-drupal

Drupal 11 modules that make a Drupal site a [W3C Linked Web Storage (LWS)
1.0](https://www.w3.org/TR/2026/WD-lws10-core-20261005/) server, following the
core Working Draft of 5 October 2026. The design, and the plan for building it,
is in [DESIGN.md](DESIGN.md).

| Module | What it does |
|---|---|
| `lws` | The LWS URL space under `/lws`, problem details, content negotiation, CORS, the guard on outbound requests, settings |
| `lws_authz` | Access tokens: trusted authorization servers, token validation, the access decision |
| `lws_storage` | Storages and their containers: the storage description, container listings, Drush commands |

## Status

**Step A1, access tokens** (DESIGN.md §10), after S1, storages. Storages are
created with Drush, and each has a root container. Over HTTP:

| URL | Response |
|---|---|
| `/lws/{storage}/` | The storage description (`application/lws+cid`, or `ld+json`/`json` by `Accept`), to anyone |
| `/lws/{storage}/root/…/` | To the storage's controllers: a container listing (`application/lws+json`, or `ld+json`/`json`), with `ETag`, `Last-Modified` and `Link` headers |
| No token, or a rejected one | `401` with a Bearer challenge: `as_uri` (the authorization server), `realm` (the storage) and, for a rejected token, `error` |
| A valid token of anyone else | `403` |
| An unknown storage, a missing resource | `404` |
| A blocked storage, or one with no authorization server | `503` |
| Anything malformed | `400` |

Errors are RFC 9457 problem details. Nothing can be written over HTTP yet: data
resources and writes come with step S2, and access for agents other than
controllers with step A3.

```sh
drush lws:as:add main https://as.example --default    # trust an authorization server
drush lws:storage:create alice --controller=https://id.example/alice
drush lws:storage:list
drush lws:storage:delete alice
```

## Access tokens

A storage accepts RFC 9068 access tokens (`typ: at+jwt`, signed with ES256,
ES384 or EdDSA) from one trusted authorization server: its own, set with
`lws:storage:create --authorization-server=<id>`, or the site's default. A token
must name that server as `iss` and the storage URI, alone, as `aud` (LWS Core
§5.2.4). Drupal does not issue tokens yet; that is step A2.

Trusted servers are configuration, managed at *Configuration › Web services ›
LWS authorization servers* or with `lws:as:add`, `lws:as:list` and
`lws:as:delete`. A server's signing keys are either pinned (`--jwks=<file>`) or
fetched from the `jwks_uri` of its metadata at `/.well-known/lws-configuration`,
cached for an hour and fetched again when a token names an unknown key.

Fetches go through a guard against server-side request forgery: HTTPS only, and
never to private, loopback or link-local addresses. In development, origins can be
exempted in `settings.php`:

```php
$settings['lws_outbound_allowlist'] = ['http://localhost:8080'];
```

## Requirements

- Drupal 11.3 or later, PHP 8.3 or later.
- [`ebremer/lws-client`](https://github.com/ebremer/lws-client), which is not on
  Packagist yet and has no release. A site that installs this module must name
  its repository and require its `main` branch itself:

  ```sh
  composer config repositories.lws-client vcs https://github.com/ebremer/lws-client
  composer require 'ebremer/lws-client:dev-main'
  ```

## Development with DDEV

The site is built with the
[ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib) add-on, which
puts a Drupal codebase (`web/`, `vendor/`) around this checkout and symlinks the
module into `web/modules/custom/lws`. This checkout's `composer.json` then acts
as the site's, and already names the lws-client repository.

```sh
ddev add-on get ddev/ddev-drupal-contrib   # once; commit the files it adds to .ddev/
ddev start
ddev poser                                  # builds the Drupal codebase
ddev symlink-project
ddev drush site:install minimal -y
ddev drush en lws_storage -y
ddev drush config:set lws.settings base_url https://lws-drupal.ddev.site -y
ddev drush lws:storage:create alice
curl -i https://lws-drupal.ddev.site/lws/alice/         # the storage description
curl -i https://lws-drupal.ddev.site/lws/alice/root/    # 401, until a server is trusted
```

Checks, as CI runs them:

```sh
ddev exec vendor/bin/phpunit -c web/core web/modules/custom/lws
ddev exec vendor/bin/phpcs --standard=web/modules/custom/lws/phpcs.xml.dist web/modules/custom/lws
ddev exec vendor/bin/phpstan analyse -c web/modules/custom/lws/phpstan.neon.dist
```

## Without DDEV

Any Drupal 11 project can use the checkout through a Composer path repository.
[`.github/build-site.sh`](.github/build-site.sh) is the recipe CI uses:

```sh
sh lws-drupal/.github/build-site.sh drupal lws-drupal
cd drupal
SIMPLETEST_DB=sqlite://localhost//tmp/lws-test.sqlite vendor/bin/phpunit -c web/core web/modules/contrib/lws
```

## Deployment notes

- **Set the canonical base URL** at *Configuration › Web services › Linked Web
  Storage*, or `lws.settings:base_url`, for example `https://storage.example`.
  Left empty, LWS URIs are derived from the request's `Host` header, which is
  only acceptable in development. The status report warns about it, and about
  serving LWS from the same host as the site's own pages.
- **Trust an authorization server** and make it the default; until then, storage
  requests that need a token answer `503`, and the status report says so.
- **Configure the private file system.** Data resources will keep their content
  there (step S2); the status report warns until it is set.
- **Web servers refuse some paths before Drupal sees them:**
  - Drupal's `.htaccess` answers `403` for any path segment that starts with a
    dot, such as `/lws/alice/root/.profile`. LWS never gives a resource such a
    name.
  - Apache answers `404` for an encoded slash (`%2F`) by default. LWS rejects
    those paths anyway.
- **Drupal 11.4 and later run `index.php` through `symfony/runtime`.** Its
  Composer plugin must be allowed (`allow-plugins.symfony/runtime`), or every
  page fails.

# lws-drupal

Drupal 11 modules that make a Drupal site a [W3C Linked Web Storage (LWS)
1.0](https://www.w3.org/TR/2026/WD-lws10-core-20261005/) server, following the
core Working Draft of 5 October 2026. The design, and the plan for building it,
is in [DESIGN.md](DESIGN.md).

| Module | What it does |
|---|---|
| `lws` | The LWS URL space under `/lws`, problem details, content negotiation, CORS, the guard on outbound requests, settings |
| `lws_authz` | Access tokens: the site's own authorization server, trusted external ones, token validation, the access decision |
| `lws_storage` | Storages and their resources: the storage description, containers, data resources, linksets, Drush commands |

## Status

**Step A2, the authorization server** (DESIGN.md §10), after S1, storages, A1,
access tokens, S2, data resources, S3, pagination, and S4, metadata and JSON
Patch. Storages are created with Drush; everything in them is managed over HTTP
by their controllers, with access tokens the site issues itself:

| Request | Response |
|---|---|
| `GET /lws/{storage}/` | The storage description (`application/lws+cid`, or `ld+json`/`json` by `Accept`), to anyone |
| `GET`/`HEAD` a container | A listing (`application/lws+json`, or `ld+json`/`json`) with each member's type, media type, size and modification time, in pages (100 members by default) linked with `first`, `next`, `prev` and `last` |
| `GET`/`HEAD` a data resource | Its bytes, with byte ranges (`206`, `416`) |
| `POST` to a container | `201` and `Location`: a data resource from the body, or a container with `Link: <https://www.w3.org/ns/lws#Container>; rel="type"`. The `Slug` header suggests the name |
| `PUT` a data resource | `204`: its content replaced. There is no create-by-`PUT` |
| `PATCH` a JSON data resource | `204`: a JSON Patch (`application/json-patch+json`) applied, all or nothing (`409` if a `test` fails) |
| `DELETE` | `204`. A container that is not empty needs `Depth: infinity` (`409` otherwise) |
| `GET`, `PUT`, `PATCH /lws/{storage}/meta/{uuid}` | The linkset of a resource (`application/linkset+json`): its parent and types, and the links clients manage. Server-managed links cannot change (`409`) |
| `Link` headers on `POST`; with `Prefer: set-linkset`, on `PUT` and `PATCH` | Set the links clients manage, such as types (`rel="type"`) and licenses |
| `If-Match`, `If-None-Match`, `If-(Un)Modified-Since` | `304` or `412` as RFC 9110 says |
| `GET /.well-known/lws-configuration` | The authorization server's metadata (RFC 8414) |
| `POST /lws/oauth/token` | Token exchange (RFC 8693): a self-signed credential for an access token to a storage |
| `GET /lws/oauth/jwks` | The keys that sign access tokens |
| No token, or a rejected one | `401` with a Bearer challenge: `as_uri` (the authorization server), `realm` (the storage) and, for a rejected token, `error` |
| A valid token of anyone else | `403` |
| Over the storage's quota | `507` |

Successful responses carry `ETag` and `Link` headers (storage, type, parent,
linkset), and errors are RFC 9457 problem details. Content is kept as managed
files in the private file system and never served through Drupal's own file
routes. Access for agents other than controllers comes with step A3.

```sh
drush lws:storage:create alice --controller=https://id.example/alice --quota=1000000000 --page-size=50
drush lws:key:rotate                                   # a new signing key, as the web server's user
drush lws:as:add main https://as.example --default    # or trust another authorization server
drush lws:storage:list
drush lws:storage:delete alice
drush lws:gc                                           # sweep unreferenced content
```

## Access tokens

A storage accepts RFC 9068 access tokens (`typ: at+jwt`, signed with ES256,
ES384 or EdDSA) from one authorization server: its own, set with
`lws:storage:create --authorization-server=<id>`, or the site's default. A token
must name that server as `iss` and the storage URI, alone, as `aud` (LWS Core
§5.2.4).

By default that server is the site's own, `local`. Its issuer is the origin of
the LWS base URL, so its metadata is at `/.well-known/lws-configuration`. Its
token endpoint exchanges an authentication credential for a token to one of the
site's storages (LWS Core §5.2.3), for 300 seconds by default and never longer than
the credential lives. It takes self-signed controlled identifier credentials
([lws10-authn-ssi-cid](https://w3c.github.io/lws-protocol/lws10-authn-ssi-cid/)):
a JWT the agent signs itself, whose `kid` names a key of its controlled
identifier document. The subject may be an HTTPS URI, whose document is fetched,
a `did:key`, or a `did:web`. Requests are rate-limited per client address and
per agent.

It signs with ES256 keys kept as JSON Web Key files, readable by their owner
only, in `$settings['lws_authz_key_directory']`, or `lws_authz/keys` in the
private file system. The first is made when it is needed; `drush lws:key:rotate`
makes a new one, and the old one is published until its tokens have expired.

Other servers are trusted at *Configuration › Web services › LWS authorization
servers* or with `lws:as:add`, `lws:as:list` and `lws:as:delete`; the settings
there choose the default server, the authentication suites and the token
lifetime. A trusted server's signing keys are either pinned (`--jwks=<file>`) or
fetched from the `jwks_uri` of its metadata at `/.well-known/lws-configuration`,
cached for an hour and fetched again when a token names an unknown key.

Fetches, of keys and of controlled identifier documents, go through a guard
against server-side request forgery: HTTPS only, and never to private, loopback
or link-local addresses. In development, origins can be exempted in
`settings.php`:

```php
$settings['lws_outbound_allowlist'] = ['http://localhost:8080'];
```

## Requirements

- Drupal 11.3 or later, PHP 8.3 or later.
- [`ebremer/lws-client`](https://github.com/ebremer/lws-client), with
  `JsonPatch::apply()`, which is not on Packagist yet and has no release. A site that installs this module must name
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
# Data resources need a private file system:
echo "\$settings['file_private_path'] = '../private';" >> web/sites/default/settings.php
ddev drush site:install minimal -y
ddev drush en lws_storage -y
ddev drush config:set lws.settings base_url https://lws-drupal.ddev.site -y
ddev drush lws:storage:create alice
curl -i https://lws-drupal.ddev.site/lws/alice/         # the storage description
curl -i https://lws-drupal.ddev.site/lws/alice/root/    # 401, naming the site's authorization server
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
- **Give the authorization server a key directory** outside the web root, in
  `$settings['lws_authz_key_directory']`, or configure the private file system.
  Without one it cannot issue tokens, storages that trust it answer requests
  that need a token with `503`, and the status report says so. Run
  `drush lws:key:rotate` as the web server's user, which must be able to read
  the keys. A site installed in a subdirectory needs the web server to serve
  `/.well-known/lws-configuration` from the site.
- **Configure the private file system.** Data resources keep their content there,
  under `private://lws/`; until it is set they cannot be created, and the status
  report says so. Another stream wrapper can be chosen with
  `lws_storage.settings:scheme`.
- **Request size.** `lws_storage.settings:max_upload_bytes` caps one upload
  (`413`); PHP's `post_max_size` and the web server's body limit still apply to
  `POST`.
- **Run cron,** which deletes the files of recursively deleted resources and of
  deleted storages.
- **Web servers refuse some paths before Drupal sees them:**
  - Drupal's `.htaccess` answers `403` for any path segment that starts with a
    dot, such as `/lws/alice/root/.profile`. LWS never gives a resource such a
    name.
  - Apache answers `404` for an encoded slash (`%2F`) by default. LWS rejects
    those paths anyway.
- **Drupal 11.4 and later run `index.php` through `symfony/runtime`.** Its
  Composer plugin must be allowed (`allow-plugins.symfony/runtime`), or every
  page fails.

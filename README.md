# lws-drupal

Drupal 11 modules that make a Drupal site a [W3C Linked Web Storage (LWS)
1.0](https://www.w3.org/TR/2026/WD-lws10-core-20261005/) server, following the
core Working Draft of 5 October 2026. The design, and the plan for building it,
is in [DESIGN.md](DESIGN.md).

| Module | What it does |
|---|---|
| `lws` | The LWS URL space under `/lws`, problem details, content negotiation, CORS, the guard on outbound requests, settings |
| `lws_authz` | Access tokens: the site's own authorization server, trusted external ones, token validation, the access decision |
| `lws_storage` | Storages and their resources: the storage description, containers, data resources, linksets, the administration pages, Drush commands |

## Status

**Step A5, OpenID Connect** (DESIGN.md §10), after S1, storages, A1, access
tokens, S2, data resources, S3, pagination, S4, metadata and JSON Patch, A2, the
authorization server, A3, access policies, A4, access requests and grants, and
S5, hardening and the administration pages. Storages are created at *Content ›
LWS storages* or with Drush; everything in them is managed over HTTP, with
access tokens the site issues itself, for self-signed credentials or OpenID
Connect ID Tokens, by their controllers and by the agents their access policies
allow:

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
| `POST /lws/{storage}/access/requests/` | An access request (`AccessRequest`, `application/lws+json`) for the agent itself |
| `POST /lws/{storage}/access/grants/` | An access grant, by a controller: its policies take effect at once |
| `GET` either service, or an entry | The requests and grants the agent may see: all of them for a controller, else its own and the grants that name it |
| `DELETE` an entry | Cancels a request (its agent or a controller), or revokes a grant (a controller), at once |
| `GET /.well-known/lws-configuration` | The authorization server's metadata (RFC 8414) |
| `POST /lws/oauth/token` | Token exchange (RFC 8693): a self-signed credential or an OpenID Connect ID Token for an access token to a storage |
| `GET /lws/oauth/jwks` | The keys that sign access tokens |
| No token, or a rejected one | `401` with a Bearer challenge: `as_uri` (the authorization server), `realm` (the storage) and, for a rejected token, `error` |
| A valid token, but no policy allows it | `403`, or `404` with `lws.settings:conceal_existence` |
| Over the storage's quota | `507` |
| A body larger than the largest content, or than PHP's `post_max_size` for a `POST` | `413`, before any of it is read |
| A body shorter than its `Content-Length` | `400`: nothing is kept |

Successful responses carry `ETag` and `Link` headers (storage, type, parent,
linkset), and errors are RFC 9457 problem details. Every response in the LWS URL
space is sandboxed (`Content-Security-Policy: sandbox`). Content is kept as
managed files in the private file system and never served through Drupal's own
file routes, except to storage administrators, as attachments.

Listings show each agent only what it may read. An agent who may not read
everything in a container gets a page of its own: its `ETag` is of what it sees,
it has no `Last-Modified`, and a page examines at most 1,000 members
(`$settings['lws_storage_scan_limit']`), so it may hold fewer than the page
size and go on with `next`. Its `totalItems` counts the visible members among
the first 1,000, never more than there are (LWS Core §8.1 lets it be
approximate).

```sh
drush lws:storage:create alice --controller=https://id.example/alice --quota=1000000000 --page-size=50
drush lws:key:rotate                                   # a new signing key, as the web server's user
drush lws:as:add main https://as.example --default    # or trust another authorization server
drush lws:storage:list
drush lws:storage:delete alice
drush lws:gc                                           # sweep unreferenced content
```

## Administration

| Page | What it does |
|---|---|
| *Content › LWS storages* (`/admin/content/lws`) | The storages: all of them for storage administrators, and their own for owners with *Manage own LWS storages* |
| `/admin/content/lws/add`, `/admin/content/lws/{id}` | Add or edit a storage: label, slug (fixed once made), controllers, owner, authorization server, quota (such as `10 GB`), page size, whether changes need `If-Match`, and whether it is enabled |
| `/admin/content/lws/{id}/access` | Who may do what in it ([Sharing](#sharing)) |
| `/admin/content/lws/{id}/resources` | A read-only resource browser, which is a View (`lws_resources`, with Views): path, kind, media type, size, creator, and *Download* and *Create media item* |
| `/admin/content/lws/{id}/delete` | Deletes it with everything in it; a large storage is blocked first and emptied in a batch |
| *Configuration › Web services › Linked Web Storage* | The base URL, path prefix and concealment; on the *Storages* tab, where content goes, the largest content, the largest recursive delete, the page size and whether changes need `If-Match` |

*Create media item* (with Media) copies a data resource's content into a new,
unpublished Media item of a type made from a file. The copy is the site's: later
changes to the resource or to who may access it do not change it.

Creating storages, and everything else here but the access page, needs
*Administer LWS storages*. Whether users may create storages of their own is an
open question (DESIGN.md §14, Q2).

## Sharing

A storage's controllers may do anything in it. Anyone else may do what its
access policies allow. A policy is an `AccessPolicy` of the access profile (LWS
Core §11.3):

- **who:** an agent, everyone (`http://xmlns.com/foaf/0.1/Agent`, with or
  without a token) or every authenticated agent
  (`http://www.w3.org/ns/auth/acl#AuthenticatedAgent`);
- **may:** `read`, `create`, `modify`, `delete`;
- **in:** resources, where a container includes everything in it at any depth,
  limited to containers, data resources or both;
- **if:** constraints on the `client`, the `format`, the `type` and the
  `dateTime`, which must all hold. What cannot be evaluated does not hold: a
  container has no format, a resource that does not exist has no format or
  types, and no request states a `purpose`.

Listings show each agent only the members it may read. Removing a policy takes
effect on the next request.

Over LWS, agents ask for access at the storage's `AccessRequestService`, and its
controllers grant it at its `AccessGrantService` (LWS Core §11): a grant's
policies are stored with it, and go when it is revoked. Requests wait on the
access page, which approves them, granting what they ask, or denies them.

Policies are managed at `/admin/content/lws/{storage id}/access` by storage
administrators and by a storage's owner with *Manage own LWS storages*, or with
Drush:

```sh
drush lws:policy:add alice public --target=https://storage.example/lws/alice/root/public/
drush lws:policy:add alice https://id.example/bob --action=read --action=create --until=2026-12-31T23:59:59Z
drush lws:policy:add alice --json=policy.json          # an AccessPolicy object
drush lws:policy:list alice
drush lws:policy:delete alice 3
```

## Access tokens

A storage accepts RFC 9068 access tokens (`typ: at+jwt`, signed with ES256,
ES384, EdDSA, RS256, RS384, RS512, PS256, PS384 or PS512) from one authorization server: its own, set with
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
a `did:key`, or a `did:web`. It also takes ID Tokens of OpenID Providers
([lws10-authn-openid](https://w3c.github.io/lws-protocol/lws10-authn-openid/)),
whose `sub`, `iss` and `azp` become the subject, issuer and client. Requests are
rate-limited per client address and per agent.

An ID Token is accepted from a provider in either of two ways:

- **The subject names it.** The subject's controlled identifier document has a
  service of type `https://www.w3.org/ns/lws#OpenIdProvider` whose
  `serviceEndpoint` is the token's `iss`. The provider's keys come from its
  OpenID Connect Discovery document, and the ID Token's `aud` must include this
  site's authorization server. Both can be turned off in the settings.
- **It is configured.** Configured providers are listed at *OpenID Providers*,
  beside the authorization servers, or managed with `lws:op:add`, `lws:op:list`
  and `lws:op:delete`. Each can:
  - pin its keys;
  - accept ID Tokens that name only their client in `aud` (`--any-audience`),
    as Keycloak's do;
  - be trusted for any subject, without fetching the subject's document
    (`--any-subject`).

```sh
drush lws:op:add halcyon https://ebremer.com/auth/realms/Halcyon --any-audience
```

The keys of the providers are verified as ES256, ES384, EdDSA or, for providers
such as Keycloak that sign with RSA, RS256 to RS512 and PS256 to PS512, with
keys of 2048 bits or more. SAML 2.0 assertions are not taken yet: that suite
will be a module of its own.

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
- **Serve LWS over HTTPS.** Access tokens are bearer tokens. The status report
  counts plain HTTP as an error, except on a loopback host.
- **Request size.** *Largest content* (`lws_storage.settings:max_upload_bytes`)
  caps one write, and is refused with `413` before any of it is read. PHP's
  `post_max_size` limits `POST`, which creates, but not `PUT`, which replaces;
  the web server's body limit (Apache's `LimitRequestBody`, 1 GB by default)
  limits both. The status report warns when no largest content is set, or when
  it is larger than `post_max_size`.
- **Keep content out of the web server's reach.** The status report counts
  content in the public file system as an error: anyone who learned a file's
  location could download it, whatever LWS policy says.
- **Let clients find the authorization server.** The status report fetches
  `/.well-known/lws-configuration` from the issuer, as clients do, and warns if
  a proxy or the web server does not pass it to Drupal.
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

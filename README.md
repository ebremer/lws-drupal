# lws-drupal

Drupal 11 modules that make a Drupal site a [W3C Linked Web Storage (LWS)
1.0](https://www.w3.org/TR/2026/WD-lws10-core-20261005/) server, following the
core Working Draft of 5 October 2026. The design, and the plan for building it,
is in [DESIGN.md](DESIGN.md).

## Status

**Step 0, the walking skeleton** (DESIGN.md §10). The `lws` module claims the
LWS URL space and proves that Drupal can serve it:

| URL | Response today |
|---|---|
| `/lws/{storage}/` | A placeholder storage description (`application/lws+cid`) for any well-formed slug |
| `/lws/{storage}/root/…/` | An empty container (`application/lws+json`) at every container path |
| `/lws/{storage}/root/…` | `404`: no data resources exist yet |
| Anything malformed or unknown | RFC 9457 problem details (`400` or `404`) |

No storages, resources, tokens or access control exist yet. Step S1 adds
storages and resources; step A1 adds token validation.

## Development with DDEV

The site is built with the
[ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib) add-on, which
puts a Drupal codebase (`web/`, `vendor/`) around this checkout and symlinks the
module into `web/modules/custom/lws`.

```sh
ddev add-on get ddev/ddev-drupal-contrib   # once; commit the files it adds to .ddev/
ddev start
ddev poser                                  # builds the Drupal codebase
ddev symlink-project
ddev drush site:install minimal -y
ddev drush en lws -y
ddev drush config:set lws.settings base_url https://lws-drupal.ddev.site -y
curl -i https://lws-drupal.ddev.site/lws/alice/root/
```

Checks, as CI runs them:

```sh
ddev exec vendor/bin/phpunit -c web/core web/modules/custom/lws/tests
ddev exec vendor/bin/phpcs --standard=web/modules/custom/lws/phpcs.xml.dist web/modules/custom/lws
ddev exec vendor/bin/phpstan analyse -c web/modules/custom/lws/phpstan.neon.dist
```

## Without DDEV

Any Drupal 11 project can use the checkout through a Composer path repository.
[`.github/build-site.sh`](.github/build-site.sh) is the recipe CI uses:

```sh
sh lws-drupal/.github/build-site.sh drupal lws-drupal
cd drupal
SIMPLETEST_DB=sqlite://localhost//tmp/lws-test.sqlite vendor/bin/phpunit -c web/core web/modules/contrib/lws/tests
```

## Deployment notes

- **Set the canonical base URL.** Set `lws.settings:base_url`, for example
  `https://storage.example`. Left empty, LWS URIs are derived from the request's
  `Host` header, which is only acceptable in development.
- **Web servers refuse some paths before Drupal sees them:**
  - Drupal's `.htaccess` answers `403` for any path segment that starts with a
    dot, such as `/lws/alice/root/.profile`. LWS never gives a resource such a
    name.
  - Apache answers `404` for an encoded slash (`%2F`) by default. LWS rejects
    those paths anyway.
- **Drupal 11.4 and later run `index.php` through `symfony/runtime`.** Its
  Composer plugin must be allowed (`allow-plugins.symfony/runtime`), or every
  page fails.

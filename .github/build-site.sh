#!/bin/sh
# Builds a Drupal 11 codebase with this module and Drupal's development tools,
# for CI. Usage: build-site.sh <site-dir> <module-dir>
set -eu

site=$1
module=$(cd "$2" && pwd)

composer create-project drupal/recommended-project:^11 "$site" --no-interaction --no-install
cd "$site"

# Symlinked rather than copied: a copy honours the export-ignore entries in
# .gitattributes and would leave out the phpcs and phpstan configuration.
composer config repositories.lws "{\"type\": \"path\", \"url\": \"$module\", \"options\": {\"symlink\": true}}"
composer config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer config --no-plugins allow-plugins.php-http/discovery true
composer config --no-plugins allow-plugins.phpstan/extension-installer true
composer config --no-plugins allow-plugins.symfony/runtime true
composer config --no-plugins allow-plugins.tbachert/spi false

composer require 'ebremer/lws-drupal:*@dev' --no-update
composer require --dev 'drupal/core-dev:^11' --no-update
composer update --no-interaction --no-progress

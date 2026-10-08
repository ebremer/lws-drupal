<?php

/**
 * @file
 * Writes an access token for the storage "perf" to a file; see README.md.
 *
 * Run with drush php:script, with the subject and the file as arguments. The
 * token comes from this site's authorization server, lasts an hour, and is
 * never printed.
 */

declare(strict_types=1);

use Ebremer\Lws\Auth\Jwt;

// Drush passes the arguments after "--" as $extra.
[$subject, $file] = ($extra ?? []) + [NULL, NULL];
if (!is_string($subject) || !is_string($file)) {
  throw new \InvalidArgumentException('Usage: drush php:script mint.php -- <subject> <file>');
}
$active = \Drupal::service('lws_authz.signing_keys')->active();
$now = time();
$token = Jwt::sign(
  ['typ' => 'at+jwt', 'alg' => 'ES256', 'kid' => $active['kid']],
  [
    'iss' => \Drupal::service('lws_authz.local_server')->getIssuer(),
    'sub' => $subject,
    'aud' => \Drupal::service('lws.url_generator')->storageUri('perf'),
    'client_id' => 'https://app.example/id',
    'iat' => $now,
    'exp' => $now + 3600,
    'jti' => bin2hex(random_bytes(8)),
  ],
  $active['key'],
);
file_put_contents($file, $token);
printf("Wrote a token for %s to %s.\n", $subject, $file);

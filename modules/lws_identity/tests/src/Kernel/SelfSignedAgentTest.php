<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_identity\Kernel;

use Drupal\user\UserInterface;
use Ebremer\Lws\Auth\Jwt;
use Ebremer\Lws\Auth\SigningKey;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\RejectedPromise;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests a user's agent signing in with its own key at this site.
 *
 * The site's authorization server exchanges a self-signed credential
 * (lws10-authn-ssi-cid) of the agent for an access token to a storage the
 * agent controls, reading the agent's document without any HTTP request.
 */
#[Group('lws_identity')]
#[RunTestsInSeparateProcesses]
final class SelfSignedAgentTest extends IdentityKernelTestBase {

  /**
   * The URLs the site tried to fetch.
   *
   * @var list<string>
   */
  private array $fetched = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('lws_authz.settings')->set('authorization_server', 'local')->save();
    $handler = function (RequestInterface $request): RejectedPromise {
      $this->fetched[] = (string) $request->getUri();
      return new RejectedPromise(new \RuntimeException('No HTTP in this test.'));
    };
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($handler)]));
  }

  /**
   * Tests signing in, and losing the key, the permission or the account.
   */
  public function testSignIn(): void {
    $alice = $this->agentUser('alice');
    $agent = $this->uris->uriOf($alice);
    $this->storages->createStorage('alice', 'Alice', [$agent]);
    $key = SigningKey::generateP256();
    $registered = $this->keys->add($alice, $key->publicKey->jwk());

    $token = $this->issued($this->exchange($this->credential($alice, $key, $registered->getKeyId())));
    $claims = Jwt::decodeClaims($token);
    $this->assertSame($agent, $claims['sub']);
    $this->assertSame($agent, $claims['client_id']);
    $this->assertSame(200, $this->send('GET', '/lws/alice/root/', ['Authorization' => 'Bearer ' . $token])->getStatusCode());

    // The verification method's full identifier names the key too.
    $this->issued($this->exchange($this->credential($alice, $key, $agent . '#' . $registered->getKeyId())));
    // Another key, or another agent's, does not.
    $this->assertRefused($this->exchange($this->credential($alice, SigningKey::generateP256(), $registered->getKeyId())));
    $bob = $this->agentUser('bob');
    $this->assertRefused($this->exchange($this->credential($bob, $key, $registered->getKeyId())));

    // A removed key stops working at once.
    $registered->delete();
    $this->assertRefused($this->exchange($this->credential($alice, $key, $registered->getKeyId())));

    // So do the keys of an account that is blocked.
    $again = $this->keys->add($alice, $key->publicKey->jwk());
    $this->issued($this->exchange($this->credential($alice, $key, $again->getKeyId())));
    $alice->block()->save();
    $this->assertRefused($this->exchange($this->credential($alice, $key, $again->getKeyId())));

    $this->assertSame([], $this->fetched);
  }

  /**
   * Tests that an expired key is refused.
   */
  public function testExpiredKey(): void {
    $alice = $this->agentUser('alice');
    $this->storages->createStorage('alice', 'Alice', [$this->uris->uriOf($alice)]);
    $key = SigningKey::generateEd25519();
    $registered = $this->keys->add($alice, $key->publicKey->jwk(), '', time() + 3600);
    $this->issued($this->exchange($this->credential($alice, $key, $registered->getKeyId())));
    $registered->set('expires', time() - 1)->save();
    $this->assertRefused($this->exchange($this->credential($alice, $key, $registered->getKeyId())));
  }

  /**
   * A self-signed credential of a user's agent.
   */
  private function credential(UserInterface $user, SigningKey $key, string $kid): string {
    $agent = $this->uris->uriOf($user);
    $now = time();
    return Jwt::sign(['typ' => 'JWT', 'kid' => $kid], [
      'sub' => $agent,
      'iss' => $agent,
      'client_id' => $agent,
      'aud' => [self::BASE],
      'iat' => $now,
      'exp' => $now + 300,
      'jti' => bin2hex(random_bytes(8)),
    ], $key);
  }

  /**
   * Sends a token exchange for the storage alice.
   */
  private function exchange(string $credential): Response {
    return $this->send('POST', '/lws/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
      'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
      'resource' => self::BASE . '/lws/alice/',
      'subject_token' => $credential,
      'subject_token_type' => 'urn:ietf:params:oauth:token-type:jwt',
    ]));
  }

  /**
   * Asserts a token response, and returns the access token.
   */
  private function issued(Response $response): string {
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $token = $this->json($response)['access_token'] ?? NULL;
    $this->assertIsString($token);
    return $token;
  }

  /**
   * Asserts that the credential was refused.
   */
  private function assertRefused(Response $response): void {
    $this->assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('invalid_request', $this->json($response)['error']);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests containers asked for in the LWS profile (LWS Core, LWS Profile).
 *
 * A client names the profile in the profile parameter of an RDF media type.
 * A container's JSON-LD is its lws+json document, so it is in the profile;
 * no other profile, and no other RDF media type, is offered.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class ContainerProfileTest extends LwsStorageKernelTestBase {

  private const STORAGE = self::BASE . '/lws/alice/';

  private const LWS = 'https://www.w3.org/ns/lws/v1';

  private const IN_PROFILE = 'application/ld+json; profile="' . self::LWS . '"';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->storages->createStorage('alice', 'Alice', ['https://id.example/alice']);
    $this->agent = 'https://id.example/alice';
    $this->storages->createContainer($this->resources->root($this->loadStorage('alice')), 'notes');
  }

  /**
   * The Content-Type of the root container for an Accept header.
   */
  private function contentType(string $accept, string $method = 'GET'): ?string {
    $response = $this->send($method, '/lws/alice/root/', ['Accept' => $accept]);
    $this->assertSame(200, $response->getStatusCode(), $accept);
    $this->assertSame('Accept, Authorization', $response->headers->get('Vary'));
    return $response->headers->get('Content-Type');
  }

  /**
   * Tests that the LWS profile is honoured, and named in Content-Type.
   */
  public function testLwsProfile(): void {
    $this->assertSame(self::IN_PROFILE, $this->contentType(self::IN_PROFILE));
    $this->assertSame(self::IN_PROFILE, $this->contentType(self::IN_PROFILE, 'HEAD'));
    // Among other profiles, quoted or not, whatever the parameter's case.
    $this->assertSame(self::IN_PROFILE, $this->contentType('application/ld+json; profile="http://www.w3.org/ns/json-ld#compacted ' . self::LWS . '"'));
    $this->assertSame(self::IN_PROFILE, $this->contentType('application/ld+json;profile=' . self::LWS));
    $this->assertSame(self::IN_PROFILE, $this->contentType('application/ld+json; Profile="' . self::LWS . '"'));
    // The document is the lws+json one.
    $lws = $this->send('GET', '/lws/alice/root/', ['Accept' => 'application/lws+json']);
    $this->assertSame($lws->getContent(), $this->send('GET', '/lws/alice/root/', ['Accept' => self::IN_PROFILE])->getContent());
    // By quality.
    $this->assertSame('application/lws+json', $this->contentType(self::IN_PROFILE . ';q=0.5, application/lws+json'));
    $this->assertSame(self::IN_PROFILE, $this->contentType('application/lws+json;q=0.5, ' . self::IN_PROFILE));
    // JSON-LD without a profile names none.
    $this->assertSame('application/ld+json', $this->contentType('application/ld+json'));
  }

  /**
   * Tests that a profile the server cannot produce does not select JSON-LD.
   */
  public function testOtherProfile(): void {
    $other = 'application/ld+json; profile="https://example.org/other-profile"';
    $this->assertProblem($this->send('GET', '/lws/alice/root/', ['Accept' => $other]), 406, self::STORAGE . 'root/');
    $this->assertProblem($this->send('GET', '/lws/alice/root/', ['Accept' => 'application/ld+json; profile="https://example.org/a https://example.org/b"']), 406, self::STORAGE . 'root/');
    // Negotiation goes on with the other ranges.
    $this->assertSame('application/lws+json', $this->contentType($other . ', application/lws+json;q=0.1'));
    $this->assertSame('application/ld+json', $this->contentType($other . ', application/ld+json;q=0.1'));
    $this->assertSame('application/lws+json', $this->contentType($other . ', */*;q=0.1'));
  }

  /**
   * Tests that other RDF media types are not offered, in the profile or not.
   */
  public function testOtherRdfTypes(): void {
    foreach (['text/turtle', 'application/trig', 'application/n-triples', 'application/n-quads'] as $type) {
      foreach ([$type, $type . '; profile="' . self::LWS . '"'] as $accept) {
        $this->assertProblem($this->send('GET', '/lws/alice/root/', ['Accept' => $accept]), 406, self::STORAGE . 'root/');
      }
    }
  }

  /**
   * Tests that the storage description and linksets take no part in it.
   *
   * They are not containers: a profile parameter plays no part in their
   * negotiation, and they never name one.
   */
  public function testNotContainers(): void {
    foreach ([self::IN_PROFILE, 'application/ld+json; profile="https://example.org/other-profile"'] as $accept) {
      $response = $this->send('GET', '/lws/alice/', ['Accept' => $accept]);
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame('application/ld+json', $response->headers->get('Content-Type'));
    }
    $notes = $this->resources->findByPath($this->loadStorage('alice'), 'root/notes/');
    $this->assertNotNull($notes);
    $response = $this->send('GET', '/lws/alice/meta/' . $notes->uuid(), ['Accept' => 'application/json; profile="' . self::LWS . '"']);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/json', $response->headers->get('Content-Type'));
  }

}

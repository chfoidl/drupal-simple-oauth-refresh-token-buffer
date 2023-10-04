<?php

namespace Drupal\Tests\simple_oauth_refresh_token_bufer\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\TempStore\SharedTempStore;
use Drupal\Core\Url;
use Drupal\Tests\simple_oauth\Kernel\AuthorizedRequestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use GuzzleHttp\Psr7\Query;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test the refresh token buffer functionality.
 */
class RefreshTokenBufferTest extends AuthorizedRequestBase {

  /**
   * The refresh token.
   */
  protected string $refreshToken;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'consumers',
    'file',
    'image',
    'options',
    'serialization',
    'system',
    'simple_oauth',
    'simple_oauth_test',
    'user',
    'simple_oauth_refresh_token_buffer',
  ];

  /**
   * Last request id.
   */
  protected string $requestId;

  /**
   * The tempstore.
   */
  protected SharedTempStore $tempStore;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->grantPermissions(Role::load(RoleInterface::AUTHENTICATED_ID), [
      'grant simple_oauth codes',
    ]);

    $this->client
      ->set('automatic_authorization', TRUE)
      ->set('refresh_token_buffer_enabled', TRUE)
      ->set('refresh_token_buffer_wait_timeout', 100)
      ->set('refresh_token_buffer_wait_retry_count', 10)
      ->save();

    $current_user = $this->container->get('current_user');
    $current_user->setAccount($this->user);

    $authorize_url = Url::fromRoute('oauth2_token.authorize')->toString();

    $parameters = [
      'response_type' => 'code',
      'client_id' => $this->client->getClientId(),
      'client_secret' => $this->clientSecret,
      'scope' => $this->scope,
      'redirect_uri' => $this->redirectUri,
    ];
    $request = Request::create($authorize_url, 'GET', $parameters);
    $response = $this->httpKernel->handle($request);
    $parsed_url = parse_url($response->headers->get('location'));
    $parsed_query = Query::parse($parsed_url['query']);
    $code = $parsed_query['code'];
    $parameters = [
      'grant_type' => 'authorization_code',
      'client_id' => $this->client->getClientId(),
      'client_secret' => $this->clientSecret,
      'code' => $code,
      'scope' => $this->scope,
      'redirect_uri' => $this->redirectUri,
    ];
    $request = Request::create($this->url->toString(), 'POST', $parameters);
    $response = $this->httpKernel->handle($request);
    $parsed_response = Json::decode((string) $response->getContent());
    $this->refreshToken = $parsed_response['refresh_token'];

    $this->tempStore = $this->container->get('simple_oauth_refresh_token_buffer.tempstore')->get('simple_oauth_refresh_token_buffer');
  }

  /**
   * Test functionality with buffer disabled.
   */
  public function testWithoutBufferEnabled() {
    $this->client->set('refresh_token_buffer_enabled', FALSE)->save();

    // First refresh is successful.
    $response = $this->doRefresh();
    $this->assertEquals(200, $response->getStatusCode());

    // Second refresh fails.
    $response = $this->doRefresh();
    $this->assertEquals(401, $response->getStatusCode());

    // Third refresh fails.
    $response = $this->doRefresh();
    $this->assertEquals(401, $response->getStatusCode());
  }

  /**
   * Test functionality with buffer enabled.
   */
  public function testWithBufferEnabled() {
    // First refresh is successful.
    $response = $this->doRefresh();
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertFalse($response->headers->has('X-Buffered'));

    // Second is buffered.
    $response = $this->doRefresh();
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($response->headers->has('X-Buffered'));

    // Third is buffered.
    $response = $this->doRefresh();
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertTrue($response->headers->has('X-Buffered'));
  }

  /**
   * Test with failed retry count.
   */
  public function testWithBufferFailedRetryCount() {
    // Set timeout to 50 ms.
    // That should result in a total wait time of 50 ms * 10 tries = 500 ms.
    $this->client->set('refresh_token_buffer_wait_timeout', 50)->save();

    // First refresh is successful.
    $response = $this->doRefresh(TRUE);
    $this->assertEquals(200, $response->getStatusCode());

    // Manually set request status to 'wait' to simulate a pending request.
    $this->tempStore->set($this->requestId, 'wait');

    // Second tries 10 times, but fails.
    $now = microtime(TRUE);
    $response = $this->doRefresh();
    $elapsed = microtime(TRUE) - $now;

    $this->assertEquals(500, $response->getStatusCode());

    // Make sure that request was between 500 and 600 ms to ensure wait
    // time is acurate.
    $this->assertGreaterThanOrEqual(0.5, $elapsed);
    $this->assertLessThan(0.6, $elapsed);
  }

  /**
   * Test with expired buffer.
   *
   * This test case makes a legitimate refresh.
   * Then tries to do the same refresh again,
   * but buffered response already expired.
   */
  public function testWithBufferExpired() {
    // First refresh is successful.
    $response = $this->doRefresh(TRUE);
    $this->assertEquals(200, $response->getStatusCode());

    // Simulate buffered request being expired.
    $this->tempStore->delete($this->requestId);

    // Second refresh fails.
    $response = $this->doRefresh();
    $this->assertEquals(401, $response->getStatusCode());
  }

  /**
   * Execute token refresh request and return response.
   */
  protected function doRefresh($setId = FALSE) {
    $parameters = [
      'grant_type' => 'refresh_token',
      'client_id' => $this->client->getClientId(),
      'client_secret' => $this->clientSecret,
      'refresh_token' => $this->refreshToken,
      'scope' => $this->scope,
    ];
    $request = Request::create($this->url->toString(), 'POST', $parameters);
    $request->headers->set('X-Consumer-ID', $this->client->getClientId());

    if ($setId) {
      $this->requestId = hash('sha256', json_encode($request->request->all()));
    }

    $response = $this->httpKernel->handle($request);

    return $response;
  }

}

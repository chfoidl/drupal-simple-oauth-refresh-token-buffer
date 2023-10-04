<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_refresh_token_buffer\EventSubscriber;

use Drupal\Core\TempStore\SharedTempStore;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscribes to kernel events to buffer refresh token requests.
 */
class RequestSubscriber implements EventSubscriberInterface {

  /**
   * Shared temp store to store buffered requests in.
   */
  protected SharedTempStore $tempStore;

  /**
   * Construct new RequestSubscriber.
   *
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $tempStoreFactory
   *   The temp store factory service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    protected SharedTempStoreFactory $tempStoreFactory,
    protected LoggerInterface $logger,
  ) {
    $this->tempStore = $tempStoreFactory->get('simple_oauth_refresh_token_buffer');
  }

  /**
   * Get subscribed events.
   *
   * @{inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => [
        ['onKernelRequest', 100],
      ],
      KernelEvents::RESPONSE => [
        ['onKernelResponse', 100],
      ],
    ];
  }

  /**
   * Handle request.
   */
  public function onKernelRequest(RequestEvent $event): void {
    // Check if request is refresh_token grant.
    if (!$this->isRequestSupported($event->getRequest())) {
      return;
    }

    $this->checkRequest($event);
  }

  /**
   * Handle response.
   */
  public function onKernelResponse(ResponseEvent $event): void {
    $request = $event->getRequest();

    // Check if request is refresh_token grant.
    if (!$this->isRequestSupported($request)) {
      return;
    }

    $response = $event->getResponse();

    // Do not buffer non-successful responses.
    if ($response->getStatusCode() !== Response::HTTP_OK) {
      return;
    }

    // Add this response to the tempstore.
    $requestId = $this->getRequestId($request);
    $this->tempStore->set($requestId, $response->getContent());
  }

  /**
   * Check if a token refresh response was already issued for the given request.
   *
   * - If so, returns the cached response.
   * - If the refresh is already running, waits until the refresh is
   *   done and returns the cached response.
   * - After 10 recursions, aborts with a error response.
   */
  protected function checkRequest(RequestEvent $event, int $count = 0) {
    $request = $event->getRequest();
    $requestId = $this->getRequestId($request);

    // Check if we have a cached response for this request.
    $cachedResponsePayload = $this->tempStore->get($requestId);

    // Nothing in store, so pass the request to the authorization server.
    if (!$cachedResponsePayload) {
      $this->passRequest($requestId);
      return;
    }

    // If payload is special string 'wait', the token refresh is
    // already running.
    // In that case, we wait for a set period of time and try again.
    if ($cachedResponsePayload === "wait") {
      if ($count < 10) {
        $this->logger->warning('Waiting for @id', [
          '@id' => $requestId,
        ]);

        // Wait for 100 ms.
        usleep(100_000);

        // Recurse.
        $this->checkRequest($event, $count + 1);
      }
      // Give up.
      else {
        $this->setTimeoutResponseOnEvent($event, $requestId);
      }

      return;
    }

    // Immediately return the cached response to avoid race conditions.
    $this->setCachedResponseOnEvent($event, $cachedResponsePayload, $requestId);
  }

  /**
   * Checks if request is supported.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   */
  protected function isRequestSupported(Request $request): bool {
    // Only handle requests to /oauth/token.
    if ($request->getPathInfo() !== '/oauth/token') {
      return FALSE;
    }

    if ($request->getContentTypeFormat() !== "form") {
      return FALSE;
    }

    $grantType = $request->request->get('grant_type');
    if ($grantType !== 'refresh_token') {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Create a id for the request derived from the request body.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return string
   *   A SHA256 hash of all request parameters.
   */
  protected function getRequestId(Request $request): string {
    return hash('sha256', json_encode($request->request->all()));
  }

  /**
   * Passes the request to the actual handler.
   *
   * This means, that the token_refresh will be handled by the
   * authorization server.
   *
   * Additionally the state for the given request is set to 'wait'
   * in the temp store to handle simultaneous requests.
   */
  protected function passRequest(string $requestId) {
    $this->tempStore->set($requestId, 'wait');

    $this->logger->info('Allowing request for @id', [
      '@id' => $requestId,
    ]);
  }

  /**
   * Create a new response with cached data and set on event.
   */
  protected function setCachedResponseOnEvent(RequestEvent $event, mixed $payload, string $requestId) {
    $this->logger->info('Using cached response for refresh token request - @requestId', [
      '@requestId' => $requestId,
    ]);

    $response = new Response(
        content: $payload,
        status: Response::HTTP_OK,
        headers: [
          'Content-Type' => 'application/json; charset=UTF-8',
          'Cache-Control' => 'no-store, private',
          'Pragma' => 'no-cache',
        ],
      );

    $event->setResponse($response);
  }

  /**
   * Create error response when timeout.
   */
  protected function setTimeoutResponseOnEvent(RequestEvent $event, string $requestId) {
    $this->logger->error('Token refresh timeout while waiting for refresh to complete - Request-ID: @id', [
      '@id' => $requestId,
    ]);

    $response = new Response(
      status: Response::HTTP_INTERNAL_SERVER_ERROR,
      headers: [
        'Content-Type' => 'application/json; charset=UTF-8',
        'Cache-Control' => 'no-store, private',
        'Pragma' => 'no-cache',
      ],
    );

    $event->setResponse($response);
  }

}

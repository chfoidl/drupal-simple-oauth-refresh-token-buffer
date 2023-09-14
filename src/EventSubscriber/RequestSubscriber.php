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

  protected SharedTempStore $tempStore;

  public function __construct(
    protected SharedTempStoreFactory $tempStoreFactory,
    protected LoggerInterface $logger,
  ) {
    $this->tempStore = $tempStoreFactory->get('simple_oauth_refresh_token_buffer');
  }

  /**
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
  public function onKernelRequest(RequestEvent $event) {
    $request = $event->getRequest();

    // Check if request is refresh_token grant.
    if (!$this->isRequestSupported($request)) {
      return;
    }

    $requestId = $this->getRequestId($request);

    // Check if we have a cached response for this request.
    $cachedResponsePayload = $this->tempStore->get($requestId);
    if ($cachedResponsePayload) {
      $this->logger->info('Using cached response for refresh token request - @requestId', [
        '@requestId' => $requestId,
      ]);

      $response = new Response(
        content: $cachedResponsePayload,
        status: Response::HTTP_OK,
        headers: [
          'Content-Type' => 'application/json; charset=UTF-8',
          'Cache-Control' => 'no-store, private',
          'Pragma' => 'no-cache',
        ],
      );

      $event->setResponse($response);
    }
  }

  /**
   * Handle response.
   */
  public function onKernelResponse(ResponseEvent $event) {
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
   * Checks if request is supported.
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
   */
  protected function getRequestId(Request $request): string {
    return hash('sha256', json_encode($request->request->all()));
  }

}

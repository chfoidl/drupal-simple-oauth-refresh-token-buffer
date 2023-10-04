# Simple OAuth Refresh Token Buffer

This modules buffers previous successful HTTP Responses for the OAuth 2.0 `RefreshTokenGrant` of the `simple_oauth` drupal contrib module.

**Table of Contents**

- [Motivation](#motivation)
- [How does it work?](#how-does-it-work)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Enable / Disable](#enable--disable)
  - [Buffer TTL](#buffer-ttl)
  - [Wait Timeout](#wait-timeout)
  - [Wait Retry Count](#wait-retry-count)
- [Module Development](#module-development)

## Motivation

Given the following scenario:

- User accesses a page of a web app
- Multiple requests are made to fetch some data
- Those requests detect an expired access token and try to refresh the tokens
- Multiple requests to refresh the tokens are made to Drupal
- Drupal handles the first token refresh successfully
- Other requests will fail, because the refresh token has been revoked on the first request

This scenario is a real pain to solve on the client.
Therefore this module tries to solve this problem directly on the server.

This leads to e.g. 5 simultaneous token refresh requests to return the same response.

## How does it work?

Whenever a token refresh request is made to `/oauth/token` with the payload format for the `RefreshTokenGrant`, this module first creates a unique ID for this request and checks if this exact request was already made previously.

- If it was not, the request is handled normally by the Authorization Server and the response is then temporarily saved.
- If it was, but the token refresh is not finished yet, the server waits for the refresh to complete and then returns the saved response.
- If it was and the refresh was already completed, it returns the previous response.

## Installation

```bash
composer require drupal/simple_auth_refresh_token_buffer
drush en simple_auth_refresh_token_buffer
```

## Configuration

The functionality of this module can be configured per `Consumer`.
Settings can therefore be found on the settings page for each `Consumer`.

### Enable / Disable

For the refresh token buffer to take effect, the functionality must be explicitly enabled for the desired `Consumer`.

If enabled, refresh token responses are buffered for each request identified as the given `Consumer`.

### Buffer TTL

The time to live for each buffered response can be configured as a *Service Parameter*:

```services.yml
parameters:
  # Make buffered responses expire after 60 seconds.
  simple_oauth_refresh_token_buffer.expire: 60
```

### Wait Timeout

When a token refresh is already pending, the current request for the same token refresh must wait for a set period of time until checking again if the response for the token refresh has been buffered.

The timeout value can be configured in the `Consumer` settings.

### Wait Retry Count

Number of tries the request handler checks for the finished token refresh response when the token refresh is already pending.

After exceeding this retry count an error response is being returned.

The retry count value can be configured in the `Consumer` settings.

## Module Development

[Development is done over at GitHub!](https://github.com/wunderwerkio/drupal-simple-oauth-refresh-token-buffer)

Please file any issues and pull requests there.

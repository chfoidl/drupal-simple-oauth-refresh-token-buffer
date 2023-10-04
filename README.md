# Simple OAuth Token Refresh Buffer

This modules buffers previous successful HTTP Responses for the OAuth 2.0 `RefreshTokenGrant` of the `simple_oauth` drupal contrib module.

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

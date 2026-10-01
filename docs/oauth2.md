# OAuth 2.0 and API access

Passport provides OAuth 2.0 access for user-facing applications and machine integrations. OAuth clients are managed in the Filament **System → OAuth Clients** page by accounts with the `oauth-clients.view`, `oauth-clients.create`, and `oauth-clients.revoke` permissions.

## Client types

Use a **Public client (Authorization Code + PKCE)** for native apps and other clients that cannot keep a secret. The redirect URI must use HTTPS, except for HTTP loopback redirects during local development. Public clients have no secret; clients must generate a PKCE verifier and send its S256 challenge with the authorization request.

Use a **Machine client (Client Credentials)** only for a trusted server-to-server integration. It receives a secret and can request the `system:read` scope for the safe integration status endpoint. Store the downloaded client configuration in a secret manager as soon as it is created.

The creation action downloads the client configuration once. The page and audit log never show or record a client secret. If a configuration file is lost, revoke that client and create a replacement. Revocation marks the client unavailable and revokes its current access and refresh tokens.

## Endpoints

Passport's authorization and token endpoints are `/oauth/authorize` and `/oauth/token`. The API is versioned under `/api/v1`:

| Endpoint | Access | Result |
| --- | --- | --- |
| `GET /api/v1/health` | Public | Generic application health status |
| `GET /api/v1/me` | User token with `profile:read` | The current user's ID, name, email, and email verification status |
| `GET /api/v1/search/users?query=...` | User token with `users:search`, plus `search.use` and the User `viewAny` policy | A bounded, paginated list of active matching users |
| `GET /api/v1/integration/status` | Client Credentials token with `system:read` | Generic integration availability status |

Send access tokens with `Authorization: Bearer <access-token>`. API requests reject inactive user accounts. Search responses include only user ID, name, email, and verification status; they do not include account permissions or secrets.

## Scopes

| Scope | Purpose |
| --- | --- |
| `profile:read` | Read the authenticated user's own profile |
| `users:search` | Use the user search API, subject to application permission and policy checks |
| `system:read` | Read the machine integration status endpoint |

The API rate limit is applied independently of OAuth scopes and permissions. A token scope does not grant an application permission by itself.

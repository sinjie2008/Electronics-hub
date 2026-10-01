# User search

Laravel Scout's database engine provides the user search API without an external search service. The `User` model exposes only its ID, name, and email to Scout. The database engine searches those fields directly, so normal user changes do not require a separate index import.

## API

Call `GET /api/v1/search/users` with a Passport user token that has the `users:search` scope. The authenticated account must also have the `search.use` permission and pass the User `viewAny` policy. Only active accounts are returned.

| Parameter | Rules | Default |
| --- | --- | --- |
| `query` | Required, 2–100 characters; SQL wildcard characters `%` and `_` are rejected | — |
| `per_page` | Integer from 1 to 50 | 25 |
| `page` | Integer from 1 to 1,000 | 1 |

Results are paginated and serialize only each user's ID, name, email, and email verification status. Search does not return permission assignments or account secrets.

The database driver is suitable for this bounded directory search without external infrastructure. If the application later needs typo tolerance or a dedicated search cluster, review the data and privacy requirements before changing engines; preserve the same field allow-list and API authorization checks.

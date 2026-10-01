# Permissions and API access

Browser administration uses the `web` guard and Spatie Laravel Permission. The admin panel at `/admin` is available only to active users with `access.admin`; individual user, role, permission, settings, and backup actions are protected by their policies and permissions.

## Roles and permissions

`php artisan migrate --seed` installs the baseline system roles and permissions. The canonical names and grants are declared in database/seeders/RolesAndPermissionsSeeder.php; review those seeders when changing the baseline access model. A seeded system role or system permission is protected from unauthorized rename or deletion, and permissions that are still assigned cannot be removed. In particular, only a Super Admin can manage the Super Admin role and its protected access grant.

When Catalog is enabled, the host seeder creates four system permissions in the application database: `catalog.view`, `catalog.create`, `catalog.update`, and `catalog.delete`. These allow Catalog view, create, update, and delete operations. They are permission definitions only; the `Admin` role receives no Catalog grants by default. Assign the specific permissions a role needs through `/admin/roles`. Active Super Admins continue to use the existing application-level bypass. Catalog screens may hide actions from unauthorized users, but the corresponding server-side checks remain authoritative. See [Catalog operations](catalog.md) for module setup.

Super Admin is the sole application-level permission bypass. AppServiceProvider owns the ordered Gate::before hook: inactive accounts are denied first, the protected Super Admin role receives the bypass, and ordinary namespaced permissions then use Spatie checks. Spatie's automatic Gate hook is disabled to preserve this order and ensure generic policy verbs reach their policies. A direct access.super-admin permission never creates a bypass. Other actors may assign or revoke only permissions they currently hold. This prevents a role editor from granting themselves or another user a privilege they do not already possess. The same policy boundary applies to user role assignment and to role/permission editing; hiding an action in Filament is not a substitute for checking its policy.

Create the first administrator interactively with `php artisan app:create-admin`. The seeder can also create one initial administrator when the deployment supplies `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL`, and `INITIAL_ADMIN_PASSWORD` for the initial seed. Treat those values as one-time secrets and remove them afterward. Public self-registration is not enabled.

## OAuth scopes and application ACL

Passport separates OAuth client authorization from application permissions. A token scope only grants access to the corresponding API route; it does not attach a role or permission to a user. Routes that act on a user must enforce both the requested Passport scope and the relevant Laravel authorization policy or permission.

| Scope | Purpose |
| --- | --- |
| `profile:read` | Read the authenticated user's own API profile. |
| `users:search` | Use the user-search API, subject to the user's `search.use` and `users.view` permissions. |
| `system:read` | Read the limited integration status with a client-credentials token. |

Interactive clients use authorization code with PKCE. Integration clients use client credentials and should receive only the scopes needed for their work. Keep client secrets in the credential store of the consuming service. Public clients do not have a client secret.

For the exact endpoints and their required scopes, see the [README route table](../README.md#oauth2-passport-and-api). The public health endpoint returns generic status only and does not grant access to private system information.

## Review checklist for new actions

When adding an admin action or API route:

1. Define the permission in IAM's baseline only if it is part of the product's default access model; otherwise add it through a controlled migration or application setup path.
2. Enforce authorization at the policy, Gate, or route middleware boundary. Keep the same check on the server even if the UI hides the control.
3. Check actor-to-target escalation: a user must not assign permissions or roles they cannot themselves grant.
4. For API routes, require both OAuth scope and application authorization when the action concerns protected application data.
5. Test success, missing authentication, insufficient scope, and insufficient application permission as separate observable behaviors.

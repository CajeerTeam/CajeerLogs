# RBAC & Authorization — 0.4.2

## Upgrade

Stop application/worker processes, back up PostgreSQL and configuration, overlay the release files, then run `php bin/cajeer migrate`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify` and `php bin/cajeer doctor`. Migration 0017 adds `rbac_roles` and optional assignments on `users` and `project_members`; all previous migrations and existing role grants are unchanged. Existing sessions keep working and pick up permission changes on subsequent protected requests. Restore from earlier compatible releases applies the missing migrations before promotion. Do not downgrade code over an upgraded schema without a compatible backup.

## Permission model

The immutable global roles Administrator, Operator and Viewer remain the privilege ceilings. Only Administrator can create, edit, assign or delete custom roles, manage users, projects and membership, change project/system security policies and read global audit. Administrator cannot receive a restrictive assignment. A custom global role limits an Operator or Viewer across all their projects; a project role is a reusable restriction applied to an explicit membership. The effective grants are the intersection of the base global role, optional global custom role, legacy membership role and optional custom project role. Project access still requires membership for non-administrators. A Viewer cannot gain Operator permissions from any assignment. No role grants system-wide administration to a non-administrator.

| Permission | Purpose |
|---|---|
| projects:read | Project/source metadata and aggregate project statistics (required in custom roles) |
| logs:read / logs:export | Search, details, log statistics, saved views / server page export |
| logs:write | Delegate ingest via a machine credential; human sessions do not ingest |
| sources:create / sources:update / sources:state | Create / edit source metadata and quotas / enable, disable, archive, restore |
| tokens:read / tokens:create / tokens:update / tokens:rotate / tokens:revoke / tokens:signing | Credential metadata / issue / edit / rotate / revoke / configure and rotate HMAC |
| services:read / services:manage | Service account metadata / create and disable (disable also requires tokens:revoke) |
| audit:read | Project security audit |

Custom permissions are a fixed whitelist; `logs:export` requires `logs:read`, token mutations require `tokens:read`, service mutations require `services:read`. Legacy coarse permissions are still reported for built-in roles but no new custom role can contain them. Existing machine credentials preserve scopes, source/project restrictions, lifetime and revocation; role changes do not revoke previously issued credentials. A user denied log export cannot issue or rotate a `logs:read` token because legacy read tokens can export. Token rotation may issue an associated signing key where required; separate signing management requires `tokens:signing`. Reading logs is not DRM: users who can see an event can copy its contents even if server export is disabled.

## Administration

Open **Roles and permissions** (`/admin/#rbac`) as Administrator. Create a global or project role with a name and selected grants. In the user card assign a global role; in the user's project list or project member table assign a project role. Use **Effective permissions** to see actual server-derived grants. Removing an assignment restores the remaining applicable ceilings; this may expand access. A role cannot be deleted while assigned. Edit/delete require current revision, assignment requires current `expected_role_id` (including null); stale operations return 409. Changes are audited in the global journal, with project assignments also visible in the relevant project journal. The editor permits up to 200 custom roles across both scopes.

## API v1

All role operations require a user session with `roles:manage`; mutations require CSRF. `GET /roles` returns the catalogue, immutable grant ceilings and custom permission registry. `POST /roles` accepts `name`, `scope` (`global` or `project`) and `permissions`; `PATCH /roles/{role}` accepts `name`, `permissions`, `revision`; `DELETE /roles/{role}` accepts `revision`. `PUT /users/{user}/role-assignment` and `PUT /projects/{project}/members/{user}/role-assignment` accept `role_id` and `expected_role_id` (UUID or null). `GET /users/{user}/permissions?project_id=UUID` is admin-only; omitted project means global grants. `/auth/me` and login include global `user.permissions`, project list/detail include `effective_permissions`. Legacy `role` and `effective_role` remain baseline labels, not the complete authorization decision.

## Verification

`php tests/identity.php`, `npm --prefix admin run build`, `php bin/cajeer openapi:lint`. For the real integration test run `APP_ENV=test CAJEER_TEST_DATABASE=1 php tests/rbac_database.php` with disposable PostgreSQL credentials and `pdo_pgsql`; it creates and drops its own schema. The exact release ZIP gate requires this test and the real 0.4.1 upgrade, plus existing DB/browser matrix and production smoke. Public Preview is retained until these checks actually pass.

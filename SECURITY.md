# Security policy

## Supported versions

Only the latest release receives security fixes.

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

Email **hello@indianopinion.org** with "query-id-cache security" in the
subject. Please do not open a public issue for a vulnerability.

Include what you found, how to reproduce it, and the WordPress and PHP
versions involved. We acknowledge reports within 3 working days and aim to
ship a fix, or a clear plan, within 30 days. We will credit you in the
release notes unless you ask us not to.

## Scope

Reports we especially want: any way the plugin could serve a draft, private,
future or per-user list to a visitor, serve cached lists to a signed-in user
or a preview, leak keys, SQL or IDs through the debug header, or let request
input influence a cache key other than through the hashed final SQL.

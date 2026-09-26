# tops-infra

Laravel integrations that let a TrafficOps application manage hostnames on
customer-owned Cloudflare accounts (BYO token) and custom domains on Laravel
Cloud. The two modules are independent: fast-landings consumes the Cloudflare
module, ads-toolbox (a private TrafficOps repo) consumes the Laravel Cloud
module.

## Language

### Cloudflare

**Owner**:
The application model that connects integrations and holds claims. Owners never
see each other's integrations, zones or claims; this is the isolation boundary.
_Avoid_: tenant, attachable, account

**Integration**:
One Cloudflare API token connected by an owner. The same token may be connected
by several owners; exclusivity is the application's decision.
_Avoid_: connection, credential, token (for the entity)

**Degraded** (integration status):
The token works but at least one of the integration's claims is drifted,
errored or unreachable.

**Invalid** (integration status):
The token is revoked, expired or lacks the required scopes. Nothing about an
invalid integration is checked until it is reconnected.
_Avoid_: broken, expired (as a status)

**Account**:
A Cloudflare account as seen through one integration. The same real account
reached through two tokens is two accounts here.

**Zone**:
A Cloudflare DNS zone visible through an integration, usually a registrable
apex.

**Sync**:
Refresh the list of accounts and zones an integration can see. Zones that
disappear are marked inaccessible, never deleted; claims inside an inaccessible
zone go to Error.
_Avoid_: refresh, reload

**Claim**:
A hostname, exact or wildcard, that an owner has reserved inside a zone together
with the DNS records it expects. Claims of different owners may not overlap;
one owner may hold a wildcard base and exact hostnames beneath it, and the
exact claim is the more specific one. An apex and a wildcard on the same zone
are independent claims.
_Avoid_: domain, hostname (for the entity), attachment

**Expectation**:
One DNS record a claim requires to exist in the zone. Its name is the claim's
hostname or lies beneath it.
_Avoid_: desired record, definition

**Managed record**:
A zone record this module created to satisfy an expectation. It is the only
kind of record the module will ever modify or delete.

**Adopted record**:
A zone record that already existed and matched an expectation. Adopted records
are never modified or deleted by the module, even when the claim is removed.
_Avoid_: external record, foreign record

**Reconcile**:
Write to the zone so that every expectation of a claim is satisfied: create
missing managed records, update stale managed records, leave everything else
alone. A record of the same type and name with different content is a conflict
and stops the reconcile.
_Avoid_: provision, apply, sync

**Check**:
Read-only comparison of a claim's expectations against the zone and against
public DNS. A check never writes.
_Avoid_: verify, validate

**Control view / Public view**:
The two answers a check produces for each expectation: what the Cloudflare API
reports (control) and what public resolvers return (public).
_Avoid_: control status, public status (as concepts)

**Drift**:
A check found a control-view record missing or changed. Drift is reported as an
event and never healed automatically; an operator reconciles explicitly.

### Laravel Cloud

Consumed by ads-toolbox, outside this workspace.

**Custom domain**:
A domain registered on one Laravel Cloud environment. Always stored as the apex
name; wildcard coverage is a flag on it, not a separate hostname.
_Avoid_: domain (unqualified), claim

**DNS requirement**:
A DNS record Laravel Cloud asks the customer to create for a custom domain.
Requirements are informational and are not turned into Cloudflare expectations.
_Avoid_: record, expectation

**Verification** (Laravel Cloud):
Asking Laravel Cloud to re-check a custom domain's DNS. Distinct from a
Cloudflare check, which is local and read-only.

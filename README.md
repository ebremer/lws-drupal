# lws-drupal

Drupal 11 modules that make a Drupal site a [W3C Linked Web Storage (LWS)
1.0](https://www.w3.org/TR/2026/WD-lws10-core-20261005/) server, following the
core Working Draft of 5 October 2026. The design, and the plan for building it,
is in [DESIGN.md](DESIGN.md).

| Module | What it does |
|---|---|
| `lws` | The LWS URL space under `/lws`, problem details, content negotiation, CORS, the guard on outbound requests, settings |
| `lws_authz` | Access tokens: the site's own authorization server, trusted external ones, token validation, the access decision |
| `lws_storage` | Storages and their resources: the storage description, containers, data resources, linksets, the administration pages, Drush commands |
| `lws_notify` | Notifications: the notification service, webhook subscriptions, and signed deliveries of changes and of access requests and grants (optional) |
| `lws_index` | The type index and type search services: the types of what an agent may read, and the resources that match a filter on types and links (optional) |
| `lws_identity` | Agent identities for Drupal users: an agent URI with a controlled identifier document naming their keys and OpenID Providers, and optionally a storage each (optional) |
| `lws_agent_users` | LWS agents as Drupal users: access policies for Drupal roles, blocking an account to bar its agent, a permission to bypass access policies, and optionally an account for every agent (optional; needs `externalauth`) |
| `lws_projection` | Drupal content as LWS resources: read-only storages of the nodes, media, terms or other content a visitor may view, as JSON, kept up to date (optional) |

## Status

**Step S8, projections** (DESIGN.md §10), after S1, storages, A1, access
tokens, S2, data resources, S3, pagination, S4, metadata and JSON Patch, A2, the
authorization server, A3, access policies, A4, access requests and grants, S5,
hardening and the administration pages, A5, OpenID Connect, S6,
notifications, S7, the type index, I1, agent identities, and U1, agent users. Storages are created at *Content ›
LWS storages* or with Drush; everything in them is managed over HTTP, with
access tokens the site issues itself, for self-signed credentials or OpenID
Connect ID Tokens, by their controllers and by the agents their access policies
allow:

| Request | Response |
|---|---|
| `GET /lws/{storage}/` | The storage description (`application/lws+cid`, or `ld+json`/`json` by `Accept`), to anyone |
| `GET`/`HEAD` a container | A listing (`application/lws+json`, or `ld+json`/`json`) with each member's type, media type, size and modification time, in pages (100 members by default) linked with `first`, `next`, `prev` and `last` |
| `GET`/`HEAD` a data resource | Its bytes, with byte ranges (`206`, `416`) |
| `POST` to a container | `201` and `Location`: a data resource from the body, or a container with `Link: <https://www.w3.org/ns/lws#Container>; rel="type"`. The `Slug` header suggests the name |
| `PUT` a data resource | `204`: its content replaced. There is no create-by-`PUT` |
| `PATCH` a JSON data resource | `204`: a JSON Patch (`application/json-patch+json`) applied, all or nothing (`409` if a `test` fails) |
| `DELETE` | `204`. A container that is not empty needs `Depth: infinity` (`409` otherwise) |
| `GET`, `PUT`, `PATCH /lws/{storage}/meta/{uuid}` | The linkset of a resource (`application/linkset+json`): its parent and types, and the links clients manage. Server-managed links cannot change (`409`) |
| `Link` headers on `POST`; with `Prefer: set-linkset`, on `PUT` and `PATCH` | Set the links clients manage, such as types (`rel="type"`) and licenses |
| `If-Match`, `If-None-Match`, `If-(Un)Modified-Since` | `304` or `412` as RFC 9110 says |
| `POST /lws/{storage}/access/requests/` | An access request (`AccessRequest`, `application/lws+json`) for the agent itself |
| `POST /lws/{storage}/access/grants/` | An access grant, by a controller: its policies take effect at once |
| `GET` either service, or an entry | The requests and grants the agent may see: all of them for a controller, else its own and the grants that name it |
| `DELETE` an entry | Cancels a request (its agent or a controller), or revokes a grant (a controller), at once |
| `POST /lws/{storage}/notifications/` | With `lws_notify`: a `WebhookSubscription` (`application/lws+json`) to resources the agent may read, delivered to its `inbox` ([Notifications](#notifications)) |
| `GET` the service, or a subscription | The agent's live subscriptions, as an LWS container; a subscription's state, to its agent and the controllers |
| `DELETE` a subscription | Cancels it (its agent or a controller) |
| `GET /lws/{storage}/types/index` | With `lws_index`: the distinct types of the resources the agent may read, as a paged `TypeIndex` ([Type index and search](#type-index-and-search)) |
| `QUERY /lws/{storage}/types/search` | With `lws_index`: the resources the agent may read that match an `application/lws-query+json` filter on types and links, as a paged `ContainerPage` |
| `GET /lws/agents/{uuid}` | With `lws_identity`: an agent's controlled identifier document (`application/cid`, or `ld+json`/`json`), to anyone ([Agent identities](#agent-identities)) |
| `GET /lws/roles/{role}` | With `lws_agent_users`: `404`. A role URI names a Drupal role as the assignee of access policies, and nothing else ([Agent users](#agent-users)) |
| `GET /lws/{projection}/root/{entity type}/{bundle}/{id}` | With `lws_projection`: an entity a visitor may view, as JSON (`application/json`), in a read-only storage ([Projections](#projections)) |
| `GET /.well-known/lws-configuration` | The authorization server's metadata (RFC 8414) |
| `POST /lws/oauth/token` | Token exchange (RFC 8693): a self-signed credential or an OpenID Connect ID Token for an access token to a storage |
| `GET /lws/oauth/jwks` | The keys that sign access tokens |
| No token, or a rejected one | `401` with a Bearer challenge: `as_uri` (the authorization server), `realm` (the storage) and, for a rejected token, `error` |
| A valid token, but no policy allows it | `403`, or `404` with `lws.settings:conceal_existence` |
| Over the storage's quota | `507` |
| A body larger than the largest content, or than PHP's `post_max_size` for a `POST` | `413`, before any of it is read |
| A body shorter than its `Content-Length` | `400`: nothing is kept |

Successful responses carry `ETag` and `Link` headers (storage, type, parent,
linkset), and errors are RFC 9457 problem details. Every response in the LWS URL
space is sandboxed (`Content-Security-Policy: sandbox`). Content is kept as
managed files in the private file system and never served through Drupal's own
file routes, except to storage administrators, as attachments.

Listings show each agent only what it may read. An agent who may not read
everything in a container gets a page of its own: its `ETag` is of what it sees,
it has no `Last-Modified`, and a page examines at most 1,000 members
(`$settings['lws_storage_scan_limit']`), so it may hold fewer than the page
size and go on with `next`. Its `totalItems` counts the visible members among
the first 1,000, never more than there are (LWS Core §8.1 lets it be
approximate).

```sh
drush lws:storage:create alice --controller=https://id.example/alice --quota=1000000000 --page-size=50
drush lws:key:rotate                                   # a new signing key, as the web server's user
drush lws:as:add main https://as.example --default    # or trust another authorization server
drush lws:storage:list
drush lws:storage:delete alice
drush lws:gc                                           # sweep unreferenced content
```

## Administration

| Page | What it does |
|---|---|
| *Content › LWS storages* (`/admin/content/lws`) | The storages: all of them for storage administrators, and their own for owners with *Manage own LWS storages* |
| `/admin/content/lws/add`, `/admin/content/lws/{id}` | Add or edit a storage: label, slug (fixed once made), controllers, owner, authorization server, quota (such as `10 GB`), page size, whether changes need `If-Match`, and whether it is enabled |
| `/admin/content/lws/{id}/access` | Who may do what in it ([Sharing](#sharing)) |
| `/admin/content/lws/{id}/resources` | A read-only resource browser, which is a View (`lws_resources`, with Views): path, kind, media type, size, creator, and *Download* and *Create media item* |
| `/admin/content/lws/{id}/subscriptions` | With `lws_notify`: who subscribed to what, where it is delivered, and how deliveries fare; *Cancel* |
| `/admin/content/lws/{id}/delete` | Deletes it with everything in it; a large storage is blocked first and emptied in a batch |
| `/user/{uid}/lws-identity` | With `lws_identity`: the user's agent URI and keys; *Add a key*, *Remove*. For the user, with *Manage own LWS agent keys*, and for administrators of agents |
| `/user/{uid}/edit` | With `lws_agent_users`: *LWS agent URI*, the agent that acts as the user, and whether the account was made for one. For administrators of users |
| *Configuration › Web services › Linked Web Storage* | The base URL, path prefix and concealment; on the *Storages* tab, where content goes, the largest content, the largest recursive delete, the page size and whether changes need `If-Match`; on the *Notifications* tab, whether activities name their actor, the limits on subscriptions, and how deliveries are made and retried; on the *Type index* tab, the relations searches may filter on; on the *Agent identities* tab, the OpenID Providers agents' documents name and whether each new agent gets a storage; on the *Agent users* tab, whether agents without an account get one, within which limits, and when unseen ones go; on the *Projections* tab, the projections of Drupal content, each with its storage, the content it holds, the type of each bundle's resources, and whether anyone may read it |

*Create media item* (with Media) copies a data resource's content into a new,
unpublished Media item of a type made from a file. The copy is the site's: later
changes to the resource or to who may access it do not change it.

Creating storages, and everything else here but the access page, needs
*Administer LWS storages*. Whether users may create storages of their own is an
open question (DESIGN.md §14, Q2).

## Sharing

A storage's controllers may do anything in it. Anyone else may do what its
access policies allow. A policy is an `AccessPolicy` of the access profile (LWS
Core §11.3):

- **who:** an agent, everyone (`http://xmlns.com/foaf/0.1/Agent`, with or
  without a token) or every authenticated agent
  (`http://www.w3.org/ns/auth/acl#AuthenticatedAgent`);
- **may:** `read`, `create`, `modify`, `delete`;
- **in:** resources, where a container includes everything in it at any depth,
  limited to containers, data resources or both;
- **if:** constraints on the `client`, the `format`, the `type` and the
  `dateTime`, which must all hold. What cannot be evaluated does not hold: a
  container has no format, a resource that does not exist has no format or
  types, and no request states a `purpose`.

Listings show each agent only the members it may read. Removing a policy takes
effect on the next request.

Over LWS, agents ask for access at the storage's `AccessRequestService`, and its
controllers grant it at its `AccessGrantService` (LWS Core §11): a grant's
policies are stored with it, and go when it is revoked. Requests wait on the
access page, which approves them, granting what they ask, or denies them.

Policies are managed at `/admin/content/lws/{storage id}/access` by storage
administrators and by a storage's owner with *Manage own LWS storages*, or with
Drush:

```sh
drush lws:policy:add alice public --target=https://storage.example/lws/alice/root/public/
drush lws:policy:add alice https://id.example/bob --action=read --action=create --until=2026-12-31T23:59:59Z
drush lws:policy:add alice --json=policy.json          # an AccessPolicy object
drush lws:policy:list alice
drush lws:policy:delete alice 3
```

## Notifications

With `lws_notify` enabled, each storage description advertises a
`NotificationService` that offers `WebhookSubscription`s (LWS Core §10,
[lws10-notifications-webhook](https://w3c.github.io/lws-protocol/lws10-notifications-webhook/)).
An agent subscribes with a `POST` of its `topic`s, the resources it is about,
and an `inbox`:

```json
{"type": "WebhookSubscription", "topic": ["https://storage.example/lws/alice/root/notes/"], "inbox": "https://app.example/inbox", "expires": "2026-12-31T23:59:59Z"}
```

- **Subscribing.** The agent must be able to read every topic (`403`
  otherwise), with the token's client. A container covers everything in it, at
  any depth; a data resource only itself; the storage URI stands for its root
  container. The inbox must pass the same guard as other outbound requests
  (`422` otherwise). A subscription lasts at most 30 days by default, an agent
  holds at most 20 at a storage (`429`), and the answer is `201` with
  `Location` and the subscription's `expires`.
- **What is delivered.** Each change to a covered resource becomes an Activity
  Streams `Create`, `Update` or `Delete`, if the subscriber may read the
  resource when the change is made: removing someone's access stops their
  notifications at once. The changes of one request go in one notification
  per subscription. Who made a change is withheld unless the settings say
  otherwise. A new or deleted access request or grant is announced to the
  inbox it names, and a grant made by approving a request to the request's
  inbox (LWS Core §11.6).
- **How.** A `POST` of the `Notification` (`application/lws+json`) after the
  response is sent, signed with HTTP Message Signatures (RFC 9421) over
  `@method`, `@scheme`, `@authority`, `@path`, `content-type` and
  `content-digest` (RFC 9530, SHA-256). The key is the site's, published in
  every storage description as a verification method `{storage}#{kid}`
  referenced from `authentication`; `WebhookVerifier` in
  [lws-client](https://github.com/ebremer/lws-client) checks such deliveries.
- **When an inbox fails.** A `5xx`, a `429` or no answer is tried again after
  2 seconds, then after 1, 10 and 60 minutes and 6 hours, the later ones on
  cron. Five failed deliveries in a row deactivate the subscription, and a
  `410 Gone` at once. A deactivated or expired subscription shows
  `"active": false` for a week, then is deleted.

```sh
drush lws:notify:list alice                # the subscriptions to a storage
drush lws:notify:cancel alice <uuid>
drush lws:notify:key:rotate                # a new webhook signing key, as the web server's user
drush queue:run lws_notify_delivery        # deliver what waits for cron now
```

## Type index and search

With `lws_index` enabled, each storage description advertises a
`TypeIndexService` and a `TypeSearchService`
([lws10-index](https://w3c.github.io/lws-protocol/lws10-index/)). A search is
an HTTP `QUERY` (RFC 10008) whose body is a filter in conjunctive normal form:
each member names a relation, and holds groups that must all match, each an
IRI or an array of IRIs of which one must.

```http
QUERY /lws/alice/types/search HTTP/1.1
Content-Type: application/lws-query+json

{"type": [["https://schema.org/Person", "http://xmlns.com/foaf/0.1/Person"], "https://www.w3.org/ns/lws#DataResource"],
 "describedby": ["https://shapes.example/PersonShape"]}
```

- **What is indexed.** Each resource's types, as its `Link` headers and
  linkset declare them, with its class (`lws:Container` or
  `lws:DataResource`), and the targets of the other links its clients set. The
  index changes in the same transaction as the resource, so it is never
  behind.
- **Types read from content,** if the *Type index* settings turn it on
  (`lws_index.settings:content_types.enabled`; off by default). Turtle and
  N-Triples content up to `content_types.max_bytes` (256 KiB) is parsed as it
  is saved, and the IRIs it gives the resource itself with `rdf:type`
  (`<> a <https://schema.org/Note> .`) are indexed, searched and filtered
  exactly as declared types are, up to 64 of them. Content that does not parse
  states no type and is stored all the same. They are not added to the linkset.
  JSON-LD is not read, so no remote context is ever fetched. After turning it on
  or off, `drush lws:index:rebuild` reads the content stored before.
- **Which relations a search may filter on.** `type`, and the descriptive
  relations listed on the *Type index* settings tab: by default `about`,
  `author`, `cite-as`, `describedby`, `license`, `profile` and `related`.
  Structural relations, such as `up` or `self`, never. The list is not
  published: a filter on any other relation finds nothing, as one on a target
  nothing declares does.
- **What an agent sees.** Only the resources it may read, and the types they
  bear, decided when it asks: removing someone's access removes their results
  at once. Searching needs no token; without one, an agent finds what is
  public. As for listings, an agent who may not read the whole storage has
  each resource checked, a page examines at most
  `$settings['lws_storage_scan_limit']` resources, and `totalItems` counts
  what it may see among the first of them.
- **Pages.** Results come in the order resources were made, types in code
  point order, both in pages of the storage's page size, linked with `first`
  and `next`. A search's page links are read with `GET`: each carries the
  filter, encrypted, so the server keeps nothing between pages.
- **Errors.** `400` without a `Content-Type`, or for a body that is not a
  filter (an empty group, a value that is not an absolute IRI); `415`, with
  `Accept-Query`, for another query format; `406` when `Accept` excludes JSON;
  `422` for a filter of more than 32 groups, 64 IRIs or 4,096 bytes, which is
  never narrowed instead; `404` for a page link that is not one.
  `OPTIONS` answers `Allow: GET, HEAD, QUERY, OPTIONS` and
  `Accept-Query: application/lws-query+json`.

```sh
drush lws:index:rebuild                    # index every resource again
```

## Agent identities

With `lws_identity` enabled, users with the *Have an LWS agent identity*
permission have an agent: an agent URI,
`https://site.example/lws/agents/{user UUID}`, by which LWS storages here and
elsewhere know them. It serves a controlled identifier document (CID 1.0,
`application/cid`, or `ld+json`/`json` by `Accept`) to anyone, and says nothing
about the user but:

- **Keys,** for self-signed credentials
  ([lws10-authn-ssi-cid](https://www.w3.org/TR/lws10-authn-ssi-cid/)): the
  public keys the user adds on the *LWS identity* tab of their account, with
  *Manage own LWS agent keys*, or that Drush adds, as `JsonWebKey`
  verification methods, `{agent URI}#{key ID}`. EC P-256 and P-384, Ed25519,
  and RSA of 2048 bits or more, up to 16; a private key is refused. A key may
  expire. A program that holds one signs a JWT whose `sub`, `iss` and
  `client_id` are the agent URI, whose `aud` is the authorization server and
  whose `kid` is the key ID, and exchanges it for an access token.
- **OpenID Providers** ([lws10-authn-openid](https://www.w3.org/TR/lws10-authn-openid/)),
  from the *Agent identities* settings tab: every agent's document names them
  as `lws:OpenIdProvider` services, so an ID Token one of them issues with the
  agent URI as `sub` stands for the agent. List only providers that issue such
  tokens for this site's users. Drupal is not an OpenID Provider itself.

This site's authorization server reads its own agents' documents directly,
never over HTTP and never cached: a key removed, or an account blocked, stops
working here at once, and elsewhere when the document is next read. An account
that is blocked or loses the permission has no document (`404`), and its keys
go when it is deleted.

**A storage for each agent,** if the settings turn it on
(`lws_identity.settings:provisioning.storage`; off by default; it needs
`lws_storage` and the canonical base URL): when an account first has an agent,
it gets a storage named after the user, such as `/lws/zoe-smith/`, controlled
by the agent and owned by the user. Only once: a storage an administrator
deletes is not made again, unless `drush lws:agent:provision` asks for it.

```sh
drush lws:agent:show alice                          # the agent URI and its keys
drush lws:agent:key-add alice key.pub.json --label=laptop --expires=2027-01-01
drush lws:agent:key-generate alice --label=bot > alice.jwk  # the private JWK is printed once, never stored
drush lws:agent:key-delete alice <kid>
drush lws:agent:provision alice                     # a storage for the agent
```

## Agent users

With `lws_agent_users` enabled (it needs the contrib
[`externalauth`](https://www.drupal.org/project/externalauth) module), an LWS
agent can act as a Drupal user. In its LWS requests, and only there, the agent
is that user:

- **Roles are assignees.** An access policy whose assignee is
  `https://site.example/lws/roles/{role}` applies to the agents of the users
  in that role, and one for `…/roles/authenticated` to every agent that acts as
  a user. A change of roles counts from the next request. Role URIs are
  identifiers only, and answer `404`.
- **Blocking bars the agent.** The agent of a blocked user gets `403` for
  everything in the LWS URL space, its own storages included, and its
  subscriptions get no notifications. Unblocking lets it in again, with the
  same tokens.
- **Bypass LWS access policies,** a restricted permission, makes the agent a
  controller of every storage, for support staff. No other Drupal permission
  gives any LWS access. User 1 has it, as it has every permission, so with
  `lws_identity` user 1's own agent controls every storage, unless the site
  turns the super user off (`security.enable_super_user: false` in
  `services.yml`).
- **Attribution.** The files of what the agent writes are the user's, and the
  log names the user. When the account is deleted, the files go to
  Anonymous; the content stays.

Which agents act as users:

- **Linked agents.** An administrator of users sets *LWS agent URI* on the
  user's edit form, or Drush does. A user has at most one, and an agent acts
  as at most one user. The link is kept in externalauth's authmap.
- **This site's own agents.** With `lws_identity`, each user's own agent URI
  acts as that user, without a link.
- **Every other agent, in provision mode** (the *Agent users* settings tab;
  off by default). The first valid token of an agent without an account makes
  one for it: no password or e-mail address, a name such as
  `lws-agent-3f9a…`, and the roles the settings give, never an administrator
  role. Such an account cannot log in. It can be limited to tokens of listed
  authorization servers, or to agent URIs that start with listed prefixes, and
  to so many accounts an hour (100 by default). Pseudonymous identifiers make
  an account each. Cron deletes the accounts made this way once unseen for as
  many days as the settings say, their content given to Anonymous.

The access token only ever authenticates requests in the LWS URL space. It
never yields a session, and no other route sees the user.

```sh
drush lws:agent-users:link bob https://bob.example/profile#me
drush lws:agent-users:find https://bob.example/profile#me   # the user, its groups, whether it is blocked
drush lws:agent-users:unlink bob
drush lws:agent-users:prune                                 # now, rather than at cron
```

## Projections

With `lws_projection` enabled (it needs core's Serialization module), Drupal
content can be read over LWS. A projection, on the *Projections* tab of the LWS
settings, names a storage and the content types, media types, vocabularies or
other bundles it holds. It makes that storage, read-only, with a container for
each entity type and bundle, `root/{entity type}/{bundle}/`, holding a JSON
data resource for each entity a visitor may view, named after its ID, such as
`/lws/content/root/node/article/12`:

- **As a visitor sees it.** Only what an anonymous visitor may view, with only
  the fields a visitor may view, serialized as core's REST module serves it
  (`application/json`). Unpublished content is not there.
- **Kept up to date.** Saving or deleting an entity changes its resource when
  the request ends. What did not change is not written again, so entity tags
  and notifications follow real changes. A change to the anonymous role's
  permissions, or to the projection, syncs it on cron, and
  `drush lws:projection:sync` does it at once. Changes that save no entity,
  such as a path alias, wait for the next sync.
- **Typed and linked.** A bundle can be given a type URI, such as
  `https://schema.org/Article`, which its resources declare (`rel="type"`), so
  the type index finds them. Each resource's linkset links the entity's page as
  `alternate`.
- **Read-only.** Every write gets `403`, from controllers too. Who may read is
  the storage's: *Anyone may read it* adds a public read policy; otherwise its
  access page and access requests decide, as for any storage.
- A projection only manages a storage it made itself. Deleting it leaves the
  storage as an ordinary one, with what was projected.

```sh
drush lws:projection:sync            # every projection, now
drush lws:projection:sync content    # one
```

## Access tokens

A storage accepts RFC 9068 access tokens (`typ: at+jwt`, signed with ES256,
ES384, EdDSA, RS256, RS384, RS512, PS256, PS384 or PS512) from one authorization server: its own, set with
`lws:storage:create --authorization-server=<id>`, or the site's default. A token
must name that server as `iss` and the storage URI, alone, as `aud` (LWS Core
§5.2.4).

By default that server is the site's own, `local`. Its issuer is the origin of
the LWS base URL, so its metadata is at `/.well-known/lws-configuration`. Its
token endpoint exchanges an authentication credential for a token to one of the
site's storages (LWS Core §5.2.3), for 300 seconds by default and never longer than
the credential lives. It takes self-signed controlled identifier credentials
([lws10-authn-ssi-cid](https://w3c.github.io/lws-protocol/lws10-authn-ssi-cid/)):
a JWT the agent signs itself, whose `kid` names a key of its controlled
identifier document. The subject may be an HTTPS URI, whose document is fetched,
a `did:key`, or a `did:web`. It also takes ID Tokens of OpenID Providers
([lws10-authn-openid](https://w3c.github.io/lws-protocol/lws10-authn-openid/)),
whose `sub`, `iss` and `azp` become the subject, issuer and client. Requests are
rate-limited per client address and per agent.

An ID Token is accepted from a provider in either of two ways:

- **The subject names it.** The subject's controlled identifier document has a
  service of type `https://www.w3.org/ns/lws#OpenIdProvider` whose
  `serviceEndpoint` is the token's `iss`. The provider's keys come from its
  OpenID Connect Discovery document, and the ID Token's `aud` must include this
  site's authorization server. Both can be turned off in the settings.
- **It is configured.** Configured providers are listed at *OpenID Providers*,
  beside the authorization servers, or managed with `lws:op:add`, `lws:op:list`
  and `lws:op:delete`. Each can:
  - pin its keys;
  - accept ID Tokens that name only their client in `aud` (`--any-audience`),
    as Keycloak's do;
  - be trusted for any subject, without fetching the subject's document
    (`--any-subject`).

```sh
drush lws:op:add keycloak https://idp.example/realms/main --any-audience
```

The keys of the providers are verified as ES256, ES384, EdDSA or, for providers
such as Keycloak that sign with RSA, RS256 to RS512 and PS256 to PS512, with
keys of 2048 bits or more. SAML 2.0 assertions are not taken yet: that suite
will be a module of its own.

It signs with ES256 keys kept as JSON Web Key files, readable by their owner
only, in `$settings['lws_authz_key_directory']`, or `lws_authz/keys` in the
private file system. The first is made when it is needed; `drush lws:key:rotate`
makes a new one, and the old one is published until its tokens have expired.

Other servers are trusted at *Configuration › Web services › LWS authorization
servers* or with `lws:as:add`, `lws:as:list` and `lws:as:delete`; the settings
there choose the default server, the authentication suites and the token
lifetime. A trusted server's signing keys are either pinned (`--jwks=<file>`) or
fetched from the `jwks_uri` of its metadata at `/.well-known/lws-configuration`,
cached for an hour and fetched again when a token names an unknown key.

Fetches, of keys and of controlled identifier documents, go through a guard
against server-side request forgery: HTTPS only, and never to private, loopback
or link-local addresses. In development, origins can be exempted in
`settings.php`:

```php
$settings['lws_outbound_allowlist'] = ['http://localhost:8080'];
```

## Requirements

- Drupal 11.3 or later, PHP 8.3 or later.
- [`ebremer/lws-client`](https://github.com/ebremer/lws-client), with
  `JsonPatch::apply()`, RSA verification and, for `lws_notify`, `WebhookSigner`,
  which is not on Packagist yet and has no release. A site that installs this module must name
  its repository and require its `main` branch itself:

  ```sh
  composer config repositories.lws-client vcs https://github.com/ebremer/lws-client
  composer require 'ebremer/lws-client:dev-main'
  ```
- [`pietercolpaert/hardf`](https://github.com/pietercolpaert/hardf) (MIT, no
  dependencies), a Turtle and N-Triples parser, for the types `lws_index` reads
  from content; Composer installs it with this module.
- For `lws_agent_users` only, [`drupal/externalauth`](https://www.drupal.org/project/externalauth)
  2.0 or later, which keeps the links of agents to users:
  `composer require 'drupal/externalauth:^2.0'`.

## Development with DDEV

The site is built with the
[ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib) add-on, which
puts a Drupal codebase (`web/`, `vendor/`) around this checkout and symlinks the
module into `web/modules/custom/lws`. This checkout's `composer.json` then acts
as the site's, and already names the lws-client repository.

```sh
ddev add-on get ddev/ddev-drupal-contrib   # once; commit the files it adds to .ddev/
ddev start
ddev poser                                  # builds the Drupal codebase
ddev symlink-project
# Data resources need a private file system:
echo "\$settings['file_private_path'] = '../private';" >> web/sites/default/settings.php
ddev drush site:install minimal -y
ddev drush en lws_storage -y
ddev drush config:set lws.settings base_url https://lws-drupal.ddev.site -y
ddev drush lws:storage:create alice
curl -i https://lws-drupal.ddev.site/lws/alice/         # the storage description
curl -i https://lws-drupal.ddev.site/lws/alice/root/    # 401, naming the site's authorization server
```

Checks, as CI runs them:

```sh
ddev exec vendor/bin/phpunit -c web/core web/modules/custom/lws
ddev exec vendor/bin/phpcs --standard=web/modules/custom/lws/phpcs.xml.dist web/modules/custom/lws
ddev exec vendor/bin/phpstan analyse -c web/modules/custom/lws/phpstan.neon.dist
```

## Without DDEV

Any Drupal 11 project can use the checkout through a Composer path repository.
[`.github/build-site.sh`](.github/build-site.sh) is the recipe CI uses:

```sh
sh lws-drupal/.github/build-site.sh drupal lws-drupal
cd drupal
SIMPLETEST_DB=sqlite://localhost//tmp/lws-test.sqlite vendor/bin/phpunit -c web/core web/modules/contrib/lws
```

CI runs the tests on SQLite, and again on MySQL 8.4, MariaDB 11.8 and
PostgreSQL 17. To do the same, point `SIMPLETEST_DB` at an empty database,
such as `mysql://root:secret@127.0.0.1/lws_test` or
`pgsql://postgres:secret@127.0.0.1/lws_test`.

## Deployment notes

- **Set the canonical base URL** at *Configuration › Web services › Linked Web
  Storage*, or `lws.settings:base_url`, for example `https://storage.example`.
  Left empty, LWS URIs are derived from the request's `Host` header, which is
  only acceptable in development. The status report warns about it, and about
  serving LWS from the same host as the site's own pages.
- **Give the authorization server a key directory** outside the web root, in
  `$settings['lws_authz_key_directory']`, or configure the private file system.
  Without one it cannot issue tokens, storages that trust it answer requests
  that need a token with `503`, and the status report says so. Run
  `drush lws:key:rotate` as the web server's user, which must be able to read
  the keys. A site installed in a subdirectory needs the web server to serve
  `/.well-known/lws-configuration` from the site.
- **Configure the private file system.** Data resources keep their content there,
  under `private://lws/`; until it is set they cannot be created, and the status
  report says so. Another stream wrapper can be chosen with
  `lws_storage.settings:scheme`.
- **Serve LWS over HTTPS.** Access tokens are bearer tokens. The status report
  counts plain HTTP as an error, except on a loopback host.
- **Request size.** *Largest content* (`lws_storage.settings:max_upload_bytes`)
  caps one write, and is refused with `413` before any of it is read. PHP's
  `post_max_size` limits `POST`, which creates, but not `PUT`, which replaces;
  the web server's body limit (Apache's `LimitRequestBody`, 1 GB by default)
  limits both. The status report warns when no largest content is set, or when
  it is larger than `post_max_size`.
- **Keep content out of the web server's reach.** The status report counts
  content in the public file system as an error: anyone who learned a file's
  location could download it, whatever LWS policy says.
- **Let clients find the authorization server.** The status report fetches
  `/.well-known/lws-configuration` from the issuer, as clients do, and warns if
  a proxy or the web server does not pass it to Drupal.
- **Run cron,** which deletes the files of recursively deleted resources and of
  deleted storages, and with `lws_notify` retries notification deliveries.
- **Notifications** (`lws_notify`) are signed with keys kept like the
  authorization server's, in `$settings['lws_notify_key_directory']` or
  `lws_notify/keys` in the private file system; without one they go unsigned,
  and the status report says so. They are delivered after each response is
  sent, which PHP-FPM does without keeping the client waiting. Run as an Apache
  module, PHP sends a response without a body, such as a `204` to `PUT` or
  `DELETE`, only once it is done, so the client waits for the deliveries too;
  the status report warns about it. There, either run PHP-FPM or turn off
  *Deliver at the end of the request* and run the delivery queue often. Inboxes
  must be HTTPS URLs of public hosts; in development, exempt others with
  `lws_outbound_allowlist`.
- **Let `QUERY` through.** Type searches use the HTTP `QUERY` method
  (RFC 10008). Apache and PHP pass it to Drupal, but some proxies, CDNs and
  web application firewalls refuse methods they do not know.
- **Web servers refuse some paths before Drupal sees them:**
  - Drupal's `.htaccess` answers `403` for any path segment that starts with a
    dot, such as `/lws/alice/root/.profile`. LWS never gives a resource such a
    name.
  - Apache answers `404` for an encoded slash (`%2F`) by default. LWS rejects
    those paths anyway.
- **Drupal 11.4 and later run `index.php` through `symfony/runtime`.** Its
  Composer plugin must be allowed (`allow-plugins.symfony/runtime`), or every
  page fails.

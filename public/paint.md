# Paint Service Contract

Paint is Elonn's drawing document Service.

It owns Paint document identity, Paint document records, Paint document operations, and Paint-specific
document lifecycle. Paint uses Storage for immutable Resource bytes; it does not own Resource byte
persistence, runtime presentation, World placement, or member authentication.

## Contract

- Service id: `paint.elonn`
- Domain: `creative`
- Revision: `2`
- Published: `2026-08-27T00:00:00Z`
- Canonical JSON: `https://paint.elonn.com/paint.json`
- Service Publication: `https://paint.elonn.com/paint-publication.json`

The canonical JSON contract is authoritative. This Markdown document describes the same Service contract
for human readers.

## Labels

The contract carries a top-level `labels` pairs table — the display copy a generic consumer
(the runtime form renderer, the reasoning Model, a Service Dashboard) shows for each argument.
Argument schemas reference it by `label_ref`; a platform orchestrator resolves the refs to text
before presenting a schema.

| Ref | Text |
| --- | --- |
| `field.search_text` | Search |
| `field.result_limit` | How many to show |
| `field.paint_title` | Title |
| `field.paint_width` | Width |
| `field.paint_height` | Height |
| `field.paint_stroke` | Stroke |

## Authentication

Paint requires authenticated platform Service calls using Conductor signed requests.

`POST /paint/call` accepts authenticated Calls from `conductor.elonn`.

`mind.elonn` remains accepted temporarily for compatibility while Conductor assumes orchestration.

## Endpoint

### `POST /paint/call`

Accepts one canonical `Call` and returns one canonical Service `Dataset`. This is Paint's first Service
with real mutating operations reachable through Conductor (`side_effects: true`).

The Call must include:

- `id`
- `content`
- `context`

The `content.operation` value selects the Paint operation.

## Operations

Each operation's arguments are declared in the canonical JSON contract under `endpoints[0].operations`. An
argument's `source` is either `model` (supplied by whichever caller selected the operation — a reasoning
Model or an explicit `operation_invocation.payload`) or `context` (resolved by the calling platform
orchestrator itself from canonical Call context, never asked of a Model). Member identity is resolved this
way already, forwarded as the `X-Elonn-Member-Id` request header — Paint does not declare it as a Call
argument.

An operation may also declare `model_selectable: false`, meaning a reasoning Model shall never select it
directly — it remains reachable only through an explicit `operation_invocation` whose target was already
established by an earlier Dataset (a search result, a list item, a document already open), never invented
from a raw query.

### `paint.create`

Create a new Paint document for drawing.

| argument | required | source | default |
|---|---|---|---|
| `title` | no | model | `"Untitled Paint"` |
| `width` | no | model | `1024` |
| `height` | no | model | `768` |

### `paint.search`

Search Paint-owned visual artwork results, including the drawing workspace for broad creation intent and
the member's own documents by title or indexed drawing content. Model-selectable — a query naming a
document by name (e.g. "open my sunset drawing") resolves here, surfacing matches for the member to act
on, not directly into `paint.read`.

| argument | required | source | default |
|---|---|---|---|
| `text` | yes | model | — |
| `limit` | no | model | `10` |

### `paint.list`

Show recent Paint documents owned by the member.

| argument | required | source | default |
|---|---|---|---|
| `limit` | no | model | `10` |

### `paint.read` — not Model-selectable

Open an existing Paint document. Only reachable through an explicit `operation_invocation`, whose
canonical `object_id` (the same field every Dataset action already carries to say what it pertains to)
supplies `document_id`.

| argument | required | source |
|---|---|---|
| `document_id` | yes | context (`object_id`) |

### `paint.draw` — not Model-selectable

Add a completed drawing stroke to an existing Paint document already identified by a prior Dataset action's
`object_id`.

| argument | required | source |
|---|---|---|
| `document_id` | yes | context (`object_id`) |
| `stroke` | yes | model |

### `paint.rename` — not Model-selectable

Rename an existing Paint document already identified by a prior Dataset action's `object_id`.

| argument | required | source |
|---|---|---|
| `document_id` | yes | context (`object_id`) |
| `title` | yes | model |

## Response

Paint returns one canonical Service `Dataset` containing Paint document objects, their Storage-backed
source/preview Resources, and relevant workspace/collection placements.

## Side Effects

`paint.create`, `paint.draw`, and `paint.rename` create or modify Paint document records and their backing
Resources in Storage. `paint.read`, `paint.search`, and `paint.list` have no side effects.

## Privacy

All operations are scoped to the authenticated member identity supplied by the calling platform service
(`X-Elonn-Member-Id`). Paint does not expose one member's documents to another.

## Errors

Paint may return these errors in the response Dataset:

- `paint.service_auth_failed`
- `paint.operation_required`
- `paint.unsupported_operation`
- `paint.invalid_create_call`
- `paint.invalid_read_call`
- `paint.invalid_draw_call`
- `paint.invalid_rename_call`
- `paint.invalid_search_call`
- `paint.document_not_found`
- `paint.document_store_unavailable`

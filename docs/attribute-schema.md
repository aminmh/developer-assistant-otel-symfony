# Indexr OpenTelemetry attribute schema

**Semantic conventions baseline:** OpenTelemetry Semantic Conventions PHP package `open-telemetry/sem-conv` 1.44.0. This release contains the convention set tagged 1.44.0. The package targets stable conventions where available; Indexr-specific join attributes remain namespaced under `indexer.*`.

## Resource attributes

| Attribute | Type | Source / meaning |
| --- | --- | --- |
| `service.name` | string | Application service name; `OTEL_RESOURCE_ATTRIBUTES` takes precedence over `OTEL_SERVICE_NAME`. |
| `service.version` | string | Application release/version. |
| `vcs.ref.head.revision` | string | Deployed commit SHA retained as source provenance; it is not the telemetry join key. Precedence: `OTEL_RESOURCE_ATTRIBUTES`, `INDEXER_COMMIT_SHA`, then project Git HEAD. |
| `indexer.revision_id` | string | Immutable revision ID assigned by Developer Assistant when a source snapshot is registered. Precedence: `OTEL_RESOURCE_ATTRIBUTES`, then `INDEXER_REVISION_ID`; never inferred from Git. |
| `indexer.project_id` | string | Stable Indexr project identifier; resource attributes override `INDEXER_PROJECT_ID`. |
| `container.id` | string | Container ID discovered from resource attributes or Linux cgroup/mount metadata. |
| `container.image.name` | string | Container image name, supplied by the deployment environment. |
| `deployment.environment.name` | string | Deployment environment; defaults from `APP_ENV` when not explicitly supplied. |
| `indexer.code_dirty` | boolean | Whether the project Git worktree is dirty when local Git metadata is available. Explicit resource attribute may override detection. |
| `indexer.schema.version` | string | Fixed join-contract version (`indexer-telemetry-v2`) used by the Runtime Collector privacy gate. |

The detector emits only this allow-list. It never copies the process environment wholesale. Missing or malformed sources are omitted; detection errors must not escape into the host application.

## Span attributes

| Attribute | Type | Meaning |
| --- | --- | --- |
| `indexer.symbol_id` | string | Canonical symbol ID for the span's code location. |
| `indexer.caller.symbol_id` | string | Canonical ID of the attributed in-application caller. |
| `indexer.route_id` | string | Canonical route ID (`route:<METHOD> <URI template>`). |
| `indexer.table_ids` | string array | Canonical table IDs (`table:<connection>.<name>`). |
| `code.function.name` | string | Fully qualified function or method name when available. |
| `code.file.path` | string | Repository-relative source path. |
| `code.line.number` | integer | Source line number. |
| `db.query.text` | string | Normalized SQL with literals and parameter values removed; excluded by the Runtime Collector. |
| `db.operation.name` | string | Leading SQL operation from a fixed verb allow-list. |
| `db.system.name` | string | Validated Laravel database driver name. |
| `db.namespace` | string | Validated Laravel database name; omitted when unsafe or unavailable. |
| `http.route` | string | Laravel route template, never the concrete URL path. |
| `http.request.method` | string | HTTP method on the server request span. |
| `http.response.status_code` | integer | Numeric HTTP response status on the server request span. |
| `indexer.php.cpu_time_us` | integer | PHP worker user-plus-system CPU time delta over the request interval, in microseconds. |
| `indexer.php.peak_memory_bytes` | integer | PHP allocator peak above request-start baseline, in bytes; omitted unless peak reset is supported. |
| `indexer.browser.trace_id` | string | On a server request span: trace ID of the browser screen view that sent the request (32 hex). The request is not a child of that view. |
| `indexer.browser.span_id` | string | On a server request span: span ID of that screen view's `browser.page` span (16 hex). |
| `browser.route` | string | On `browser.page`, `browser.interaction`, and `browser.error` spans: the screen's route template or page component name, never a URL. |
| `browser.vital.lcp_ms` | integer | Largest Contentful Paint of the first screen of a page load, in milliseconds. |
| `browser.vital.inp_ms` | integer | Longest interaction of the screen view, in milliseconds (an upper bound on INP). |
| `browser.vital.cls` | double | Cumulative Layout Shift of the screen view. |
| `browser.frontend.project_id` / `browser.frontend.revision_id` | string | The separately indexed front-end project and revision, when configured. |
| `indexer.exception.type` | string | Exception class name on the active error span. |
| `indexer.exception.normalized_message` | string | Bounded message with URLs, quoted values, email addresses, IP addresses, UUIDs, and numbers normalized. |
| `indexer.exception.top_frames` | string array | Up to five canonical in-application symbol IDs from the exception stack. |

## Privacy and identity requirements

- Never export request/response bodies, cookies, authorization headers, SQL binding values, full cache keys, or environment variables.
- Browser spans are relayed, not trusted: every field is validated against a fixed shape in `BrowserTelemetry::parse`, and a browser error contributes its class name only, never its message.
- Redact before span export from the PHP process. Collector-side redaction is an additional layer.
- All symbol IDs are serialized through `packages/core`; adapters must not construct IDs independently.
- Resource and span IDs are join keys, not credentials. Collector transport security is configured by the deployment.

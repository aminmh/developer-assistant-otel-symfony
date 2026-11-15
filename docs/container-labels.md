# Container labels and resource metadata

The discovery contract uses labels to identify opted-in application containers. The telemetry SDK must emit the corresponding project and revision as resource attributes so that spans remain joinable after export.

| Docker label | Source | OpenTelemetry resource attribute |
| --- | --- | --- |
| `phpindexer.enable=true` | Explicit opt-in | — |
| `phpindexer.project` | `INDEXER_PROJECT_ID` | `indexer.project_id` |
| `phpindexer.framework` | `laravel` or `symfony` | — |
| `org.opencontainers.image.revision` | `GIT_SHA` | `vcs.ref.head.revision` |

Pass `GIT_SHA` and `INDEXER_PROJECT_ID` to the container. Set `INDEXER_COMMIT_SHA` and `OTEL_RESOURCE_ATTRIBUTES` from those same values. `ResourceDetector` gives an explicit revision in `OTEL_RESOURCE_ATTRIBUTES` priority over `INDEXER_COMMIT_SHA`, then falls back to Git metadata. The project ID in the resource attributes likewise takes precedence over `INDEXER_PROJECT_ID`.

```yaml
services:
  app:
    build:
      context: .
    labels:
      phpindexer.enable: "true"
      phpindexer.project: "${INDEXER_PROJECT_ID:?set INDEXER_PROJECT_ID}"
      phpindexer.framework: "laravel"
      org.opencontainers.image.revision: "${GIT_SHA:?set GIT_SHA}"
    environment:
      GIT_SHA: "${GIT_SHA:?set GIT_SHA}"
      INDEXER_PROJECT_ID: "${INDEXER_PROJECT_ID:?set INDEXER_PROJECT_ID}"
      INDEXER_COMMIT_SHA: "${GIT_SHA:?set GIT_SHA}"
      OTEL_RESOURCE_ATTRIBUTES: "indexer.project_id=${INDEXER_PROJECT_ID:?set INDEXER_PROJECT_ID},vcs.ref.head.revision=${GIT_SHA:?set GIT_SHA}"
```

Use the same required variables in each app service. Do not place credentials or arbitrary environment values in labels or resource attributes.

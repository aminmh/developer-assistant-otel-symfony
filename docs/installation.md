# Installation and runtime configuration

Install the framework adapter from the application's Composer project. The adapters require the matching framework and include the shared core. Native function-hook instrumentation uses the official OpenTelemetry auto-instrumentation packages plus the PECL extension; those packages are optional so the Indexr adapter can still boot safely without them.

```sh
composer require aminmh/developer-assistant-otel-laravel
# or
composer require aminmh/developer-assistant-otel-symfony
composer require open-telemetry/exporter-otlp
```

Install `ext-opentelemetry` in the PHP image using the PECL package supported by the base image, then enable it in `php.ini`. The extension is not a Composer package. The auto-instrumentation packages require that extension; if it is absent, their hooks are unavailable and the Indexr framework adapter catches instrumentation failures and leaves application behavior intact.

After enabling the extension, optionally install the matching framework auto-instrumentation package and integrations your application uses:

```sh
composer require open-telemetry/opentelemetry-auto-laravel # Laravel native hooks
# or
composer require open-telemetry/opentelemetry-auto-symfony # Symfony native hooks
```

Set these environment variables in each app container. Keep the revision/project values aligned with the labels described in [container-labels.md](container-labels.md).

```dotenv
INDEXER_OTEL_ENABLED=true
INDEXER_OTEL_DB=true
INDEXER_OTEL_CACHE=true
INDEXER_OTEL_CALLER_ATTRIBUTION=true
INDEXER_PROJECT_ID=my-project
INDEXER_REVISION_ID=rev_0123456789abcdef0123456789abcdef
INDEXER_PROJECT_ROOT=/app
OTEL_SERVICE_NAME=my-project
OTEL_RESOURCE_ATTRIBUTES=indexer.project_id=my-project,indexer.revision_id=rev_0123456789abcdef0123456789abcdef,indexer.code_dirty=false,indexer.schema.version=indexer-telemetry-v2
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://otel-collector:4318/v1/traces
OTEL_METRICS_EXPORTER=none
OTEL_LOGS_EXPORTER=none
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=1.0
OTEL_BSP_MAX_QUEUE_SIZE=2048
OTEL_BSP_MAX_EXPORT_BATCH_SIZE=512
OTEL_BSP_SCHEDULE_DELAY=5000
OTEL_BSP_EXPORT_TIMEOUT=2000
OTEL_EXPORTER_OTLP_TIMEOUT=2000
# Optional: stack frames inspected for DB caller attribution (default 40, bounded 5-200)
INDEXER_OTEL_BACKTRACE_DEPTH=40
# Optional: continue an incoming W3C traceparent header (default false)
INDEXER_OTEL_TRUST_TRACEPARENT=false
```

### Request span boundaries

Each HTTP request gets one `http.server` span:

- **Start.** The span opens before routing: in Laravel from provider boot (PHP-FPM) or a prepended global middleware (Octane), and in Symfony at `kernel.request` priority 4096, ahead of the router and the security firewall. It is back-dated to `REQUEST_TIME_FLOAT`, so framework bootstrap counts toward its duration.
- **End.** Its duration ends when the response has been sent. Its context stays active until the last termination hook, so post-response work (session writes, debug-tool storage) appears inside the request's trace instead of as separate root traces.
- **Unmatched routes.** 404/405 responses and errors raised before routing are recorded with `http.route` set to `(unmatched)`.
- **Errors.** 5xx responses mark the span as an error even when no exception was reported. 4xx responses do not.
- **Route IDs.** A route ID records the request's own method when the route accepts it (HEAD counts as GET).

Incoming `traceparent` headers are ignored unless `INDEXER_OTEL_TRUST_TRACEPARENT` (Laravel `indexer-otel.trust_traceparent`, Symfony `indexer_otel.trust_traceparent`) is enabled. Otherwise any client could choose trace IDs and force sampling, so enable it only behind a gateway that sets or sanitizes the header.

Queued work continues the dispatching request's trace: Laravel jobs carry it in the payload, and Symfony Messenger messages carry it in a `TraceContextStamp`. A Laravel job that throws and is released for retry closes its span at `JobExceptionOccurred`, so the next job on the worker starts its own trace.

Under PHP-FPM the batch processor flushes at request shutdown, inside the worker that served the request. When the package installs the SDK itself, export and transport timeouts default to 2 seconds with one retry, so an unreachable Collector cannot hold workers for long. When the host configures the SDK instead (`OTEL_PHP_AUTOLOAD_ENABLED=true`), set `OTEL_EXPORTER_OTLP_TIMEOUT` and `OTEL_BSP_EXPORT_TIMEOUT` explicitly; the SDK defaults are 10 and 30 seconds.

Caller attribution never attributes frames under `vendor/`. On PHP 8.4 and later, closures resolve to `closure@<path>:<definition line>`. Earlier PHP versions expose only a closure's call site, so attribution skips closure frames and uses the enclosing application method.

`INDEXER_OTEL_CACHE` (and the Symfony `indexer_otel.cache` option) no longer has an effect. The Collector drops the cache-operation attribute, so the Laravel cache listener and the Symfony `cache.app` decorator were removed. The option is still accepted so existing configuration keeps loading.

Use sampler ratio `1.0` in development/staging and `0.1` in production unless the deployment has an explicit sampling policy. Export remains asynchronous through the SDK batch span processor; no application hook sends data over the network, with one exception: the browser telemetry relay below, which is off by default.

Laravel publishes `config/indexer-otel.php` with `php artisan vendor:publish --tag=indexer-otel-config`. Environment values override that config. Symfony uses the `indexer_otel` bundle configuration; environment values override bundle configuration as well.

`php artisan indexer:export --output=indexer-export.json` and `bin/console indexer:export --output=indexer-export.json` emit the version 1 static document validated by [indexer-export.schema.json](indexer-export.schema.json). They only inspect in-memory route/container/event/schedule/queue metadata and require no DB or network connection.

### Browser telemetry (Laravel)

Browser telemetry reports each screen view of the application: a route name, Web Vitals (LCP, INP, CLS), slow interactions, and the class names of JavaScript errors. It also lets Developer Assistant show which back-end requests a screen view sent. It is off by default and available in the Laravel adapter only.

1. Set `INDEXER_OTEL_BROWSER=true`. This registers two routes: `GET /_indexer/browser/script` (the snippet) and `POST /_indexer/browser` (the relay). Change the path with `INDEXER_OTEL_BROWSER_PATH`.
2. Load the snippet in the application's layout, before the application's own scripts:

   ```html
   <script src="/_indexer/browser/script" defer></script>
   ```

3. Tell the snippet which screen is showing, as a route template and never a concrete path. Inertia applications need nothing: the page component name is used. With Vue Router:

   ```js
   router.afterEach((to) => window.indexrBrowser?.setRoute(to.matched.at(-1)?.path ?? '(unknown)'));
   ```

   Screens that are never named are reported as `(unknown)`.
4. `INDEXER_OTEL_BROWSER_SAMPLE_RATE` sets the share of page loads that report (default `0.1`). If the front end is indexed as its own project, set `INDEXER_OTEL_BROWSER_PROJECT_ID` and `INDEXER_OTEL_BROWSER_REVISION_ID` to name it.

How it works and what it protects:

- **The browser never talks to the Collector.** The snippet posts a small batch to the relay on the application's own origin. The relay validates every field against a fixed shape, stamps the application's project and revision, and forwards the spans. The relay always answers `204`.
- **Only the application's own pages are accepted.** The relay, and the screen-view tag below, require a same-origin request (`Sec-Fetch-Site: same-origin`, which scripts cannot set) that carries the session cookie. The relay is exempt from CSRF verification because `navigator.sendBeacon` cannot send a token; it is rate limited to 120 requests a minute.
- **No URL, query string, element text, selector, error message, or user agent is sent.** A span carries a route name, timings, and an error class name. The Collector checks the route name's shape again.
- **Requests are not joined into the browser's trace.** The snippet adds a `traceparent` header to same-origin requests. The adapter does not treat it as a parent (that still needs `INDEXER_OTEL_TRUST_TRACEPARENT`): each request stays the root of its own trace and records the view's IDs in `indexer.browser.trace_id` and `indexer.browser.span_id`. Sampling of requests is unchanged, so a screen view lists only the requests the back end sampled.
- **The relay sends over the network inside the request.** It posts to the Collector with a 0.5 second connect and 1.5 second total timeout.
- **A web server that answers `.js` paths itself** is why the snippet path has no extension.


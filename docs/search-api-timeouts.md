# Search API Timeouts and Provisioning Resilience

This document describes how the plugin protects user-facing requests from a
slow or unavailable Commons Connect Search API, and which environment variables
control that behaviour.

## Background

When content is created or changed in WordPress (a new site, post, profile,
group, or discussion), the incremental provisioners synchronously call the
Search API to index that content. These calls happen inside WordPress action
hooks that fire during the user's request.

Previously the HTTP client used a 60-second request timeout with no connection
timeout and retried failed connections up to five times. If the Search API was
unreachable, a single site registration could block for several minutes before
returning. The settings below make that failure fast and visible instead.

## Environment variables

Both variables are read in `CCClientOptions::loadOptions()` and, like the other
`CC_SEARCH_*` settings, override any value stored in the database. They are
expressed in **whole seconds**.

| Variable | Default | Description |
|---|---|---|
| `CC_SEARCH_TIMEOUT` | `5` | Maximum time to wait for a complete Search API response (Guzzle's `timeout`). Once a connection is established, the request is aborted if the service has not responded within this many seconds. |
| `CC_SEARCH_CONNECT_TIMEOUT` | `3` | Maximum time to wait to establish the TCP connection to the Search API host (Guzzle's `connect_timeout`). This is what prevents a long stall when the host is unreachable, because cURL would otherwise wait for the full request timeout just to connect. |

Example (these are set in `.lando.yml` for local development, alongside the
existing `CC_SEARCH_*` variables):

```yaml
overrides:
  environment:
    CC_SEARCH_TIMEOUT: 5
    CC_SEARCH_CONNECT_TIMEOUT: 3
```

If neither variable is set, the defaults above apply. The values can also be
passed directly when constructing `CCClientOptions`
(`cc_search_timeout` / `cc_search_connect_timeout`), but the environment
variables are the intended way to tune them per environment.

## Interactive vs. batch (WP-CLI) behaviour

`SearchAPI` distinguishes between interactive (request-time) use and long-running
batch use via two constructor arguments:

- `retry_on_connect_error` (default `false`) — whether to retry requests that
  fail with a connection error.
- `request_timeout` (default: the configured `CC_SEARCH_TIMEOUT`) — overrides
  the request timeout in seconds.

**Interactive provisioning** (the default, used by the incremental provisioners
and the REST search controller) runs with retries on connection errors turned
off, so an unreachable API cannot multiply the delay felt by the user.

**WP-CLI commands** (`wp cc search ...`) construct the client with
`retry_on_connect_error: true` and a long `request_timeout` of 60 seconds, so
bulk indexing keeps its original, more forgiving behaviour. Connection errors
and `5xx` responses are retried up to five times with an increasing backoff.

## Pre-flight reachability guard

Before any synchronous provisioning call, the provisioners call
`search_api_available()`
(`src/Search/Provisioning/provisioning_helper_functions.php`). This performs a
fast `ping()` against the Search API using a short timeout
(`SEARCH_API_PREFLIGHT_TIMEOUT`, 2 seconds).

- If the API responds, provisioning proceeds normally.
- If the API is unreachable, the failure is written to the error log and the
  provisioner returns early **without** attempting the blocking index/update/
  delete request.

This means an unavailable Search API adds at most a couple of seconds to a
request rather than minutes, and every skipped registration is recorded. Look
for log lines such as:

```
[CC-Client] Search API UNREACHABLE - skipping provisioning. Search registration is FAILING. Context: Site ADD - Site ID: 42
```

The `Context` portion identifies the operation and the affected object so that
failed search registrations can be traced and, if necessary, re-provisioned
later with `wp cc search` once the service is restored.

## Related files

- `src/CCClientOptions.php` — defines and loads the timeout options.
- `src/Search/SearchAPI.php` — applies the timeouts, the retry flag, and the
  short-timeout `ping()`.
- `src/Search/Provisioning/provisioning_helper_functions.php` — the
  `search_api_available()` pre-flight guard.
- `src/Search/SearchCommand.php` — constructs the batch client with retries and
  a long timeout.

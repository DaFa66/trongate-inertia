# trongate-inertia (spike)

An [Inertia.js](https://inertiajs.com) protocol adapter for Trongate v2, built as a
module. Lets a React or Vue front end keep taking its page props from the server
while the back end stays vanilla Trongate. **No engine file is touched.**

Status: spike, built and verified against Trongate v2.2026.0920d.

## The idea

One action, two response shapes:

```php
public function index(): void {
    $this->inertia->render('Journal/Index', [
        'rows' => $this->rows(),
        'total' => count($rows)
    ]);
}
```

- A normal browser hit gets the HTML shell with the page object in `data-page`.
- A request carrying `X-Inertia: true` gets JSON `{component, props, url, version}`.

Everything else (303 on non-GET redirects, 409 + `X-Inertia-Location` for external
redirects, errors and flash as one-shot props, asset versioning, a 404 that is still
an Inertia response) lives in the module.

## Install

Copy two folders into a Trongate v2 app:

```
modules/inertia/     the adapter
modules/journal/     demo controller (delete it once you have your own)
```

Then visit `/journal` in a browser. Nothing to configure, no schema changes, no
build step: the demo stores its rows in the session.

## Adapter API

| Method | Purpose |
| --- | --- |
| `render(string $component, array $props = [])` | Render a page, in whichever shape the caller can accept. |
| `share(array $props)` | Props added to every page in this request (auth user, etc). |
| `set_version(string $version)` | Asset version. Inertia forces a full reload when it changes. |
| `redirect(string $target_url, int $status = 303)` | Inertia-aware redirect, 302 for plain browser requests. |
| `external_redirect(string $target_url)` | For redirects XHR cannot follow: 409 + `X-Inertia-Location`. |
| `not_found(string $component, array $props = [])` | App-level miss: page object with a 404 status. |
| `is_inertia_request()` | Is this request from an Inertia client? |

Use `$this->inertia`, not `Modules::run('inertia/...')`. `Modules::run()` builds a
fresh controller instance per call, so shared props would not survive.

## Verified

`bash modules/inertia/spike-tests/test.sh` runs the whole protocol with curl and
prints the evidence. All 11 checks pass:

| # | Check | Result |
| --- | --- | --- |
| 1 | Plain browser hit | 200, HTML shell with `data-page` |
| 2 | Page object in the shell | csrf token 64 chars, props intact |
| 3 | `X-Inertia: true` visit | JSON page object + `X-Inertia: true`, `Vary: X-Inertia` |
| 4 | Stale asset version | 409 + `X-Inertia-Location` |
| 5 | JSON POST, no token | 403 from the framework's own CSRF gate |
| 6 | JSON POST with token | 303 See Other + `Location` |
| 7 | Follow-up GET | saved row and flash message in props |
| 8 | Failed validation | field-keyed `props.errors` on the next page |
| 9 | External redirect | 409 + `X-Inertia-Location` |
| 10 | Framework 404 on an Inertia visit | HTML (see gaps) |
| 11 | App-level 404 | page object with a 404 status |

The 409 shape matches Inertia core, which routes those responses on
`hasStatus(409) && hasHeader('x-inertia-location')`. Any response carrying the
`x-inertia` header is processed as a page regardless of status code, which is what
makes check 11 work.

Dual mode holds too: the same endpoint answers a plain HTML form post with a 302,
so non-Inertia clients keep working.

## What Trongate v2 already gives you

Most of the "this needs core surgery" list turns out to be already built:

- **JSON request bodies.** `post()` branches on content type and `json_decode`s
  `php://input` (`modules/form/Form.php`). Inertia's JSON bodies just work.
- **CSRF with JSON bodies.** The gate is `post('csrf_token')`
  (`modules/validation/Validation.php`), the same parser, so a token in the JSON
  body satisfies it. No header interceptor needed.
- **JSON validation errors.** `validation_errors(400)` already emits field-keyed
  JSON with a 400 and `Content-Type: application/json`.
- **Swappable error page.** `ERROR_404` in `config/config.php` is a constant
  pointing at a handler module, so framework 404 rendering is configurable.

The real work is the response shape plus one-shot props. Roughly 230 lines.

## Gaps

- **No middleware seam.** The engine has no hook layer, so the adapter is opt-in
  per action. That is the price of not touching core.
- **Framework-level 404s are still HTML.** `draw_error_page()` is private to Core
  and renders the `ERROR_404` handler as a page. To answer JSON for a bad route,
  point `ERROR_404` at your own handler that checks for the `X-Inertia` header.
- **Core's `redirect()` takes no status argument** and sends an implicit 302, so
  the adapter carries its own 303 version.
- **Do not use the framework's `json()` helper** for the response. In this version
  it is a debug pretty printer: it echoes `'<pre>'.json_encode(...).'</pre>'` and
  sets no content type. The adapter sets headers and encodes the page itself.
- **Not implemented:** partial reloads (`X-Inertia-Partial-Component/Data`), error
  bags, deferred and merge props, prefetching, history encryption, and asset
  versioning from a build hash (call `set_version()` yourself).
- **Auth is session cookies only,** so the SPA must be same origin.

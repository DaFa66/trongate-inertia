<?php
/**
 * Inertia root view.
 *
 * Stands in for the app.blade-style shell: a mount point carrying the page
 * object, plus a minimal client that speaks the Inertia protocol. In a real
 * React app the script below is replaced by the @inertiajs/react client and
 * the mount point is your app's entry bundle.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trongate + Inertia (spike)</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; max-width: 46rem; line-height: 1.5; }
        pre { background: #f4f4f4; padding: 1rem; overflow-x: auto; }
        .meta { color: #666; font-size: .875rem; }
        input, button { font: inherit; padding: .35rem .5rem; }
        #app { min-height: 12rem; }
    </style>
</head>
<body>
<div id="app" data-page='<?= $page_json ?>'></div>

<script>
const BASE = "<?= BASE_URL ?>";

let page = JSON.parse(document.getElementById("app").dataset.page);

const view = (p) => `
    <h1>${p.component}</h1>
    <p class="meta">url: ${p.url} &middot; version: ${p.version}</p>
    <pre>${JSON.stringify(p.props, null, 2)}</pre>
    <form inertia method="post" action="${BASE}journal/store">
        <input name="symbol" placeholder="symbol" size="10">
        <input name="amount" placeholder="amount" size="10">
        <input type="hidden" name="csrf_token" value="${p.props.csrf_token}">
        <button>Add row</button>
    </form>
    <p>
        <a inertia href="${BASE}journal">Reload this page</a> |
        <a inertia href="${BASE}journal/external">External redirect (409 test)</a> |
        <a inertia href="${BASE}journal/missing_row">App-level 404 (Inertia response)</a> |
        <a inertia href="${BASE}nosuchthing">Framework 404 (still HTML, see README)</a>
    </p>
`;

function render() {
    document.getElementById("app").innerHTML = view(page);
}

async function swap(response) {
    if (response.status === 409) {
        const location = response.headers.get("X-Inertia-Location");
        if (location) { window.location.href = location; return; }
    }

    const type = response.headers.get("content-type") || "";

    if (type.includes("application/json")) {
        page = await response.json();
        render();
        history.pushState(page, "", page.url);
        return;
    }

    // Not an Inertia response. A real Inertia client raises an HTTP exception
    // here and surfaces an error, rather than silently navigating.
    if (response.redirected === true) {
        // Bounced somewhere else (a login page, the site home). Follow it.
        window.location.href = response.url;
        return;
    }

    const body = (await response.text()).slice(0, 300);
    document.getElementById("app").innerHTML =
        `<h1>Inertia request failed</h1>
         <p class="meta">HTTP ${response.status}, not an Inertia response</p>
         <pre>${body.replace(/</g, "&lt;")}</pre>`;
}

async function visit(url, options = {}) {
    // Merge, do not clobber. The caller's Content-Type has to survive, or the
    // body arrives as text/plain, post() never parses it, and the framework's
    // own CSRF gate rejects the request with a 403.
    const headers = {
        "X-Inertia": "true",
        "X-Inertia-Version": page.version,
        "Accept": "text/html, application/xhtml+xml",
        ...(options.headers || {})
    };

    const response = await fetch(url, {
        ...options,
        credentials: "same-origin",
        headers: headers
    });

    await swap(response);
}

document.addEventListener("click", (event) => {
    const link = event.target.closest("a[inertia]");
    if (!link) { return; }
    event.preventDefault();
    visit(link.href);
});

document.addEventListener("submit", async (event) => {
    const form = event.target.closest("form[inertia]");
    if (!form) { return; }
    event.preventDefault();

    // The hidden csrf_token input carries the token, so a native submit works
    // as well as this JSON one.
    const body = Object.fromEntries(new FormData(form));

    await visit(form.action, {
        method: form.method || "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body)
    });
});

window.addEventListener("popstate", () => visit(window.location.href));

render();
</script>
</body>
</html>

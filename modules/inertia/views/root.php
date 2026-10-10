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
        <button>Add row (404s without a token, 303 with one)</button>
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

    // Not an Inertia response (an error page, a full document) - hard load it.
    window.location.href = response.url;
}

async function visit(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        credentials: "same-origin",
        headers: {
            "X-Inertia": "true",
            "X-Inertia-Version": page.version,
            "Accept": "text/html, application/xhtml+xml"
        }
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

    const body = Object.fromEntries(new FormData(form));
    body.csrf_token = page.props.csrf_token;

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

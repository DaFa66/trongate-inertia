<?php
/**
 * Inertia guard - an interceptor for the Inertia adapter.
 *
 * Registered via the INTERCEPTORS constant in config/config.php:
 *
 *     define('INERTIA_VERSION', '1');
 *     define('INTERCEPTORS', ['inertia_guard' => 'run']);
 *
 * This runs on every request, before the controller is dispatched, so the
 * asset-version contract is enforced centrally instead of only inside
 * Inertia::render(). Anything that answers an Inertia client without calling
 * render() (an API endpoint, a framework 404) now gets the version check too.
 *
 * Non-Inertia traffic is left completely alone.
 */
class Inertia_guard extends Trongate {

    /**
     * Enforce the Inertia asset-version contract, then step aside.
     *
     * @return void
     */
    public function run(): void {
        if ($this->is_inertia_request() === false) {
            return;
        }

        header('Vary: X-Inertia');

        $expected = defined('INERTIA_VERSION') ? (string) INERTIA_VERSION : '1';
        $client_version = (string) ($_SERVER['HTTP_X_INERTIA_VERSION'] ?? '');

        if ($client_version !== $expected) {
            // ASSUMED_URL is defined by ignition.php before interceptors run:
            // it is this request's own URL, with custom routing already applied.
            header('X-Inertia-Location: ' . ASSUMED_URL, true, 409);
            die();
        }
    }

    /**
     * Is this request coming from an Inertia client?
     *
     * @return bool
     */
    private function is_inertia_request(): bool {
        return isset($_SERVER['HTTP_X_INERTIA'])
            && strtolower((string) $_SERVER['HTTP_X_INERTIA']) === 'true';
    }
}

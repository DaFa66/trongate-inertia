<?php
/**
 * Inertia — protocol adapter for React/Vue front ends (spike build).
 *
 * Lets an Inertia.js client keep taking its page props from the server while
 * the back end stays vanilla Trongate v2. The adapter is a module, so the
 * engine is never touched.
 *
 * Two response shapes come out of one call to render():
 *   - a normal browser hit  -> full HTML shell with the page object in data-page
 *   - a request carrying X-Inertia: true -> JSON {component, props, url, version}
 *
 * NOTE: deliberately does NOT use the framework's json() helper, which is a
 * debug pretty printer (it echoes '<pre>'.json_encode() and sets no content
 * type). We set the headers and encode the page ourselves.
 */
class Inertia extends Trongate {

    private string $version = '1';
    private string $root_view = 'root';
    private array $shared = [];
    private array $page = [];

    /**
     * @param string|null $module_name Auto-provided by the framework.
     */
    public function __construct(?string $module_name = null) {
        parent::__construct($module_name);
        if (defined('INERTIA_VERSION')) {
            $this->version = (string) INERTIA_VERSION;
        }
        block_url('inertia');
    }

    /**
     * Share props with every page rendered in this request (auth user, etc).
     *
     * @param array $props Props to merge into every page's prop bag.
     * @return void
     */
    public function share(array $props): void {
        $this->shared = array_merge($this->shared, $props);
    }

    /**
     * Set the asset version. Inertia forces a full reload when this changes.
     *
     * @param string $version A build hash or timestamp.
     * @return void
     */
    public function set_version(string $version): void {
        $this->version = $version;
    }

    /**
     * Is this request coming from an Inertia client?
     *
     * @return bool
     */
    public function is_inertia_request(): bool {
        return isset($_SERVER['HTTP_X_INERTIA'])
            && strtolower((string) $_SERVER['HTTP_X_INERTIA']) === 'true';
    }

    /**
     * Render a page component, in whichever shape the caller can accept.
     *
     * @param string $component The client-side component name, e.g. Journal/Index.
     * @param array $props Props for that component.
     * @return void
     */
    public function render(string $component, array $props = []): void {
        $this->page = [
            'component' => $component,
            'props' => array_merge(
                ['csrf_token' => $this->csrf_token()],
                $this->shared,
                $this->pull_once_props(),
                $props
            ),
            'url' => $_SERVER['REQUEST_URI'] ?? '/',
            'version' => $this->version
        ];

        if ($this->is_inertia_request() === false) {
            $json = json_encode($this->page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->view($this->root_view, ['page_json' => htmlspecialchars($json, ENT_QUOTES, 'UTF-8')]);
            return;
        }

        $client_version = $_SERVER['HTTP_X_INERTIA_VERSION'] ?? '';

        if ($client_version !== $this->version) {
            header('X-Inertia-Location: ' . $this->page['url'], true, 409);
            die();
        }

        $this->send_page();
    }

    /**
     * Redirect an Inertia client. Non-GET requests must answer 303 so the
     * client follows up with a GET rather than a repeat POST.
     *
     * @param string $target_url Internal route or absolute URL.
     * @param int $status 303 by default; 302 for plain browser requests.
     * @return void
     */
    public function redirect(string $target_url, int $status = 303): void {
        if ($this->is_inertia_request() === false) {
            redirect($target_url);
            return;
        }

        header('X-Inertia: true');
        header('Location: ' . $this->absolute_url($target_url), true, $status);
        die();
    }

    /**
     * Redirect somewhere the client cannot reach by XHR (a PDF, a download,
     * another domain). Inertia expects 409 + X-Inertia-Location.
     *
     * @param string $target_url Absolute URL.
     * @return void
     */
    public function external_redirect(string $target_url): void {
        if ($this->is_inertia_request() === false) {
            redirect($target_url);
            return;
        }

        header('X-Inertia-Location: ' . $this->absolute_url($target_url), true, 409);
        die();
    }

    /**
     * Render a component with a 404 status, so an app-level miss (a row that
     * does not exist) is still an Inertia response rather than an HTML page.
     *
     * @param string $component The component to render.
     * @param array $props Props for that component.
     * @return void
     */
    public function not_found(string $component, array $props = []): void {
        http_response_code(404);
        $this->render($component, $props);
    }

    /**
     * Write the Inertia page object as the response body.
     *
     * @return void
     */
    private function send_page(): void {
        header('Content-Type: application/json');
        header('X-Inertia: true');
        header('Vary: X-Inertia');
        echo json_encode($this->page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        die();
    }

    /**
     * Read one-shot props out of the session: validation errors written by the
     * Validation module and any flash message. Both are cleared as they are read.
     *
     * @return array
     */
    private function pull_once_props(): array {
        $props = [];

        $errors = $_SESSION['form_submission_errors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            $props['errors'] = array_map(
                static function ($messages): string {
                    return is_array($messages) ? implode(' ', $messages) : (string) $messages;
                },
                $errors
            );
        }
        unset($_SESSION['form_submission_errors'], $_SESSION['form_submission_name']);

        if (isset($_SESSION['flashdata'])) {
            $props['flash'] = (string) $_SESSION['flashdata'];
            unset($_SESSION['flashdata']);
        }

        return $props;
    }

    /**
     * Get the session CSRF token, seeding it when no page form has yet.
     *
     * @return string
     */
    private function csrf_token(): string {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }

    /**
     * Prepend BASE_URL to internal routes, leave absolute URLs alone.
     *
     * @param string $target_url
     * @return string
     */
    private function absolute_url(string $target_url): string {
        if (str_starts_with($target_url, 'http')) {
            return $target_url;
        }

        return BASE_URL . $target_url;
    }
}

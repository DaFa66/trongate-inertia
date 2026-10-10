<?php
/**
 * Journal — demo module for the Inertia adapter spike.
 *
 * Simplest thing that exercises the whole protocol: a page visit, a JSON POST
 * that saves and redirects, a failed validation round trip, and an external
 * redirect. Rows live in the session so the spike needs no schema changes.
 */
class Journal extends Trongate {

    /**
     * GET /journal — the page visit. HTML shell for a browser, JSON for Inertia.
     *
     * @return void
     */
    public function index(): void {
        $rows = $this->rows();

        $this->inertia->render('Journal/Index', [
            'rows' => $rows,
            'total' => count($rows)
        ]);
    }

    /**
     * POST /journal/store — validate, save, redirect.
     *
     * @return void
     */
    public function store(): void {
        $this->validation->set_rules('symbol', 'symbol', 'required|min_length[2]');
        $this->validation->set_rules('amount', 'amount', 'required|numeric');

        if ($this->validation->run() === false) {
            // The errors ride back in the next page's props.errors. 303 keeps
            // the client on a GET instead of replaying the POST.
            $this->inertia->redirect('journal');
            return;
        }

        $rows = $this->rows();
        $rows[] = [
            'symbol' => strtoupper((string) post('symbol', true)),
            'amount' => post('amount')
        ];
        $_SESSION['journal_rows'] = $rows;

        set_flashdata('Row added.');
        $this->inertia->redirect('journal');
    }

    /**
     * GET /journal/missing_row — an app-level miss, still an Inertia response.
     *
     * @return void
     */
    public function missing_row(): void {
        $this->inertia->not_found('Journal/Missing', ['row_id' => 404]);
    }

    /**
     * GET /journal/external — a redirect XHR cannot follow.
     *
     * @return void
     */
    public function external(): void {
        $this->inertia->external_redirect('https://trongate.io');
    }

    /**
     * Current rows.
     *
     * @return array
     */
    private function rows(): array {
        return $_SESSION['journal_rows'] ?? [];
    }
}

<?php

return [

    /*
     * FSD 4.3: "Reports above a configurable row-count threshold are
     * generated asynchronously by the Reporting Service and made
     * available for download once complete, rather than blocking the
     * requesting page." A report whose dataset has this many rows or
     * fewer is generated inline during the POST /reports request (status
     * goes straight to COMPLETED in the response); above it, the job is
     * queued and the client polls GET /reports/:id.
     */
    'async_row_threshold' => (int) env('REPORTS_ASYNC_ROW_THRESHOLD', 500),

];

<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    use ApiResponse;

    /** The company the signed-in user belongs to. All organization data is limited to it. */
    protected function companyId(Request $request): int
    {
        return $request->user()->company_id
            ?? abort(403, 'Your account is not linked to a company.');
    }

    /** ?per_page= clamped to 1..100 (default 20). */
    protected function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 20), 1), 100);
    }
}
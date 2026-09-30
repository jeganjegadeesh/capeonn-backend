<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $company = Company::findOrFail($this->companyId($request));
        $company->loadCount(['departments', 'users as employees_count']);

        return $this->success((new CompanyResource($company))->resolve());
    }

    public function update(UpdateCompanyRequest $request): JsonResponse
    {
        $company = Company::findOrFail($this->companyId($request));
        $company->update($request->validated());
        $company->loadCount(['departments', 'users as employees_count']);

        return $this->success((new CompanyResource($company))->resolve(), 'Company updated');
    }
}

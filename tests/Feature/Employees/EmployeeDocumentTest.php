<?php

namespace Tests\Feature\Employees;

use App\Models\EmployeeDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Organization\OrganizationTestCase;

class EmployeeDocumentTest extends OrganizationTestCase
{
    private function as(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_employee_can_upload_and_list_documents(): void
    {
        Storage::fake('public');

        $emp = $this->makeUser(Role::EMPLOYEE);
        $this->as($emp);

        $file = UploadedFile::fake()->create('resume.pdf', 500, 'application/pdf');

        $uploadRes = $this->postJson("/api/v1/employees/{$emp->id}/documents", [
            'title'         => 'My Resume 2026',
            'document_type' => 'resume',
            'file'          => $file,
        ]);

        $uploadRes->assertStatus(201)
            ->assertJsonPath('data.title', 'My Resume 2026')
            ->assertJsonPath('data.document_type', 'resume');

        $listRes = $this->getJson("/api/v1/employees/{$emp->id}/documents");
        $listRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'My Resume 2026');
    }

    public function test_employee_can_delete_own_document(): void
    {
        Storage::fake('public');

        $emp = $this->makeUser(Role::EMPLOYEE);
        $doc = EmployeeDocument::create([
            'user_id'        => $emp->id,
            'company_id'     => $this->company->id,
            'title'          => 'Old Offer Letter',
            'document_type'  => 'contract',
            'file_path'      => 'documents/offer.pdf',
            'file_name'      => 'offer.pdf',
            'file_size'      => 1024,
            'uploaded_by_id' => $emp->id,
        ]);

        $this->as($emp);

        $res = $this->deleteJson("/api/v1/documents/{$doc->id}");
        $res->assertOk();

        $this->assertDatabaseMissing('employee_documents', ['id' => $doc->id]);
    }
}

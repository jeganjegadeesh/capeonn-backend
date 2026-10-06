<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\ChatProvisioningService;
use Illuminate\Console\Command;

class ConnectEmployeeChatsCommand extends Command
{
    protected $signature = 'chat:connect-employees {company_id? : Optional company ID}';

    protected $description = 'Connect active employees together with direct chat channels and introductory messages';

    public function handle(ChatProvisioningService $service): int
    {
        $companyId = $this->argument('company_id');

        if ($companyId) {
            $company = Company::find($companyId);
            if (! $company) {
                $this->error("Company with ID {$companyId} not found.");
                return self::FAILURE;
            }
            $companies = collect([$company]);
        } else {
            $companies = Company::all();
        }

        $total = 0;
        foreach ($companies as $comp) {
            $count = $service->connectAllEmployees($comp->id);
            $this->info("Company '{$comp->name}': created {$count} direct chats.");
            $total += $count;
        }

        $this->info("Completed. Total direct chats connected: {$total}.");
        return self::SUCCESS;
    }
}

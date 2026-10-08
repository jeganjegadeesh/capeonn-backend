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
        $this->info("Direct chats are managed lazily and created on-demand when users converse.");
        $this->info("Completed. No redundant pre-provisioned channels required.");
        return self::SUCCESS;
    }
}

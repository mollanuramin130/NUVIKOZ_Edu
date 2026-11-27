<?php

namespace App\Console\Commands;

use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareLocalPreview extends Command
{
    protected $signature = 'edu:prepare-local-preview';

    protected $description = 'Configure the existing synthetic local preview school calendar';

    public function handle(): int
    {
        $connection = DB::connection();
        if (! app()->environment('local')
            || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'edu_local'
            || $connection->getConfig('host') !== '127.0.0.1'
            || $connection->getConfig('unix_socket')
            || $connection->getConfig('url')) {
            $this->error('Only the local loopback edu_local database is supported.');

            return self::FAILURE;
        }

        $configured = $connection->transaction(function (): bool {
            $school = School::where('code', 'DEMO001')->lockForUpdate()->first();
            if (! $school || $school->name !== 'NUVIKOZ Demo School') {
                return false;
            }

            $settings = $school->settings ?? [];
            if (! empty($settings['timezone'])) {
                return is_string($settings['timezone'])
                    && in_array($settings['timezone'], timezone_identifiers_list(), true);
            }

            $settings['timezone'] = 'Asia/Kolkata';
            $school->update(['settings' => $settings]);

            return true;
        });

        if (! $configured) {
            $this->error('Synthetic school missing or existing timezone invalid; no changes made.');

            return self::FAILURE;
        }

        $this->info('Local preview school calendar configured. Existing access and MFA rules are unchanged.');

        return self::SUCCESS;
    }
}

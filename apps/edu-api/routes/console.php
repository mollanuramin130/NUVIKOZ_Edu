<?php

use App\Services\InitialSchoolAdmin;
use App\Services\ProvisionedSchoolAdminActivation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The authorized manifest contains only a proof digest. Input is hidden and never echoed.
Artisan::command('edu:initialize-staging-school-admin', function () {
    if (! app()->environment('staging') || ! $this->input->isInteractive()) {
        $this->error('An interactive staging operator session is required.');

        return 1;
    }

    try {
        app(InitialSchoolAdmin::class)->initialize((string) $this->secret('Owner-approved manifest', false));
    } catch (Throwable) {
        $this->error('Initialization failed. No account or proof was committed.');

        return 1;
    }

    $this->info('Synthetic school initialized. Activate through the existing activation form.');

    return 0;
})->purpose('Initialize the first School Admin of an owner-approved synthetic staging school');

Artisan::command('edu:reissue-staging-school-admin-proof', function () {
    if (! app()->environment('staging') || ! $this->input->isInteractive()) {
        $this->error('An interactive staging operator session is required.');

        return 1;
    }

    try {
        app(InitialSchoolAdmin::class)->reissue((string) $this->secret('Owner-approved replacement manifest', false));
    } catch (Throwable) {
        $this->error('Reissue failed. No replacement proof was committed.');

        return 1;
    }

    $this->info('Pending School Admin proof replaced. Activate through the existing activation form.');

    return 0;
})->purpose('Replace an unused proof of the owner-approved pending staging School Admin');

Artisan::command('edu:deliver-school-admin-proof {registration}', function (): int {
    $registration = $this->argument('registration');
    if (! Str::isUlid($registration)) {
        return 1;
    }

    return app(ProvisionedSchoolAdminActivation::class)->deliver($registration) ? 0 : 1;
})->purpose('Process one pending School Admin proof through the configured trusted adapter');

Artisan::command('edu:expire-school-admin-proofs', function (): void {
    app(ProvisionedSchoolAdminActivation::class)->expire();
})->purpose('Erase expired School Admin proof material')->everyMinute();

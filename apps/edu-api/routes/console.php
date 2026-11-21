<?php

use App\Services\InitialSchoolAdmin;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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

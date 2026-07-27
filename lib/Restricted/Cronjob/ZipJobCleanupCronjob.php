<?php

declare(strict_types=1);

namespace KLXM\Restricted\Cronjob;

use KLXM\Restricted\Media\BoardShareService;
use rex_cronjob;

class ZipJobCleanupCronjob extends rex_cronjob
{
    public function execute()
    {
        $deleted = BoardShareService::cleanupExpiredZipJobs(2000);
        $this->setMessage('KLXM Restricted ZIP-Cleanup: ' . $deleted . ' Job(s) entfernt.');

        return true;
    }

    public function getTypeName()
    {
        return 'KLXM Restricted ZIP-Job-Bereinigung';
    }

    public function getParamFields()
    {
        return [];
    }
}

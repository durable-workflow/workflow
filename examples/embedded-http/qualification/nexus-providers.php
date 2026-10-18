<?php

require_once dirname(__DIR__) . '/qualification/nexus-workflows.php';

return [App\Providers\AppServiceProvider::class, App\Workflows\PublishedNexusStorageProvider::class];

<?php

declare(strict_types=1);

return [
    // Static release policy; immutable storage and restore execution remain external proof.
    'backup_retention_days' => 35,
    'evidence_retention_days' => 90,
    'evidence_max_age_seconds' => 86400,
    'rpo_seconds' => 900,
    'rto_seconds' => 3600,
];

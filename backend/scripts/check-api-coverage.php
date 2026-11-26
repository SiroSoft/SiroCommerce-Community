<?php

declare(strict_types=1);

// Compatibility entry point. The former stable-prefix string counter was not
// evidence; all callers now use the exact operation/layer handoff gate.
require __DIR__ . '/check-api-handoff.php';

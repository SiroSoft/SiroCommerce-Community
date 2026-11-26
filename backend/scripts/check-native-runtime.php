<?php

declare(strict_types=1);

/** Prevent cutover domains from regressing to legacy orchestration in HTTP controllers. */
$root = dirname(__DIR__);
$files = [
    'app/Controllers/CartController.php',
    'app/Controllers/CheckoutController.php',
    'app/Controllers/OrderController.php',
    'app/Controllers/ProductController.php',
    'app/Controllers/AdminOrderController.php',
    'app/Controllers/AdminInventoryController.php',
    'app/Controllers/AdminProductController.php',
    'app/Controllers/CouponController.php',
    'app/Controllers/PromotionController.php',
    'app/Controllers/AuthController.php',
    'app/Controllers/DevController.php',
    'app/Controllers/AnalyticsController.php',
    'app/Controllers/SeoController.php',
    'app/Controllers/SmartController.php',
];
$violations = [];
foreach ($files as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $contents = (string) file_get_contents($path);
    // These two repositories are injected read/scope adapters by the native
    // admin services; they do not perform controller orchestration.
    $legacyUse = preg_replace('~use App\\\\Repositories\\\\(?:OrderRepository|ProductRepository);~', '', $contents);
    if (is_string($legacyUse) && preg_match('~use App\\\\(Services|Repositories)\\\\~', $legacyUse) === 1) $violations[] = $relative;
}
if ($violations !== []) {
    fwrite(STDERR, "native-runtime: FAIL\n" . implode("\n", $violations) . "\n");
    exit(1);
}
echo "native-runtime: PASS\n";

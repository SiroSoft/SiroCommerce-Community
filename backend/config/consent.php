<?php

declare(strict_types=1);

/**
 * Public cookie/privacy policy. Bump `version` whenever the text changes; the
 * storefront re-prompts visitors because the decision is stored per version.
 */
return [
    'version' => '1',
    'updated_at' => '2026-11-21T00:00:00+00:00',
    'sections' => [
        [
            'id' => 'shipping',
            'title' => 'Shipping',
            'body' => 'Orders are prepared and dispatched within two business days. Tracking details are shared by email as soon as the parcel leaves our warehouse.',
        ],
        [
            'id' => 'returns',
            'title' => 'Returns',
            'body' => 'Eligible items can be returned within 30 days of delivery in their original condition. Financial records are retained where required by law.',
        ],
        [
            'id' => 'privacy',
            'title' => 'Privacy',
            'body' => 'We store the minimum needed to fulfil orders and support your account. Consent choices are recorded as pseudonymous session hashes, never as raw IP addresses or personal data.',
        ],
        [
            'id' => 'cookies',
            'title' => 'Cookies',
            'body' => 'Necessary storage keeps the cart and session working. Analytics and marketing storage stay off until you explicitly opt in, and you can change your choice at any time from the footer.',
        ],
    ],
];

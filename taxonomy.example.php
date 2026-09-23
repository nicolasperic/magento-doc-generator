<?php
/**
 * Label taxonomy — copy to `taxonomy.php` and edit for your codebase.
 *
 * Three families, per docs/TAXONOMY.md:
 *   domains      — what a module is *about* (cohesion). Curated by hand, 1–2 per module.
 *   integrations — what it is *coupled to* (external systems). Auto-matched from signals.
 *   legacy       — modules being decommissioned; surfaces as a "Legacy" status label.
 *                  ("Stub/Placeholder" is detected automatically from stats.classes === 0.)
 *
 * Integration signals are matched two ways:
 *   'vendor/package'  → substring match against the module's composer `require` keys
 *   '~needle'         → substring match against the module directory name
 */
declare(strict_types=1);

return [
    // dirName => [domain, ...]   (1–2 each; more usually means the module is too coarse)
    'domains' => [
        // 'module-checkout-express'   => ['Checkout'],
        // 'module-customer-sync'      => ['Customer', 'Platform / Infra'],
        // 'module-order-export'       => ['Orders', 'Fulfillment'],
    ],

    // Modules being decommissioned or one-time migration tooling.
    'legacy' => [
        // 'module-legacy-gateway',
    ],

    // label => [needle, ...]
    'integrations' => [
        'Stripe'        => ['stripe/stripe-php', '~stripe'],
        'Braintree'     => ['braintree/braintree_php', '~braintree'],
        'PayPal'        => ['~paypal'],
        'Adyen'         => ['adyen/', '~adyen'],
        'Klarna'        => ['klarna/', '~klarna'],
        'Vertex'        => ['vertexinc/', '~vertex'],
        'Avalara'       => ['avalara/', '~avatax'],
        'Algolia'       => ['algolia/', '~algolia'],
        'Amazon S3'     => ['league/flysystem-aws-s3-v3', '~s3'],
        'Google Cloud'  => ['google/cloud', 'flysystem-google-cloud-storage'],
        'Twilio'        => ['twilio/', '~twilio'],
        'SendGrid'      => ['sendgrid/', '~sendgrid'],
    ],
];

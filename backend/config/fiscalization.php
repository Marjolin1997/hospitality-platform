<?php

return [
    'secret_dir' => env('FISCAL_SECRET_DIR', '/run/secrets'),
    'dpt_ca_bundle' => env('FISCAL_DPT_CA_BUNDLE'),
    'test_endpoint' => env('FISCAL_DPT_TEST_ENDPOINT'),
    'production_endpoint' => env('FISCAL_DPT_PRODUCTION_ENDPOINT'),
];

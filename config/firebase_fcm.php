<?php

return [
    'serviceAccountFile' => getenv('SISTEMAX_FIREBASE_SERVICE_ACCOUNT_FILE') ?: (__DIR__ . '/firebase_service_account.json'),
    'serviceAccountJson' => getenv('SISTEMAX_FIREBASE_SERVICE_ACCOUNT_JSON') ?: '',
    'projectId' => getenv('SISTEMAX_FIREBASE_PROJECT_ID') ?: '',
];

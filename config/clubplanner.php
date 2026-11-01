<?php

return [

    // The root of the platform, without a path: https://your-club.clubplanner.app
    'base_url' => env('CLUBPLANNER_BASE_URL'),

    // Issued under Settings -> Platform -> Partner API.
    'api_key' => env('CLUBPLANNER_API_KEY'),

    'version' => env('CLUBPLANNER_API_VERSION', 'v1'),

    'timeout' => (int) env('CLUBPLANNER_TIMEOUT', 15),

    // The API reads and writes wall-clock times in the club's own timezone.
    'timezone' => env('CLUBPLANNER_TIMEZONE', 'Europe/Brussels'),

    // Response keys to strip at any depth. Empty returns the API's full answer;
    // use \Proclame\ClubPlanner\Redactor::SENSITIVE_FIELDS to drop credentials
    // and bank details. Do not read-merge-write a record through a redacting
    // client: the stripped fields would be missing from what you send back.
    'redact' => [],

];

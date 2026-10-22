<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Application Name
    |--------------------------------------------------------------------------
    |
    | The name of your application as it will appear in Google Console.
    |
    */
    'app_name' => env('GOOGLE_APP_NAME', 'Laravel Order System'),

    /*
    |--------------------------------------------------------------------------
    | Google Sheets Credentials Path
    |--------------------------------------------------------------------------
    |
    | The path to your Google service account JSON credentials file.
    | This file should be stored in storage/app/google-credentials.json
    |
    */
    'credentials_path' => env('GOOGLE_CREDENTIALS_PATH', storage_path('app/google-credentials.json')),

    /*
    |--------------------------------------------------------------------------
    | Google Sheets Credentials JSON
    |--------------------------------------------------------------------------
    |
    | Alternatively, provide the service-account JSON directly as a secret
    | environment variable for deployments where files are not persisted.
    |
    */
    'credentials_json' => env('GOOGLE_CREDENTIALS_JSON'),

    /*
    |--------------------------------------------------------------------------
    | Google Spreadsheet ID
    |--------------------------------------------------------------------------
    |
    | The ID of the Google Spreadsheet where orders will be synced.
    | You can find this in the URL: https://docs.google.com/spreadsheets/d/{SPREADSHEET_ID}/edit
    |
    */
    'spreadsheet_id' => env('GOOGLE_SPREADSHEET_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Sheet Range
    |--------------------------------------------------------------------------
    |
    | The sheet name and range where data will be appended.
    | Default is 'Orders!A:J' for columns A through J.
    |
    */
    'sheet_range' => env('GOOGLE_SHEET_RANGE', 'Orders!A:J'),
];

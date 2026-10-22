<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
    private ?Sheets $service = null;

    private string $spreadsheetId = '';

    public function __construct()
    {
        $this->spreadsheetId = (string) (config('google.spreadsheet_id') ?? '');
        $credentialsJson = config('google.credentials_json');
        $credentialsPath = config('google.credentials_path');

        if (is_string($credentialsJson) && trim($credentialsJson) !== '') {
            $credentials = json_decode($credentialsJson, true);

            if (! is_array($credentials) || json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Google Sheets credentials JSON is invalid.');

                return;
            }
        } elseif (is_string($credentialsPath) && is_file($credentialsPath)) {
            $credentials = $credentialsPath;
        } else {
            return;
        }

        try {
            $client = new Client;
            $client->setApplicationName(config('google.app_name', 'Laravel Order System'));
            $client->setScopes([Sheets::SPREADSHEETS]);
            $client->setAuthConfig($credentials);
            $this->service = new Sheets($client);
        } catch (\Throwable $exception) {
            Log::error('Google Sheets client could not be initialized.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Append order data to Google Sheets.
     *
     * @param  array<string, mixed>  $orderData
     */
    public function appendOrder(array $orderData): bool
    {
        $service = $this->configuredService();

        if ($service === null) {
            return false;
        }

        try {
            $values = [
                [
                    $orderData['order_number'],
                    $orderData['customer_name'],
                    $orderData['phone'],
                    $orderData['wilaya'],
                    $orderData['subtotal'],
                    $orderData['shipping_fee'],
                    $orderData['total'],
                    $orderData['status'],
                    $orderData['items_summary'],
                    $orderData['created_at'],
                ],
            ];

            $body = new ValueRange([
                'values' => $values,
            ]);

            $service->spreadsheets_values->append(
                $this->spreadsheetId,
                config('google.sheet_range', 'Orders!A:J'),
                $body,
                ['valueInputOption' => 'RAW']
            );

            Log::info('Order synced to Google Sheets', ['order_number' => $orderData['order_number']]);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Failed to sync order to Google Sheets', [
                'error' => $exception->getMessage(),
                'order_number' => $orderData['order_number'] ?? null,
            ]);

            return false;
        }
    }

    /**
     * Create the sheet with headers if it doesn't exist.
     */
    public function setupSheet(): bool
    {
        $service = $this->configuredService();

        if ($service === null) {
            return false;
        }

        try {
            $headers = [
                ['Order Number', 'Customer Name', 'Phone', 'Wilaya', 'Subtotal', 'Shipping Fee', 'Total', 'Status', 'Items', 'Created At'],
            ];

            $body = new ValueRange([
                'values' => $headers,
            ]);

            $service->spreadsheets_values->update(
                $this->spreadsheetId,
                config('google.sheet_range', 'Orders!A1:J1'),
                $body,
                ['valueInputOption' => 'RAW']
            );

            return true;
        } catch (\Throwable $exception) {
            Log::error('Failed to setup Google Sheet', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    private function configuredService(): ?Sheets
    {
        if ($this->service !== null && $this->spreadsheetId !== '') {
            return $this->service;
        }

        Log::warning('Google Sheets sync skipped because credentials or spreadsheet ID are not configured.');

        return null;
    }
}

<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
    private Client $client;

    private Sheets $service;

    private string $spreadsheetId;

    public function __construct()
    {
        $this->client = new Client;
        $this->client->setApplicationName(config('google.app_name', 'Laravel Order System'));
        $this->client->setScopes([Sheets::SPREADSHEETS]);
        $this->client->setAuthConfig(config('google.credentials_path'));
        $this->service = new Sheets($this->client);
        $this->spreadsheetId = config('google.spreadsheet_id');
    }

    /**
     * Append order data to Google Sheets.
     *
     * @param  array<string, mixed>  $orderData
     */
    public function appendOrder(array $orderData): bool
    {
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

            $this->service->spreadsheets_values->append(
                $this->spreadsheetId,
                config('google.sheet_range', 'Orders!A:J'),
                $body,
                ['valueInputOption' => 'RAW']
            );

            Log::info('Order synced to Google Sheets', ['order_number' => $orderData['order_number']]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to sync order to Google Sheets', [
                'error' => $e->getMessage(),
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
        try {
            $headers = [
                ['Order Number', 'Customer Name', 'Phone', 'Wilaya', 'Subtotal', 'Shipping Fee', 'Total', 'Status', 'Items', 'Created At'],
            ];

            $body = new ValueRange([
                'values' => $headers,
            ]);

            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                config('google.sheet_range', 'Orders!A1:J1'),
                $body,
                ['valueInputOption' => 'RAW']
            );

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to setup Google Sheet', ['error' => $e->getMessage()]);

            return false;
        }
    }
}

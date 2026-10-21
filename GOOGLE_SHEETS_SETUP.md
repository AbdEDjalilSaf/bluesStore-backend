# Google Sheets Integration Setup

This guide explains how to configure the Google Sheets integration for syncing customer orders to your Google Sheet (noodsaf01@gmail.com).

## Prerequisites

1. A Google account (noodsaf01@gmail.com)
2. Access to Google Cloud Console
3. A Google Sheet to store orders

## Step 1: Create a Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Enable the Google Sheets API:
   - Navigate to "APIs & Services" > "Library"
   - Search for "Google Sheets API"
   - Click "Enable"

## Step 2: Create a Service Account

1. Go to "APIs & Services" > "Credentials"
2. Click "Create Credentials" > "Service Account"
3. Fill in the service account details:
   - Name: `Laravel Order System`
   - Service account description: `Service account for order management`
4. Click "Create and Continue"
5. Skip the optional steps (roles) and click "Done"

## Step 3: Generate Service Account Key

1. Click on the newly created service account
2. Go to the "Keys" tab
3. Click "Add Key" > "Create new key"
4. Select "JSON" and click "Create"
5. The JSON file will be downloaded automatically

## Step 4: Configure the Service Account

1. Open the downloaded JSON file and copy the `client_email` value
2. Share your Google Sheet with this email address:
   - Open your Google Sheet
   - Click "Share" button
   - Paste the `client_email` and give it "Editor" permissions
   - Click "Send"

## Step 5: Get the Spreadsheet ID

1. Open your Google Sheet in the browser
2. The URL will look like: `https://docs.google.com/spreadsheets/d/{SPREADSHEET_ID}/edit`
3. Copy the `{SPREADSHEET_ID}` part

## Step 6: Configure the Application

1. Rename the downloaded JSON key file to `google-credentials.json`
2. Move it to: `storage/app/google-credentials.json`
3. Add the following to your `.env` file:

```env
GOOGLE_APP_NAME=Laravel Order System
GOOGLE_CREDENTIALS_PATH=storage/app/google-credentials.json
GOOGLE_SPREADSHEET_ID=your_spreadsheet_id_here
GOOGLE_SHEET_RANGE=Orders!A:J
```

4. Replace `your_spreadsheet_id_here` with the ID from Step 5

## Step 7: Initialize the Sheet

Run the following command to create the headers in your Google Sheet:

```bash
php artisan tinker --execute 'App\Services\GoogleSheetsService::app()->setupSheet();'
```

This will create the following columns:
- Order Number
- Customer Name
- Phone
- Wilaya
- Subtotal
- Shipping Fee
- Total
- Status
- Items
- Created At

## Step 8: Test the Integration

Place a test order through the API to verify that orders are being synced to Google Sheets:

```bash
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{
    "customer_name": "Test Customer",
    "phone": "0550123456",
    "wilaya_id": 1,
    "items": [{"product_id": 1}]
  }'
```

Check your Google Sheet to see if the order appears.

## Troubleshooting

### Permission Errors
- Ensure the service account email has "Editor" access to the Google Sheet
- Verify the JSON credentials file path is correct

### API Errors
- Check that the Google Sheets API is enabled in your Google Cloud project
- Verify the spreadsheet ID is correct

### Orders Not Syncing
- Check the Laravel logs: `storage/logs/laravel.log`
- Ensure the `.env` variables are correctly configured
- Run `php artisan config:clear` to refresh configuration

## Security Notes

- **Never commit** the `google-credentials.json` file to version control
- Add `storage/app/google-credentials.json` to your `.gitignore` file
- Keep the service account key file secure
- Regularly rotate service account keys for better security

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderRequest',
    type: 'object',
    required: ['customer_name', 'phone', 'wilaya_id', 'items'],
    properties: [
        new OA\Property(property: 'customer_name', type: 'string', example: 'Karim Benali'),
        new OA\Property(property: 'phone', type: 'string', description: 'Algerian mobile number (0[5-7]XXXXXXXX).', example: '0550123456'),
        new OA\Property(property: 'wilaya_id', type: 'integer', description: 'ID of an active wilaya.', example: 16),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderItemRequest')),
    ]
)]
#[OA\Schema(
    schema: 'OrderItemRequest',
    type: 'object',
    required: ['product_id'],
    properties: [
        new OA\Property(property: 'product_id', type: 'integer', description: 'ID of an active product. Send one entry per unit ordered.', example: 1),
    ]
)]
class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^0[5-7][0-9]{8}$/'],
            'wilaya_id' => ['required', 'integer', Rule::exists('wilayas', 'id')->where('is_active', true)],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone number must be a valid Algerian mobile number (e.g. 0550123456).',
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MaterialRequestFormSchemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaterialRequestPlatformController extends Controller
{
    public function __construct(private MaterialRequestFormSchemaService $schemas) {}

    public function schema(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(config('material-requests.requester_types', ['client', 'trainee', 'alumni']))],
        ]);

        return response()->json($this->schemas->build($data['type'] ?? 'client'));
    }

    public function openapi(): JsonResponse
    {
        $base = rtrim((string) config('app.url'), '/');
        $error = ['$ref' => '#/components/schemas/ValidationError'];
        $requestRef = ['$ref' => '#/components/schemas/MaterialRequest'];
        $availabilityRef = ['$ref' => '#/components/schemas/AvailabilityResponse'];

        return response()->json([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'LIA Material Requests API',
                'version' => '1.0.0',
                'description' => 'Platform-to-platform equipment/material request API. Authenticate with X-LIA-Key. See docs/material-requests-api.md for examples.',
            ],
            'servers' => [['url' => $base]],
            'security' => [['liaKey' => []]],
            'tags' => [
                ['name' => 'Schema'],
                ['name' => 'Catalog'],
                ['name' => 'Requests'],
            ],
            'components' => [
                'securitySchemes' => [
                    'liaKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-LIA-Key'],
                ],
                'schemas' => [
                    'ValidationError' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string', 'example' => 'Validation failed'],
                            'errors' => [
                                'type' => 'object',
                                'additionalProperties' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                    'Field' => [
                        'type' => 'object',
                        'required' => ['key', 'type', 'required', 'label'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['text', 'textarea', 'email', 'tel', 'url', 'date', 'select', 'checkbox', 'file', 'signature', 'items']],
                            'required' => ['type' => 'boolean'],
                            'section' => ['type' => 'string'],
                            'label' => [
                                'type' => 'object',
                                'properties' => ['en' => ['type' => 'string'], 'kn' => ['type' => 'string']],
                            ],
                            'max_length' => ['type' => 'integer'],
                            'options' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'options_source' => ['type' => 'string'],
                            'depends_on' => ['type' => ['string', 'null']],
                            'catalog_url' => ['type' => 'string', 'format' => 'uri'],
                        ],
                    ],
                    'FormSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['client', 'trainee', 'alumni']],
                            'fields' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Field']],
                            'requester_types' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'options' => [
                                'type' => 'object',
                                'properties' => [
                                    'cohorts' => ['type' => 'array', 'items' => ['type' => 'object']],
                                    'rwanda_divisions_url' => ['type' => 'string', 'format' => 'uri'],
                                ],
                            ],
                            'links' => ['type' => 'object'],
                        ],
                    ],
                    'CatalogItem' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'category' => ['type' => ['string', 'null']],
                            'condition' => ['type' => ['string', 'null']],
                            'available_qty' => ['type' => 'integer'],
                            'photo_path' => ['type' => ['string', 'null']],
                        ],
                    ],
                    'AvailabilityResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'from' => ['type' => 'string', 'format' => 'date'],
                            'to' => ['type' => 'string', 'format' => 'date'],
                            'rules' => ['type' => 'object'],
                            'global_blocked_dates' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
                        ],
                    ],
                    'MaterialRequest' => [
                        'type' => 'object',
                        'properties' => [
                            'reference_code' => ['type' => 'string', 'example' => 'MR-AB12CD34'],
                            'status' => ['type' => 'string', 'enum' => ['submitted', 'approved', 'rejected', 'issued', 'returned', 'closed']],
                            'requester_type' => ['type' => 'string', 'enum' => ['client', 'trainee', 'alumni']],
                            'needed_from' => ['type' => 'string', 'format' => 'date'],
                            'return_by' => ['type' => 'string', 'format' => 'date'],
                            'source' => ['type' => ['string', 'null']],
                            'source_detail' => ['type' => ['string', 'null']],
                            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/api/v1/material-requests/form-schema' => [
                    'get' => [
                        'tags' => ['Schema'],
                        'summary' => 'Field definitions for a requester type',
                        'security' => [['liaKey' => []]],
                        'parameters' => [
                            ['name' => 'type', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['client', 'trainee', 'alumni'], 'default' => 'client']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Bilingual field schema', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FormSchema']]]],
                            '401' => ['description' => 'Missing or invalid X-LIA-Key'],
                            '422' => ['description' => 'Unknown requester type', 'content' => ['application/json' => ['schema' => $error]]],
                        ],
                    ],
                ],
                '/api/v1/material-requests/catalog' => [
                    'get' => [
                        'tags' => ['Catalog'],
                        'summary' => 'Requestable equipment with available quantity',
                        'security' => [['liaKey' => []]],
                        'responses' => [
                            '200' => [
                                'description' => 'Catalog',
                                'content' => ['application/json' => ['schema' => [
                                    'type' => 'object',
                                    'properties' => ['items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CatalogItem']]],
                                ]]],
                            ],
                            '401' => ['description' => 'Missing or invalid X-LIA-Key'],
                        ],
                    ],
                ],
                '/api/v1/material-requests/availability' => [
                    'get' => [
                        'tags' => ['Catalog'],
                        'summary' => 'Booked, blocked and unavailable dates per item',
                        'security' => [['liaKey' => []]],
                        'parameters' => [
                            ['name' => 'item_ids', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                            ['name' => 'from', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date']],
                            ['name' => 'to', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Availability', 'content' => ['application/json' => ['schema' => $availabilityRef]]],
                            '401' => ['description' => 'Missing or invalid X-LIA-Key'],
                            '422' => ['description' => 'Invalid query', 'content' => ['application/json' => ['schema' => $error]]],
                        ],
                    ],
                ],
                '/api/v1/material-requests/submit' => [
                    'post' => [
                        'tags' => ['Requests'],
                        'summary' => 'Submit a material/equipment request',
                        'description' => 'Send JSON, or multipart/form-data when uploading id_document and/or signature files. Profile fields may be supplied directly by the trusted platform; signed prefill remains available to the hosted form.',
                        'security' => [['liaKey' => []]],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => ['schema' => ['type' => 'object']],
                                'multipart/form-data' => ['schema' => ['type' => 'object']],
                            ],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Submitted',
                                'content' => ['application/json' => ['schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'message' => ['type' => 'string'],
                                        'reference_code' => ['type' => 'string'],
                                        'status' => ['type' => 'string'],
                                    ],
                                ]]],
                            ],
                            '401' => ['description' => 'Missing or invalid X-LIA-Key'],
                            '422' => ['description' => 'Validation, blocked date or stock error', 'content' => ['application/json' => ['schema' => $error]]],
                        ],
                    ],
                ],
                '/api/v1/material-requests/{reference}' => [
                    'get' => [
                        'tags' => ['Requests'],
                        'summary' => 'Status of a request created by this API client',
                        'security' => [['liaKey' => []]],
                        'parameters' => [
                            ['name' => 'reference', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'example' => 'MR-AB12CD34']],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Request status',
                                'content' => ['application/json' => ['schema' => [
                                    'type' => 'object',
                                    'properties' => ['request' => $requestRef],
                                ]]],
                            ],
                            '401' => ['description' => 'Missing or invalid X-LIA-Key'],
                            '404' => ['description' => 'Not found for this API client'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}

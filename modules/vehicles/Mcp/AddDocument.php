<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use App\Support\Mcp\IncomingFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Vehicles\VehicleDocument;

class AddDocument extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_add_document';

    protected string $description = 'Add a document to a car (insurance, inspection, road tax, invoice, manual...), optionally with an expiry date (used for renewal reminders) and the file itself (PDF or image, up to 12 MB). Pass the file as `file` (a file the person attached), `file_url` (public https), or `file_base64` + `file_name`. If you cannot pass the file, add the document without it and give the person the returned url to upload it there.';

    public function __construct()
    {
        $this->setMeta(IncomingFile::FILE_PARAMS_META); // lets ChatGPT pass a file the person attached
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->required(),
            'type' => $schema->string()->enum(VehicleDocument::TYPES)->required(),
            'title' => $schema->string()->description('e.g. "RCA 2026–2027" or "Deviz service ulei".')->required(),
            'expires_on' => $schema->string()->format('date')->description('YYYY-MM-DD, for documents that expire.'),
            'notes' => $schema->string(),
            ...IncomingFile::schema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        // Same rules as the Documents form (minus the upload field, which IncomingFile validates).
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(VehicleDocument::TYPES)],
            'title' => ['required', 'string', 'max:120'],
            'expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $v = VehicleData::vehicle($data['vehicle_id']);
        if (! $v) {
            return $this->notFound('Vehicle');
        }
        $file = IncomingFile::from($request); // validated before anything is written

        $document = $v->documents()->create([
            'type' => $data['type'],
            'title' => $data['title'],
            'expires_on' => $data['expires_on'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $this->user()->id,
        ]);
        if ($file) {
            $document->attachFile($file);
        }

        return Response::json([
            'document_id' => $document->id,
            'title' => $document->title,
            'file' => $file?->getClientOriginalName(),
            'url' => VehicleData::url("/m/vehicles/{$v->id}/documents"),
        ]);
    }
}

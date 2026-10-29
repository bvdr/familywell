<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use App\Support\Mcp\IncomingFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Modules\Vehicles\VehicleDocument;

#[IsOpenWorld]
class AddDocument extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_add_document';

    protected string $description = 'Use this when the user wants to file a car document (insurance, inspection, road tax, invoice, manual...), optionally with an expiry date (used for renewal reminders) and the file itself (PDF or image, up to 12 MB) as `file`, `file_url` or `file_base64` + `file_name`. When the file can\'t be passed, add the document without it and give the person the returned url to upload it.';

    protected string $title = 'Add car document';

    public function __construct()
    {
        $this->setMeta(IncomingFile::FILE_PARAMS_META); // lets ChatGPT pass a file the person attached
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->description('Car id, from vehicles_list.')->required(),
            'type' => $schema->string()->description('Kind of document.')->enum(VehicleDocument::TYPES)->required(),
            'title' => $schema->string()->description('e.g. "RCA 2026–2027" or "Deviz service ulei".')->required(),
            'expires_on' => $schema->string()->format('date')->description('YYYY-MM-DD, for documents that expire.'),
            'notes' => $schema->string()->description('Free-text notes.'),
            ...IncomingFile::schema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
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

        return Response::structured([
            'document_id' => $document->id,
            'title' => $document->title,
            'file' => $file?->getClientOriginalName(),
            'url' => VehicleData::url("/m/vehicles/{$v->id}/documents"),
        ]);
    }
}

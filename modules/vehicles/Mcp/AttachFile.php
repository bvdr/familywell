<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use App\Support\Mcp\IncomingFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class AttachFile extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_attach_file';

    protected string $description = 'Attach a file (PDF or image, up to 12 MB) to an existing repair (adds it to the repair\'s attachments) or document (replaces its file). Ids come from vehicles_add_repair / vehicles_add_document / vehicles_get (document_id) / vehicles_history ("repair-12" means repair 12). For a service invoice, use vehicles_add_document with type service_invoice. Pass the file as `file` (a file the person attached), `file_url` (public https), or `file_base64` + `file_name`.';

    public function __construct()
    {
        $this->setMeta(IncomingFile::FILE_PARAMS_META); // lets ChatGPT pass a file the person attached
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->required(),
            'target' => $schema->string()->enum(['repair', 'document'])->required(),
            'id' => $schema->integer()->description('The repair or document id.')->required(),
            ...IncomingFile::schema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'target' => ['required', 'in:repair,document'],
            'id' => ['required', 'integer'],
        ]);
        $v = VehicleData::vehicle($data['vehicle_id']);
        $record = $data['target'] === 'repair' ? $v?->repairs()->find($data['id']) : $v?->documents()->find($data['id']);
        if (! $record) {
            return $this->notFound(ucfirst($data['target']));
        }
        $file = IncomingFile::from($request);
        if (! $file) {
            return Response::error('No file given: pass file, file_url, or file_base64 + file_name.');
        }

        $record->attachFile($file);

        return Response::json([
            'attached' => $file->getClientOriginalName(),
            'target' => $data['target'],
            'id' => $record->id,
            'url' => VehicleData::url("/m/vehicles/{$v->id}/".($data['target'] === 'repair' ? 'repairs' : 'documents')),
        ]);
    }
}

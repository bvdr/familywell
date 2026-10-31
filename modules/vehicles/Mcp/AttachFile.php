<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use App\Support\Mcp\IncomingFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsDestructive]
#[IsOpenWorld]
class AttachFile extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_attach_file';

    protected string $description = 'Use this when the user wants a file (PDF or image, up to 12 MB) added to an existing repair (added to its attachments) or document (replaces its file). Ids come from vehicles_add_repair, vehicles_add_document, vehicles_get (document_id) or vehicles_get_history ("repair-12" means repair 12). For a service invoice use vehicles_add_document with type service_invoice.';

    protected string $title = 'Attach file to car record';

    public function __construct()
    {
        $this->setMeta(IncomingFile::FILE_PARAMS_META); // lets ChatGPT pass a file the person attached
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->description('Car id, from vehicles_list.')->required(),
            'target' => $schema->string()->description('repair or document.')->enum(['repair', 'document'])->required(),
            'id' => $schema->integer()->description('The repair or document id.')->required(),
            ...IncomingFile::schema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
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

        return Response::structured([
            'attached' => $file->getClientOriginalName(),
            'target' => $data['target'],
            'id' => $record->id,
            'url' => VehicleData::url("/m/vehicles/{$v->id}/".($data['target'] === 'repair' ? 'repairs' : 'documents')),
        ]);
    }
}

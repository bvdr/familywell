<?php

namespace App\Mcp\Apps;

use App\Support\Tenancy\CurrentFamily;
use App\Support\Theming\ThemeTokens;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Ui\AppMeta;
use Laravel\Mcp\Server\Ui\Csp;

/**
 * One MCP Apps widget (https://modelcontextprotocol.io/extensions/apps/overview): a self-contained HTML
 * page the host (Claude, ChatGPT, ...) renders in a sandboxed iframe next to a tool's result. A tool opts
 * in with `protected ?string $app = '<widget>'` (App\Mcp\Tool adds the `_meta.ui.resourceUri`).
 *
 * Every widget is the shared runtime (resources/mcp-apps/core.{css,js}) plus its own resources/mcp-apps/
 * <widget>.js, all inlined: no network, so the CSP stays at the secure default (no external domains).
 * The widget renders the tool result it is pushed and changes things only by calling our existing write
 * tools through the host, so scopes, sharing rules, notifications and realtime are exactly the MCP ones.
 *
 * ponytail: one class instantiated per widget (FamilywellServer::boot) instead of a class per widget;
 * each instance is a plain listed resource, which every host handles (templates are less supported).
 */
class Widget extends AppResource
{
    /** Widget name => what it shows (resource description). Adding a widget = a line here + its js file. */
    public const WIDGETS = [
        'tasks' => 'Checklist for a to-do list or task search: tick tasks off, add tasks.',
        'shopping' => 'Shopping list grouped by aisle: tick items as bought, add items.',
        'project' => 'Project board: progress, tickable tasks, dependencies, linked shopping lists.',
        'today' => 'What is due today and this week, tickable.',
        'spending' => 'Spending summary chart by category/shop, or a list of expenses.',
        'inventory' => 'Household stock with +/- steppers and add-to-shopping.',
        'vehicle' => 'Car cards: odometer, maintenance (mark done), documents, history.',
        'birthdays' => 'Upcoming birthdays with countdowns.',
        'note' => 'A note rendered with tickable checklists.',
        'contact' => 'A contact card with tap-to-call / email links.',
        'recipe' => 'Recipes: photo, live calculator (servings / total / what you have), ingredients, steps, add to shopping.',
    ];

    /** Widgets that can go full screen (they show a full-screen button when the host offers it). */
    public const FULLSCREEN = ['shopping', 'recipe'];

    /**
     * Widgets that show images from Familywell (signed photo URLs). Only these get an img-src for our own
     * origin in their CSP; every other widget keeps the no-external-resources default.
     */
    public const IMAGES = ['recipe'];

    /** Extra shared scripts a widget inlines before its own file (paths under resources/). */
    private const LIBS = ['recipe' => ['js/lib/recipe-scale.js']];

    public function __construct(public readonly string $widget)
    {
        $this->uri = self::uriFor($widget);
        $this->name = "familywell-{$widget}";
        $this->title = 'Familywell '.$widget;
        $this->description = self::WIDGETS[$widget];
    }

    public static function uriFor(string $widget): string
    {
        return "ui://familywell/{$widget}";
    }

    public function handle(Request $request): Response
    {
        $read = fn (string $file) => file_get_contents(resource_path("mcp-apps/{$file}"));

        return Response::view('mcp-app', [
            'title' => $this->title,
            'sdk' => self::sdk(in_array($this->widget, self::FULLSCREEN, true) ? ['inline', 'fullscreen'] : []),
            // The person's Familywell skin, same resolution as the web app. The page is read with their
            // token (McpFamilyContext has set the family), so this is their colours, not a shared default.
            'themeCss' => ThemeTokens::css(ThemeTokens::resolve(auth()->user(), CurrentFamily::id())),
            'css' => $read('core.css'),
            'js' => $read('core.js')."\n".$this->libs()."\n".$read("{$this->widget}.js"),
        ]);
    }

    /**
     * Shared ES modules inlined as plain script: the widget page is one classic <script>, so the `export`
     * keywords are dropped (the declarations stay global). recipe-scale.js is written for exactly this.
     */
    private function libs(): string
    {
        return collect(self::LIBS[$this->widget] ?? [])
            ->map(fn (string $f) => preg_replace('/^export /m', '', file_get_contents(resource_path($f))))
            ->implode("\n");
    }

    public function appMeta(): AppMeta
    {
        if (! in_array($this->widget, self::IMAGES, true)) {
            return parent::appMeta();
        }
        // Photos are served by this app (signed URLs built on the request's host, mcp.familywell.co for MCP
        // calls) - allow both that host and the main app URL, nothing else.
        $origins = collect([url('/'), config('app.url')])->map(fn ($u) => rtrim((string) $u, '/'))->unique()->values()->all();

        return new AppMeta(csp: new Csp(resourceDomains: $origins));
    }

    /**
     * laravel/mcp's postMessage SDK with our display modes declared. The spec requires a view to list the
     * modes it supports in ui/initialize's appCapabilities.availableDisplayModes (hosts may refuse a
     * fullscreen request otherwise), but the SDK hardcodes `appCapabilities:{}` with no option. So we
     * patch that one literal; tests/Feature/Mcp/AppsTest asserts it still matches after package updates.
     */
    public static function sdk(array $modes): string
    {
        $sdk = (string) app('mcp.sdk');

        return $modes === [] ? $sdk : str_replace(
            'appCapabilities:{}',
            'appCapabilities:'.json_encode(['availableDisplayModes' => $modes]),
            $sdk,
        );
    }
}

<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\{identifier};

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class {identifier}ExtensionController extends Controller
{
    private const TABLE = '{identifier}';

    private const DEFAULTS = [
        'modrinth_enabled' => '1',
        'curseforge_enabled' => '0',
        'curseforge_api_key' => '',
        'allow_override' => '0',
        'mods_directory' => '/mods',
        'page_size' => '20',
        'contact' => '',
    ];

    public function __construct(
        private ViewFactory $view,
        private BlueprintExtensionLibrary $blueprint,
    ) {
    }

    public function index(): View
    {
        $settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $this->blueprint->dbGet(self::TABLE, $key);
            $settings[$key] = ($value === null || $value === '') ? $default : (string) $value;
        }

        $installCount = 0;
        $serverCount = 0;
        try {
            $installCount = (int) DB::table('modmanager_installs')->count();
            $serverCount = (int) DB::table('modmanager_installs')->distinct()->count('server_id');
        } catch (\Throwable) {
            // Table missing until the migration ran; the page must still render.
        }

        return $this->view->make('admin.extensions.{identifier}.index', [
            'settings' => $settings,
            'installCount' => $installCount,
            'serverCount' => $serverCount,
            'root' => '/admin/extensions/{identifier}',
            'blueprint' => $this->blueprint,
        ]);
    }

    public function update({identifier}SettingsFormRequest $request): RedirectResponse
    {
        $data = $request->normalize();

        foreach (['modrinth_enabled', 'curseforge_enabled', 'allow_override'] as $flag) {
            $data[$flag] = !empty($data[$flag]) && $data[$flag] !== '0' ? '1' : '0';
        }

        $data['mods_directory'] = '/' . trim((string) ($data['mods_directory'] ?? '/mods'), "/ \t");
        if ($data['mods_directory'] === '/') {
            $data['mods_directory'] = '/mods';
        }

        $data['page_size'] = (string) max(5, min(50, (int) ($data['page_size'] ?? 20)));

        // An empty key field means "keep the stored key" so admins can save other
        // settings without re-entering the secret every time.
        if (($data['curseforge_api_key'] ?? '') === '') {
            unset($data['curseforge_api_key']);
        }
        if (!empty($data['clear_curseforge_api_key'])) {
            $data['curseforge_api_key'] = '';
        }
        unset($data['clear_curseforge_api_key']);

        foreach ($data as $key => $value) {
            $this->blueprint->dbSet(self::TABLE, $key, (string) $value);
        }

        $this->blueprint->alert('success', 'Mod Manager settings saved.');

        return redirect()->route('admin.extensions.{identifier}.index');
    }
}

class {identifier}SettingsFormRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'modrinth_enabled' => 'nullable|in:0,1',
            'curseforge_enabled' => 'nullable|in:0,1',
            'curseforge_api_key' => 'nullable|string|max:191',
            'clear_curseforge_api_key' => 'nullable|in:0,1',
            'allow_override' => 'nullable|in:0,1',
            'mods_directory' => 'required|string|max:64|regex:/^[A-Za-z0-9_\-\/.]+$/|not_regex:/\.\./',
            'page_size' => 'required|integer|min:5|max:50',
            'contact' => 'nullable|string|max:120',
        ];
    }

    public function attributes(): array
    {
        return [
            'modrinth_enabled' => 'Modrinth enabled',
            'curseforge_enabled' => 'CurseForge enabled',
            'curseforge_api_key' => 'CurseForge API key',
            'allow_override' => 'Allow loader override',
            'mods_directory' => 'Mods directory',
            'page_size' => 'Results per page',
            'contact' => 'Contact (User-Agent)',
        ];
    }
}

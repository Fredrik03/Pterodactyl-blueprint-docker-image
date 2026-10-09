<form id="config-form" action="" method="POST">
  {{ csrf_field() }}

  <div class="row">
    <div class="col-xs-12 col-md-6">
      <div class="box box-success">
        <div class="box-header with-border">
          <h3 class="box-title">Modrinth</h3>
        </div>
        <div class="box-body">
          <div class="form-group">
            <label class="control-label">Enable Modrinth</label>
            <div>
              <input type="hidden" name="modrinth_enabled" value="0">
              <input type="checkbox" name="modrinth_enabled" value="1" @if($settings['modrinth_enabled'] === '1') checked @endif>
              <span class="text-muted small">Modrinth needs no API key.</span>
            </div>
          </div>
          <div class="form-group">
            <label class="control-label">Contact for the User-Agent header</label>
            <input type="text" name="contact" class="form-control" value="{{ $settings['contact'] }}" placeholder="admin@example.com or https://yourpanel.example">
            <p class="text-muted small">Modrinth asks that API clients identify themselves. Optional, but polite.</p>
          </div>
        </div>
      </div>
    </div>

    <div class="col-xs-12 col-md-6">
      <div class="box box-warning">
        <div class="box-header with-border">
          <h3 class="box-title">CurseForge</h3>
        </div>
        <div class="box-body">
          <div class="form-group">
            <label class="control-label">Enable CurseForge</label>
            <div>
              <input type="hidden" name="curseforge_enabled" value="0">
              <input type="checkbox" name="curseforge_enabled" value="1" @if($settings['curseforge_enabled'] === '1') checked @endif>
              <span class="text-muted small">Requires an API key.</span>
            </div>
          </div>
          <div class="form-group">
            <label class="control-label">API key</label>
            <input type="password" name="curseforge_api_key" class="form-control" value="" autocomplete="new-password" placeholder="@if($settings['curseforge_api_key'] !== '')A key is stored. Leave blank to keep it.@else Paste your CurseForge for Studios key @endif">
            <p class="text-muted small">
              Create one at <a href="https://console.curseforge.com/" target="_blank" rel="noopener">console.curseforge.com</a>.
              The key never leaves the panel; the browser only talks to this panel.
            </p>
            @if($settings['curseforge_api_key'] !== '')
            <div>
              <input type="hidden" name="clear_curseforge_api_key" value="0">
              <input type="checkbox" name="clear_curseforge_api_key" value="1"> <span class="small">Remove the stored key</span>
            </div>
            @endif
          </div>
          <p class="text-muted small">
            Some CurseForge authors disable third-party downloads. Those files show a link to CurseForge instead of an install button.
          </p>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-xs-12 col-md-6">
      <div class="box box-primary">
        <div class="box-header with-border">
          <h3 class="box-title">Behaviour</h3>
        </div>
        <div class="box-body">
          <div class="form-group">
            <label class="control-label">Mods directory</label>
            <input type="text" name="mods_directory" class="form-control" value="{{ $settings['mods_directory'] }}" placeholder="/mods">
            <p class="text-muted small">Relative to the server root. Forge, NeoForge, Fabric and Quilt all use <code>/mods</code>.</p>
          </div>
          <div class="form-group">
            <label class="control-label">Results per page</label>
            <input type="number" name="page_size" class="form-control" min="5" max="50" value="{{ $settings['page_size'] }}">
          </div>
          <div class="form-group">
            <label class="control-label">Allow users to override the detected loader</label>
            <div>
              <input type="hidden" name="allow_override" value="0">
              <input type="checkbox" name="allow_override" value="1" @if($settings['allow_override'] === '1') checked @endif>
              <span class="text-muted small">Off by default: the Mods tab stays locked unless a mod loader is detected on the server.</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-xs-12 col-md-6">
      <div class="box box-default">
        <div class="box-header with-border">
          <h3 class="box-title">How detection works</h3>
        </div>
        <div class="box-body">
          <p class="small">
            When a user opens the Mods tab the panel lists the server's root directory (and <code>libraries/net</code>)
            through Wings and looks for Forge, NeoForge, Fabric or Quilt launcher jars, loader library folders and
            launcher scripts. The startup command and egg variables are used as a second opinion, and the Minecraft
            version is read from the Forge/NeoForge library folder, the jar name, or a <code>MINECRAFT_VERSION</code>
            style variable. Vanilla, Paper, Spigot and proxy servers are rejected.
          </p>
          <p class="small text-muted">
            Tracked installs: <strong>{{ $installCount }}</strong> mod(s) across <strong>{{ $serverCount }}</strong> server(s).
          </p>
          <p class="small text-muted">
            Tip: in Admin &rsaquo; Extensions you can also restrict which eggs show the Mods tab at all.
          </p>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-xs-12">
      <div class="box">
        <div class="box-footer">
          <button type="submit" name="_method" value="PATCH" class="btn btn-primary btn-sm pull-right">Save changes</button>
        </div>
      </div>
    </div>
  </div>
</form>

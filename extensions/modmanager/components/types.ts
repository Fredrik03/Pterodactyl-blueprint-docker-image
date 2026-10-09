export type Loader = 'forge' | 'neoforge' | 'fabric' | 'quilt';
export type ProviderId = 'modrinth' | 'curseforge';

export interface ProviderInfo {
    id: ProviderId;
    label: string;
}

export interface Detection {
    loader: Loader | null;
    game_version: string | null;
    source: 'files' | 'startup' | null;
    evidence: string[];
    daemon_reachable: boolean;
    mods_directory_exists: boolean;
    checked_at: string;
}

export interface Status {
    extension_version: string;
    settings: {
        allow_override: boolean;
        mods_directory: string;
        page_size: number;
    };
    providers: ProviderInfo[];
    loaders: Loader[];
    detection: Detection;
    /** Choice saved for this server through POST /context. */
    saved: { loader: Loader | null; game_version: string | null };
    /** Minecraft release versions per loader, newest first. */
    game_versions: Record<Loader, string[]>;
}

export interface Context {
    loader: Loader;
    game_version: string | null;
}

/** Parameters the user chose on top of detection. */
export interface Overrides {
    loader?: Loader;
    game_version?: string;
}

export interface Project {
    provider: ProviderId;
    id: string;
    slug: string | null;
    name: string;
    summary: string;
    icon_url: string | null;
    downloads: number;
    author: string | null;
    url: string;
    categories: string[];
    game_versions: string[];
    client_side: string | null;
    server_side: string | null;
    updated_at: string | null;
    installed: boolean;
}

export interface SearchResult {
    results: Project[];
    total: number;
    page: number;
    page_size: number;
    context: Context;
}

export interface ModFile {
    url: string;
    filename: string;
    size: number | null;
    sha1: string | null;
    sha512: string | null;
}

export interface Dependency {
    project_id: string | null;
    version_id: string | null;
    type: 'required' | 'optional' | 'incompatible' | 'embedded' | 'tool' | 'include' | string;
    project_name?: string | null;
    project_slug?: string | null;
    installed?: boolean;
}

export interface Version {
    provider: ProviderId;
    id: string;
    project_id: string;
    name: string;
    version_number: string;
    type: 'release' | 'beta' | 'alpha';
    published_at: string | null;
    game_versions: string[];
    loaders: string[];
    file: ModFile | null;
    unavailable_reason: string | null;
    page_url: string | null;
    dependencies: Dependency[];
}

export interface VersionsResult {
    project_id: string;
    versions: Version[];
    context: Context;
}

export interface InstallRecord {
    provider: ProviderId;
    project_id: string;
    project_slug: string | null;
    project_name: string;
    version_id: string;
    version_name: string | null;
    version_number: string | null;
    filename: string;
    loader: string;
    game_version: string | null;
    icon_url: string | null;
    sha1: string | null;
    size: number | null;
    installed_by: number | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface InstallResponse extends InstallRecord {
    status: 'downloading';
    warning: string | null;
}

export interface InstalledMod {
    filename: string;
    size: number;
    modified_at: string | null;
    enabled: boolean;
    managed: boolean;
    pending: boolean;
    record: InstallRecord | null;
}

export interface InstalledResult {
    directory: string;
    exists: boolean;
    mods: InstalledMod[];
    daemon_error: string | null;
}

export interface UpdateInfo {
    filename: string;
    current: InstallRecord;
    latest: Version;
}

export interface UpdatesResult {
    updates: UpdateInfo[];
    checked: number;
    errors: string[];
    context: Context;
}

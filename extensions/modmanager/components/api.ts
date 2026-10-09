import http from '@/api/http';
import {
    InstallResponse,
    InstalledResult,
    Loader,
    Overrides,
    ProviderId,
    SearchResult,
    Status,
    UpdatesResult,
    VersionsResult,
} from './types';

const base = (uuid: string) => `/api/client/extensions/modmanager/servers/${uuid}`;

const overrideParams = (overrides?: Overrides): Record<string, string | number> => {
    const params: Record<string, string | number> = {};
    if (!overrides) {
        return params;
    }
    if (overrides.loader) {
        params.loader = overrides.loader;
    }
    if (overrides.game_version) {
        params.game_version = overrides.game_version;
    }
    return params;
};

export const getStatus = async (uuid: string, refresh = false): Promise<Status> => {
    const { data } = await http.get(`${base(uuid)}/status`, { params: refresh ? { refresh: 1 } : {} });
    return data.attributes as Status;
};

export interface SearchParams {
    provider: ProviderId;
    query: string;
    page: number;
    sort: string;
}

export const searchMods = async (uuid: string, params: SearchParams, overrides?: Overrides): Promise<SearchResult> => {
    const { data } = await http.get(`${base(uuid)}/search`, {
        params: { ...params, ...overrideParams(overrides) },
    });
    return data.attributes as SearchResult;
};

export const getVersions = async (
    uuid: string,
    provider: ProviderId,
    project: string,
    overrides?: Overrides
): Promise<VersionsResult> => {
    const { data } = await http.get(`${base(uuid)}/versions`, {
        params: { provider, project, ...overrideParams(overrides) },
    });
    return data.attributes as VersionsResult;
};

export const saveContext = async (
    uuid: string,
    context: { game_version: string | null; loader: Loader | null }
): Promise<{ loader: Loader | null; game_version: string | null }> => {
    const { data } = await http.post(`${base(uuid)}/context`, context);
    return data.attributes as { loader: Loader | null; game_version: string | null };
};

export const getInstalled = async (uuid: string): Promise<InstalledResult> => {
    const { data } = await http.get(`${base(uuid)}/installed`);
    return data.attributes as InstalledResult;
};

export const getUpdates = async (uuid: string, overrides?: Overrides): Promise<UpdatesResult> => {
    const { data } = await http.get(`${base(uuid)}/updates`, { params: overrideParams(overrides) });
    return data.attributes as UpdatesResult;
};

export interface InstallParams {
    provider: ProviderId;
    project: string;
    version: string;
    replace?: string;
}

export const installMod = async (uuid: string, params: InstallParams, overrides?: Overrides): Promise<InstallResponse> => {
    const { data } = await http.post(`${base(uuid)}/install`, { ...params, ...overrideParams(overrides) });
    return data.attributes as InstallResponse;
};

export const toggleMod = async (uuid: string, filename: string): Promise<{ filename: string; enabled: boolean }> => {
    const { data } = await http.post(`${base(uuid)}/toggle`, { filename });
    return data.attributes as { filename: string; enabled: boolean };
};

export const removeMod = async (uuid: string, filename: string): Promise<void> => {
    await http.post(`${base(uuid)}/remove`, { filename });
};

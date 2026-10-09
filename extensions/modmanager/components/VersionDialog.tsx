import React, { useEffect, useState } from 'react';
import { Dialog } from '@/components/elements/dialog';
import { Button } from '@/components/elements/button/index';
import Spinner from '@/components/elements/Spinner';
import { httpErrorToHuman } from '@/api/http';
import { getVersions, installMod } from './api';
import { InstallRecord, Overrides, Project, Version } from './types';
import { formatDate, loaderLabel, projectKey } from './helpers';

interface Props {
    uuid: string;
    project: Project;
    loader: string;
    open: boolean;
    onClose: () => void;
    overrides: Overrides;
    installedProjects: Set<string>;
    onInstalled: (records: InstallRecord[], warnings: string[]) => void;
}

const typeClass = (type: Version['type']): string =>
    type === 'release'
        ? 'bg-green-600 text-green-100'
        : type === 'beta'
        ? 'bg-yellow-600 text-yellow-100'
        : 'bg-red-600 text-red-100';

const VersionDialog = ({ uuid, project, loader, open, onClose, overrides, installedProjects, onInstalled }: Props) => {
    const [versions, setVersions] = useState<Version[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [withDeps, setWithDeps] = useState(true);
    const [installing, setInstalling] = useState(false);
    const [progress, setProgress] = useState<string | null>(null);

    const context: Overrides = overrides;

    useEffect(() => {
        if (!open) {
            return;
        }
        setLoading(true);
        setError(null);
        setVersions(null);
        setSelectedId(null);
        getVersions(uuid, project.provider, project.id, context)
            .then((response) => {
                setVersions(response.versions);
                const first = response.versions.find((item) => item.file !== null) || response.versions[0];
                setSelectedId(first ? first.id : null);
            })
            .catch((e) => setError(httpErrorToHuman(e)))
            .then(() => setLoading(false));
    }, [open, uuid, project.id, overrides.loader, overrides.game_version]);

    const selected = versions ? versions.find((item) => item.id === selectedId) || null : null;
    const requiredDeps = (selected ? selected.dependencies : []).filter((dep) => dep.type === 'required' && dep.project_id);
    const missingDeps = requiredDeps.filter(
        (dep) => !dep.installed && !installedProjects.has(projectKey(project.provider, dep.project_id as string))
    );

    const run = async () => {
        if (!selected || !selected.file) {
            return;
        }
        setInstalling(true);
        setError(null);
        const records: InstallRecord[] = [];
        const warnings: string[] = [];
        try {
            setProgress(`Starting download of ${project.name}…`);
            const main = await installMod(uuid, { provider: project.provider, project: project.id, version: selected.id }, context);
            records.push(main);
            if (main.warning) {
                warnings.push(main.warning);
            }

            if (withDeps) {
                for (const dep of missingDeps) {
                    const depId = dep.project_id as string;
                    const label = dep.project_name || depId;
                    setProgress(`Resolving dependency ${label}…`);
                    try {
                        const response = await getVersions(uuid, project.provider, depId, context);
                        const pinned = dep.version_id
                            ? response.versions.find((item) => item.id === dep.version_id && item.file !== null)
                            : undefined;
                        const pick = pinned || response.versions.find((item) => item.file !== null);
                        if (!pick) {
                            warnings.push(`No compatible file was found for the dependency ${label}. Install it manually.`);
                            continue;
                        }
                        records.push(await installMod(uuid, { provider: project.provider, project: depId, version: pick.id }, context));
                    } catch (e) {
                        warnings.push(`Dependency ${label}: ${httpErrorToHuman(e)}`);
                    }
                }
            }

            onInstalled(records, warnings);
            onClose();
        } catch (e) {
            setError(httpErrorToHuman(e));
            if (records.length > 0) {
                onInstalled(records, warnings);
            }
        } finally {
            setInstalling(false);
            setProgress(null);
        }
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={`Install ${project.name}`}
            description={`Only files published for ${loaderLabel(loader)} on Minecraft ${overrides.game_version || ''} are listed.`}
        >
            {loading ? (
                <Spinner centered />
            ) : error && !versions ? (
                <p className={'text-red-300 text-sm'}>{error}</p>
            ) : (
                versions && (
                    <>
                        <p className={'text-xs text-neutral-400 mb-2'}>
                            {versions.length} compatible file{versions.length === 1 ? '' : 's'}
                        </p>
                        <div className={'max-h-64 overflow-y-auto space-y-1 pr-1'}>
                            {versions.length === 0 && (
                                <p className={'text-sm text-neutral-400 py-4 text-center'}>
                                    This mod has no file for {loaderLabel(loader)} on Minecraft{' '}
                                    {overrides.game_version}. It cannot be installed on this server.
                                </p>
                            )}
                            {versions.map((item) => (
                                <button
                                    key={item.id}
                                    type={'button'}
                                    onClick={() => setSelectedId(item.id)}
                                    className={`w-full text-left rounded px-3 py-2 border ${
                                        item.id === selectedId
                                            ? 'border-cyan-500 bg-neutral-700'
                                            : 'border-neutral-700 bg-neutral-800 hover:bg-neutral-700'
                                    }`}
                                >
                                    <div className={'flex items-center justify-between gap-2'}>
                                        <span className={'text-sm text-neutral-100 truncate'}>{item.name || item.version_number}</span>
                                        <span className={`text-xs uppercase px-1 rounded ${typeClass(item.type)}`}>{item.type}</span>
                                    </div>
                                    <p className={'text-xs text-neutral-400 truncate'}>
                                        {item.version_number}
                                        {item.game_versions.length > 0
                                            ? ` · MC ${item.game_versions.slice(0, 4).join(', ')}${item.game_versions.length > 4 ? '…' : ''}`
                                            : ''}
                                        {item.published_at ? ` · ${formatDate(item.published_at)}` : ''}
                                    </p>
                                    {!item.file && (
                                        <p className={'text-xs text-yellow-400 mt-1'}>
                                            {item.unavailable_reason}{' '}
                                            {item.page_url && (
                                                <a
                                                    href={item.page_url}
                                                    target={'_blank'}
                                                    rel={'noopener noreferrer'}
                                                    className={'underline'}
                                                >
                                                    Open on CurseForge
                                                </a>
                                            )}
                                        </p>
                                    )}
                                </button>
                            ))}
                        </div>
                        {selected && requiredDeps.length > 0 && (
                            <div className={'mt-3 bg-neutral-800 rounded p-3'}>
                                <p className={'text-xs uppercase text-neutral-400 mb-1'}>Required dependencies</p>
                                <ul className={'text-sm text-neutral-200 space-y-1'}>
                                    {requiredDeps.map((dep, index) => (
                                        <li key={index} className={'flex items-center justify-between gap-2'}>
                                            <span className={'truncate'}>{dep.project_name || dep.project_id}</span>
                                            <span className={'text-xs text-neutral-400'}>
                                                {missingDeps.indexOf(dep) >= 0 ? 'missing' : 'installed'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                {missingDeps.length > 0 && (
                                    <label className={'text-xs text-neutral-300 flex items-center mt-2 cursor-pointer select-none'}>
                                        <input
                                            type={'checkbox'}
                                            className={'mr-1'}
                                            checked={withDeps}
                                            onChange={(e) => setWithDeps(e.currentTarget.checked)}
                                        />
                                        Also install the {missingDeps.length} missing dependenc{missingDeps.length === 1 ? 'y' : 'ies'}
                                    </label>
                                )}
                            </div>
                        )}
                        {error && <p className={'text-red-300 text-sm mt-2'}>{error}</p>}
                        {progress && <p className={'text-neutral-300 text-sm mt-2'}>{progress}</p>}
                    </>
                )
            )}
            <Dialog.Footer>
                <Button.Text onClick={onClose} disabled={installing}>
                    Cancel
                </Button.Text>
                <Button onClick={run} disabled={installing || !selected || !selected.file}>
                    {installing ? 'Installing…' : 'Install'}
                </Button>
            </Dialog.Footer>
        </Dialog>
    );
};

export default VersionDialog;

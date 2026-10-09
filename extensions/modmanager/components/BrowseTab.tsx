import React, { useEffect, useRef, useState } from 'react';
import Input from '@/components/elements/Input';
import Select from '@/components/elements/Select';
import Spinner from '@/components/elements/Spinner';
import Can from '@/components/elements/Can';
import { Button } from '@/components/elements/button/index';
import { Alert } from '@/components/elements/alert';
import useFlash from '@/plugins/useFlash';
import { searchMods } from './api';
import { InstallRecord, Overrides, Project, ProviderId, SearchResult, Status } from './types';
import { FLASH_KEY, clampStyle, formatCount, loaderLabel, projectKey } from './helpers';
import VersionDialog from './VersionDialog';

interface Props {
    uuid: string;
    status: Status;
    overrides: Overrides;
    installedProjects: Set<string>;
    onInstallStarted: (records: InstallRecord[], warnings: string[]) => void;
}

const SORTS: Array<[string, string]> = [
    ['relevance', 'Relevance'],
    ['downloads', 'Most downloads'],
    ['updated', 'Recently updated'],
    ['newest', 'Newest'],
];

const ProjectCard = ({ project, installed, onInstall }: { project: Project; installed: boolean; onInstall: () => void }) => (
    <div className={'bg-neutral-700 rounded shadow p-3 flex gap-3'}>
        <div className={'w-12 h-12 rounded bg-neutral-800 flex-shrink-0 overflow-hidden flex items-center justify-center'}>
            {project.icon_url ? (
                <img src={project.icon_url} alt={''} className={'w-12 h-12 object-cover'} />
            ) : (
                <span className={'text-neutral-500 text-lg'}>?</span>
            )}
        </div>
        <div className={'flex-1 min-w-0'}>
            <div className={'flex items-start justify-between gap-2'}>
                <div className={'min-w-0'}>
                    <a
                        href={project.url}
                        target={'_blank'}
                        rel={'noopener noreferrer'}
                        className={'text-neutral-100 font-medium hover:underline truncate block'}
                    >
                        {project.name}
                    </a>
                    <p className={'text-xs text-neutral-400 truncate'}>
                        {project.author ? `by ${project.author} · ` : ''}
                        {formatCount(project.downloads)} downloads
                    </p>
                </div>
                <div className={'flex-shrink-0'}>
                    <Can action={'file.create'}>
                        {installed ? (
                            <Button.Text size={Button.Sizes.Small} onClick={onInstall}>
                                Installed
                            </Button.Text>
                        ) : (
                            <Button size={Button.Sizes.Small} onClick={onInstall}>
                                Install
                            </Button>
                        )}
                    </Can>
                </div>
            </div>
            <p className={'text-sm text-neutral-300 mt-1'} style={clampStyle}>
                {project.summary}
            </p>
            {project.categories.length > 0 && (
                <p className={'text-xs text-neutral-500 mt-1 truncate'}>{project.categories.slice(0, 4).join(' · ')}</p>
            )}
        </div>
    </div>
);

const BrowseTab = ({ uuid, status, overrides, installedProjects, onInstallStarted }: Props) => {
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const [provider, setProvider] = useState<ProviderId>(status.providers.length > 0 ? status.providers[0].id : 'modrinth');
    const [query, setQuery] = useState('');
    const [debounced, setDebounced] = useState('');
    const [sort, setSort] = useState('relevance');
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<SearchResult | null>(null);
    const [loading, setLoading] = useState(false);
    const [selected, setSelected] = useState<Project | null>(null);
    const requestId = useRef(0);

    useEffect(() => {
        if (!status.providers.find((item) => item.id === provider) && status.providers.length > 0) {
            setProvider(status.providers[0].id);
        }
    }, [status.providers]);

    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(query.trim()), 400);
        return () => window.clearTimeout(timer);
    }, [query]);

    useEffect(() => {
        setPage(1);
    }, [debounced, sort, provider, overrides.loader, overrides.game_version]);

    useEffect(() => {
        if (status.providers.length === 0 || !overrides.game_version) {
            setResult(null);
            return;
        }
        const id = ++requestId.current;
        setLoading(true);
        clearFlashes(FLASH_KEY);
        searchMods(uuid, { provider, query: debounced, page, sort }, overrides)
            .then((response) => {
                if (id === requestId.current) {
                    setResult(response);
                }
            })
            .catch((error) => {
                if (id === requestId.current) {
                    clearAndAddHttpError({ key: FLASH_KEY, error });
                }
            })
            .then(() => {
                if (id === requestId.current) {
                    setLoading(false);
                }
            });
    }, [uuid, provider, debounced, sort, page, overrides.loader, overrides.game_version]);

    const totalPages = result ? Math.max(1, Math.ceil(Math.min(result.total, 10000) / result.page_size)) : 1;

    if (!overrides.game_version) {
        return (
            <Alert type={'warning'}>
                Select the Minecraft version this server runs at the top of the page. Only mods published for that
                exact version and loader are shown and installed.
            </Alert>
        );
    }

    return (
        <>
            <div className={'flex flex-wrap gap-2 mb-4'}>
                <div className={'flex-1'} style={{ minWidth: '14rem' }}>
                    <Input
                        value={query}
                        onChange={(e) => setQuery(e.currentTarget.value)}
                        placeholder={'Search mods by name…'}
                    />
                </div>
                {status.providers.length > 1 && (
                    <Select value={provider} onChange={(e) => setProvider(e.currentTarget.value as ProviderId)} className={'w-40'}>
                        {status.providers.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.label}
                            </option>
                        ))}
                    </Select>
                )}
                <Select value={sort} onChange={(e) => setSort(e.currentTarget.value)} className={'w-48'}>
                    {SORTS.map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </Select>
            </div>
            {loading && !result ? (
                <Spinner size={'large'} centered />
            ) : (
                result && (
                    <>
                        <p className={'text-xs text-neutral-400 mb-2'}>
                            {result.total.toLocaleString()} result{result.total === 1 ? '' : 's'} on{' '}
                            {status.providers.find((item) => item.id === provider)?.label || provider} for{' '}
                            {loaderLabel(result.context.loader)} {result.context.game_version}
                            {loading ? ' · updating…' : ''}
                        </p>
                        <div className={'grid gap-3 md:grid-cols-2'}>
                            {result.results.map((project) => (
                                <ProjectCard
                                    key={`${project.provider}:${project.id}`}
                                    project={project}
                                    installed={project.installed || installedProjects.has(projectKey(project.provider, project.id))}
                                    onInstall={() => setSelected(project)}
                                />
                            ))}
                        </div>
                        {result.results.length === 0 && (
                            <p className={'text-center text-neutral-400 py-10'}>
                                No mods match this search for the selected loader and Minecraft version.
                            </p>
                        )}
                        <div className={'flex items-center justify-between mt-4'}>
                            <Button.Text
                                size={Button.Sizes.Small}
                                disabled={page <= 1 || loading}
                                onClick={() => setPage((current) => Math.max(1, current - 1))}
                            >
                                Previous
                            </Button.Text>
                            <span className={'text-sm text-neutral-400'}>
                                Page {page} of {totalPages}
                            </span>
                            <Button.Text
                                size={Button.Sizes.Small}
                                disabled={page >= totalPages || loading}
                                onClick={() => setPage((current) => current + 1)}
                            >
                                Next
                            </Button.Text>
                        </div>
                    </>
                )
            )}
            {selected && (
                <VersionDialog
                    uuid={uuid}
                    project={selected}
                    loader={result ? result.context.loader : overrides.loader || status.detection.loader || ''}
                    open={true}
                    onClose={() => setSelected(null)}
                    overrides={overrides}
                    installedProjects={installedProjects}
                    onInstalled={onInstallStarted}
                />
            )}
        </>
    );
};

export default BrowseTab;

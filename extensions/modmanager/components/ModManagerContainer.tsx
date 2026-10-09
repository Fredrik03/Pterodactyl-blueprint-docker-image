import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import useFlash from '@/plugins/useFlash';
import { usePermissions } from '@/plugins/usePermissions';
import Spinner from '@/components/elements/Spinner';
import { Alert } from '@/components/elements/alert';
import { getInstalled, getStatus, saveContext } from './api';
import { InstallRecord, InstalledResult, Loader, Overrides, Status } from './types';
import { FLASH_KEY, projectKey } from './helpers';
import LoaderBanner from './LoaderBanner';
import BrowseTab from './BrowseTab';
import InstalledTab from './InstalledTab';

type Tab = 'browse' | 'installed';

const POLL_INTERVAL = 2500;
const POLL_TIMEOUT = 3 * 60 * 1000;

/**
 * Server tab added by the Mod Manager extension. Wings does the actual
 * downloading; this page only talks to the extension's own client API.
 */
const ModManagerContainer = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const [canSaveContext] = usePermissions(['file.create']);

    const [status, setStatus] = useState<Status | null>(null);
    const [statusLoading, setStatusLoading] = useState(true);
    const [tab, setTab] = useState<Tab>('browse');
    const [loaderOverride, setLoaderOverride] = useState<Loader | ''>('');
    const [gameVersion, setGameVersion] = useState('');
    const [installed, setInstalled] = useState<InstalledResult | null>(null);
    const [installedLoading, setInstalledLoading] = useState(false);
    // filename -> expected size (null when the provider did not report one)
    const [pending, setPending] = useState<Record<string, number | null>>({});
    const pendingSince = useRef(0);
    const lastSizes = useRef<Record<string, number>>({});

    const loadStatus = useCallback(
        (refresh = false) => {
            setStatusLoading(true);
            clearFlashes(FLASH_KEY);
            return getStatus(uuid, refresh)
                .then((result) => {
                    setStatus(result);
                    if (result.settings.allow_override) {
                        setLoaderOverride(result.saved.loader || '');
                    }
                    // A version the server chose earlier wins over detection; detection
                    // fills the gap on first visit.
                    setGameVersion(result.saved.game_version || result.detection.game_version || '');
                })
                .catch((error) => clearAndAddHttpError({ key: FLASH_KEY, error }))
                .then(() => setStatusLoading(false));
        },
        [uuid]
    );

    const loadInstalled = useCallback(() => {
        setInstalledLoading(true);
        return getInstalled(uuid)
            .then((result) => {
                setInstalled(result);
                return result;
            })
            .catch((error) => {
                clearAndAddHttpError({ key: FLASH_KEY, error });
                return null;
            })
            .then((result) => {
                setInstalledLoading(false);
                return result;
            });
    }, [uuid]);

    useEffect(() => {
        loadStatus();
        loadInstalled();
    }, [uuid]);

    // Persist the choice for everyone on this server (needs file.create); users
    // without that right still get the selection for their own session.
    const persist = (nextLoader: Loader | '', nextVersion: string) => {
        if (!canSaveContext) {
            return;
        }
        saveContext(uuid, { game_version: nextVersion || null, loader: nextLoader || null }).catch((error) =>
            clearAndAddHttpError({ key: FLASH_KEY, error })
        );
    };

    const onGameVersion = (version: string) => {
        setGameVersion(version);
        persist(loaderOverride, version);
    };

    const onLoaderOverride = (loader: Loader | '') => {
        setLoaderOverride(loader);
        // Version lists differ per loader; keep the version only if the new loader supports it.
        const supported = status && loader ? status.game_versions[loader] || [] : null;
        const nextVersion = supported && gameVersion && supported.indexOf(gameVersion) < 0 ? '' : gameVersion;
        if (nextVersion !== gameVersion) {
            setGameVersion(nextVersion);
        }
        persist(loader, nextVersion);
    };

    // While Wings is downloading, re-list the mods folder until every expected
    // file has reached its size (or stopped growing when the size is unknown).
    const pendingKey = Object.keys(pending).sort().join('|');
    useEffect(() => {
        if (!pendingKey) {
            return;
        }
        const interval = window.setInterval(() => {
            if (Date.now() - pendingSince.current > POLL_TIMEOUT) {
                setPending({});
                addFlash({
                    key: FLASH_KEY,
                    type: 'warning',
                    message: 'Some downloads are taking a long time. Refresh the Installed tab in a moment to check on them.',
                });
                return;
            }
            getInstalled(uuid)
                .then((result) => {
                    setInstalled(result);
                    setPending((previous) => {
                        const next = { ...previous };
                        for (const filename of Object.keys(previous)) {
                            const mod = result.mods.find((m) => m.filename === filename);
                            if (!mod || mod.pending) {
                                continue;
                            }
                            const expected = previous[filename];
                            const stable = lastSizes.current[filename] === mod.size && mod.size > 0;
                            lastSizes.current[filename] = mod.size;
                            if ((expected !== null && mod.size >= expected) || (expected === null && stable)) {
                                delete next[filename];
                            }
                        }
                        return next;
                    });
                })
                .catch(() => undefined);
        }, POLL_INTERVAL);
        return () => window.clearInterval(interval);
    }, [uuid, pendingKey]);

    const onInstallStarted = (records: InstallRecord[], warnings: string[]) => {
        clearFlashes(FLASH_KEY);
        if (records.length > 0) {
            pendingSince.current = Date.now();
            setPending((previous) => {
                const next = { ...previous };
                records.forEach((record) => {
                    next[record.filename] = record.size;
                });
                return next;
            });
            addFlash({
                key: FLASH_KEY,
                type: 'success',
                message:
                    records.length === 1
                        ? `Downloading ${records[0].project_name} into the mods folder.`
                        : `Downloading ${records.length} files into the mods folder.`,
            });
            loadInstalled();
        }
        warnings.forEach((message) => addFlash({ key: FLASH_KEY, type: 'warning', message }));
    };

    const overrides = useMemo<Overrides>(
        () => ({
            loader: loaderOverride || undefined,
            game_version: gameVersion || undefined,
        }),
        [loaderOverride, gameVersion]
    );

    const installedProjects = useMemo(() => {
        const keys = new Set<string>();
        (installed ? installed.mods : []).forEach((mod) => {
            if (mod.record) {
                keys.add(projectKey(mod.record.provider, mod.record.project_id));
            }
        });
        return keys;
    }, [installed]);

    const effectiveLoader = loaderOverride || (status ? status.detection.loader : null);
    const locked = !!status && !effectiveLoader && !status.settings.allow_override;
    const needsLoaderChoice = !!status && !effectiveLoader && status.settings.allow_override;

    return (
        <ServerContentBlock title={'Mods'} showFlashKey={FLASH_KEY}>
            {!status ? (
                statusLoading ? <Spinner size={'large'} centered /> : null
            ) : (
                <>
                    <LoaderBanner
                        status={status}
                        refreshing={statusLoading}
                        loaderOverride={loaderOverride}
                        gameVersion={gameVersion}
                        onRefresh={() => loadStatus(true)}
                        onLoaderOverride={onLoaderOverride}
                        onGameVersion={onGameVersion}
                    />
                    {status.providers.length === 0 && (
                        <Alert type={'warning'} className={'mb-4'}>
                            No mod providers are enabled. Ask an administrator to enable Modrinth or CurseForge on the Mod
                            Manager admin page.
                        </Alert>
                    )}
                    {locked ? (
                        <Alert type={'danger'}>
                            This server does not appear to run a mod loader. Mod Manager only works on Forge, NeoForge,
                            Fabric or Quilt servers. If the server was just installed, start it once so the loader
                            files exist, then press Re-detect.
                        </Alert>
                    ) : needsLoaderChoice ? (
                        <Alert type={'warning'}>
                            No mod loader was detected. Pick the loader this server uses from the list above to continue.
                        </Alert>
                    ) : (
                        <>
                            <div className={'flex items-center border-b border-neutral-600 mb-4'}>
                                {(['browse', 'installed'] as Tab[]).map((item) => (
                                    <button
                                        key={item}
                                        type={'button'}
                                        onClick={() => setTab(item)}
                                        className={`px-4 py-2 text-sm uppercase tracking-wide border-b-2 -mb-px ${
                                            tab === item
                                                ? 'border-cyan-500 text-neutral-100'
                                                : 'border-transparent text-neutral-400 hover:text-neutral-200'
                                        }`}
                                    >
                                        {item === 'browse' ? 'Browse' : `Installed${installed ? ` (${installed.mods.length})` : ''}`}
                                    </button>
                                ))}
                            </div>
                            {tab === 'browse' ? (
                                <BrowseTab
                                    uuid={uuid}
                                    status={status}
                                    overrides={overrides}
                                    installedProjects={installedProjects}
                                    onInstallStarted={onInstallStarted}
                                />
                            ) : (
                                <InstalledTab
                                    uuid={uuid}
                                    installed={installed}
                                    loading={installedLoading}
                                    pending={pending}
                                    overrides={overrides}
                                    onRefresh={loadInstalled}
                                    onInstallStarted={onInstallStarted}
                                />
                            )}
                        </>
                    )}
                </>
            )}
        </ServerContentBlock>
    );
};

export default ModManagerContainer;

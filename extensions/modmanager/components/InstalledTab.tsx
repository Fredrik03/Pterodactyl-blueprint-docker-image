import React, { useState } from 'react';
import Spinner from '@/components/elements/Spinner';
import Can from '@/components/elements/Can';
import { Button } from '@/components/elements/button/index';
import { Dialog } from '@/components/elements/dialog';
import { Alert } from '@/components/elements/alert';
import useFlash from '@/plugins/useFlash';
import { getUpdates, installMod, removeMod, toggleMod } from './api';
import { InstallRecord, InstalledMod, InstalledResult, Overrides, UpdateInfo } from './types';
import { FLASH_KEY, formatBytes, formatDate, loaderLabel } from './helpers';

interface Props {
    uuid: string;
    installed: InstalledResult | null;
    loading: boolean;
    pending: Record<string, number | null>;
    overrides: Overrides;
    onRefresh: () => Promise<InstalledResult | null>;
    onInstallStarted: (records: InstallRecord[], warnings: string[]) => void;
}

const Tag = ({ className, children }: { className: string; children: React.ReactNode }) => (
    <span className={`ml-2 text-xs uppercase ${className}`}>{children}</span>
);

const InstalledTab = ({ uuid, installed, loading, pending, overrides, onRefresh, onInstallStarted }: Props) => {
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const [busy, setBusy] = useState<string | null>(null);
    const [confirmRemove, setConfirmRemove] = useState<InstalledMod | null>(null);
    const [updates, setUpdates] = useState<UpdateInfo[] | null>(null);
    const [checking, setChecking] = useState(false);

    const toggle = (mod: InstalledMod) => {
        setBusy(mod.filename);
        clearFlashes(FLASH_KEY);
        toggleMod(uuid, mod.filename)
            .then(() => onRefresh())
            .catch((error) => clearAndAddHttpError({ key: FLASH_KEY, error }))
            .then(() => setBusy(null));
    };

    const remove = (mod: InstalledMod) => {
        setBusy(mod.filename);
        setConfirmRemove(null);
        clearFlashes(FLASH_KEY);
        removeMod(uuid, mod.filename)
            .then(() => {
                addFlash({
                    key: FLASH_KEY,
                    type: 'success',
                    message: `Removed ${mod.record ? mod.record.project_name : mod.filename}.`,
                });
                setUpdates((list) => (list ? list.filter((item) => item.filename !== mod.filename) : list));
                return onRefresh();
            })
            .catch((error) => clearAndAddHttpError({ key: FLASH_KEY, error }))
            .then(() => setBusy(null));
    };

    const checkUpdates = () => {
        setChecking(true);
        clearFlashes(FLASH_KEY);
        getUpdates(uuid, overrides)
            .then((response) => {
                setUpdates(response.updates);
                response.errors.forEach((message) => addFlash({ key: FLASH_KEY, type: 'warning', message }));
                if (response.updates.length === 0) {
                    addFlash({
                        key: FLASH_KEY,
                        type: 'info',
                        message: `All ${response.checked} tracked mod${response.checked === 1 ? ' is' : 's are'} up to date.`,
                    });
                }
            })
            .catch((error) => clearAndAddHttpError({ key: FLASH_KEY, error }))
            .then(() => setChecking(false));
    };

    const applyUpdate = (update: UpdateInfo) => {
        setBusy(update.filename);
        clearFlashes(FLASH_KEY);
        installMod(
            uuid,
            {
                provider: update.current.provider,
                project: update.current.project_id,
                version: update.latest.id,
                replace: update.filename,
            },
            overrides
        )
            .then((record) => {
                setUpdates((list) => (list ? list.filter((item) => item.filename !== update.filename) : list));
                onInstallStarted([record], record.warning ? [record.warning] : []);
            })
            .catch((error) => clearAndAddHttpError({ key: FLASH_KEY, error }))
            .then(() => setBusy(null));
    };

    const mods = installed ? installed.mods : [];
    const managedCount = mods.filter((mod) => mod.managed).length;
    const updateFor = (filename: string) => (updates ? updates.find((item) => item.filename === filename) : undefined);

    return (
        <>
            <div className={'flex items-center justify-between mb-3 flex-wrap gap-2'}>
                <p className={'text-sm text-neutral-400'}>
                    {installed ? `${mods.length} file${mods.length === 1 ? '' : 's'} in ${installed.directory}` : ''}
                </p>
                <div className={'flex gap-2'}>
                    <Button.Text size={Button.Sizes.Small} onClick={() => onRefresh()} disabled={loading}>
                        Refresh
                    </Button.Text>
                    <Can action={'file.create'}>
                        <Button
                            size={Button.Sizes.Small}
                            onClick={checkUpdates}
                            disabled={checking || managedCount === 0 || !overrides.game_version}
                            title={!overrides.game_version ? 'Select the Minecraft version first' : undefined}
                        >
                            {checking ? 'Checking…' : 'Check for updates'}
                        </Button>
                    </Can>
                </div>
            </div>
            {installed && installed.daemon_error && (
                <Alert type={'danger'} className={'mb-3'}>
                    {installed.daemon_error}
                </Alert>
            )}
            {installed && !installed.exists && !installed.daemon_error && (
                <Alert type={'warning'} className={'mb-3'}>
                    The {installed.directory} folder does not exist yet. It is created with the first install.
                </Alert>
            )}
            {loading && !installed ? (
                <Spinner size={'large'} centered />
            ) : mods.length === 0 ? (
                <p className={'text-center text-neutral-400 py-10'}>No mods installed. Use the Browse tab to add some.</p>
            ) : (
                <div className={'space-y-2'}>
                    {mods.map((mod) => {
                        const isPending = mod.pending || pending[mod.filename] !== undefined;
                        const update = updateFor(mod.filename);
                        const isBusy = busy === mod.filename;
                        const record = mod.record;
                        return (
                            <div
                                key={mod.filename}
                                className={`bg-neutral-700 rounded shadow p-3 flex items-center gap-3 ${mod.enabled ? '' : 'opacity-60'}`}
                            >
                                <div className={'w-10 h-10 rounded bg-neutral-800 flex-shrink-0 overflow-hidden flex items-center justify-center'}>
                                    {record && record.icon_url ? (
                                        <img src={record.icon_url} alt={''} className={'w-10 h-10 object-cover'} />
                                    ) : (
                                        <span className={'text-neutral-500'}>?</span>
                                    )}
                                </div>
                                <div className={'flex-1 min-w-0'}>
                                    <p className={'text-neutral-100 truncate'}>
                                        {record ? record.project_name : mod.filename}
                                        {!mod.enabled && <Tag className={'text-yellow-400'}>disabled</Tag>}
                                        {isPending && <Tag className={'text-cyan-400'}>downloading…</Tag>}
                                        {!mod.managed && <Tag className={'text-neutral-400'}>unmanaged</Tag>}
                                        {update && <Tag className={'text-green-400'}>update available</Tag>}
                                    </p>
                                    <p className={'text-xs text-neutral-400 truncate'}>
                                        {record
                                            ? `${record.version_number || record.version_name || ''} · ${loaderLabel(record.loader)}${
                                                  record.game_version ? ` ${record.game_version}` : ''
                                              } · `
                                            : ''}
                                        {mod.filename} · {formatBytes(mod.size)}
                                        {mod.modified_at ? ` · ${formatDate(mod.modified_at)}` : ''}
                                    </p>
                                    {update && (
                                        <p className={'text-xs text-green-300 truncate'}>
                                            Latest: {update.latest.name || update.latest.version_number}
                                            {update.latest.published_at ? ` (${formatDate(update.latest.published_at)})` : ''}
                                        </p>
                                    )}
                                </div>
                                <div className={'flex gap-2 flex-shrink-0'}>
                                    {update && (
                                        <Can action={'file.create'}>
                                            <Button size={Button.Sizes.Small} onClick={() => applyUpdate(update)} disabled={isBusy || isPending}>
                                                Update
                                            </Button>
                                        </Can>
                                    )}
                                    <Can action={'file.update'}>
                                        <Button.Text size={Button.Sizes.Small} onClick={() => toggle(mod)} disabled={isBusy || isPending}>
                                            {mod.enabled ? 'Disable' : 'Enable'}
                                        </Button.Text>
                                    </Can>
                                    <Can action={'file.delete'}>
                                        <Button.Danger size={Button.Sizes.Small} onClick={() => setConfirmRemove(mod)} disabled={isBusy || isPending}>
                                            Remove
                                        </Button.Danger>
                                    </Can>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
            <Dialog.Confirm
                open={confirmRemove !== null}
                onClose={() => setConfirmRemove(null)}
                title={'Remove mod'}
                confirm={'Remove'}
                onConfirmed={() => confirmRemove && remove(confirmRemove)}
            >
                {confirmRemove
                    ? `${confirmRemove.filename} will be deleted from the server. World data and config files are not touched.`
                    : ''}
            </Dialog.Confirm>
        </>
    );
};

export default InstalledTab;

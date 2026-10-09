import React, { useState } from 'react';
import { Button } from '@/components/elements/button/index';
import Select from '@/components/elements/Select';
import { Loader, Status } from './types';
import { loaderLabel } from './helpers';

interface Props {
    status: Status;
    refreshing: boolean;
    loaderOverride: Loader | '';
    gameVersion: string;
    onRefresh: () => void;
    onLoaderOverride: (loader: Loader | '') => void;
    onGameVersion: (version: string) => void;
}

/**
 * Header of the Mods tab: which loader the server runs and which Minecraft
 * version to filter everything by. The version is a dropdown of real releases
 * for that loader, so a file can never be installed for the wrong version.
 */
const LoaderBanner = ({ status, refreshing, loaderOverride, gameVersion, onRefresh, onLoaderOverride, onGameVersion }: Props) => {
    const [showEvidence, setShowEvidence] = useState(false);
    const detection = status.detection;
    const effectiveLoader: Loader | null = loaderOverride || detection.loader;
    const allowOverride = status.settings.allow_override;

    const versions: string[] = effectiveLoader ? [...(status.game_versions[effectiveLoader] || [])] : [];
    if (detection.game_version && versions.indexOf(detection.game_version) < 0) {
        versions.unshift(detection.game_version);
    }
    if (gameVersion && versions.indexOf(gameVersion) < 0) {
        versions.unshift(gameVersion);
    }

    return (
        <div className={'bg-neutral-700 rounded shadow-md p-4 mb-4'}>
            <div className={'flex flex-wrap items-center gap-3'}>
                <div className={'flex-1 min-w-0'}>
                    <p className={'text-xs uppercase text-neutral-400'}>Mod loader</p>
                    <p className={'text-lg text-neutral-100'}>
                        {effectiveLoader ? (
                            <>
                                <span className={'font-semibold'}>{loaderLabel(effectiveLoader)}</span>
                                {gameVersion && <span className={'text-neutral-300'}> for Minecraft {gameVersion}</span>}
                                {loaderOverride && loaderOverride !== detection.loader && (
                                    <span className={'ml-2 text-xs uppercase text-yellow-400'}>manual override</span>
                                )}
                            </>
                        ) : (
                            <span className={'text-red-300'}>None detected</span>
                        )}
                    </p>
                    <p className={'text-xs text-neutral-400 mt-1'}>
                        {detection.source === 'files' && 'Loader confirmed from the files Wings found in the server directory.'}
                        {detection.source === 'startup' &&
                            'Loader taken from the startup command and egg variables; the server files did not confirm it.'}
                        {detection.source === null && 'Nothing on this server points at Forge, NeoForge, Fabric or Quilt.'}
                        {!detection.daemon_reachable && ' Wings could not be reached.'}
                        {effectiveLoader && !gameVersion && (
                            <span className={'text-yellow-300'}> Select the Minecraft version this server runs to start browsing.</span>
                        )}
                        {effectiveLoader && gameVersion && detection.game_version === gameVersion && ' Minecraft version detected from the server.'}
                    </p>
                </div>
                <div className={'flex items-end gap-2 flex-wrap'}>
                    {allowOverride && (
                        <div>
                            <label className={'block text-xs uppercase text-neutral-400 mb-1'}>Loader</label>
                            <Select
                                value={loaderOverride}
                                onChange={(e) => onLoaderOverride(e.currentTarget.value as Loader | '')}
                                className={'w-44'}
                            >
                                <option value={''}>Detected ({loaderLabel(detection.loader)})</option>
                                {status.loaders.map((loader) => (
                                    <option key={loader} value={loader}>
                                        {loaderLabel(loader)}
                                    </option>
                                ))}
                            </Select>
                        </div>
                    )}
                    <div>
                        <label className={'block text-xs uppercase text-neutral-400 mb-1'}>Minecraft version</label>
                        <Select
                            value={gameVersion}
                            onChange={(e) => onGameVersion(e.currentTarget.value)}
                            className={'w-44'}
                            disabled={!effectiveLoader}
                        >
                            <option value={''}>Select version…</option>
                            {versions.map((mcVersion) => (
                                <option key={mcVersion} value={mcVersion}>
                                    {mcVersion}
                                    {mcVersion === detection.game_version ? ' (detected)' : ''}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <Button.Text size={Button.Sizes.Small} onClick={onRefresh} disabled={refreshing}>
                        {refreshing ? 'Checking…' : 'Re-detect'}
                    </Button.Text>
                </div>
            </div>
            {detection.evidence.length > 0 && (
                <div className={'mt-3'}>
                    <button
                        type={'button'}
                        className={'text-xs text-neutral-400 hover:text-neutral-200 underline'}
                        onClick={() => setShowEvidence((current) => !current)}
                    >
                        {showEvidence ? 'Hide' : 'Show'} what was checked
                    </button>
                    {showEvidence && (
                        <ul className={'mt-2 text-xs text-neutral-300 list-disc pl-5 space-y-1'}>
                            {detection.evidence.map((line, index) => (
                                <li key={index}>{line}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
};

export default LoaderBanner;

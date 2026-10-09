import React from 'react';
import { Loader } from './types';

export const FLASH_KEY = 'server:mods';

const LOADER_LABELS: Record<Loader, string> = {
    forge: 'Forge',
    neoforge: 'NeoForge',
    fabric: 'Fabric',
    quilt: 'Quilt',
};

export const loaderLabel = (loader: string | null | undefined): string =>
    loader ? LOADER_LABELS[loader as Loader] || loader : 'Unknown';

export const formatCount = (value: number): string => {
    if (value >= 1_000_000) {
        return `${(value / 1_000_000).toFixed(value >= 10_000_000 ? 0 : 1)}M`;
    }
    if (value >= 1_000) {
        return `${(value / 1_000).toFixed(value >= 10_000 ? 0 : 1)}K`;
    }
    return String(value);
};

export const formatBytes = (bytes: number | null | undefined): string => {
    if (!bytes || bytes <= 0) {
        return '0 B';
    }
    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    const value = bytes / Math.pow(1024, index);
    return `${value.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
};

export const formatDate = (iso: string | null | undefined): string => {
    if (!iso) {
        return '';
    }
    const date = new Date(iso);
    return isNaN(date.getTime()) ? '' : date.toLocaleDateString();
};

export const projectKey = (provider: string, projectId: string): string => `${provider}:${projectId}`;

export const clampStyle: React.CSSProperties = {
    display: '-webkit-box',
    WebkitLineClamp: 2,
    WebkitBoxOrient: 'vertical',
    overflow: 'hidden',
};

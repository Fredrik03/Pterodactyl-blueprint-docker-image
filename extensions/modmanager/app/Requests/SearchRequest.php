<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modmanager\Requests;

class SearchRequest extends ReadRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'provider' => 'required|string|in:modrinth,curseforge',
            'query' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1|max:500',
            'sort' => 'nullable|string|in:relevance,downloads,newest,updated,follows',
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'title' => $this->title,
            'summary' => $this->summary,
            'importance_level' => $this->importance_level,
            'sentiment' => $this->sentiment,
            'confidence' => $this->confidence,
            'status' => $this->status,
            'occurred_at' => optional($this->occurred_at)?->toAtomString(),
            'published_at' => optional($this->published_at)?->toAtomString(),
            'timeline_stage' => $this->timeline_stage,
            'timeline_order' => $this->timeline_order,
            'facts' => $this->facts,
            'normalized_facts' => $this->facts['normalized_data'] ?? null,
            'chain' => $this->eventChain ? [
                'id' => $this->eventChain->id,
                'chain_key' => $this->eventChain->chain_key,
                'chain_type' => $this->eventChain->chain_type,
                'topic' => $this->eventChain->topic,
                'status' => $this->eventChain->status,
                'started_at' => optional($this->eventChain->started_at)?->toAtomString(),
                'latest_occurred_at' => optional($this->eventChain->latest_occurred_at)?->toAtomString(),
            ] : null,
            'primary_security' => $this->primarySecurity ? [
                'id' => $this->primarySecurity->id,
                'canonical_symbol' => $this->primarySecurity->canonical_symbol,
                'name' => $this->primarySecurity->name,
            ] : null,
            'articles' => $this->whenLoaded('articles', fn () => $this->articles->map(fn ($article): array => [
                'id' => $article->id,
                'title' => $article->title,
                'published_at' => optional($article->published_at)?->toAtomString(),
                'relation_type' => $article->pivot?->relation_type,
            ])->values()->all()),
        ];
    }
}

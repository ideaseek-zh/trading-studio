<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventChainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chain_key' => $this->chain_key,
            'chain_type' => $this->chain_type,
            'topic' => $this->topic,
            'summary' => $this->summary,
            'status' => $this->status,
            'importance_level' => $this->importance_level,
            'sentiment' => $this->sentiment,
            'started_at' => optional($this->started_at)?->toAtomString(),
            'latest_occurred_at' => optional($this->latest_occurred_at)?->toAtomString(),
            'latest_published_at' => optional($this->latest_published_at)?->toAtomString(),
            'event_count' => $this->event_count,
            'article_count' => $this->article_count,
            'facts' => $this->facts,
            'primary_security' => $this->primarySecurity ? [
                'id' => $this->primarySecurity->id,
                'canonical_symbol' => $this->primarySecurity->canonical_symbol,
                'symbol' => $this->primarySecurity->symbol,
                'name' => $this->primarySecurity->name,
            ] : null,
            'timeline' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event): array => [
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'title' => $event->title,
                'summary' => $event->summary,
                'timeline_stage' => $event->timeline_stage,
                'timeline_order' => $event->timeline_order,
                'occurred_at' => optional($event->occurred_at)?->toAtomString(),
                'published_at' => optional($event->published_at)?->toAtomString(),
                'importance_level' => $event->importance_level,
                'status' => $event->status,
                'articles' => $event->relationLoaded('articles')
                    ? $event->articles->map(fn ($article): array => [
                        'id' => $article->id,
                        'title' => $article->title,
                        'published_at' => optional($article->published_at)?->toAtomString(),
                        'relation_type' => $article->pivot?->relation_type,
                    ])->values()->all()
                    : [],
            ])->values()->all()),
        ];
    }
}

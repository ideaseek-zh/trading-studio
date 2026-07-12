<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'category' => $this->category,
            'importance_level' => $this->importance_level,
            'sentiment' => $this->sentiment,
            'status' => $this->status,
            'language' => $this->language,
            'copyright_status' => $this->copyright_status,
            'quality_status' => $this->quality_status,
            'quality_score' => $this->quality_score,
            'canonical_url' => $this->canonical_url,
            'published_at' => optional($this->published_at)?->toAtomString(),
            'source_timestamp' => optional($this->source_timestamp)?->toAtomString(),
            'source' => $this->source ? [
                'id' => $this->source->id,
                'source_key' => $this->source->source_key,
                'source_name' => $this->source->source_name,
                'provider' => $this->source->provider,
            ] : null,
            'content' => $this->whenLoaded('content', fn (): array => [
                'content_text' => $this->content?->content_text,
                'content_html' => $this->content?->content_html,
                'attachments' => $this->content?->attachments,
                'images' => $this->content?->images,
                'tags' => $this->content?->tags,
                'quality_issues' => $this->content?->quality_issues,
                'structured_data' => $this->content?->structured_data,
                'normalized_data' => $this->content?->structured_data['normalized'] ?? null,
                'extraction_version' => $this->content?->extraction_version,
                'extracted_at' => optional($this->content?->extracted_at)?->toAtomString(),
                'cleaned_at' => optional($this->content?->cleaned_at)?->toAtomString(),
            ]),
            'securities' => $this->whenLoaded('securities', fn () => $this->securities->map(fn ($security): array => [
                'id' => $security->id,
                'canonical_symbol' => $security->canonical_symbol,
                'symbol' => $security->symbol,
                'name' => $security->name,
                'mention_type' => $security->pivot?->mention_type,
                'matched_text' => $security->pivot?->matched_text,
                'confidence' => $security->pivot?->confidence,
                'is_primary' => (bool) ($security->pivot?->is_primary ?? false),
            ])->values()->all()),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'title' => $event->title,
                'importance_level' => $event->importance_level,
                'status' => $event->status,
                'occurred_at' => optional($event->occurred_at)?->toAtomString(),
            ])->values()->all()),
        ];
    }
}

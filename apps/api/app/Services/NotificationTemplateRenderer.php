<?php

namespace App\Services;

use App\Models\NotificationTemplate;

class NotificationTemplateRenderer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function render(array $payload, ?NotificationTemplate $template = null): array
    {
        $context = $this->context($payload);
        $subjectTemplate = $template?->subject_template ?: $this->defaultSubjectTemplate($payload);
        $bodyTemplate = $template?->body_template ?: $this->defaultBodyTemplate();

        return [
            'subject' => $this->interpolate($subjectTemplate, $context),
            'body' => $this->interpolate($bodyTemplate, $context),
            'message_format' => $template?->message_format ?: 'markdown',
            'template_key' => $template?->template_key,
            'channel_type' => $template?->channel_type,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function context(array $payload): array
    {
        $signal = $payload['signal'] ?? [];
        $security = $payload['security'] ?? [];
        $chain = $payload['chain'] ?? [];
        $meta = $payload['meta'] ?? [];
        $batchSignals = collect($payload['batch_signals'] ?? []);

        return [
            'signal' => $signal,
            'security' => $security,
            'chain' => $chain,
            'meta' => $meta,
            'batch' => [
                'count' => (int) ($meta['batch_count'] ?? $batchSignals->count() ?: 1),
                'lines' => $batchSignals
                    ->map(fn (array $item): string => sprintf(
                        '- %s / %s / 分数 %s',
                        (string) ($item['title'] ?? '未命名'),
                        (string) ($item['symbol'] ?? '--'),
                        (string) ($item['signal_score'] ?? '--')
                    ))
                    ->implode("\n"),
            ],
            'security_display' => trim(((string) ($security['symbol'] ?? '--')).' '.((string) ($security['name'] ?? ''))),
            'signal_score_display' => (string) ($signal['signal_score'] ?? '--'),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function interpolate(string $template, array $context): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function (array $matches) use ($context): string {
            $value = data_get($context, $matches[1]);

            if (is_array($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            }

            return (string) ($value ?? '');
        }, $template);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function defaultSubjectTemplate(array $payload): string
    {
        $batchCount = (int) ($payload['meta']['batch_count'] ?? 1);

        return $batchCount > 1
            ? '[Trading Studio] {{signal.title}} 等 {{batch.count}} 条告警'
            : '[Trading Studio] {{signal.title}}';
    }

    private function defaultBodyTemplate(): string
    {
        return implode("\n", [
            '# 交易信号通知',
            '',
            '标题：{{signal.title}}',
            '证券：{{security_display}}',
            '类型：{{signal.signal_type}}',
            '方向：{{signal.direction}}',
            '评分：{{signal_score_display}}',
            '发布时间：{{signal.published_at}}',
            '事件链：{{chain.chain_type}} / {{chain.topic}}',
            '',
            '合并条数：{{batch.count}}',
            '{{batch.lines}}',
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Services\MercadoPagoVendingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessMercadoPagoWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [5, 15, 45, 120, 300];

    public function __construct(public string $eventId)
    {
    }

    public function handle(MercadoPagoVendingService $service): void
    {
        $event = DB::table('mercadopago_webhook_events')->where('event_id', $this->eventId)->first();
        if (!$event || $event->processed_at) {
            return;
        }

        DB::table('mercadopago_webhook_events')->where('event_id', $this->eventId)->update([
            'attempts' => DB::raw('attempts + 1'),
            'processing_error' => null,
            'updated_at' => now(),
        ]);

        try {
            $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
            $service->processVerifiedWebhook($payload);

            DB::table('mercadopago_webhook_events')->where('event_id', $this->eventId)->update([
                'processed_at' => now(),
                'processing_error' => null,
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            DB::table('mercadopago_webhook_events')->where('event_id', $this->eventId)->update([
                'processing_error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            throw $e;
        }
    }
}

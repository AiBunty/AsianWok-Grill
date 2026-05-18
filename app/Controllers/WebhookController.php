<?php

declare(strict_types=1);

namespace AWG\Controllers;

use AWG\Services\WhatsAppCloudService;

final class WebhookController
{
    public function __construct(private readonly WhatsAppCloudService $service)
    {
    }

    public function whatsappWebhookGet(array $query): array
    {
        return $this->service->verifyWebhookChallenge($query);
    }

    public function whatsappWebhookPost(array $body): array
    {
        return $this->service->processWebhookPayload($body);
    }
}

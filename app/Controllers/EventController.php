<?php

declare(strict_types=1);

namespace AWG\Controllers;

use AWG\Services\EventService;

final class EventController
{
    public function __construct(private readonly EventService $service)
    {
    }

    public function eventsList(array $query = []): array
    {
        $includeInactive = (bool) ($query['include_inactive'] ?? false);
        return $this->service->eventsList($includeInactive);
    }

    public function eventPopup(array $query): array
    {
        return $this->service->eventPopup((string) ($query['eventId'] ?? $query['event_id'] ?? ''));
    }

    public function eventDetail(array $query): array
    {
        return $this->service->eventDetail((string) ($query['eventId'] ?? $query['event_id'] ?? ''));
    }

    public function sendEventOtp(array $body): array
    {
        return $this->service->sendEventOtp($body);
    }

    public function verifyEventOtp(array $body): array
    {
        return $this->service->verifyEventOtp($body);
    }

    public function registerFreeEvent(array $body): array
    {
        return $this->service->registerFreeEvent($body);
    }

    public function createEventOrder(array $body): array
    {
        return $this->service->createEventOrder($body);
    }

    public function confirmEventPayment(array $body): array
    {
        return $this->service->confirmEventPayment($body);
    }

    public function resendEventConfirmation(array $body): array
    {
        return $this->service->resendEventConfirmation($body);
    }

    public function requestEventCancellation(array $body): array
    {
        return $this->service->requestEventCancellation($body);
    }

    public function adminListEvents(array $query = []): array
    {
        return $this->service->eventsList(true);
    }

    public function adminCreateEvent(array $body): array
    {
        return $this->service->adminCreateOrUpdateEvent($body);
    }

    public function adminUpdateEvent(array $body): array
    {
        return $this->service->adminCreateOrUpdateEvent($body);
    }

    public function adminToggleEvent(array $body): array
    {
        return $this->service->adminToggleEvent($body);
    }

    public function adminDeleteEvent(array $body): array
    {
        return $this->service->adminDeleteEvent($body);
    }

    public function adminCloneEvent(array $body): array
    {
        return $this->service->adminCloneEvent($body);
    }

    public function adminEventImageUpload(array $body): array
    {
        return $this->service->adminEventImageUpload($body);
    }

    public function verifyEventQr(array $body): array
    {
        return $this->service->verifyEventQr($body);
    }

    public function adminPreviewEventQr(array $body): array
    {
        $body['preview'] = true;
        return $this->service->verifyEventQr($body);
    }

    public function adminBatchCheckinEventQr(array $body): array
    {
        return $this->service->adminBatchCheckinEventQr($body);
    }

    public function eventGuestReport(array $query): array
    {
        return $this->service->eventGuestReport($query);
    }

    public function eventTransactionsReport(array $query): array
    {
        return $this->service->eventTransactionsReport($query);
    }

    public function adminMailLogReport(array $query): array
    {
        return $this->service->adminMailLogReport($query);
    }

    // Legacy compatibility aliases
    public function listEvents(): array
    {
        return $this->service->eventsList(true);
    }

    public function saveEvent(array $body): array
    {
        return $this->service->adminCreateOrUpdateEvent($body);
    }

    public function deleteEvent(array $body): array
    {
        return $this->service->adminDeleteEvent($body);
    }

    public function toggleEvent(array $body): array
    {
        return $this->service->adminToggleEvent($body);
    }

    public function cloneEvent(array $body): array
    {
        return $this->service->adminCloneEvent($body);
    }

    public function registerForEvent(array $body): array
    {
        return $this->service->registerForEvent((string) ($body['event_id'] ?? ''), $body);
    }

    public function requestOtpForCheckin(array $body): array
    {
        return $this->service->requestOtpForCheckin((string) ($body['phone'] ?? ''));
    }

    public function verifyOtpForCheckin(array $body): array
    {
        return $this->service->verifyOtpForCheckin((string) ($body['phone'] ?? ''), (string) ($body['otp'] ?? ''));
    }

    public function previewEventQr(array $body): array
    {
        return $this->adminPreviewEventQr($body);
    }

    public function mailLogReport(array $query): array
    {
        return $this->service->adminMailLogReport($query);
    }

    public function generateEventQr(array $body): array
    {
        return $this->service->generateQrForEvent((string) ($body['eventId'] ?? ''));
    }
}
